<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AiInsightService;
use App\Services\AnalyticsService;
use App\Services\SuppressionService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Suppression is counted per PATIENT (latest result of each active patient), in one place.
 */
class SuppressionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $kapoeta;

    private Facility $torit;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);

        $this->kapoeta = Facility::where('code', 'KCH-001')->firstOrFail();
        $this->torit = Facility::where('code', 'TSH-009')->firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@clinic.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function patient(?Facility $facility = null, array $attributes = []): Patient
    {
        $facility ??= $this->kapoeta;
        $this->seq++;

        return Patient::create($attributes + [
            'art_number' => 'ART-SUP-'.$this->seq,
            'first_name' => 'Test',
            'last_name' => 'Patient'.$this->seq,
            'sex' => 'male',
            'age' => 34,
            'art_start_date' => '2022-03-01',
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => 'active',
        ]);
    }

    private function vl(Patient $patient, int $daysAgo, int $value): ViralLoadResult
    {
        $date = today()->subDays($daysAgo)->toDateString();

        return ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $date,
            'result_date' => $date,
            'result_value' => $value,
            'is_suppressed' => $value < 1000,
            'lab_name' => 'Test Lab',
            'sample_type' => 'Plasma',
        ]);
    }

    /**
     * Kapoeta: 6 active patients, 5 with results.
     *  p1 several tests, latest suppressed   p2 suppressed then unsuppressed   p3 no result
     *  p4 child, suppressed                  p5 pregnant, suppressed           p6 breastfeeding, unsuppressed
     *  p7 inactive, suppressed (must be ignored)
     * Torit: 2 active patients, both unsuppressed.
     */
    private function seedScenario(): void
    {
        $p1 = $this->patient();
        $this->vl($p1, 300, 5000);
        $this->vl($p1, 200, 700);
        $this->vl($p1, 100, 40);

        $p2 = $this->patient();
        $this->vl($p2, 200, 40);
        $this->vl($p2, 10, 4000);

        $this->patient(); // p3: no result

        $this->vl($this->patient(null, ['age' => 8]), 50, 40);
        $this->vl($this->patient(null, ['sex' => 'female', 'is_pregnant' => true]), 50, 40);
        $this->vl($this->patient(null, ['sex' => 'female', 'is_breastfeeding' => true]), 50, 9000);

        $this->vl($this->patient(null, ['status' => 'lost_to_followup']), 20, 40);

        $this->vl($this->patient($this->torit), 30, 8000);
        $this->vl($this->patient($this->torit), 30, 3000);
    }

    private function service(): SuppressionService
    {
        return app(SuppressionService::class);
    }

    public function test_latest_result_decides_not_the_history_of_tests(): void
    {
        $recovered = $this->patient();
        $this->vl($recovered, 300, 5000);
        $this->vl($recovered, 100, 40);
        $relapsed = $this->patient();
        $this->vl($relapsed, 200, 40);
        $this->vl($relapsed, 10, 4000);

        $s = $this->service()->summary();

        $this->assertSame(2, $s['patients_tested']);
        $this->assertSame(1, $s['patients_suppressed']);
        $this->assertSame(1, $s['patients_unsuppressed']);
        $this->assertEquals(50.0, $s['suppression_rate']);
    }

    public function test_patient_without_result_is_excluded_from_rate_but_counted_in_coverage(): void
    {
        $this->vl($this->patient(), 10, 40);
        $this->patient();

        $s = $this->service()->summary();

        $this->assertSame(1, $s['patients_tested']);
        $this->assertEquals(100.0, $s['suppression_rate']);
        $this->assertSame(2, $s['active_patients']);
        $this->assertEquals(50.0, $s['vl_coverage_percent']);
    }

    public function test_inactive_patients_are_not_counted(): void
    {
        $this->seedScenario();

        $this->assertSame(8, $this->service()->summary()['active_patients']);
        $this->assertSame(7, $this->service()->summary()['patients_tested']);
    }

    public function test_cohort_splits_use_the_same_per_patient_rule(): void
    {
        $this->seedScenario();
        $loc = ['facility_id' => $this->kapoeta->id];

        $cohorts = $this->service()->cohorts($loc);

        // Pediatric: p4. Adult: p1, p2, p3 (no result), p5, p6. PMTCT: p5, p6.
        $this->assertSame([1, 2, 1], $cohorts['suppressed']);
        $this->assertSame([0, 2, 1], $cohorts['unsuppressed']);
        $this->assertSame([1, 4, 2], $cohorts['totals']);
        $this->assertEquals([100.0, 50.0, 50.0], $cohorts['rates']);
        $this->assertSame(3, $cohorts['overall']['suppressed']);
        $this->assertSame(5, $cohorts['overall']['total']);
    }

    public function test_filtered_facility_view(): void
    {
        $this->seedScenario();

        $kapoeta = $this->service()->summary(['facility_id' => $this->kapoeta->id]);
        $this->assertSame(6, $kapoeta['active_patients']);
        $this->assertSame(5, $kapoeta['patients_tested']);
        $this->assertSame(3, $kapoeta['patients_suppressed']);
        $this->assertSame(2, $kapoeta['patients_unsuppressed']);
        $this->assertEquals(60.0, $kapoeta['suppression_rate']);
        $this->assertEquals(83.3, $kapoeta['vl_coverage_percent']);

        $torit = $this->service()->summary(['facility_id' => $this->torit->id]);
        $this->assertSame(0, $torit['patients_suppressed']);
        $this->assertEquals(0, $torit['suppression_rate']);
        $this->assertEquals(100.0, $torit['vl_coverage_percent']);

        $all = $this->service()->summary();
        $this->assertSame(7, $all['patients_tested']);
        $this->assertEquals(42.9, $all['suppression_rate']);
    }

    public function test_date_window_limits_which_results_compete_to_be_latest(): void
    {
        $p = $this->patient();
        $this->vl($p, 100, 40);
        $this->vl($p, 10, 4000);

        $before = today()->subDays(50)->toDateString();
        $this->assertSame(1, $this->service()->summary([], null, $before)['patients_suppressed']);
        $this->assertSame(0, $this->service()->summary()['patients_suppressed']);
    }

    public function test_monthly_trend_counts_each_patient_once_using_latest_as_of_month_end(): void
    {
        $month = now()->startOfMonth();
        $p = $this->patient();
        // Suppressed three months ago, unsuppressed this month.
        $this->vlOn($p, $month->copy()->subMonths(3)->addDays(2), 40);
        $this->vlOn($p, $month->copy()->addDay(), 4000);
        $q = $this->patient();
        $this->vlOn($q, $month->copy()->subMonths(2)->addDays(2), 40);

        $trend = $this->service()->monthlyTrend([], 6);

        // Index 2 = three months ago, 3 = two months ago, 5 = this month.
        $this->assertSame([0, 0, 1, 2, 2, 1], $trend['suppressed']);
        $this->assertSame([0, 0, 0, 0, 0, 1], $trend['unsuppressed']);
        $this->assertSame([0, 0, 1, 2, 2, 2], $trend['totals']);
        $this->assertEquals(50.0, $trend['rates'][5]);
    }

    private function vlOn(Patient $patient, $date, int $value): void
    {
        ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $date->toDateString(),
            'result_date' => $date->toDateString(),
            'result_value' => $value,
            'is_suppressed' => $value < 1000,
            'lab_name' => 'Test Lab',
            'sample_type' => 'Plasma',
        ]);
    }

    public function test_dashboard_reports_viral_load_page_analytics_and_ai_show_the_same_numbers(): void
    {
        $this->seedScenario();
        $keys = ['suppression_rate', 'patients_tested', 'patients_suppressed', 'patients_unsuppressed', 'active_patients', 'vl_coverage_percent'];

        foreach ([['facility_id' => $this->kapoeta->id], ['facility_id' => 'all']] as $filter) {
            $query = http_build_query($filter);
            $expected = $this->service()->summary($filter);
            $expected = array_intersect_key($expected, array_flip($keys));

            $dashboard = $this->actingAs($this->admin)->get('/dashboard?'.$query)->viewData('metrics');
            $reports = $this->actingAs($this->admin)->get('/reports?'.$query)->viewData('stats');
            $vlReport = $this->actingAs($this->admin)->get('/reports/viral-load?'.$query)->viewData('stats');

            foreach ([$dashboard, $reports, $vlReport] as $shown) {
                $this->assertEquals($expected, array_intersect_key($shown, array_flip($keys)));
            }

            $cohort = app(AnalyticsService::class)->getCohortSuppressionBreakdown('all', 'all', $filter['facility_id'])['overall'];
            $this->assertEquals($expected['suppression_rate'], $cohort['rate']);
            $this->assertSame($expected['patients_tested'], $cohort['total']);
        }

        // The Viral Load page and AI insights are clinic-wide, so compare with the "all" figures.
        $expected = array_intersect_key($this->service()->summary(), array_flip($keys));
        $vlPage = $this->actingAs($this->admin)->get('/viral-load')->viewData('stats');
        $this->assertEquals($expected, array_intersect_key($vlPage, array_flip($keys)));

        $ai = app(AiInsightService::class)->getAggregatedData()['viral_load_metrics'];
        $this->assertEquals($expected['suppression_rate'], $ai['overall_suppression_rate_percent']);
        $this->assertSame($expected['patients_tested'], $ai['patients_tested']);
        $this->assertSame($expected['patients_suppressed'], $ai['patients_suppressed']);
        $this->assertEquals($expected['vl_coverage_percent'], $ai['vl_coverage_percent']);

        // Analytics cohort rates match the dashboard/report cohort rates.
        $kapoeta = ['facility_id' => $this->kapoeta->id];
        $report = $this->actingAs($this->admin)->get('/reports/viral-load?facility_id='.$this->kapoeta->id)->viewData('stats');
        $rates = app(AnalyticsService::class)->getCohortSuppressionBreakdown('all', 'all', $this->kapoeta->id)['rates'];
        $this->assertEquals([$report['pediatric_rate'], $report['adult_rate'], $report['pmtct_rate']], $rates);
        $this->assertEquals($this->service()->cohorts($kapoeta)['rates'], $rates);
    }

    public function test_queries_compile_to_portable_sql_for_postgres(): void
    {
        $facilityId = $this->kapoeta->id;
        $original = config('database.default');
        config(['database.default' => 'pgsql']);
        // No Postgres server needed: queries are only compiled, never run. The stub PDO is for string quoting only.
        $pg = DB::connection('pgsql');
        $pg->setPdo(new \PDO('sqlite::memory:'));
        $pg->setReadPdo(new \PDO('sqlite::memory:'));

        try {
            $queries = DB::connection('pgsql')->pretend(function () use ($facilityId) {
                $service = app(SuppressionService::class);
                $service->summary(['facility_id' => $facilityId], '2026-01-01', '2026-12-31');
                $service->cohorts(['state_id' => 'x']);
                $service->monthlyTrend([], 2);
            });
        } finally {
            config(['database.default' => $original]);
        }

        $this->assertNotEmpty($queries);
        $sql = strtolower(implode("\n", array_column($queries, 'query')));

        $this->assertStringContainsString('not exists', $sql);
        $this->assertStringContainsString('"patients"', $sql);
        foreach (['strftime', 'ifnull', 'julianday', 'group_concat', 'datetime(', 'date(\'now', '`'] as $sqliteOnly) {
            $this->assertStringNotContainsString($sqliteOnly, $sql, "SQLite-only construct {$sqliteOnly} found");
        }
    }
}
