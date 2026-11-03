<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\PatientTransfer;
use App\Models\RegimenHistory;
use App\Models\SampleCollection;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AccessScope;
use App\Services\PatientTransferService;
use App\Services\SuppressionService;
use App\Services\SyncService;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Patient transfers between facilities (fixtures only).
 *   A = Kapoeta Civil Hospital (sender), B = Torit State Hospital (receiver), C = a third facility.
 */
class PatientTransferTest extends TestCase
{
    use RefreshDatabase;

    private Facility $a;

    private Facility $b;

    private Facility $c;

    private User $admin;

    private User $clinicianA;

    private User $clerkA;

    private User $clinicianB;

    private User $clerkB;

    private User $clinicianC;

    private User $labTechB;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->a = Facility::where('code', 'KCH-001')->firstOrFail();
        $this->b = Facility::where('code', 'TSH-009')->firstOrFail();
        $this->c = Facility::create(['name' => 'Third Test Clinic', 'code' => 'TTC-901', 'county_id' => $this->b->county_id]);

        $this->admin = $this->user('admin@x.org', UserRole::ADMINISTRATOR);
        $this->clinicianA = $this->user('clin-a@x.org', UserRole::CLINICIAN, $this->a);
        $this->clerkA = $this->user('clerk-a@x.org', UserRole::DATA_CLERK, $this->a);
        $this->clinicianB = $this->user('clin-b@x.org', UserRole::CLINICIAN, $this->b);
        $this->clerkB = $this->user('clerk-b@x.org', UserRole::DATA_CLERK, $this->b);
        $this->clinicianC = $this->user('clin-c@x.org', UserRole::CLINICIAN, $this->c);
        $this->labTechB = $this->user('lab-b@x.org', UserRole::LAB_TECHNICIAN, $this->b);

