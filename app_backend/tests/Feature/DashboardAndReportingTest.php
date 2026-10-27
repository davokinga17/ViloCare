<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\County;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AnalyticsService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DashboardAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinician;
    protected Facility $facility;
    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->facility = Facility::where('name', 'Kapoeta Civil Hospital')->orWhere('code', 'KCH-001')->first() ?? Facility::firstOrFail();

        $this->clinician = User::create([
            'name' => 'Dr. Elizabeth',
            'email' => 'elizabeth@clinic.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->patient = Patient::create([
            'art_number' => 'ART-99001',
            'first_name' => 'Grace',
            'last_name' => 'Achieng',
            'sex' => 'female',
            'age' => 31,
            'art_start_date' => '2021-04-15',
            'is_pregnant' => true,
            'is_breastfeeding' => false,
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);

        $this->patient->assignNewRegimen(
            regimenName: 'TDF + 3TC + DTG (TLD)',
            regimenLine: '1st Line',
            startDate: '2021-04-15',
            userId: $this->clinician->id
        );
    }

    public function test_authenticated_staff_can_view_dashboard_with_all_metrics(): void
    {
        // 1. Create a sample
        SampleCollection::create([
            'sample_code' => 'SMP-DASH-01',
            'patient_id' => $this->patient->id,
            'collection_date' => now()->toDateString(),
            'sample_type' => 'Plasma',
            'status' => 'collected',
        ]);

        // 2. Create a VL result
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 40,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(15)->toDateString(),
            'algorithm_reason' => 'Routine 6-month PMTCT repeat',
        ]);

        // 3. Create an appointment
        Appointment::create([
            'patient_id' => $this->patient->id,
            'appointment_date' => now()->toDateString(),
            'reason' => 'Routine Clinical Review',
            'status' => 'scheduled',
        ]);

        $response = $this->actingAs($this->clinician)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('Grace Achieng');
        $response->assertSee('ART-99001');
        $response->assertSee('Viral Load Suppression');
    }

    public function test_patient_cohort_report_view_and_csv_export(): void
    {
        $response = $this->actingAs($this->clinician)->get('/reports/patients');
        $response->assertStatus(200);
        $response->assertSee('Patient Cohort Registry Report');
        $response->assertSee('ART-99001');

        $csvResponse = $this->actingAs($this->clinician)->get('/reports/patients?export=csv');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $csvResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('ART Number', $csvResponse->streamedContent());
        $this->assertStringContainsString('ART-99001', $csvResponse->streamedContent());
    }

    public function test_viral_load_summary_report_and_csv_export(): void
    {
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 50,
            'is_suppressed' => true,
            'lab_name' => 'National HIV Reference Lab',
        ]);

        $response = $this->actingAs($this->clinician)->get('/reports/viral-load');
        $response->assertStatus(200);
        $response->assertSee('Viral Load Suppression');
        $response->assertSee('ART-99001');

        $csvResponse = $this->actingAs($this->clinician)->get('/reports/viral-load?export=csv');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('Suppression Status', $csvResponse->streamedContent());
        $this->assertStringContainsString('ART-99001', $csvResponse->streamedContent());
    }

    public function test_eac_status_report_and_csv_export(): void
    {
        $vl = ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-01',
            'result_date' => '2026-01-05',
            'result_value' => 35000,
            'is_suppressed' => false,
        ]);

        EacEpisode::create([
            'patient_id' => $this->patient->id,
            'viral_load_result_id' => $vl->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->clinician)->get('/reports/eac');
        $response->assertStatus(200);
        $response->assertSee('Enhanced Adherence Counselling');
        $response->assertSee('ART-99001');

        $csvResponse = $this->actingAs($this->clinician)->get('/reports/eac?export=csv');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('Initial High VL', $csvResponse->streamedContent());
    }

    public function test_due_services_report_and_csv_export(): void
    {
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 60,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(7)->toDateString(),
            'algorithm_reason' => 'Routine PMTCT 6-month interval',
        ]);

        $response = $this->actingAs($this->clinician)->get('/reports/due-list?days=30');
        $response->assertStatus(200);
        $response->assertSee('Due for Viral Load Services');
        $response->assertSee('ART-99001');

        $csvResponse = $this->actingAs($this->clinician)->get('/reports/due-list?days=30&export=csv');
        $csvResponse->assertStatus(200);
        $this->assertStringContainsString('Due Date', $csvResponse->streamedContent());
        $this->assertStringContainsString('ART-99001', $csvResponse->streamedContent());
    }

    public function test_report_verification_code_validation(): void
    {
        // Valid reference code format
        $validCode = 'VLC-PAT-20260814-A1B2C3';
        $response = $this->actingAs($this->clinician)->get("/reports/verify?ref={$validCode}");
        $response->assertStatus(200);
        $response->assertSee('Authentic ViLoCare Clinical Document');

        // Invalid reference code
        $invalidCode = 'FAKE-CODE-999';
        $invalidResponse = $this->actingAs($this->clinician)->get("/reports/verify?ref={$invalidCode}");
        $invalidResponse->assertStatus(200);
        $invalidResponse->assertSee('Invalid Reference Code');
    }

    public function test_dashboard_facility_and_date_range_filtering(): void
    {
        // Another facility
        $otherFacility = Facility::create([
            'name' => 'Secondary Outreach Health Post',
            'code' => 'SOH-002',
            'county_id' => $this->facility->county_id,
            'is_active' => true,
        ]);

        // Patient at other facility
        $otherPatient = Patient::create([
            'art_number' => 'ART-99002',
            'first_name' => 'John',
            'last_name' => 'Opondo',
            'sex' => 'male',
            'age' => 45,
            'art_start_date' => now()->subMonths(6)->toDateString(),
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $otherFacility->id,
            'county_id' => $otherFacility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);

        // Create due VL for Grace (facility KCH-001)
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 40,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(10)->toDateString(),
            'algorithm_reason' => 'Routine 6-month PMTCT repeat',
        ]);

        // Create due VL for John (other facility)
        ViralLoadResult::create([
            'patient_id' => $otherPatient->id,
            'sample_date' => '2026-01-10',
            'result_date' => '2026-01-12',
            'result_value' => 30,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(10)->toDateString(),
            'algorithm_reason' => 'Routine 12-month general adult repeat',
        ]);

        // 1. Default request filters to current default facility ($this->facility)
        $defaultResp = $this->actingAs($this->clinician)->get('/dashboard');
        $defaultResp->assertStatus(200);
        $defaultResp->assertSee('Grace Achieng');
        $defaultResp->assertDontSee('John Opondo');

        // 2. Filter specifically by other facility
        $otherResp = $this->actingAs($this->clinician)->get("/dashboard?facility_id={$otherFacility->id}");
        $otherResp->assertStatus(200);
        $otherResp->assertSee('John Opondo');
        $otherResp->assertDontSee('Grace Achieng');

        // 3. Filter by all facilities
        $allResp = $this->actingAs($this->clinician)->get('/dashboard?facility_id=all');
        $allResp->assertStatus(200);
        $allResp->assertSee('Grace Achieng');
        $allResp->assertSee('John Opondo');

        // 4. Date range filter
        $periodResp = $this->actingAs($this->clinician)->get('/dashboard?facility_id=all&date_range=this_month');
        $periodResp->assertStatus(200);
    }

    public function test_reports_hub_location_filters_and_charts(): void
    {
        $response = $this->actingAs($this->clinician)->get('/reports');
        $response->assertStatus(200);
        $response->assertSee('reports-filter-form');
        $response->assertSee('State:');
        $response->assertSee('County:');
        $response->assertSee('Facility:');
        $response->assertSee('reports-trend-chart');
        $response->assertSee('reports-cohort-bar-chart');
        $response->assertSee('Total Cohort Size');
        $response->assertSee('Viral Suppression Rate');
    }

    public function test_dashboard_cascading_state_and_county_filters(): void
    {
        // Create due VL for Grace so she appears in the dashboard due list.
        // Sample/result dates are relative to now() so they stay inside the chart's fixed 6-month window
        // (the VL activity chart is only drawn when that window has data).
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => now()->subDays(2)->toDateString(),
            'result_date' => now()->toDateString(),
            'result_value' => 40,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(10)->toDateString(),
            'algorithm_reason' => 'Routine 6-month PMTCT repeat',
        ]);

        $easternEquatoria = State::where('name', 'Eastern Equatoria')->first();
        $this->assertNotNull($easternEquatoria);

        // Filter by state
        $stateResp = $this->actingAs($this->clinician)->get("/dashboard?state_id={$easternEquatoria->id}&county_id=all&facility_id=all");
        $stateResp->assertStatus(200);
        $stateResp->assertSee('Grace Achieng');
        $stateResp->assertSee('dashboard-trend-chart');
        $stateResp->assertSee('dashboard-cohort-bar-chart');

        // Filter by county
        $countyResp = $this->actingAs($this->clinician)->get("/dashboard?state_id={$easternEquatoria->id}&county_id={$this->facility->county_id}&facility_id=all");
        $countyResp->assertStatus(200);
        $countyResp->assertSee('Grace Achieng');
    }

    public function test_reports_page_location_filtering_updates_metrics(): void
    {
        $otherFacility = Facility::create([
            'name' => 'Torit Regional Clinic',
            'code' => 'TRC-999',
            'county_id' => $this->facility->county_id,
            'is_active' => true,
        ]);

        $otherPatient = Patient::create([
            'art_number' => 'ART-TRC-01',
            'first_name' => 'Samuel',
            'last_name' => 'Lokolong',
            'sex' => 'male',
            'age' => 28,
            'art_start_date' => '2023-05-10',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $otherFacility->id,
            'county_id' => $otherFacility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => 'active',
        ]);

        // Unfiltered: shows 2 patients
        $allResp = $this->actingAs($this->clinician)->get('/reports?facility_id=all');
        $allResp->assertStatus(200);
        $this->assertEquals(2, $allResp->viewData('stats')['total_patients']);

        // Filtered to this->facility: shows 1 patient
        $singleResp = $this->actingAs($this->clinician)->get("/reports?facility_id={$this->facility->id}");
        $singleResp->assertStatus(200);
        $this->assertEquals(1, $singleResp->viewData('stats')['total_patients']);

        // Filtered to other facility: shows 1 patient
        $otherResp = $this->actingAs($this->clinician)->get("/reports?facility_id={$otherFacility->id}");
        $otherResp->assertStatus(200);
        $this->assertEquals(1, $otherResp->viewData('stats')['total_patients']);
    }

    public function test_dashboard_activity_chart_renders_month_labels_and_counts(): void
    {
        $thisMonth = now()->startOfMonth()->addDays(1);
        $twoMonthsAgo = now()->startOfMonth()->subMonths(2)->addDays(3);

        // Two results this month and two results two months ago (results tested, by result date).
        foreach ([$thisMonth, $thisMonth->copy()->addDay(), $twoMonthsAgo, $twoMonthsAgo->copy()->addDay()] as $date) {
            ViralLoadResult::create([
                'patient_id' => $this->patient->id,
                'sample_date' => $date->toDateString(),
                'result_date' => $date->toDateString(),
                'result_value' => 40,
                'is_suppressed' => true,
            ]);
        }

        $labels = [];
        for ($i = 5; $i >= 0; $i--) {
            $labels[] = now()->startOfMonth()->subMonths($i)->format('M Y');
        }

        $response = $this->actingAs($this->clinician)->get('/dashboard?facility_id=all');
        $response->assertStatus(200);

        $line = $response->viewData('charts')['line'];
        $this->assertSame($labels, array_column($line['months'], 'label'));
        $tested = collect($line['series'])->firstWhere('key', 'results_tested')['values'];
        $this->assertSame([0, 0, 0, 2, 0, 2], $tested);
        $response->assertSee('vlActivityChart', false);
    }

    public function test_analytics_service_generates_trend_and_cohort_data(): void
    {
        ViralLoadResult::create([
            'patient_id' => $this->patient->id,
            'sample_date' => now()->toDateString(),
            'result_date' => now()->toDateString(),
            'result_value' => 50,
            'is_suppressed' => true,
        ]);

        $service = app(AnalyticsService::class);
        $trend = $service->getMonthlySuppressionTrend(months: 6);
        $this->assertCount(6, $trend['labels']);
        $this->assertCount(6, $trend['rates']);
        $this->assertEquals(95, $trend['target']);

        $cohort = $service->getCohortSuppressionBreakdown();
        $this->assertContains('Pediatric (<15 yrs)', $cohort['categories']);
        $this->assertContains('Adult (15+ yrs)', $cohort['categories']);
        $this->assertArrayHasKey('overall', $cohort);
    }
}
