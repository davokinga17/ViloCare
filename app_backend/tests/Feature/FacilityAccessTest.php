<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\RegimenHistory;
use App\Models\SampleCollection;
use App\Models\TestingLaboratory;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AccessScope;
use Database\Seeders\LocationSeeder;
use Database\Seeders\TestingLaboratorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Facility-based access: every user except an administrator sees only their facility's data (lab technicians:
 * see App\Services\AccessScope). Fixtures only; no real patient data.
 *
 *   facilityA  Kapoeta Civil Hospital: hosts a testing laboratory, in Kapoeta South county
 *   facilityC  a clinic in the SAME county, no laboratory
 *   facilityB  Torit State Hospital: another county
 */
class FacilityAccessTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facilityA;

    private Facility $facilityB;

    private Facility $facilityC;

    private User $admin;

    private User $clinicianA;

    private User $clinicianB;

    private User $clerkA;

    private User $labTechC;     // facility without a laboratory: own facility only

    private User $labTechA;     // facility that hosts a laboratory: whole county

    private User $labTechNphl;  // stand-alone national laboratory: everything

    private User $unassigned;

    /** @var array<string, Patient> */
    private array $patients = [];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->seed(TestingLaboratorySeeder::class);

        $this->facilityA = Facility::where('code', 'KCH-001')->firstOrFail();
        $this->facilityB = Facility::where('code', 'TSH-009')->firstOrFail();
        $this->facilityC = Facility::create([
            'name' => 'Kapoeta Test Clinic', 'code' => 'KTC-900', 'county_id' => $this->facilityA->county_id,
        ]);
        $this->assertNotSame($this->facilityA->county_id, $this->facilityB->county_id);

        $this->admin = $this->user('admin@t.org', UserRole::ADMINISTRATOR);
        $this->clinicianA = $this->user('clin-a@t.org', UserRole::CLINICIAN, $this->facilityA);
        $this->clinicianB = $this->user('clin-b@t.org', UserRole::CLINICIAN, $this->facilityB);
        $this->clerkA = $this->user('clerk-a@t.org', UserRole::DATA_CLERK, $this->facilityA);
        $this->labTechC = $this->user('lab-c@t.org', UserRole::LAB_TECHNICIAN, $this->facilityC);
        $this->labTechA = $this->user('lab-a@t.org', UserRole::LAB_TECHNICIAN, $this->facilityA);
        $nphl = TestingLaboratory::whereNull('facility_id')->firstOrFail();
        $this->labTechNphl = $this->user('lab-n@t.org', UserRole::LAB_TECHNICIAN, null, $nphl);
        $this->unassigned = $this->user('none@t.org', UserRole::CLINICIAN);

        foreach (['A' => $this->facilityA, 'B' => $this->facilityB, 'C' => $this->facilityC] as $key => $facility) {
            $this->patients[$key] = $this->patientWithRecords($facility);
        }
    }

    private function user(string $email, UserRole $role, ?Facility $facility = null, ?TestingLaboratory $lab = null): User
    {
        return User::create([
            'name' => 'Staff '.$email, 'email' => $email, 'role' => $role,
            'facility_id' => $facility?->id, 'testing_laboratory_id' => $lab?->id,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    /** A patient with one record of everything that belongs to a patient, due for a viral load. */
    private function patientWithRecords(Facility $facility): Patient
    {
        $this->seq++;
        $patient = Patient::create([
            'art_number' => 'ART-ACC-'.$this->seq, 'first_name' => 'Test', 'last_name' => 'Patient'.$this->seq,
            'phone' => '+2547000010'.$this->seq, 'sex' => 'female', 'age' => 30, 'art_start_date' => '2023-01-01',
            'facility_id' => $facility->id, 'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id, 'status' => 'active',
        ]);

        $result = ViralLoadResult::create([
            'patient_id' => $patient->id, 'sample_date' => today()->subDays(200)->toDateString(),
            'result_date' => today()->subDays(190)->toDateString(), 'result_value' => 5000, 'is_suppressed' => false,
            'lab_name' => 'Lab', 'sample_type' => 'Plasma', 'next_due_date' => today()->subDays(10)->toDateString(),
            'algorithm_reason' => 'Test',
        ]);
        EacEpisode::create(['patient_id' => $patient->id, 'viral_load_result_id' => $result->id, 'status' => 'active']);
        SampleCollection::create([
            'sample_code' => 'SMP-ACC-'.$this->seq, 'patient_id' => $patient->id, 'collection_date' => today()->toDateString(),
            'sample_type' => 'Plasma', 'facility_id' => $facility->id, 'status' => 'collected',
        ]);
        Appointment::create([
            'patient_id' => $patient->id, 'appointment_date' => today()->addDays(3)->toDateString(),
            'reason' => 'Review', 'status' => 'scheduled',
        ]);
        RegimenHistory::create([
            'patient_id' => $patient->id, 'regimen_name' => 'TDF/3TC/DTG', 'regimen_line' => '1st Line',
            'start_date' => '2023-01-01',
        ]);
        NotificationLog::create([
            'patient_id' => $patient->id, 'recipient_phone' => $patient->phone, 'category' => 'custom',
            'message_body' => 'Test', 'status' => 'sent',
        ]);

        return $patient;
    }

    /** Counts of every patient-owned model as the given user sees them. */
    private function visible(?User $user): array
    {
        $this->actingAs($user);
        AccessScope::flush();
        $counts = [
            'patients' => Patient::count(),
            'results' => ViralLoadResult::count(),
            'eac' => EacEpisode::count(),
            'samples' => SampleCollection::count(),
            'appointments' => Appointment::count(),
            'regimens' => RegimenHistory::count(),
            'notifications' => NotificationLog::count(),
        ];
        $this->app['auth']->forgetGuards();

        return $counts;
    }

    private function everything(int $n): array
    {
        return array_fill_keys(['patients', 'results', 'eac', 'samples', 'appointments', 'regimens', 'notifications'], $n);
    }

    // ------------------------------------------------------------------------------------------------
    // Role x scope matrix
    // ------------------------------------------------------------------------------------------------

    public function test_scope_types_per_role(): void
    {
        $this->assertTrue(AccessScope::forUser($this->admin)->isAll());
        $this->assertSame(AccessScope::FACILITY, AccessScope::forUser($this->clinicianA)->type);
        $this->assertSame(AccessScope::FACILITY, AccessScope::forUser($this->clerkA)->type);
        $this->assertSame(AccessScope::FACILITY, AccessScope::forUser($this->labTechC)->type);
        $this->assertSame(AccessScope::COUNTY, AccessScope::forUser($this->labTechA)->type);
        $this->assertTrue(AccessScope::forUser($this->labTechNphl)->isAll());
        $this->assertTrue(AccessScope::forUser($this->unassigned)->isNone());
    }

    public function test_every_patient_owned_model_follows_the_role_scope_matrix(): void
    {
        $this->assertSame($this->everything(3), $this->visible($this->admin));
        $this->assertSame($this->everything(1), $this->visible($this->clinicianA));
        $this->assertSame($this->everything(1), $this->visible($this->clerkA));
        $this->assertSame($this->everything(1), $this->visible($this->clinicianB));
        $this->assertSame($this->everything(1), $this->visible($this->labTechC), 'lab at a facility without a laboratory: own facility only');
        $this->assertSame($this->everything(2), $this->visible($this->labTechA), 'lab at a laboratory-hosting facility: the county (A and C)');
        $this->assertSame($this->everything(3), $this->visible($this->labTechNphl), 'national laboratory: all facilities');
        $this->assertSame($this->everything(0), $this->visible($this->unassigned), 'unassigned: nothing');
    }

    public function test_the_sample_scope_includes_samples_sent_from_the_users_facility_or_county(): void
    {
        $this->actingAs($this->labTechA);
        $this->assertEqualsCanonicalizing(
            [$this->patients['A']->id, $this->patients['C']->id],
            SampleCollection::pluck('patient_id')->all()
        );
    }

    // ------------------------------------------------------------------------------------------------
    // Direct URLs
    // ------------------------------------------------------------------------------------------------

    public function test_direct_urls_outside_the_scope_return_404_and_inside_return_200(): void
    {
        $other = $this->patients['B'];
        $otherSample = SampleCollection::withoutGlobalScopes()->where('patient_id', $other->id)->firstOrFail();
        $otherResult = ViralLoadResult::withoutGlobalScopes()->where('patient_id', $other->id)->firstOrFail();
        $otherEac = EacEpisode::withoutGlobalScopes()->where('patient_id', $other->id)->firstOrFail();
        $otherAppointment = Appointment::withoutGlobalScopes()->where('patient_id', $other->id)->firstOrFail();

        $this->actingAs($this->clinicianA);
        $this->get("/patients/{$other->id}")->assertNotFound();
        $this->get("/patients/{$other->id}/edit")->assertNotFound();
        $this->put("/patients/{$other->id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->get("/samples/{$otherSample->id}")->assertNotFound();
        $this->get("/viral-load/{$otherResult->id}")->assertNotFound();
        $this->get("/eac/{$otherEac->id}")->assertNotFound();
        $this->get("/appointments/{$otherAppointment->id}")->assertNotFound();
        $this->assertNotSame('Hacked', Patient::withoutGlobalScopes()->find($other->id)->first_name);

        $mine = $this->patients['A'];
        $this->get("/patients/{$mine->id}")->assertOk();
    }

    // ------------------------------------------------------------------------------------------------
    // Consumers: dashboard, due list, reports, CSV, filters
    // ------------------------------------------------------------------------------------------------

    public function test_dashboard_due_list_reports_and_csv_show_only_in_scope_numbers_and_agree(): void
    {
        $dash = $this->actingAs($this->clinicianA)->get('/dashboard?facility_id=all');
        $dash->assertOk();
        $this->assertSame(1, $dash->viewData('metrics')['due_for_vl']);
        $this->assertSame(1, $dash->viewData('metrics')['total_patients']);

        $list = $this->get('/reports/due-list');
        $rows = $list->viewData('duePmtct')->count() + $list->viewData('dueEac')->count()
            + $list->viewData('dueResults')->count() + $list->viewData('dueBaseline')->count();
        $this->assertSame(1, $rows);

        $csv = $this->get('/reports/due-list?export=csv')->streamedContent();
        $this->assertStringContainsString($this->patients['A']->art_number, $csv);
        $this->assertStringNotContainsString($this->patients['B']->art_number, $csv);
        $this->assertStringNotContainsString($this->patients['C']->art_number, $csv);

        $report = $this->get('/reports');
        $this->assertSame(1, $report->viewData('stats')['total_patients']);
        $this->assertSame(1, $report->viewData('stats')['due_vl']);

        $patientsCsv = $this->get('/reports/patients?export=csv')->streamedContent();
        $this->assertStringNotContainsString($this->patients['B']->art_number, $patientsCsv);

        // The admin sees all three, and the three views still agree.
        $adminDash = $this->actingAs($this->admin)->get('/dashboard?facility_id=all');
        $this->assertSame(3, $adminDash->viewData('metrics')['due_for_vl']);
    }

    public function test_charts_and_suppression_numbers_are_scoped(): void
    {
        $this->actingAs($this->clinicianA);
        $charts = app(\App\Services\DashboardChartsService::class)->monthlyVlActivity([]);
        $due = collect($charts['series'])->firstWhere('key', 'vl_due')['values'];
        $this->assertLessThanOrEqual(1, array_sum($due));
        $this->assertSame(1, app(\App\Services\SuppressionService::class)->summary()['active_patients']);
        $this->assertSame(1, app(\App\Services\VlDueService::class)->count());

        $this->actingAs($this->admin);
        $this->assertSame(3, app(\App\Services\SuppressionService::class)->summary()['active_patients']);
        $this->assertSame(3, app(\App\Services\VlDueService::class)->count());
    }

    public function test_a_filter_can_only_narrow_never_widen(): void
    {
        $resp = $this->actingAs($this->clinicianA)->get("/dashboard?facility_id={$this->facilityB->id}");
        $resp->assertOk();
        $this->assertSame(1, $resp->viewData('metrics')['total_patients'], 'the foreign facility filter is ignored, scope stays');
        $this->assertSame([$this->facilityA->id], $resp->viewData('facilities')->pluck('id')->all());
        $this->assertSame('all', $resp->viewData('selectedFacilityId'));

        $patients = $this->get("/patients?facility_id={$this->facilityB->id}");
        $this->assertSame(0, $patients->viewData('patients')->total());

        $reports = $this->get("/reports/patients?facility_id={$this->facilityB->id}&state_id=all&county_id=all");
        $this->assertStringNotContainsString($this->patients['B']->art_number, $reports->getContent());

        // A county lab technician may filter by a facility inside the county, not outside it.
        $lab = $this->actingAs($this->labTechA)->get("/dashboard?facility_id={$this->facilityC->id}");
        $this->assertSame(1, $lab->viewData('metrics')['total_patients']);
        $labOut = $this->get("/dashboard?facility_id={$this->facilityB->id}");
        $this->assertSame(2, $labOut->viewData('metrics')['total_patients']);
        $this->assertEqualsCanonicalizing(
            Facility::where('county_id', $this->facilityA->county_id)->pluck('id')->all(),
            $labOut->viewData('facilities')->pluck('id')->all(),
            'a county lab technician is offered the facilities of the county only'
        );
        $this->assertNotContains($this->facilityB->id, $labOut->viewData('facilities')->pluck('id')->all());
    }

    public function test_location_narrowing_in_the_access_scope(): void
    {
        $scope = AccessScope::forUser($this->clinicianA);
        $this->assertSame(['facility_id' => $this->facilityA->id], $scope->narrowLocation(['facility_id' => 'all']));
        $this->assertSame(['facility_id' => AccessScope::NO_MATCH], $scope->narrowLocation(['facility_id' => $this->facilityB->id]));
        $this->assertSame(['county_id' => $this->facilityA->county_id], AccessScope::forUser($this->labTechA)->narrowLocation([]));
        $this->assertSame(['state_id' => 'x'], AccessScope::forUser($this->admin)->narrowLocation(['state_id' => 'x']));
    }

    // ------------------------------------------------------------------------------------------------
    // Sync, scheduler and console stay unscoped
    // ------------------------------------------------------------------------------------------------

    public function test_console_and_scheduler_see_everything_with_nobody_logged_in(): void
    {
        $this->assertSame(3, Patient::count());
        $this->assertSame(3, app(\App\Services\VlDueService::class)->count());

        config(['sync.sends_sms' => true]);
        $this->fakeSmsGateway();
        foreach (Patient::all() as $p) {
            ViralLoadResult::where('patient_id', $p->id)->update(['next_due_date' => today()->addDays(2)->toDateString()]);
        }
        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);
        $this->assertSame(3, NotificationLog::where('category', 'due_date_reminder')->count());
    }

    public function test_sync_api_returns_all_data_even_when_a_restricted_user_session_exists(): void
    {
        config(['sync.api_token' => 'tok-1234567890']);
        $resp = $this->actingAs($this->clinicianA)->getJson('/api/v1/sync/pull', [
            'X-Sync-Token' => 'tok-1234567890', 'X-Client-Version' => '1.1.3',
        ]);
        $resp->assertOk();
        $this->assertCount(3, $resp->json('tables.patients'));
        $this->assertCount(3, $resp->json('tables.viral_load_results'));
        $this->assertCount(3, $resp->json('tables.sample_collections'));
    }

    public function test_the_sync_centre_of_a_restricted_user_does_not_scope_the_sync_engine(): void
    {
        $this->actingAs($this->clinicianA);
        $seen = AccessScope::unscoped(fn () => Patient::count());
        $this->assertSame(3, $seen);
        $this->assertSame(1, Patient::count());
    }

    // ------------------------------------------------------------------------------------------------
    // Creating records
    // ------------------------------------------------------------------------------------------------

    private function patientPayload(string $art, ?string $facilityId): array
    {
        return [
            'art_number' => $art, 'first_name' => 'New', 'last_name' => 'Person', 'sex' => 'male', 'age' => 40,
            'art_start_date' => '2024-01-01', 'facility_id' => $facilityId,
            'regimen_name' => 'TDF/3TC/DTG', 'regimen_line' => '1st Line',
        ];
    }

    public function test_a_restricted_user_registers_patients_only_in_their_own_facility(): void
    {
        $this->actingAs($this->clerkA)->post('/patients', $this->patientPayload('ART-NEW-1', $this->facilityB->id))
            ->assertRedirect();
        $patient = Patient::where('art_number', 'ART-NEW-1')->firstOrFail();
        $this->assertSame($this->facilityA->id, $patient->facility_id, 'a forged facility_id is ignored');
        $this->assertSame($this->facilityA->county_id, $patient->county_id);

        $this->actingAs($this->admin)->post('/patients', $this->patientPayload('ART-NEW-2', $this->facilityB->id))->assertRedirect();
        $this->assertSame($this->facilityB->id, Patient::where('art_number', 'ART-NEW-2')->value('facility_id'));
    }

    public function test_a_restricted_user_cannot_move_a_patient_to_another_facility_by_editing_the_request(): void
    {
        $mine = $this->patients['A'];
        $this->actingAs($this->clinicianA)->put("/patients/{$mine->id}", $this->patientPayload($mine->art_number, $this->facilityB->id) + ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame($this->facilityA->id, Patient::withoutGlobalScopes()->find($mine->id)->facility_id);
    }

    public function test_samples_take_the_patients_facility_and_out_of_scope_patients_are_rejected(): void
    {
        $this->actingAs($this->clinicianA)->post('/samples', [
            'sample_code' => 'SMP-NEW-1', 'patient_id' => $this->patients['A']->id, 'collection_date' => today()->toDateString(),
            'sample_type' => 'Plasma', 'facility_id' => $this->facilityB->id, 'status' => 'collected',
        ])->assertRedirect();
        $this->assertSame($this->facilityA->id, SampleCollection::where('sample_code', 'SMP-NEW-1')->value('facility_id'));

        $this->post('/samples', [
            'sample_code' => 'SMP-NEW-2', 'patient_id' => $this->patients['B']->id, 'collection_date' => today()->toDateString(),
            'sample_type' => 'Plasma', 'status' => 'collected',
        ])->assertNotFound();
        $this->assertFalse(SampleCollection::withoutGlobalScopes()->where('sample_code', 'SMP-NEW-2')->exists());

        $this->post('/appointments', [
            'patient_id' => $this->patients['B']->id, 'appointment_date' => today()->addDay()->toDateString(), 'reason' => 'x',
        ])->assertNotFound();
    }

    public function test_a_county_lab_technician_can_enter_a_result_for_a_patient_in_the_county_but_not_outside(): void
    {
        $payload = fn (Patient $p) => [
            'patient_id' => $p->id, 'sample_date' => today()->subDays(3)->toDateString(), 'result_date' => today()->toDateString(),
            'result_value' => 40, 'sample_type' => 'Plasma',
            'lab_name' => TestingLaboratory::where('is_active', true)->value('name'),
        ];

        $this->actingAs($this->labTechA)->post('/viral-load', $payload($this->patients['C']))->assertRedirect();
        $this->assertSame(2, ViralLoadResult::withoutGlobalScopes()->where('patient_id', $this->patients['C']->id)->count());

        $this->post('/viral-load', $payload($this->patients['B']))->assertNotFound();
        $this->assertSame(1, ViralLoadResult::withoutGlobalScopes()->where('patient_id', $this->patients['B']->id)->count());
    }

    public function test_patient_import_is_limited_to_the_users_own_facility(): void
    {
        $csv = "art_number,first_name,last_name,sex,age,phone,address,art_start_date,is_pregnant,is_breastfeeding,facility_code,regimen,regimen_line\n"
            ."ART-IMP-1,Ann,One,female,30,,,2023-05-01,no,no,KCH-001,TDF/3TC/DTG,1st Line\n"
            ."ART-IMP-2,Bob,Two,male,31,,,2023-05-01,no,no,TSH-009,TDF/3TC/DTG,1st Line\n";
        $file = UploadedFile::fake()->createWithContent('p.csv', $csv);

        $resp = $this->actingAs($this->clerkA)->post('/patients/import', ['csv_file' => $file]);
        $resp->assertOk();
        $this->assertTrue(Patient::withoutGlobalScopes()->where('art_number', 'ART-IMP-1')->where('facility_id', $this->facilityA->id)->exists());
        $this->assertFalse(Patient::withoutGlobalScopes()->where('art_number', 'ART-IMP-2')->exists());
    }

    // ------------------------------------------------------------------------------------------------
    // Users
    // ------------------------------------------------------------------------------------------------

    public function test_a_clinician_creates_only_lab_technicians_and_data_clerks_in_their_own_facility(): void
    {
        $this->actingAs($this->clinicianA)->post('/users', [
            'name' => 'New Clerk', 'email' => 'new-clerk@t.org', 'role' => 'data_clerk',
            'facility_id' => $this->facilityB->id, // forged
        ])->assertRedirect(route('users.index'));
        $this->assertSame($this->facilityA->id, User::where('email', 'new-clerk@t.org')->value('facility_id'));

        $this->post('/users', ['name' => 'X', 'email' => 'x@t.org', 'role' => 'clinician'])->assertSessionHasErrors('role');
        $this->post('/users', ['name' => 'Y', 'email' => 'y@t.org', 'role' => 'administrator'])->assertSessionHasErrors('role');
        $this->assertFalse(User::where('email', 'x@t.org')->orWhere('email', 'y@t.org')->exists());
    }

    public function test_a_clinician_sees_and_edits_only_lab_technicians_and_clerks_of_their_own_facility(): void
    {
        $other = $this->user('clerk-b@t.org', UserRole::DATA_CLERK, $this->facilityB);

        $emails = $this->actingAs($this->clinicianA)->get('/users')->viewData('users')->pluck('email')->all();
        $this->assertContains('clerk-a@t.org', $emails);
        $this->assertNotContains('clerk-b@t.org', $emails);
        $this->assertNotContains('clin-a@t.org', $emails, 'other clinicians are not listed');
        $this->assertNotContains('admin@t.org', $emails);
        $this->assertNotContains('lab-c@t.org', $emails, 'facility C is another facility');

        $this->get("/users/{$other->id}/edit")->assertForbidden();
        $this->put("/users/{$other->id}", ['name' => 'Z', 'email' => 'clerk-b@t.org', 'role' => 'data_clerk', 'is_active' => 1])->assertForbidden();
        $this->post("/users/{$other->id}/reset-password")->assertForbidden();
        $this->get("/users/{$this->clerkA->id}/edit")->assertOk();
    }

    public function test_an_administrator_must_assign_a_facility_or_a_laboratory_by_role(): void
    {
        $this->actingAs($this->admin);
        $nphl = TestingLaboratory::whereNull('facility_id')->firstOrFail();

        $this->post('/users', ['name' => 'C', 'email' => 'c1@t.org', 'role' => 'clinician'])->assertSessionHasErrors('facility_id');
        $this->post('/users', ['name' => 'L', 'email' => 'l1@t.org', 'role' => 'lab_technician'])->assertSessionHasErrors('facility_id');
        $this->post('/users', [
            'name' => 'L', 'email' => 'l2@t.org', 'role' => 'lab_technician',
            'facility_id' => $this->facilityA->id, 'testing_laboratory_id' => $nphl->id,
        ])->assertSessionHasErrors('facility_id');

        $this->post('/users', ['name' => 'L', 'email' => 'l3@t.org', 'role' => 'lab_technician', 'testing_laboratory_id' => $nphl->id])
            ->assertRedirect(route('users.index'));
        $lab = User::where('email', 'l3@t.org')->firstOrFail();
        $this->assertSame($nphl->id, $lab->testing_laboratory_id);
        $this->assertNull($lab->facility_id);

        $this->post('/users', ['name' => 'C', 'email' => 'c2@t.org', 'role' => 'clinician', 'facility_id' => $this->facilityB->id, 'testing_laboratory_id' => $nphl->id])
            ->assertRedirect(route('users.index'));
        $clin = User::where('email', 'c2@t.org')->firstOrFail();
        $this->assertSame($this->facilityB->id, $clin->facility_id);
        $this->assertNull($clin->testing_laboratory_id, 'only lab technicians can use a laboratory');

        $this->post('/users', ['name' => 'A', 'email' => 'a2@t.org', 'role' => 'administrator', 'facility_id' => $this->facilityB->id])
            ->assertRedirect(route('users.index'));
        $this->assertNull(User::where('email', 'a2@t.org')->value('facility_id'), 'administrators need no assignment');
    }

    public function test_the_users_page_warns_about_unassigned_staff_and_the_command_fixes_them(): void
    {
        $page = $this->actingAs($this->admin)->get('/users');
        $page->assertOk()->assertSee('have no facility assigned');
        $this->assertSame(['none@t.org'], $page->viewData('unassigned')->pluck('email')->all());

        $this->artisan('vilocare:assign-user-facility', ['email' => 'none@t.org', 'facility_code' => 'TSH-009'])->assertExitCode(0);
        $this->assertSame($this->facilityB->id, $this->unassigned->fresh()->facility_id);
        $this->assertCount(0, $this->get('/users')->viewData('unassigned'));

        $this->artisan('vilocare:assign-user-facility', ['email' => 'none@t.org', 'facility_code' => 'NOPE'])->assertExitCode(1);
    }

    public function test_user_assignment_columns_are_synced_fields_of_the_user_model(): void
    {
        $this->assertContains('facility_id', (new User)->getFillable());
        $this->assertContains('testing_laboratory_id', (new User)->getFillable());
    }

    // ------------------------------------------------------------------------------------------------
    // Unassigned users
    // ------------------------------------------------------------------------------------------------

    public function test_an_unassigned_non_administrator_sees_the_message_and_no_data(): void
    {
        foreach (['/dashboard', '/patients', '/reports', '/samples', '/viral-load', '/eac', '/appointments', '/notifications'] as $url) {
            $resp = $this->actingAs($this->unassigned)->get($url);
            $resp->assertStatus(403);
            $resp->assertSee('No facility assigned. Ask an administrator.');
            $resp->assertDontSee($this->patients['A']->art_number);
        }

        $this->actingAs($this->unassigned)->get('/profile')->assertOk();
        $this->assertSame($this->everything(0), $this->visible($this->unassigned));
        $this->actingAs($this->unassigned);
        $this->assertSame(0, app(\App\Services\VlDueService::class)->count());
    }

    // ------------------------------------------------------------------------------------------------
    // Performance
    // ------------------------------------------------------------------------------------------------

    /** Queries a page runs: the count must not grow with the number of patients (no N+1). */
    private function queries(string $url, User $user): array
    {
        $this->actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $queries = array_map(fn ($q) => preg_replace('/\s+/', ' ', $q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    public function test_no_n_plus_one_on_the_patient_list_dashboard_and_due_list(): void
    {
        $pages = ['/patients', '/dashboard', '/reports/due-list'];
        for ($i = 0; $i < 10; $i++) {
            $this->patientWithRecords($this->facilityA);
        }
        $before = [];
        foreach ($pages as $page) {
            $this->queries($page, $this->clinicianA); // warm caches (schema lookups, scope cache)
            $before[$page] = $this->queries($page, $this->clinicianA);
        }

        for ($i = 0; $i < 8; $i++) {
            $p = $this->patientWithRecords($this->facilityA);
            $this->assertSame($this->facilityA->id, $p->facility_id);
        }

        foreach ($pages as $page) {
            $after = $this->queries($page, $this->clinicianA);
            $extra = array_diff_key(array_count_values($after), array_count_values($before[$page]));
            // 8 patients were added: a per-patient query would add 8 or more. A constant extra eager load is fine.
            $this->assertLessThan(count($before[$page]) + 4, count($after), "{$page} must not run a query per patient. New shapes: ".json_encode(array_keys($extra)));
        }
    }
}