        $this->patient = Patient::create([
            'art_number' => 'ART-TRF-1', 'first_name' => 'Transfer', 'last_name' => 'Fixture', 'phone' => '+211777000123',
            'address' => 'Fixture street 9', 'sex' => 'female', 'age' => 30, 'art_start_date' => '2023-01-01',
            'facility_id' => $this->a->id, 'county_id' => $this->a->county_id, 'state_id' => $this->a->county->state_id,
            'status' => 'active',
        ]);
        $result = ViralLoadResult::create([
            'patient_id' => $this->patient->id, 'sample_date' => today()->subDays(200)->toDateString(),
            'result_date' => today()->subDays(190)->toDateString(), 'result_value' => 50, 'is_suppressed' => true,
            'lab_name' => 'Lab', 'sample_type' => 'Plasma', 'next_due_date' => today()->subDays(5)->toDateString(), 'algorithm_reason' => 'Test',
        ]);
        EacEpisode::create(['patient_id' => $this->patient->id, 'viral_load_result_id' => $result->id, 'status' => 'active']);
        RegimenHistory::create(['patient_id' => $this->patient->id, 'regimen_name' => 'TDF/3TC/DTG', 'regimen_line' => '1st Line', 'start_date' => '2023-01-01']);
        Appointment::create(['patient_id' => $this->patient->id, 'appointment_date' => today()->addDays(5)->toDateString(), 'reason' => 'Review', 'status' => 'scheduled']);
        SampleCollection::create([
            'sample_code' => 'SMP-TRF-1', 'patient_id' => $this->patient->id, 'collection_date' => today()->subDays(3)->toDateString(),
            'sample_type' => 'Plasma', 'facility_id' => $this->a->id, 'status' => 'collected',
        ]);
    }

    private function user(string $email, UserRole $role, ?Facility $facility = null): User
    {
        return User::create([
            'name' => 'Staff', 'email' => $email, 'role' => $role, 'facility_id' => $facility?->id,
            'password' => Hash::make('Password@123'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function transferOut(?User $by = null, ?Facility $to = null, array $extra = [])
    {
        return $this->actingAs($by ?? $this->clinicianA)->post("/patients/{$this->patient->id}/transfer-out", $extra + [
            'to_facility_id' => ($to ?? $this->b)->id, 'transfer_date' => today()->toDateString(), 'letter_reference' => 'LTR-001',
        ]);
    }

    private function pending(): PatientTransfer
    {
        return PatientTransfer::withoutGlobalScopes()->where('status', 'pending')->firstOrFail();
    }

    private function row(): Patient
    {
        return Patient::withoutGlobalScopes()->findOrFail($this->patient->id);
    }

    private function dueCountFor(User $user): int
    {
        $this->actingAs($user);

        return app(VlDueService::class)->count();
    }

    // ---------------------------------------------------------------------------------------------
    // Transfer out
    // ---------------------------------------------------------------------------------------------

    public function test_transfer_out_creates_a_pending_transfer_and_the_patient_is_not_due_or_active_at_the_old_facility(): void
    {
        $this->assertSame(1, $this->dueCountFor($this->clinicianA));

        $this->transferOut()->assertRedirect(route('transfers.outgoing'));

        $transfer = $this->pending();
        $this->assertSame($this->patient->id, $transfer->patient_id);
        $this->assertSame($this->a->id, $transfer->from_facility_id);
        $this->assertSame($this->b->id, $transfer->to_facility_id);
        $this->assertSame('LTR-001', $transfer->letter_reference);
        $this->assertSame($this->clinicianA->id, $transfer->requested_by);

        $row = $this->row();
        $this->assertSame('transferred_out', $row->status);
        $this->assertSame($this->a->id, $row->facility_id, 'the patient stays at the old facility until accepted');

        $this->assertSame(0, $this->dueCountFor($this->clinicianA), 'not due');
        $this->actingAs($this->clinicianA);
        $this->assertSame(0, app(SuppressionService::class)->summary()['active_patients'], 'not counted active');
        $this->assertSame(0, $this->dueCountFor($this->clinicianB), 'not due at the receiver either, until accepted');
    }

    public function test_the_patient_is_read_only_at_the_old_facility_while_the_transfer_is_pending(): void
    {
        $this->transferOut();

        $this->actingAs($this->clinicianA)->get("/patients/{$this->patient->id}")->assertOk()->assertSee('Transfer out pending');
        $this->get("/patients/{$this->patient->id}/edit")->assertForbidden();
        $this->put("/patients/{$this->patient->id}", ['first_name' => 'Changed'])->assertForbidden();
        $this->post("/appointments", ['patient_id' => $this->patient->id, 'appointment_date' => today()->addDay()->toDateString(), 'reason' => 'x'])->assertForbidden();
        $this->post('/samples', ['sample_code' => 'SMP-X', 'patient_id' => $this->patient->id, 'collection_date' => today()->toDateString(), 'sample_type' => 'Plasma'])->assertForbidden();
        $this->post("/patients/{$this->patient->id}/regimen", ['regimen_name' => 'X', 'regimen_line' => '2nd Line', 'start_date' => today()->toDateString()])->assertForbidden();
        $this->assertSame('Transfer', $this->row()->first_name);
        $this->assertSame(1, Appointment::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count());
    }

    public function test_transfer_out_rules(): void
    {
        // Same facility, unknown date in the future, missing destination.
        $this->transferOut(null, $this->a)->assertSessionHasErrors('to_facility_id');
        $this->transferOut(null, $this->b, ['transfer_date' => today()->addDay()->toDateString()])->assertSessionHasErrors('transfer_date');
        $this->assertSame(0, PatientTransfer::withoutGlobalScopes()->count());

        // Data clerks and lab technicians cannot transfer out; another facility's clinician cannot see the patient.
        $this->transferOut($this->clerkA)->assertForbidden();
        $this->transferOut($this->labTechB)->assertNotFound(); // not their patient: out of scope
        $this->transferOut($this->clinicianB)->assertNotFound();
        $this->assertSame(0, PatientTransfer::withoutGlobalScopes()->count());

        $this->transferOut()->assertRedirect();
        // A second pending transfer for the same patient is refused.
        $this->transferOut($this->admin, $this->c)->assertSessionHasErrors('patient');
        $this->assertSame(1, PatientTransfer::withoutGlobalScopes()->count());

        // A patient who is not active cannot be transferred out.
        $other = Patient::withoutGlobalScopes()->create([
            'art_number' => 'ART-TRF-2', 'first_name' => 'Gone', 'last_name' => 'Away', 'sex' => 'male', 'age' => 40, 'art_start_date' => '2023-01-01',
            'facility_id' => $this->a->id, 'county_id' => $this->a->county_id, 'state_id' => $this->a->county->state_id, 'status' => 'deceased',
        ]);
        $this->actingAs($this->clinicianA)->post("/patients/{$other->id}/transfer-out", ['to_facility_id' => $this->b->id, 'transfer_date' => today()->toDateString()])
            ->assertSessionHasErrors('patient');
    }

    // ---------------------------------------------------------------------------------------------
    // Incoming
    // ---------------------------------------------------------------------------------------------

    public function test_the_receiving_facility_sees_minimal_details_and_cannot_open_the_record_before_accepting(): void
    {
        $this->transferOut();

        $page = $this->actingAs($this->clerkB)->get('/transfers/incoming');
        $page->assertOk()->assertSee('ART-TRF-1')->assertSee('Transfer Fixture');
        $page->assertDontSee('+211777000123')->assertDontSee('Fixture street 9');
        $this->assertCount(1, $page->viewData('transfers'));

        $this->get("/patients/{$this->patient->id}")->assertNotFound();
        $this->assertSame(0, Patient::count(), 'the patient is not in the receiver\'s scope yet');

        // Nothing for a facility that is not involved.
        $this->actingAs($this->clinicianC)->get('/transfers/incoming')->assertOk()->assertDontSee('ART-TRF-1');
    }

    // ---------------------------------------------------------------------------------------------
    // Accept
    // ---------------------------------------------------------------------------------------------

    public function test_accepting_moves_the_same_patient_and_keeps_the_art_number_and_history(): void
    {
        $this->transferOut();
        $transfer = $this->pending();

        $this->actingAs($this->clerkB)->post("/transfers/{$transfer->id}/accept")->assertRedirect(route('transfers.incoming'));

        $row = $this->row();
        $this->assertSame($this->patient->id, $row->id, 'the same record');
        $this->assertSame('ART-TRF-1', $row->art_number);
        $this->assertSame($this->b->id, $row->facility_id);
        $this->assertSame($this->b->county_id, $row->county_id);
        $this->assertSame($this->b->county->state_id, $row->state_id);
        $this->assertSame('active', $row->status);

        $transfer->refresh();
        $this->assertSame('accepted', $transfer->status);
        $this->assertSame($this->clerkB->id, $transfer->accepted_by);
        $this->assertNotNull($transfer->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accepted_patient_transfer', 'record_id' => $transfer->id, 'user_id' => $this->clerkB->id]);

        // History stayed on the same patient.
        foreach ([ViralLoadResult::class, EacEpisode::class, RegimenHistory::class, Appointment::class] as $model) {
            $this->assertSame(1, $model::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count(), $model);
        }
        $this->assertSame(1, Patient::withoutGlobalScopes()->where('art_number', 'ART-TRF-1')->count(), 'the ART number is still unique');

        // The receiver now sees the patient everywhere, and the due logic is theirs.
        $this->actingAs($this->clinicianB)->get("/patients/{$this->patient->id}")->assertOk()->assertSee('ART-TRF-1');
        $this->assertSame(1, $this->dueCountFor($this->clinicianB));
        $this->assertSame(0, $this->dueCountFor($this->clinicianA));
        $this->actingAs($this->clinicianB);
        $this->assertSame(1, app(SuppressionService::class)->summary()['active_patients']);
        $this->assertCount(1, ViralLoadResult::all());
        $this->assertCount(1, EacEpisode::all());

        // The patient was removed from the incoming list.
        $this->get('/transfers/incoming')->assertDontSee('Accept transfer in');
    }

    public function test_the_old_facility_keeps_a_read_only_entry_but_no_clinical_data_after_acceptance(): void
    {
        $this->transferOut();
        $this->actingAs($this->clerkB)->post("/transfers/{$this->pending()->id}/accept");

        // New clinical data recorded at the new facility.
        $new = $this->actingAs($this->clinicianB)->post('/viral-load', [
            'patient_id' => $this->patient->id, 'sample_date' => today()->subDays(2)->toDateString(), 'result_date' => today()->toDateString(),
            'result_value' => 40, 'sample_type' => 'Plasma', 'lab_name' => \App\Models\TestingLaboratory::where('is_active', true)->value('name'),
        ]);
        $new->assertRedirect();
        $this->assertSame(2, ViralLoadResult::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count());

        $list = $this->actingAs($this->clinicianA)->get('/transfers/outgoing');
        $list->assertOk()->assertSee('ART-TRF-1')->assertSee('Accepted');
        $list->assertDontSee('+211777000123');
        $this->assertCount(1, $list->viewData('transfers'));

        $this->get("/patients/{$this->patient->id}")->assertNotFound();
        $this->get("/patients/{$this->patient->id}/edit")->assertNotFound();
        $this->put("/patients/{$this->patient->id}", ['first_name' => 'Hacked'])->assertNotFound();
        $this->assertSame(0, Patient::count());
        $this->assertSame(0, ViralLoadResult::count(), 'no clinical results at the old facility, old or new');
        $this->assertSame(0, EacEpisode::count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_only_the_receiving_facility_or_an_administrator_can_accept(): void
    {
        $this->transferOut();
        $id = $this->pending()->id;

        $this->actingAs($this->clinicianC)->post("/transfers/{$id}/accept")->assertNotFound();      // not involved
        $this->actingAs($this->clinicianA)->post("/transfers/{$id}/accept")->assertForbidden();     // the sender
        $this->actingAs($this->labTechB)->post("/transfers/{$id}/accept")->assertForbidden();       // wrong role
        $this->assertSame('pending', PatientTransfer::withoutGlobalScopes()->find($id)->status);

        $this->actingAs($this->admin)->post("/transfers/{$id}/accept")->assertRedirect();
        $this->assertSame('accepted', PatientTransfer::withoutGlobalScopes()->find($id)->status);
        $this->assertSame($this->b->id, $this->row()->facility_id);
    }

    public function test_accepting_twice_changes_nothing_the_second_time(): void
    {
        $this->transferOut();
        $id = $this->pending()->id;
        $this->actingAs($this->clinicianB)->post("/transfers/{$id}/accept")->assertRedirect();
        $acceptedAt = PatientTransfer::withoutGlobalScopes()->find($id)->accepted_at;

        $this->post("/transfers/{$id}/accept")->assertSessionHasErrors('transfer');
        $this->assertEquals($acceptedAt, PatientTransfer::withoutGlobalScopes()->find($id)->accepted_at);
        $this->assertSame($this->b->id, $this->row()->facility_id);
    }

    // ---------------------------------------------------------------------------------------------
    // Cancel
    // ---------------------------------------------------------------------------------------------

    public function test_the_sender_can_cancel_a_pending_transfer_and_the_patient_is_active_again(): void
    {
        $this->transferOut();
        $id = $this->pending()->id;

        $this->actingAs($this->clinicianB)->post("/transfers/{$id}/cancel")->assertForbidden();   // the receiver cannot cancel
        $this->actingAs($this->clinicianC)->post("/transfers/{$id}/cancel")->assertNotFound();
        $this->actingAs($this->clerkA)->post("/transfers/{$id}/cancel")->assertForbidden();        // role

        $this->actingAs($this->clinicianA)->post("/transfers/{$id}/cancel")->assertRedirect(route('transfers.outgoing'));

        $this->assertSame('cancelled', PatientTransfer::withoutGlobalScopes()->find($id)->status);
        $row = $this->row();
        $this->assertSame('active', $row->status);
        $this->assertSame($this->a->id, $row->facility_id);
        $this->assertSame(1, $this->dueCountFor($this->clinicianA), 'due again at the old facility');
        $this->actingAs($this->clinicianA)->put("/patients/{$this->patient->id}", [
            'art_number' => 'ART-TRF-1', 'first_name' => 'Edited', 'last_name' => 'Fixture', 'sex' => 'female', 'age' => 30,
            'art_start_date' => '2023-01-01', 'status' => 'active',
        ])->assertRedirect();

        // The receiver no longer sees it, and cannot accept a cancelled transfer.
        $this->actingAs($this->clinicianB)->get('/transfers/incoming')->assertDontSee('ART-TRF-1');
        $this->post("/transfers/{$id}/accept")->assertSessionHasErrors('transfer');
        $this->assertSame($this->a->id, $this->row()->facility_id);
    }

    public function test_an_accepted_transfer_cannot_be_cancelled(): void
    {
        $this->transferOut();
        $id = $this->pending()->id;
        $this->actingAs($this->clinicianB)->post("/transfers/{$id}/accept");

        $this->actingAs($this->clinicianA)->post("/transfers/{$id}/cancel")->assertSessionHasErrors('transfer');
        $this->assertSame('accepted', PatientTransfer::withoutGlobalScopes()->find($id)->status);
        $this->assertSame($this->b->id, $this->row()->facility_id);
    }

    // ---------------------------------------------------------------------------------------------
    // A -> B -> C, administrators, reports
    // ---------------------------------------------------------------------------------------------

    public function test_a_second_transfer_later_works_from_a_to_b_to_c(): void
    {
        $this->transferOut();
        $this->actingAs($this->clinicianB)->post("/transfers/{$this->pending()->id}/accept");

        $this->actingAs($this->clinicianB)->post("/patients/{$this->patient->id}/transfer-out", [
            'to_facility_id' => $this->c->id, 'transfer_date' => today()->toDateString(),
        ])->assertRedirect();
        $this->actingAs($this->clinicianC)->post("/transfers/{$this->pending()->id}/accept")->assertRedirect();

        $row = $this->row();
        $this->assertSame($this->c->id, $row->facility_id);
        $this->assertSame('ART-TRF-1', $row->art_number);
        $this->assertSame('active', $row->status);
        $this->assertSame(2, PatientTransfer::withoutGlobalScopes()->where('patient_id', $this->patient->id)->where('status', 'accepted')->count());
        $this->assertSame(1, ViralLoadResult::withoutGlobalScopes()->where('patient_id', $this->patient->id)->count());

        // Each facility keeps its own read-only entry: A sees A->B, B sees A->B and B->C.
        $this->assertCount(1, $this->actingAs($this->clinicianA)->get('/transfers/outgoing')->viewData('transfers'));
        $this->assertCount(1, $this->actingAs($this->clinicianB)->get('/transfers/outgoing')->viewData('transfers'));
        $this->assertCount(2, $this->actingAs($this->admin)->get('/transfers/outgoing')->viewData('transfers'));

        // The ART number stays unique: nobody can register it again.
        $this->actingAs($this->clinicianC)->post('/patients', [
            'art_number' => 'ART-TRF-1', 'first_name' => 'Dup', 'last_name' => 'Dup', 'sex' => 'male', 'age' => 30,
            'art_start_date' => '2024-01-01', 'regimen_name' => 'X', 'regimen_line' => '1st Line',
        ])->assertSessionHasErrors('art_number');
    }

    public function test_the_facility_report_counts_transfers_in_and_out_for_the_users_scope(): void
    {
        $this->transferOut();
        $stats = $this->actingAs($this->clinicianA)->get('/reports')->viewData('stats')['transfers'];
        $this->assertSame(['out' => 1, 'in' => 0, 'pending_out' => 1, 'pending_in' => 0], $stats);

        $stats = $this->actingAs($this->clinicianB)->get('/reports')->viewData('stats')['transfers'];
        $this->assertSame(['out' => 0, 'in' => 0, 'pending_out' => 0, 'pending_in' => 1], $stats);

        $this->post("/transfers/{$this->pending()->id}/accept");
        $stats = $this->actingAs($this->clinicianB)->get('/reports')->viewData('stats')['transfers'];
        $this->assertSame(1, $stats['in']);
        $this->assertSame(0, $stats['pending_in']);
        $this->assertSame(0, $this->actingAs($this->clinicianC)->get('/reports')->viewData('stats')['transfers']['in']);
    }

    public function test_lab_technicians_have_no_access_to_transfer_pages(): void
    {
        $this->actingAs($this->labTechB)->get('/transfers/incoming')->assertForbidden();
        $this->get('/transfers/outgoing')->assertForbidden();
    }

    // ---------------------------------------------------------------------------------------------
    // Consistency check command
    // ---------------------------------------------------------------------------------------------

    public function test_the_check_command_is_clean_for_consistent_data_and_reports_a_conflict_without_changing_anything(): void
    {
        $this->transferOut();
        $this->artisan('vilocare:check-transfers')->assertExitCode(0);
        $this->actingAs($this->clinicianB)->post("/transfers/{$this->pending()->id}/accept");
        $this->artisan('vilocare:check-transfers')->assertExitCode(0);

        // Two computers disagreed: the transfer says accepted but the patient row is back at the sender.
        DB::table('patients')->where('id', $this->patient->id)->update(['facility_id' => $this->a->id, 'county_id' => $this->a->county_id]);
        $before = DB::table('patients')->where('id', $this->patient->id)->first();

        $this->artisan('vilocare:check-transfers')->assertExitCode(1);
        $this->assertEquals($before, DB::table('patients')->where('id', $this->patient->id)->first(), 'read-only');
    }

    // ---------------------------------------------------------------------------------------------
    // Sync
    // ---------------------------------------------------------------------------------------------

    private function rowsOf(string $table, string $id): array
    {
        return [(array) DB::table($table)->where('id', $id)->first()];
    }

    public function test_a_transfer_round_trips_between_two_computers_and_the_patient_moves_everywhere(): void
    {
        // Computer 1 (sender) records the transfer out.
        $this->transferOut();
        $transferId = $this->pending()->id;
        $afterOut = [
            'patients' => $this->rowsOf('patients', $this->patient->id),
            'patient_transfers' => $this->rowsOf('patient_transfers', $transferId),
        ];

        // Computer 2 (receiver) starts empty of this patient and pulls both rows.
        DB::table('patient_transfers')->where('id', $transferId)->delete();
        DB::table('appointments')->where('patient_id', $this->patient->id)->delete();
        DB::table('eac_episodes')->where('patient_id', $this->patient->id)->delete();
        DB::table('viral_load_results')->where('patient_id', $this->patient->id)->delete();
        DB::table('regimen_histories')->where('patient_id', $this->patient->id)->delete();
        DB::table('sample_collections')->where('patient_id', $this->patient->id)->delete();
        DB::table('patients')->where('id', $this->patient->id)->delete();

        // The sync engine always runs unscoped (middleware on the sync routes, none in the console).
        $result = AccessScope::unscoped(fn () => (new SyncService)->applyRemotePull($afterOut));
        $this->assertSame(0, $result['failed'], json_encode($result['errors']));
        $this->assertSame('pending', PatientTransfer::withoutGlobalScopes()->find($transferId)->status);
        $this->assertSame('transferred_out', $this->row()->status);

        // The receiver accepts on computer 2.
        $this->actingAs($this->clerkB)->post("/transfers/{$transferId}/accept")->assertRedirect();
        $payload = AccessScope::unscoped(fn () => (new SyncService)->getPendingLocalPayload())['tables'];
        $this->assertArrayHasKey('patient_transfers', $payload, 'the accepted transfer is queued for upload');
        $this->assertSame('accepted', $payload['patient_transfers'][0]['status']);
        $this->assertSame($this->b->id, collect($payload['patients'])->firstWhere('id', $this->patient->id)['facility_id']);

        // Back on the central server / computer 1: still the pre-accept rows, then the upload arrives.
        DB::table('patients')->where('id', $this->patient->id)->update([
            'facility_id' => $this->a->id, 'county_id' => $this->a->county_id, 'state_id' => $this->a->county->state_id,
            'status' => 'transferred_out', 'last_modified_at' => now()->subHour(),
        ]);
        DB::table('patient_transfers')->where('id', $transferId)->update([
            'status' => 'pending', 'accepted_by' => null, 'accepted_at' => null, 'last_modified_at' => now()->subHour(),
        ]);

        $push = AccessScope::unscoped(fn () => (new SyncService)->processServerPush(['tables' => $payload], 'computer-2'));
        $this->assertTrue($push['success'] ?? true);

        $row = $this->row();
        $this->assertSame($this->b->id, $row->facility_id, 'the patient moved on the other computer too');
        $this->assertSame('active', $row->status);
        $this->assertSame('accepted', PatientTransfer::withoutGlobalScopes()->find($transferId)->status);

        // After sync the old facility no longer lists the patient as its own.
        $this->assertSame(0, $this->dueCountFor($this->clinicianA));
        $this->assertSame(1, Patient::withoutGlobalScopes()->where('art_number', 'ART-TRF-1')->count());
    }

    public function test_the_transfer_table_is_a_synced_model_after_patients(): void
    {
        $tables = array_keys(config('sync.synced_models'));
        $this->assertGreaterThan(array_search('patients', $tables), array_search('patient_transfers', $tables));
    }

    public function test_sync_runs_unscoped_even_with_a_restricted_user_logged_in(): void
    {
        $this->transferOut();
        config(['sync.api_token' => 'transfer-token-123456']);

        $resp = $this->actingAs($this->clinicianC)->getJson('/api/v1/sync/pull', ['X-Sync-Token' => 'transfer-token-123456', 'X-Client-Version' => '1.1.3']);
        $resp->assertOk();
        $this->assertCount(1, $resp->json('tables.patient_transfers'));

        $old = $this->getJson('/api/v1/sync/pull', ['X-Sync-Token' => 'transfer-token-123456', 'X-Client-Version' => '1.1.2']);
        $this->assertArrayNotHasKey('patient_transfers', $old->json('tables'));
    }

    public function test_the_service_checks_the_state_inside_the_transaction(): void
    {
        $this->transferOut();
        $transfer = $this->pending();

        // The patient record was changed by someone else after the transfer was requested.
        DB::table('patients')->where('id', $this->patient->id)->update(['status' => 'active']);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(PatientTransferService::class)->accept($transfer, $this->clinicianB);
    }

    public function test_the_transfer_scope_hides_other_facilities_transfers(): void
    {
        $this->transferOut();
        $this->actingAs($this->clinicianC);
        $this->assertSame(0, PatientTransfer::count());
        $this->actingAs($this->clinicianA);
        $this->assertSame(1, PatientTransfer::count());
        $this->actingAs($this->clinicianB);
        $this->assertSame(1, PatientTransfer::count());
        $this->assertTrue(AccessScope::forUser($this->admin)->isAll());
    }
}
