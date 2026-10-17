<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\SampleRejection;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SampleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $clinician;
    protected User $labTech;
    protected User $dataClerk;
    protected Facility $facility;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->facility = Facility::firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@clinic.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->clinician = User::create([
            'name' => 'Dr. Paul Clinician',
            'email' => 'paul@clinic.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->labTech = User::create([
            'name' => 'Tech Alice',
            'email' => 'alice@clinic.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->dataClerk = User::create([
            'name' => 'Clerk George',
            'email' => 'george@clinic.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-70001',
            'first_name' => 'Esther',
            'last_name' => 'Wanjiku',
            'sex' => 'female',
            'age' => 29,
            'art_start_date' => '2023-01-10',
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);
    }

    public function test_staff_can_log_sample_collection(): void
    {
        $response = $this->actingAs($this->dataClerk)->post('/samples', [
            'sample_code' => 'SMP-TEST-001',
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-10',
            'sample_type' => 'Plasma',
            'facility_id' => $this->facility->id,
            'health_facility_code' => $this->facility->code,
            'status' => 'collected',
            'notes' => 'Routine 6-month viral load monitoring draw',
        ]);

        $sample = SampleCollection::where('sample_code', 'SMP-TEST-001')->first();
        $this->assertNotNull($sample);
        $this->assertEquals($this->patient->id, $sample->patient_id);
        $this->assertEquals('Plasma', $sample->sample_type);
        $this->assertEquals('collected', $sample->status);
        $this->assertEquals('pending', $sample->sync_status);
        $this->assertNotNull($sample->last_modified_at);

        $response->assertRedirect(route('samples.show', $sample));
    }

    public function test_lab_technician_can_mark_sample_received_at_lab(): void
    {
        $sample = SampleCollection::create([
            'sample_code' => 'SMP-TEST-002',
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-11',
            'sample_type' => 'DBS',
            'status' => 'collected',
            'facility_id' => $this->facility->id,
            'health_facility_code' => $this->facility->code,
        ]);

        $response = $this->actingAs($this->labTech)->post(route('samples.receive', $sample), [
            'sample_reception_date' => '2026-08-12',
            'reception_notes' => '5 DBS spots dry and packaged with desiccant.',
        ]);

        $sample->refresh();
        $this->assertEquals('received_at_lab', $sample->status);
        $this->assertEquals('2026-08-12', $sample->sample_reception_date->toDateString());
        $this->assertEquals($this->labTech->id, $sample->received_by);
        $this->assertStringContainsString('5 DBS spots dry', $sample->notes);

        $response->assertRedirect(route('samples.show', $sample));
    }

    public function test_lab_technician_can_log_sample_rejection_with_proper_foreign_key(): void
    {
        $sample = SampleCollection::create([
            'sample_code' => 'SMP-TEST-003',
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-12',
            'sample_type' => 'Plasma',
            'status' => 'collected',
            'facility_id' => $this->facility->id,
            'health_facility_code' => $this->facility->code,
        ]);

        $response = $this->actingAs($this->labTech)->post(route('samples.reject', $sample), [
            'rejection_date' => '2026-08-13',
            'reason' => 'Hemolyzed specimen',
            'action_taken' => 'Discarded specimen tube; requested immediate redraw from clinic phlebotomy',
            'corrective_action' => 'Phlebotomy team instructed on proper needle gauge and inversion technique',
        ]);

        $sample->refresh();
        $this->assertEquals('rejected', $sample->status);
        $this->assertTrue($sample->isRejected());

        // Verify SampleRejection record has proper foreign key
        $rejection = SampleRejection::where('sample_collection_id', $sample->id)->first();
        $this->assertNotNull($rejection);
        $this->assertEquals('Hemolyzed specimen', $rejection->reason);
        $this->assertEquals('2026-08-13', $rejection->rejection_date->toDateString());
        $this->assertEquals($this->labTech->id, $rejection->rejected_by);
        $this->assertEquals('pending', $rejection->sync_status);

        $this->assertNotNull($sample->rejection);
        $this->assertEquals($rejection->id, $sample->rejection->id);

        $response->assertRedirect(route('samples.show', $sample));
    }

    private function makeCollectedSample(string $code, ?string $notes = null): SampleCollection
    {
        return SampleCollection::create([
            'sample_code' => $code,
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-11',
            'sample_type' => 'Plasma',
            'status' => 'collected',
            'notes' => $notes,
            'facility_id' => $this->facility->id,
            'health_facility_code' => $this->facility->code,
        ]);
    }

    public function test_receiving_with_only_the_reception_date_succeeds(): void
    {
        $sample = $this->makeCollectedSample('SMP-TEST-010', 'Collected at clinic.');

        // This is exactly what the Receive button posts: no reception_notes key at all.
        $response = $this->actingAs($this->labTech)->post(route('samples.receive', $sample), [
            'sample_reception_date' => '2026-08-12',
        ]);

        $response->assertRedirect(route('samples.show', $sample));
        $sample->refresh();
        $this->assertEquals('received_at_lab', $sample->status);
        $this->assertEquals('2026-08-12', $sample->sample_reception_date->toDateString());
        $this->assertEquals('Collected at clinic.', $sample->notes);
    }

    public function test_reception_notes_are_appended_to_existing_notes(): void
    {
        $sample = $this->makeCollectedSample('SMP-TEST-011', 'Collected at clinic.');

        $this->actingAs($this->labTech)->post(route('samples.receive', $sample), [
            'sample_reception_date' => '2026-08-12',
            'reception_notes' => 'Cold chain intact.',
        ])->assertRedirect(route('samples.show', $sample));

        $this->assertEquals("Collected at clinic.\n[Reception]: Cold chain intact.", $sample->fresh()->notes);
    }

    public function test_rejecting_without_optional_fields_succeeds(): void
    {
        $sample = $this->makeCollectedSample('SMP-TEST-012');

        $response = $this->actingAs($this->labTech)->post(route('samples.reject', $sample), [
            'rejection_date' => '2026-08-13',
            'reason' => 'Insufficient volume',
            'action_taken' => 'Requested redraw',
        ]);

        $response->assertRedirect(route('samples.show', $sample));
        $this->assertEquals('rejected', $sample->fresh()->status);
        $rejection = SampleRejection::where('sample_collection_id', $sample->id)->first();
        $this->assertNotNull($rejection);
        $this->assertNull($rejection->corrective_action);
    }

    public function test_data_clerk_is_forbidden_from_receiving_or_rejecting_sample(): void
    {
        $sample = SampleCollection::create([
            'sample_code' => 'SMP-TEST-004',
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-12',
            'sample_type' => 'Plasma',
            'status' => 'collected',
            'facility_id' => $this->facility->id,
        ]);

        // Clerk tries to receive at lab -> 403 Forbidden
        $receiveResp = $this->actingAs($this->dataClerk)->post(route('samples.receive', $sample), [
            'sample_reception_date' => '2026-08-13',
        ]);
        $receiveResp->assertStatus(403);

        // Clerk tries to reject sample -> 403 Forbidden
        $rejectResp = $this->actingAs($this->dataClerk)->post(route('samples.reject', $sample), [
            'rejection_date' => '2026-08-13',
            'reason' => 'Clotted blood',
            'action_taken' => 'Discarded',
        ]);
        $rejectResp->assertStatus(403);
    }

    public function test_sample_accession_code_generator(): void
    {
        $code1 = SampleCollection::generateSampleCode();
        $this->assertStringStartsWith('SMP-' . date('Ym') . '-', $code1);

        SampleCollection::create([
            'sample_code' => $code1,
            'patient_id' => $this->patient->id,
            'collection_date' => '2026-08-10',
            'sample_type' => 'Plasma',
        ]);

        $code2 = SampleCollection::generateSampleCode();
        $this->assertNotEquals($code1, $code2);
    }
}
