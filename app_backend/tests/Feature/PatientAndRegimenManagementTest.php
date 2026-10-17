<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RegimenHistory;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PatientAndRegimenManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected User $dataClerk;
    protected User $labTech;
    protected Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->facility = Facility::firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@test.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->clinician = User::create([
            'name' => 'Dr. Jane Clinician',
            'email' => 'clinician@test.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->dataClerk = User::create([
            'name' => 'Clerk John',
            'email' => 'clerk@test.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->labTech = User::create([
            'name' => 'Tech Mark',
            'email' => 'labtech@test.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    public function test_data_clerk_can_register_patient_with_baseline_regimen(): void
    {
        $response = $this->actingAs($this->dataClerk)->post('/patients', [
            'art_number' => 'ART-50001',
            'first_name' => 'Faith',
            'last_name' => 'Moraa',
            'sex' => 'female',
            'age' => 26,
            'phone' => '+254712345678',
            'art_start_date' => '2023-05-10',
            'is_pregnant' => 1,
            'is_breastfeeding' => 0,
            'facility_id' => $this->facility->id,
            'regimen_name' => 'TDF + 3TC + DTG (TLD)',
            'regimen_line' => '1st Line',
        ]);

        $patient = Patient::where('art_number', 'ART-50001')->first();
        $this->assertNotNull($patient);
        $this->assertEquals('Faith Moraa', $patient->full_name);
        $this->assertEquals(26, $patient->age);
        $this->assertTrue($patient->is_pregnant);
        $this->assertEquals($this->facility->county_id, $patient->county_id);
        $this->assertEquals($this->facility->county->state_id, $patient->state_id);

        $response->assertRedirect(route('patients.show', $patient));

        // Verify baseline regimen in regimen_histories
        $this->assertDatabaseHas('regimen_histories', [
            'patient_id' => $patient->id,
            'regimen_name' => 'TDF + 3TC + DTG (TLD)',
            'regimen_line' => '1st Line',
            'end_date' => null,
        ]);

        $this->assertNotNull($patient->activeRegimen);
        $this->assertEquals('TDF + 3TC + DTG (TLD)', $patient->activeRegimen->regimen_name);
    }

    public function test_regimen_switch_preserves_full_history_without_overwriting(): void
    {
        // 1. Create patient with initial regimen
        $patient = Patient::create([
            'art_number' => 'ART-50002',
            'first_name' => 'Samuel',
            'last_name' => 'Njoroge',
            'sex' => 'male',
            'age' => 42,
            'art_start_date' => '2021-01-15',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
            'created_by' => $this->clinician->id,
        ]);

        $initialRegimen = $patient->assignNewRegimen(
            regimenName: 'AZT + 3TC + EFV',
            regimenLine: '1st Line',
            startDate: '2021-01-15',
            reason: 'Initial enrollment',
            userId: $this->clinician->id
        );

        $this->assertEquals(1, $patient->regimenHistories()->count());
        $this->assertNull($initialRegimen->end_date);

        // 2. Clinician switches patient to 2nd Line regimen
        $switchResponse = $this->actingAs($this->clinician)->post(route('patients.regimen.store', $patient), [
            'regimen_name' => 'AZT + 3TC + ATV/r',
            'regimen_line' => '2nd Line',
            'start_date' => '2024-02-01',
            'reason_for_change' => 'Treatment Failure / Unsuppressed VL (Post-EAC)',
        ]);

        $switchResponse->assertRedirect(route('patients.show', $patient));

        // 3. Confirm both records exist in history table
        $patient->refresh();
        $this->assertEquals(2, $patient->regimenHistories()->count());

        // Previous regimen now closed
        $initialRegimen->refresh();
        $this->assertEquals('2024-02-01', $initialRegimen->end_date->toDateString());

        // Current active regimen is the new one
        $this->assertNotNull($patient->activeRegimen);
        $this->assertEquals('AZT + 3TC + ATV/r', $patient->activeRegimen->regimen_name);
        $this->assertEquals('2nd Line', $patient->activeRegimen->regimen_line);
        $this->assertNull($patient->activeRegimen->end_date);
    }

    public function test_bulk_csv_import_creates_patients_and_reports_errors(): void
    {
        $csvContent = "art_number,first_name,last_name,sex,age,art_start_date,phone,facility_code,regimen,regimen_line\n";
        $csvContent .= "ART-90001,John,Mwangi,male,35,2022-04-10,+254700111222,{$this->facility->code},TDF + 3TC + DTG (TLD),1st Line\n";
        $csvContent .= "ART-90002,Lucy,Atieno,female,12,2023-08-15,+254700111333,{$this->facility->code},ABC + 3TC + DTG (Pediatric),1st Line\n";
        // Invalid row 1: missing name & invalid sex
        $csvContent .= "ART-90003,,Ochieng,alien,40,2021-01-01,+254700111444,{$this->facility->code},TDF + 3TC + DTG (TLD),1st Line\n";
        // Invalid row 2: invalid age (> 120)
        $csvContent .= "ART-90004,Old,Person,male,150,2020-01-01,+254700111555,{$this->facility->code},TDF + 3TC + DTG (TLD),1st Line\n";

        $file = UploadedFile::fake()->createWithContent('patients.csv', $csvContent);

        $response = $this->actingAs($this->dataClerk)->post('/patients/import', [
            'csv_file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertSee('Bulk Import Summary');
        $response->assertSee('2'); // 2 imported
        $response->assertSee('2'); // 2 failed

        $this->assertDatabaseHas('patients', ['art_number' => 'ART-90001']);
        $this->assertDatabaseHas('patients', ['art_number' => 'ART-90002', 'age' => 12]);
        $this->assertDatabaseMissing('patients', ['art_number' => 'ART-90003']);
        $this->assertDatabaseMissing('patients', ['art_number' => 'ART-90004']);
    }

    public function test_sample_csv_template_can_be_downloaded(): void
    {
        $response = $this->actingAs($this->dataClerk)->get(route('patients.download-template'));
        $response->assertStatus(200);
        $this->assertStringContainsString('vilocare_patient_bulk_import_template.csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_lab_tech_cannot_access_patient_registration(): void
    {
        $response = $this->actingAs($this->labTech)->get('/patients');
        $response->assertStatus(403);

        $createResponse = $this->actingAs($this->labTech)->get('/patients/create');
        $createResponse->assertStatus(403);
    }

    public function test_latest_of_many_relationships_resolve_correctly(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-50099',
            'first_name' => 'Grace',
            'last_name' => 'Wanjiru',
            'sex' => 'female',
            'age' => 30,
            'art_start_date' => '2022-01-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
            'created_by' => $this->clinician->id,
        ]);

        // Create older regimen
        RegimenHistory::create([
            'patient_id' => $patient->id,
            'regimen_name' => 'Regimen A',
            'regimen_line' => '1st Line',
            'start_date' => '2022-01-01',
            'end_date' => '2023-01-01',
            'created_at' => now()->subMonths(12),
        ]);

        // Create newer active regimen
        $activeReg = RegimenHistory::create([
            'patient_id' => $patient->id,
            'regimen_name' => 'Regimen B',
            'regimen_line' => '1st Line',
            'start_date' => '2023-01-01',
            'end_date' => null,
            'created_at' => now()->subMonths(6),
        ]);

        // Verify eager loading like in PatientController index
        $loadedPatient = Patient::with(['facility', 'county', 'activeRegimen'])->findOrFail($patient->id);
        $this->assertNotNull($loadedPatient->activeRegimen);
        $this->assertEquals($activeReg->id, $loadedPatient->activeRegimen->id);
        $this->assertEquals('Regimen B', $loadedPatient->activeRegimen->regimen_name);
    }

    public function test_pregnancy_status_change_on_existing_patient_triggers_immediate_vl_indication(): void
    {
        // 1. Existing established ART patient who was initially NOT pregnant
        $patient = Patient::create([
            'art_number' => 'ART-TEST-005',
            'first_name' => 'Sarah',
            'last_name' => 'Namubiru',
            'sex' => 'female',
            'age' => 28,
            'phone' => '+211910994073',
            'art_start_date' => '2023-05-10',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
            'created_by' => $this->clinician->id,
        ]);

        $this->assertFalse($patient->isImmediateVlRequired());

        // 2. Update patient profile setting Currently Pregnant = Yes
        $response = $this->actingAs($this->clinician)->put(route('patients.update', $patient), [
            'art_number' => 'ART-TEST-005',
            'first_name' => 'Sarah',
            'last_name' => 'Namubiru',
            'sex' => 'female',
            'age' => 28,
            'phone' => '+211910994073',
            'art_start_date' => '2023-05-10',
            'is_pregnant' => 1,
            'is_breastfeeding' => 0,
            'facility_id' => $this->facility->id,
            'status' => 'active',
        ]);

        $response->assertRedirect(route('patients.show', $patient));
        $response->assertSessionHas('success');

        $patient->refresh();
        $this->assertTrue($patient->is_pregnant);
        $this->assertNotNull($patient->pregnancy_onset_at);
        $this->assertTrue($patient->isImmediateVlRequired());
        $this->assertEquals(today()->toDateString(), $patient->effectiveNextDueDate()->toDateString());

        // 3. Check Audit log recorded pregnancy onset
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'pregnancy_onset_recorded',
            'table_name' => 'patients',
            'record_id' => (string) $patient->id,
        ]);

        // 4. Verify profile page displays PMTCT badge and Immediate VL alert
        $showResponse = $this->actingAs($this->clinician)->get(route('patients.show', $patient));
        $showResponse->assertOk();
        $showResponse->assertSee('Pregnant (PMTCT Cohort)');
        $showResponse->assertSee('Immediate Viral Load Test Indicated');
    }

    public function test_administrator_can_delete_patient_and_cascade_cleanup(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-DEL-001',
            'first_name' => 'Delete',
            'last_name' => 'Me',
            'sex' => 'male',
            'age' => 40,
            'art_start_date' => '2023-01-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Create regimen
        $patient->assignNewRegimen('TDF + 3TC + DTG', '1st Line', '2023-01-01', 'Initial', $this->admin->id);

        $response = $this->actingAs($this->admin)->delete(route('patients.destroy', $patient));
        $response->assertRedirect(route('patients.index'));

        $this->assertDatabaseMissing('patients', ['id' => $patient->id]);
        $this->assertDatabaseMissing('regimen_histories', ['patient_id' => $patient->id]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deleted_patient',
            'table_name' => 'patients',
            'record_id' => (string) $patient->id,
        ]);
    }

    public function test_non_admin_cannot_delete_patient(): void
    {
        $patient = Patient::create([
            'art_number' => 'ART-DEL-002',
            'first_name' => 'Cannot',
            'last_name' => 'Delete',
            'sex' => 'female',
            'age' => 25,
            'art_start_date' => '2023-01-01',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        // Data clerk attempt -> 403
        $clerkResponse = $this->actingAs($this->dataClerk)->delete(route('patients.destroy', $patient));
        $clerkResponse->assertStatus(403);

        // Clinician attempt -> 403
        $clinicianResponse = $this->actingAs($this->clinician)->delete(route('patients.destroy', $patient));
        $clinicianResponse->assertStatus(403);

        $this->assertDatabaseHas('patients', ['id' => $patient->id]);
    }
}

