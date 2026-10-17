<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\ViralLoadResult;
use App\Services\Ai\AiProviderInterface;
use App\Services\AiInsightService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AiInsightServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Facility $facility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->facility = Facility::firstOrFail();
    }

    /**
     * Helper to create test patient
     */
    protected function createPatient(array $attributes = []): Patient
    {
        return Patient::create(array_merge([
            'art_number' => 'ART-TEST-' . rand(1000, 99999),
            'first_name' => 'SecretName',
            'last_name' => 'ConfidentialSurname',
            'phone' => '+211910000000',
            'address' => 'Private Address 123',
            'sex' => 'female',
            'age' => 28,
            'art_start_date' => '2022-01-01',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ], $attributes));
    }

    public function test_aggregated_data_contains_zero_personally_identifiable_information(): void
    {
        // Create patients with distinct personal details
        $this->createPatient(['first_name' => 'John', 'last_name' => 'Doe', 'art_number' => 'ART-99001', 'phone' => '+211910111111']);
        $this->createPatient(['first_name' => 'Jane', 'last_name' => 'Smith', 'art_number' => 'ART-99002', 'phone' => '+211910222222', 'age' => 12]);

        $service = new AiInsightService();
        $data = $service->getAggregatedData();
        $jsonPayload = json_encode($data);

        // Assert strictly that zero PII strings exist in the compiled JSON
        $this->assertStringNotContainsString('John', $jsonPayload);
        $this->assertStringNotContainsString('Doe', $jsonPayload);
        $this->assertStringNotContainsString('Jane', $jsonPayload);
        $this->assertStringNotContainsString('Smith', $jsonPayload);
        $this->assertStringNotContainsString('ART-99001', $jsonPayload);
        $this->assertStringNotContainsString('ART-99002', $jsonPayload);
        $this->assertStringNotContainsString('+211910111111', $jsonPayload);
        $this->assertStringNotContainsString('Private Address', $jsonPayload);

        // Verify structure has expected aggregated statistical buckets
        $this->assertArrayHasKey('clinic_metadata', $data);
        $this->assertArrayHasKey('cohort_demographics', $data);
        $this->assertArrayHasKey('viral_load_metrics', $data);
        $this->assertArrayHasKey('eac_cascade', $data);
        $this->assertArrayHasKey('specimen_and_laboratory', $data);
        $this->assertArrayHasKey('appointments_and_due_monitoring', $data);
    }

    public function test_aggregated_metrics_calculate_accurate_numbers_and_rates(): void
    {
        $p1 = $this->createPatient(['age' => 10, 'sex' => 'male']); // Pediatric
        $p2 = $this->createPatient(['age' => 30, 'sex' => 'female', 'is_pregnant' => true]); // Adult pregnant
        $p3 = $this->createPatient(['age' => 45, 'sex' => 'female']); // Adult

        // VL 1: Pediatric Suppressed (<1000)
        ViralLoadResult::create([
            'patient_id' => $p1->id,
            'sample_date' => '2026-08-01',
            'result_date' => '2026-08-05',
            'result_value' => 40,
            'is_target_not_detected' => false,
            'is_suppressed' => true,
        ]);

        // VL 2: Adult Unsuppressed (>=1000)
        ViralLoadResult::create([
            'patient_id' => $p2->id,
            'sample_date' => '2026-08-01',
            'result_date' => '2026-08-05',
            'result_value' => 5000,
            'is_target_not_detected' => false,
            'is_suppressed' => false,
        ]);

        $service = new AiInsightService();
        $data = $service->getAggregatedData();

        $this->assertEquals(3, $data['cohort_demographics']['total_active_patients']);
        $this->assertEquals(1, $data['cohort_demographics']['age_breakdown']['under_15_pediatric']);
        $this->assertEquals(2, $data['cohort_demographics']['age_breakdown']['age_25_plus_adults']);
        $this->assertEquals(2, $data['cohort_demographics']['sex_breakdown']['female']);
        $this->assertEquals(1, $data['cohort_demographics']['sex_breakdown']['male']);
        $this->assertEquals(1, $data['cohort_demographics']['special_populations']['pregnant_women']);

        // Viral load stats: 1 of 2 suppressed = 50%
        $this->assertEquals(2, $data['viral_load_metrics']['total_tests_recorded']);
        $this->assertEquals(1, $data['viral_load_metrics']['suppressed_count']);
        $this->assertEquals(1, $data['viral_load_metrics']['unsuppressed_count']);
        $this->assertEquals(50.0, $data['viral_load_metrics']['overall_suppression_rate_percent']);
        $this->assertEquals(100.0, $data['viral_load_metrics']['pediatric_suppression_rate_percent']);
        $this->assertEquals(0.0, $data['viral_load_metrics']['adult_suppression_rate_percent']);
    }

    public function test_clinical_summary_uses_ai_provider_and_caches_result(): void
    {
        Cache::flush();

        $mockProvider = new class implements AiProviderInterface {
            public int $callCount = 0;

            public function generateClinicalSummary(array $aggregatedData): string
            {
                $this->callCount++;
                return "Mocked AI Summary: All systems optimal.";
            }

            public function answerStaffQuestion(string $question, array $aggregatedData): string
            {
                return "Mocked Answer to: " . $question;
            }
        };

        $service = new AiInsightService($mockProvider);

        // 1st call: should invoke mock provider
        $result1 = $service->getClinicalSummary(false);
        $this->assertStringContainsString("Mocked AI Summary", $result1['summary']);
        $this->assertFalse($result1['from_cache']);
        $this->assertEquals(1, $mockProvider->callCount);

        // 2nd call without force refresh: should return from cache
        $result2 = $service->getClinicalSummary(false);
        $this->assertStringContainsString("Mocked AI Summary", $result2['summary']);
        $this->assertTrue($result2['from_cache']);
        $this->assertEquals(1, $mockProvider->callCount); // Provider call count unchanged

        // 3rd call with force refresh: should invoke provider again
        $result3 = $service->getClinicalSummary(true);
        $this->assertFalse($result3['from_cache']);
        $this->assertEquals(2, $mockProvider->callCount);
    }
}
