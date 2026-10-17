<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\County;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AnalyticsService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FiltersAndGraphsTest extends TestCase
{
    use RefreshDatabase;

    protected User $clinician;
    protected State $stateEES;
    protected County $countyKapoetaSouth;
    protected County $countyTorit;
    protected Facility $facilityKapoeta;
    protected Facility $facilityTorit;
    protected Patient $patientKapoetaAdult;
    protected Patient $patientKapoetaPediatric;
    protected Patient $patientToritAdult;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->clinician = User::create([
            'name' => 'Dr. Elizabeth Test',
            'email' => 'elizabeth.test@clinic.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->stateEES = State::where('name', 'Eastern Equatoria')->firstOrFail();
        $this->countyKapoetaSouth = County::where('name', 'Kapoeta South County')->firstOrFail();
        $this->countyTorit = County::where('name', 'Torit County')->firstOrFail();

        $this->facilityKapoeta = Facility::where('name', 'Kapoeta Civil Hospital')->firstOrFail();
        $this->facilityTorit = Facility::where('name', 'Torit State Hospital')->firstOrFail();

        // Patient 1: Kapoeta, Adult, Suppressed VL
        $this->patientKapoetaAdult = Patient::create([
            'art_number' => 'ART-TEST-001',
            'first_name' => 'Amina',
            'last_name' => 'Adult',
            'sex' => 'female',
            'age' => 29,
            'art_start_date' => '2023-01-15',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facilityKapoeta->id,
            'county_id' => $this->countyKapoetaSouth->id,
            'state_id' => $this->stateEES->id,
            'status' => 'active',
        ]);

        ViralLoadResult::create([
            'patient_id' => $this->patientKapoetaAdult->id,
            'sample_date' => '2026-08-10',
            'result_date' => '2026-08-12',
            'result_value' => 40,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(20)->toDateString(),
            'algorithm_reason' => 'Routine 12-month adult test',
        ]);

        // Patient 2: Kapoeta, Pediatric (<15), Unsuppressed VL (High VL)
        $this->patientKapoetaPediatric = Patient::create([
            'art_number' => 'ART-TEST-002',
            'first_name' => 'Musa',
            'last_name' => 'Child',
            'sex' => 'male',
            'age' => 11,
            'art_start_date' => '2024-03-01',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facilityKapoeta->id,
            'county_id' => $this->countyKapoetaSouth->id,
            'state_id' => $this->stateEES->id,
            'status' => 'active',
        ]);

        ViralLoadResult::create([
            'patient_id' => $this->patientKapoetaPediatric->id,
            'sample_date' => '2026-08-15',
            'result_date' => '2026-08-18',
            'result_value' => 25000,
            'is_suppressed' => false,
            'next_due_date' => now()->addDays(5)->toDateString(),
            'algorithm_reason' => 'Routine 6-month pediatric test',
        ]);

        // Patient 3: Torit County / Torit State Hospital, Adult, Suppressed VL
        $this->patientToritAdult = Patient::create([
            'art_number' => 'ART-TEST-003',
            'first_name' => 'Torit',
            'last_name' => 'Citizen',
            'sex' => 'male',
            'age' => 42,
            'art_start_date' => '2022-06-20',
            'is_pregnant' => false,
            'is_breastfeeding' => false,
            'facility_id' => $this->facilityTorit->id,
            'county_id' => $this->countyTorit->id,
            'state_id' => $this->stateEES->id,
            'status' => 'active',
        ]);

        ViralLoadResult::create([
            'patient_id' => $this->patientToritAdult->id,
            'sample_date' => '2026-08-20',
            'result_date' => '2026-08-22',
            'result_value' => 20,
            'is_suppressed' => true,
            'next_due_date' => now()->addDays(15)->toDateString(),
            'algorithm_reason' => 'Routine 12-month adult test',
        ]);
    }

    public function test_reports_page_contains_state_county_facility_filters(): void
    {
        $response = $this->actingAs($this->clinician)->get('/reports');
        $response->assertStatus(200);

        // Assert filter form and dropdowns exist
        $response->assertSee('reports-filter-form');
        $response->assertSee('State:');
        $response->assertSee('County:');
        $response->assertSee('Facility:');
        $response->assertSee('Eastern Equatoria');
        $response->assertSee('Kapoeta South County');
        $response->assertSee('Kapoeta Civil Hospital');
    }

    public function test_dashboard_contains_state_county_facility_filters(): void
    {
        $response = $this->actingAs($this->clinician)->get('/dashboard');
        $response->assertStatus(200);

        // Assert filter form and dropdowns exist
        $response->assertSee('dashboard-filter-form');
        $response->assertSee('State:');
        $response->assertSee('County:');
        $response->assertSee('Facility:');
        $response->assertSee('Eastern Equatoria');
        $response->assertSee('Kapoeta South County');
        $response->assertSee('Kapoeta Civil Hospital');
    }

    public function test_reports_page_cascading_filter_by_state_county_facility(): void
    {
        // 1. Filter by All Facilities -> shows 3 patients
        $allResp = $this->actingAs($this->clinician)->get('/reports?facility_id=all');
        $allResp->assertStatus(200);
        $this->assertEquals(3, $allResp->viewData('stats')['total_patients']);
        $this->assertEquals(3, $allResp->viewData('stats')['total_vl']);
        $this->assertEquals(66.7, $allResp->viewData('stats')['suppression_rate']); // 2 of 3 suppressed

        // 2. Filter by State (Eastern Equatoria) -> shows all 3 in Eastern Equatoria
        $stateResp = $this->actingAs($this->clinician)->get("/reports?state_id={$this->stateEES->id}&county_id=all&facility_id=all");
        $stateResp->assertStatus(200);
        $this->assertEquals(3, $stateResp->viewData('stats')['total_patients']);

        // 3. Filter by County (Kapoeta South County) -> narrows to 2 patients
        $countyResp = $this->actingAs($this->clinician)->get("/reports?state_id={$this->stateEES->id}&county_id={$this->countyKapoetaSouth->id}&facility_id=all");
        $countyResp->assertStatus(200);
        $this->assertEquals(2, $countyResp->viewData('stats')['total_patients']);
        $this->assertEquals(2, $countyResp->viewData('stats')['total_vl']);
        $this->assertEquals(50.0, $countyResp->viewData('stats')['suppression_rate']); // 1 of 2 suppressed

        // 4. Filter by Facility (Torit State Hospital) -> narrows to 1 patient
        $facilityResp = $this->actingAs($this->clinician)->get("/reports?facility_id={$this->facilityTorit->id}");
        $facilityResp->assertStatus(200);
        $this->assertEquals(1, $facilityResp->viewData('stats')['total_patients']);
        $this->assertEquals(1, $facilityResp->viewData('stats')['total_vl']);
        $this->assertEquals(100.0, $facilityResp->viewData('stats')['suppression_rate']); // 1 of 1 suppressed
    }

    public function test_dashboard_cascading_filter_by_state_county_facility(): void
    {
        // 1. Filter by County: Torit County -> only Torit Citizen visible in due list
        $toritResp = $this->actingAs($this->clinician)->get("/dashboard?state_id={$this->stateEES->id}&county_id={$this->countyTorit->id}&facility_id=all");
        $toritResp->assertStatus(200);
        $toritResp->assertSee('Torit Citizen');
        $toritResp->assertDontSee('Amina Adult');
        $toritResp->assertDontSee('Musa Child');

        // 2. Filter by County: Kapoeta South County -> Amina and Musa visible, Torit Citizen not visible
        $kapoetaResp = $this->actingAs($this->clinician)->get("/dashboard?state_id={$this->stateEES->id}&county_id={$this->countyKapoetaSouth->id}&facility_id=all");
        $kapoetaResp->assertStatus(200);
        $kapoetaResp->assertSee('Amina Adult');
        $kapoetaResp->assertSee('Musa Child');
        $kapoetaResp->assertDontSee('Torit Citizen');

        // 3. Filter by Facility: Kapoeta Civil Hospital -> same 2 patients
        $facResp = $this->actingAs($this->clinician)->get("/dashboard?facility_id={$this->facilityKapoeta->id}");
        $facResp->assertStatus(200);
        $facResp->assertSee('Amina Adult');
        $facResp->assertSee('Musa Child');
        $facResp->assertDontSee('Torit Citizen');
    }

    public function test_charts_are_rendered_on_both_reports_and_dashboard(): void
    {
        // Dashboard contains trend line chart and cohort bar/pie charts
        $dashResp = $this->actingAs($this->clinician)->get('/dashboard');
        $dashResp->assertStatus(200);
        $dashResp->assertSee('dashboard-trend-chart');
        $dashResp->assertSee('dashboard-cohort-bar-chart');
        $dashResp->assertSee('dashboard-cohort-pie-chart');
        $dashResp->assertSee('chart.umd.min.js');

        // Reports page contains trend line chart and cohort bar/pie charts
        $repResp = $this->actingAs($this->clinician)->get('/reports');
        $repResp->assertStatus(200);
        $repResp->assertSee('reports-trend-chart');
        $repResp->assertSee('reports-cohort-bar-chart');
        $repResp->assertSee('reports-cohort-pie-chart');
        $repResp->assertSee('chart.umd.min.js');

        // Viral load report also contains visual charts
        $vlResp = $this->actingAs($this->clinician)->get('/reports/viral-load');
        $vlResp->assertStatus(200);
        $vlResp->assertSee('vl-report-trend-chart');
        $vlResp->assertSee('vl-report-cohort-bar-chart');
        $vlResp->assertSee('chart.umd.min.js');
    }

    public function test_analytics_service_calculates_cohort_and_monthly_metrics(): void
    {
        $service = app(AnalyticsService::class);

        // Monthly trend test
        $trend = $service->getMonthlySuppressionTrend(months: 6);
        $this->assertCount(6, $trend['labels']);
        $this->assertEquals(95, $trend['target']);

        // Check that August 2026 data contains the 3 seeded tests (2 suppressed, 1 unsuppressed = 66.7%)
        $augIndex = array_search('Aug 2026', $trend['labels']);
        if ($augIndex !== false) {
            $this->assertEquals(3, $trend['totals'][$augIndex]);
            $this->assertEquals(2, $trend['suppressed'][$augIndex]);
            $this->assertEquals(1, $trend['unsuppressed'][$augIndex]);
            $this->assertEquals(66.7, $trend['rates'][$augIndex]);
        }

        // Cohort breakdown test
        $cohort = $service->getCohortSuppressionBreakdown();
        // Categories: Pediatric (<15 yrs), Adult (15+ yrs), PMTCT (Maternal)
        $this->assertEquals(['Pediatric (<15 yrs)', 'Adult (15+ yrs)', 'PMTCT (Maternal)'], $cohort['categories']);

        // Pediatric: 1 patient, 0 suppressed, 1 unsuppressed
        $this->assertEquals(0, $cohort['suppressed'][0]);
        $this->assertEquals(1, $cohort['unsuppressed'][0]);
        $this->assertEquals(0.0, $cohort['rates'][0]);

        // Adult: 2 patients, 2 suppressed, 0 unsuppressed
        $this->assertEquals(2, $cohort['suppressed'][1]);
        $this->assertEquals(0, $cohort['unsuppressed'][1]);
        $this->assertEquals(100.0, $cohort['rates'][1]);

        // Overall
        $this->assertEquals(2, $cohort['overall']['suppressed']);
        $this->assertEquals(1, $cohort['overall']['unsuppressed']);
        $this->assertEquals(3, $cohort['overall']['total']);
        $this->assertEquals(66.7, $cohort['overall']['rate']);
    }

    public function test_location_seeder_never_moves_patients_or_deletes_referenced_locations(): void
    {
        // A patient in a location the seeder does not know about (e.g. added by the cloud).
        $otherState = State::create(['name' => 'Kampala Metropolitan', 'code' => 'KLA-LEGACY']);
        $otherCounty = County::create(['name' => 'Kampala Central', 'code' => 'KC-LEGACY', 'state_id' => $otherState->id]);
        $otherFacility = Facility::create(['name' => 'ViLoCare Main ART Centre', 'code' => 'VLC-001', 'county_id' => $otherCounty->id]);

        $patient = Patient::create([
            'art_number' => 'ART-LEGACY-001',
            'first_name' => 'Legacy',
            'last_name' => 'Patient',
            'sex' => 'female',
            'age' => 30,
            'art_start_date' => '2022-01-01',
            'facility_id' => $otherFacility->id,
            'county_id' => $otherCounty->id,
            'state_id' => $otherState->id,
            'status' => 'active',
        ]);

        // Re-running the seeder (twice) must not touch them.
        $this->seed(LocationSeeder::class);
        $this->seed(LocationSeeder::class);

        $patient->refresh();
        $this->assertEquals($otherFacility->id, $patient->facility_id);
        $this->assertEquals($otherCounty->id, $patient->county_id);
        $this->assertEquals($otherState->id, $patient->state_id);
        $this->assertDatabaseHas('facilities', ['id' => $otherFacility->id]);
        $this->assertDatabaseHas('counties', ['id' => $otherCounty->id]);
        $this->assertDatabaseHas('states', ['id' => $otherState->id]);

        // The seeder's own locations are present, once each, and rows that already existed keep their id.
        $this->assertSame(1, Facility::where('code', 'KCH-001')->count());
        $this->assertEquals($this->facilityKapoeta->id, Facility::where('code', 'KCH-001')->value('id'));
        $this->assertDatabaseHas('counties', ['name' => 'Kapoeta South County']);
        $this->assertDatabaseHas('states', ['name' => 'Eastern Equatoria']);
    }}
