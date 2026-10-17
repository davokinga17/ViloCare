<?php

namespace Tests\Unit;

use App\Models\Facility;
use App\Models\Patient;
use App\Models\ViralLoadResult;
use App\Services\ViralLoadEngine;
use Carbon\Carbon;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViralLoadEngineTest extends TestCase
{
    use RefreshDatabase;

    protected ViralLoadEngine $engine;
    protected Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->facility = Facility::firstOrFail();
        $this->engine = new ViralLoadEngine();
    }

    /**
     * Helper to create test patient
     */
    protected function createPatient(array $attributes = []): Patient
    {
        return Patient::create(array_merge([
            'art_number' => 'ART-TEST-' . rand(1000, 99999),
            'first_name' => 'Test',
            'last_name' => 'Patient',
            'sex' => 'female',
            'age' => 30,
            'art_start_date' => '2022-01-01',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ], $attributes));
    }

    public function test_threshold_suppression_logic(): void
    {
        // < 1000 is suppressed
        $this->assertTrue($this->engine->isSuppressed(0)); // LDL / Target Not Detected
        $this->assertTrue($this->engine->isSuppressed(40));
        $this->assertTrue($this->engine->isSuppressed(999));

        // >= 1000 is unsuppressed / high VL
        $this->assertFalse($this->engine->isSuppressed(1000));
        $this->assertFalse($this->engine->isSuppressed(55000));
    }

    public function test_general_adult_first_suppressed_result_due_in_6_months(): void
    {
        $patient = $this->createPatient(['age' => 28, 'is_pregnant' => false, 'is_breastfeeding' => false]);
        $sampleDate = Carbon::parse('2026-01-10');

        $calc = $this->engine->calculateNextDueDate($patient, 150, $sampleDate);

        $this->assertNotNull($calc['next_due_date']);
        $this->assertEquals('2026-07-10', $calc['next_due_date']->toDateString());
        $this->assertFalse($calc['requires_eac']);
        $this->assertFalse($calc['requires_clinical_review']);
        $this->assertStringContainsString('6-month', $calc['reason']);
    }

    public function test_general_adult_with_2_consecutive_suppressed_results_switches_to_12_months(): void
    {
        $patient = $this->createPatient(['age' => 35, 'is_pregnant' => false, 'is_breastfeeding' => false]);

        // 1st historical suppressed test
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2025-06-01',
            'result_date' => '2025-06-05',
            'result_value' => 50,
            'is_suppressed' => true,
        ]);

        // 2nd suppressed test now
        $sampleDate = Carbon::parse('2026-01-15');
        $calc = $this->engine->calculateNextDueDate($patient, 80, $sampleDate);

        $this->assertNotNull($calc['next_due_date']);
        $this->assertEquals('2027-01-15', $calc['next_due_date']->toDateString()); // Exactly 12 months
        $this->assertStringContainsString('12-month', $calc['reason']);
    }

    public function test_pediatric_under_15_remains_on_6_month_interval_regardless_of_consecutive_suppression(): void
    {
        $patient = $this->createPatient(['age' => 9, 'sex' => 'male']);

        // Historical suppressed tests
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2025-01-01',
            'result_date' => '2025-01-05',
            'result_value' => 20,
            'is_suppressed' => true,
        ]);
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2025-07-01',
            'result_date' => '2025-07-05',
            'result_value' => 30,
            'is_suppressed' => true,
        ]);

        // 3rd consecutive suppressed test: still 6 months because age < 15
        $sampleDate = Carbon::parse('2026-01-10');
        $calc = $this->engine->calculateNextDueDate($patient, 40, $sampleDate);

        $this->assertNotNull($calc['next_due_date']);
        $this->assertEquals('2026-07-10', $calc['next_due_date']->toDateString()); // 6 months, not 12
        $this->assertStringContainsString('Pediatric', $calc['reason']);
    }

    public function test_pregnant_and_breastfeeding_patients_due_in_6_months(): void
    {
        $pregnantPatient = $this->createPatient(['age' => 25, 'is_pregnant' => true, 'is_breastfeeding' => false]);
        $breastfeedingPatient = $this->createPatient(['age' => 26, 'is_pregnant' => false, 'is_breastfeeding' => true]);

        $sampleDate = Carbon::parse('2026-02-01');

        $calcPreg = $this->engine->calculateNextDueDate($pregnantPatient, 100, $sampleDate);
        $this->assertEquals('2026-08-01', $calcPreg['next_due_date']->toDateString());
        $this->assertStringContainsString('PMTCT', $calcPreg['reason']);

        $calcBf = $this->engine->calculateNextDueDate($breastfeedingPatient, 100, $sampleDate);
        $this->assertEquals('2026-08-01', $calcBf['next_due_date']->toDateString());
        $this->assertStringContainsString('PMTCT', $calcBf['reason']);
    }

    public function test_combined_scenario_pregnant_patient_under_15(): void
    {
        // Combined case: Adolescent pregnancy under 15
        $adolescentPregnant = $this->createPatient(['age' => 14, 'sex' => 'female', 'is_pregnant' => true]);
        $sampleDate = Carbon::parse('2026-03-01');

        $calc = $this->engine->calculateNextDueDate($adolescentPregnant, 50, $sampleDate);
        $this->assertEquals('2026-09-01', $calc['next_due_date']->toDateString()); // 6 months
        $this->assertFalse($calc['requires_eac']);
    }

    public function test_newly_identified_anc_pregnancy_initial_test_due_at_3_months(): void
    {
        $newAnc = $this->createPatient([
            'age' => 22,
            'is_pregnant' => true,
            'art_start_date' => '2026-01-01',
        ]);

        $initialDue = $this->engine->calculateInitialDueDate($newAnc);
        $this->assertEquals('2026-04-01', Carbon::parse($initialDue['due_date'])->toDateString()); // 3 months
        $this->assertStringContainsString('ANC', $initialDue['reason']);
    }

    public function test_high_viral_load_triggers_eac_package(): void
    {
        $patient = $this->createPatient(['age' => 30]);
        $sampleDate = Carbon::parse('2026-01-10');

        $calc = $this->engine->calculateNextDueDate($patient, 15400, $sampleDate); // High VL

        $this->assertNull($calc['next_due_date']);
        $this->assertTrue($calc['requires_eac']);
        $this->assertFalse($calc['requires_clinical_review']);
        $this->assertStringContainsString('EAC', $calc['reason']);
    }

    public function test_post_eac_repeat_test_suppressed_resumes_routine_schedule(): void
    {
        $patient = $this->createPatient(['age' => 30]);
        $sampleDate = Carbon::parse('2026-05-10');

        $calc = $this->engine->calculateNextDueDate($patient, 120, $sampleDate, isPostEacRepeat: true);

        $this->assertNotNull($calc['next_due_date']);
        $this->assertEquals('2026-11-10', $calc['next_due_date']->toDateString());
        $this->assertFalse($calc['requires_eac']);
        $this->assertFalse($calc['requires_clinical_review']);
        $this->assertStringContainsString('Re-suppressed', $calc['reason']);
    }

    public function test_post_eac_repeat_test_persistent_high_vl_flags_for_clinical_review(): void
    {
        $patient = $this->createPatient(['age' => 30]);
        $sampleDate = Carbon::parse('2026-05-10');

        $calc = $this->engine->calculateNextDueDate($patient, 8500, $sampleDate, isPostEacRepeat: true);

        $this->assertNull($calc['next_due_date']);
        $this->assertFalse($calc['requires_eac']);
        $this->assertTrue($calc['requires_clinical_review']);
        $this->assertStringContainsString('Suspected treatment failure: clinical review', $calc['reason']);
    }

    public function test_patient_already_on_art_who_becomes_pregnant_gets_immediate_test(): void
    {
        $patient = $this->createPatient([
            'age' => 28,
            'is_pregnant' => true,
            'art_start_date' => '2023-01-01',
        ]);

        $due = $this->engine->calculateInitialDueDate($patient, alreadyOnArtWhenPregnant: true);
        $this->assertEquals(now()->toDateString(), $due['due_date']);
        $this->assertStringContainsString('Immediate', $due['reason']);

        $pregnancyOnset = $this->engine->calculateDueDateForPregnancyOnset($patient);
        $this->assertEquals(now()->toDateString(), $pregnancyOnset['due_date']);
    }

    public function test_routine_baseline_general_population_due_at_6_months(): void
    {
        $patient = $this->createPatient([
            'age' => 32,
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'art_start_date' => '2026-02-01',
        ]);

        $initialDue = $this->engine->calculateInitialDueDate($patient);
        $this->assertEquals('2026-08-01', $initialDue['due_date']);
        $this->assertStringContainsString('6 months', $initialDue['reason']);
    }

    public function test_interrupted_suppression_resets_consecutive_suppressed_count(): void
    {
        $patient = $this->createPatient(['age' => 40]);

        // 1st test: suppressed
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2025-01-01',
            'result_date' => '2025-01-05',
            'result_value' => 50,
            'is_suppressed' => true,
        ]);

        // 2nd test: unsuppressed / viral spike
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2025-07-01',
            'result_date' => '2025-07-05',
            'result_value' => 3500,
            'is_suppressed' => false,
        ]);

        // 3rd test: re-suppressed (1st after spike -> must be 6 months, not 12 months)
        $sampleDate = Carbon::parse('2026-01-10');
        $calc = $this->engine->calculateNextDueDate($patient, 70, $sampleDate);

        $this->assertNotNull($calc['next_due_date']);
        $this->assertEquals('2026-07-10', $calc['next_due_date']->toDateString()); // 6 months
        $this->assertStringContainsString('6-month', $calc['reason']);
    }

    public function test_process_result_calculates_and_persists_algorithmic_fields(): void
    {
        $patient = $this->createPatient(['age' => 29]);

        $result = ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 45,
            'lab_name' => 'National HIV Lab',
            'sample_type' => 'Plasma',
        ]);

        $this->engine->processResult($result);
        $result->refresh();

        $this->assertTrue($result->is_suppressed);
        $this->assertEquals('2026-07-10', $result->next_due_date->toDateString());
        $this->assertNotNull($result->algorithm_reason);
        $this->assertFalse($result->flagged_for_clinical_review);
    }
}
