<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ViralLoadAndEacTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinician;
    protected User $labTech;
    protected User $dataClerk;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $facility = Facility::firstOrFail();

        $this->clinician = User::create([
            'name' => 'Dr. Mercy',
            'email' => 'mercy@clinic.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->labTech = User::create([
            'name' => 'Lab Brian',
            'email' => 'brian@clinic.org',
            'role' => UserRole::LAB_TECHNICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->dataClerk = User::create([
            'name' => 'Clerk Sarah',
            'email' => 'sarah@clinic.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-60001',
            'first_name' => 'James',
            'last_name' => 'Kariuki',
            'sex' => 'male',
            'age' => 34,
            'art_start_date' => '2022-03-01',
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => 'active',
        ]);
    }

    public function test_lab_technician_can_record_suppressed_viral_load_result(): void
    {
        $response = $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 60,
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
        ]);

        $result = ViralLoadResult::where('patient_id', $this->patient->id)->first();
        $this->assertNotNull($result);
        $this->assertTrue($result->is_suppressed);
        $this->assertEquals('2026-07-10', $result->next_due_date->toDateString());
        $this->assertNull($result->eac_episode_id);

        $response->assertRedirect(route('viral-load.show', $result));
    }

    public function test_high_viral_load_initiates_eac_episode_with_session_1(): void
    {
        $response = $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 24500, // High VL
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
        ]);

        $result = ViralLoadResult::where('patient_id', $this->patient->id)->first();
        $this->assertNotNull($result);
        $this->assertFalse($result->is_suppressed);
        $this->assertNotNull($result->eac_episode_id);

        // Verify EAC Episode created
        $episode = EacEpisode::find($result->eac_episode_id);
        $this->assertNotNull($episode);
        $this->assertEquals('active', $episode->status);

        // Verify Session 1 auto-scheduled
        $session1 = $episode->sessions()->where('session_number', 1)->first();
        $this->assertNotNull($session1);
        $this->assertEquals('pending', $session1->completion_status);
    }

    public function test_eac_3_session_progression_and_repeat_vl_scheduling(): void
    {
        // 1. Initiate high VL & episode
        $result = ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 18000,
            'is_suppressed' => false,
        ]);

        $episode = EacEpisode::create([
            'patient_id' => $this->patient->id,
            'viral_load_result_id' => $result->id,
            'status' => 'active',
        ]);
        $result->update(['eac_episode_id' => $episode->id]);

        $session1 = $episode->sessions()->create([
            'patient_id' => $this->patient->id,
            'session_number' => 1,
            'session_date' => '2026-01-26',
            'completion_status' => 'pending',
        ]);

        // 2. Clinician completes Session 1
        $this->actingAs($this->clinician)->put(route('eac.sessions.update', $session1), [
            'session_date' => '2026-01-26',
            'barriers' => ['Forgetfulness / Busy Routine'],
            'action_plan' => 'Set phone alarm at 8:00 PM daily',
            'next_session_date' => '2026-02-10',
        ]);

        $session1->refresh();
        $this->assertEquals('completed', $session1->completion_status);

        // Verify Session 2 created
        $session2 = $episode->sessions()->where('session_number', 2)->first();
        $this->assertNotNull($session2);
        $this->assertEquals('2026-02-10', $session2->session_date->toDateString());

        // 3. Clinician completes Session 2
        $this->actingAs($this->clinician)->put(route('eac.sessions.update', $session2), [
            'session_date' => '2026-02-10',
            'barriers' => ['Travel / Distance to Clinic'],
            'action_plan' => 'Provided 2-month refill and treatment buddy',
            'next_session_date' => '2026-02-24',
        ]);

        $session2->refresh();
        $this->assertEquals('completed', $session2->completion_status);

        // Verify Session 3 created
        $session3 = $episode->sessions()->where('session_number', 3)->first();
        $this->assertNotNull($session3);

        // 4. Clinician completes Session 3
        $this->actingAs($this->clinician)->put(route('eac.sessions.update', $session3), [
            'session_date' => '2026-02-24',
            'action_plan' => 'Adherence sustained >95%. Ready for repeat VL test.',
        ]);

        $session3->refresh();
        $this->assertEquals('completed', $session3->completion_status);

        // Verify Episode marked 'sessions_completed' with repeat VL due date
        $episode->refresh();
        $this->assertEquals('sessions_completed', $episode->status);
        $this->assertNotNull($episode->repeat_vl_due_date);
    }

    public function test_post_eac_persistent_high_vl_flags_patient_for_clinical_review(): void
    {
        $initialHighVl = ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-01',
            'result_date' => '2026-01-05',
            'result_value' => 50000,
            'is_suppressed' => false,
        ]);

        $episode = EacEpisode::create([
            'patient_id' => $this->patient->id,
            'viral_load_result_id' => $initialHighVl->id,
            'status' => 'sessions_completed',
        ]);

        // Record Post-EAC repeat test with persistent high VL (e.g. 12,000 c/ml)
        $response = $this->actingAs($this->labTech)->post('/viral-load', [
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-04-01',
            'result_date' => '2026-04-03',
            'result_value' => 12000,
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
            'is_post_eac_repeat' => 1,
            'eac_episode_id' => $episode->id,
        ]);

        $repeatResult = ViralLoadResult::where('patient_id', $this->patient->id)
            ->where('is_post_eac_repeat', true)
            ->first();

        $this->assertNotNull($repeatResult);
        $this->assertFalse($repeatResult->is_suppressed);
        $this->assertTrue($repeatResult->flagged_for_clinical_review);

        $episode->refresh();
        $this->assertEquals('flagged_for_regimen_switch', $episode->status);
    }

    public function test_data_clerk_is_forbidden_from_entering_viral_load(): void
    {
        $response = $this->actingAs($this->dataClerk)->get('/viral-load/create');
        $response->assertStatus(403);
    }
}
