<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\DashboardChartsService;
use App\Services\SuppressionService;
use App\Services\VlDueService;
use Carbon\Carbon;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The numbers behind the dashboard charts, checked against a hand-built scenario.
 * "Today" is frozen at 2026-10-14, so the 6-month window is May..Oct 2026 (Oct is month to date).
 */
class DashboardChartsServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Facility $kapoeta;

    private Facility $torit;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00'));
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): DashboardChartsService
    {
        return app(DashboardChartsService::class);
    }

    private function patient(array $attributes = [], ?Facility $facility = null): Patient
    {
        $facility ??= $this->kapoeta;
        $this->seq++;

        return Patient::create($attributes + [
            'art_number' => 'ART-CH-'.$this->seq,
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

    private function vl(Patient $patient, string $sampleDate, string $resultDate, int $value, ?string $nextDue = null): ViralLoadResult
    {
        return ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $sampleDate,
            'result_date' => $resultDate,
            'result_value' => $value,
            'is_suppressed' => $value < 1000,
            'lab_name' => 'Test Lab',
            'sample_type' => 'Plasma',
            'next_due_date' => $nextDue,
        ]);
    }

    private function sample(Patient $patient, string $date, string $status, ?Facility $facility = null): SampleCollection
    {
        $this->seq++;

        return SampleCollection::create([
            'sample_code' => 'SC-CH-'.$this->seq,
            'patient_id' => $patient->id,
            'collection_date' => $date,
            'sample_type' => 'Plasma',
            'health_facility_code' => 'KCH-001',
            'facility_id' => ($facility ?? $this->kapoeta)->id,
            'status' => $status,
        ]);
    }

    private function episode(Patient $patient, ViralLoadResult $trigger, string $status, ?string $repeatDue = null): EacEpisode
    {
        return EacEpisode::create([
            'patient_id' => $patient->id,
            'viral_load_result_id' => $trigger->id,
            'status' => $status,
            'repeat_vl_due_date' => $repeatDue,
        ]);
    }

    private function appointment(Patient $patient, string $date, string $status = 'scheduled'): Appointment
    {
        return Appointment::create([
            'patient_id' => $patient->id,
            'appointment_date' => $date,
            'appointment_time' => '09:00',
            'reason' => 'Review',
            'status' => $status,
        ]);
    }

    /**
     * Kapoeta (11 patients)                                                        Torit (1 patient)
     *  p1  adult, two results: Mar (due Aug 15) and Sep 5 (due Oct 20)              t1 adult, unsuppressed, due Sep 30
     *  p2  adult, two results in Aug, both due in August (counts once)              EAC active, sample collected Sep 21
     *  p3  PREGNANT 14-YEAR-OLD, no results                                         appointment today
     *  p4  pregnant adult, onset Jul 10, never tested (immediate PMTCT today)
     *  p5  child 8, suppressed result Oct 5 (due Nov 2)
     *  p6  breastfeeding adult, no results
     *  p7  EAC sessions_completed, repeat VL due Oct 25
     *  p8, p9  EAC active          p10 EAC resolved (repeat due Jun 20)       p11 EAC flagged
     */
    private function seedScenario(): void
    {
        $p1 = $this->patient();
        $this->vl($p1, '2026-03-10', '2026-03-12', 40, '2026-08-15');
        $this->vl($p1, '2026-09-03', '2026-09-05', 40, '2026-10-20');
        $this->sample($p1, '2026-09-03', 'processed');
        $this->sample($p1, '2026-05-01', 'processed'); // first day of the window

        $p2 = $this->patient();
        $this->vl($p2, '2026-08-02', '2026-08-04', 5000, '2026-08-10');
        $this->vl($p2, '2026-08-20', '2026-08-22', 200, '2026-08-25');
        $this->sample($p2, '2026-08-20', 'processed');
        $this->sample($p2, '2026-04-30', 'processed'); // day before the window

        $this->patient(['age' => 14, 'sex' => 'female', 'is_pregnant' => true]);
        $this->patient(['age' => 28, 'sex' => 'female', 'is_pregnant' => true, 'pregnancy_onset_at' => '2026-07-10 08:00:00']);

        $p5 = $this->patient(['age' => 8]);
        $this->vl($p5, '2026-10-02', '2026-10-05', 30, '2026-11-02');
        $this->sample($p5, '2026-10-02', 'collected');

        $this->patient(['age' => 30, 'sex' => 'female', 'is_breastfeeding' => true]);

        $p7 = $this->patient();
        $this->episode($p7, $this->vl($p7, '2026-07-01', '2026-07-03', 8000), 'sessions_completed', '2026-10-25');

        $p8 = $this->patient();
        $this->episode($p8, $this->vl($p8, '2026-09-10', '2026-09-12', 6000), 'active');
        $this->sample($p8, '2026-10-10', 'in_transit');

        $p9 = $this->patient();
        $this->episode($p9, $this->vl($p9, '2026-09-15', '2026-09-17', 7000), 'active');

        $p10 = $this->patient();
        $this->episode($p10, $this->vl($p10, '2026-06-01', '2026-06-03', 9000), 'resolved_suppressed', '2026-06-20');

        $p11 = $this->patient();
        $this->episode($p11, $this->vl($p11, '2026-08-05', '2026-08-07', 12000), 'flagged_for_regimen_switch');

        $this->appointment($p1, '2026-10-14');
        $this->appointment($p2, '2026-10-14');
        $this->appointment($p5, '2026-10-14', 'completed');
        $this->appointment($p5, '2026-10-15');

        $t1 = $this->patient([], $this->torit);
        $this->episode($t1, $this->vl($t1, '2026-09-20', '2026-09-22', 4000, '2026-09-30'), 'active');
        $this->sample($t1, '2026-09-21', 'collected', $this->torit);
        $this->appointment($t1, '2026-10-14');
    }

    private function kapoetaLocation(): array
    {
        return ['facility_id' => $this->kapoeta->id];
    }

    private function series(array $line, string $key): array
    {
        return collect($line['series'])->firstWhere('key', $key)['values'];
    }

    // --- 1. line chart -----------------------------------------------------------------------------

    public function test_line_chart_has_six_months_ending_with_the_current_month_marked_month_to_date(): void
    {
        $line = $this->service()->monthlyVlActivity([]);

        $this->assertSame(['May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], array_column($line['months'], 'label'));
        $this->assertSame([false, false, false, false, false, true], array_column($line['months'], 'is_current'));
        $this->assertSame(['Oct 2026', 'month to date'], $line['labels'][5]);
        $this->assertSame('Last 6 months', $line['subtitle']);
        $this->assertStringContainsString('ignores the date-range filter', $line['caption']);
        $this->assertFalse($line['has_data']);
    }

    public function test_vl_due_counts_each_patient_once_per_month_using_recorded_due_dates(): void
    {
        $this->seedScenario();

        $due = $this->series($this->service()->monthlyVlActivity($this->kapoetaLocation()), 'vl_due');

        // Jun: p10's EAC repeat date. Jul: p4's pregnancy onset. Aug: p1's old result + p2's two results (p2 once).
        // Sep: none. Oct (current month, VlDueService rules): p4 immediate PMTCT, p7 post-EAC, p1 latest result.
        $this->assertSame([0, 1, 1, 2, 0, 3], $due);
    }

    public function test_samples_collected_are_counted_by_collection_date_within_the_window(): void
    {
        $this->seedScenario();

        $samples = $this->series($this->service()->monthlyVlActivity($this->kapoetaLocation()), 'samples_collected');

        // May 1 is inside, April 30 is outside.
        $this->assertSame([1, 0, 0, 1, 1, 2], $samples);
    }

    public function test_results_tested_are_counted_by_result_date_within_the_window(): void
    {
        $this->seedScenario();

        $tested = $this->series($this->service()->monthlyVlActivity($this->kapoetaLocation()), 'results_tested');

        // p1's March result is outside the window.
        $this->assertSame([0, 1, 1, 3, 3, 1], $tested);
    }

    public function test_line_chart_follows_the_location_filter(): void
    {
        $this->seedScenario();

        $all = $this->service()->monthlyVlActivity([]);
        $torit = $this->service()->monthlyVlActivity(['facility_id' => $this->torit->id]);

        $this->assertSame([0, 0, 0, 0, 1, 0], $this->series($torit, 'vl_due'));
        $this->assertSame([0, 0, 0, 0, 1, 0], $this->series($torit, 'samples_collected'));
        $this->assertSame([0, 0, 0, 0, 1, 0], $this->series($torit, 'results_tested'));
        $this->assertSame([0, 1, 1, 2, 1, 3], $this->series($all, 'vl_due'));
    }

    public function test_line_chart_uses_a_fixed_number_of_grouped_queries_not_one_per_month(): void
    {
        $this->seedScenario();
        $due = app(VlDueService::class)->due($this->kapoetaLocation());

        DB::enableQueryLog();
        $this->service()->monthlyVlActivity($this->kapoetaLocation(), $due);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // past results, past EAC, past pregnancy, samples, results.
        $this->assertSame(5, $queries);
    }

    // --- 2. suppression donut ----------------------------------------------------------------------

    public function test_suppression_donut_uses_the_shared_per_patient_definition(): void
    {
        $this->seedScenario();

        $donut = $this->service()->suppression($this->kapoetaLocation());
        $summary = app(SuppressionService::class)->summary($this->kapoetaLocation());

        // Latest result per patient: p1, p2, p5 suppressed; p7..p11 unsuppressed.
        $this->assertSame(8, $donut['tested']);
        $this->assertSame([3, 5], array_column($donut['slices'], 'count'));
        $this->assertEquals(37.5, $donut['rate']);
        $this->assertEquals([37.5, 62.5], array_column($donut['slices'], 'percent'));
        $this->assertEquals($summary['suppression_rate'], $donut['rate']);
        // 8 of the 11 active Kapoeta patients have a result.
        $this->assertEquals(72.7, $donut['coverage_percent']);
        $this->assertStringContainsString('VL coverage 72.7%', $donut['caption']);
    }

    public function test_dashboard_suppression_donut_and_rate_are_the_same_number(): void
    {
        $this->seedScenario();

        $response = $this->actingAs($this->admin)->get('/dashboard?facility_id='.$this->kapoeta->id)->assertOk();
        $metrics = $response->viewData('metrics');
        $donut = $response->viewData('charts')['suppression'];

        $this->assertEquals($metrics['suppression_rate'], $donut['rate']);
        $this->assertSame($metrics['patients_suppressed'], $donut['slices'][0]['count']);
        $this->assertSame($metrics['patients_unsuppressed'], $donut['slices'][1]['count']);
        $this->assertEquals($metrics['vl_coverage_percent'], $donut['coverage_percent']);
    }

    // --- 3 + 4. workload and EAC -------------------------------------------------------------------

    public function test_todays_workload_counts_real_rows_with_units(): void
    {
        $this->seedScenario();

        $bars = collect($this->service()->todaysWorkload($this->kapoetaLocation())['bars'])->keyBy('key');

        // Completed and tomorrow's appointments are not "scheduled today".
        $this->assertSame(2, $bars['appointments']['count']);
        $this->assertSame('appointments', $bars['appointments']['unit']);
        // Collected + in transit only (processed ones are done).
        $this->assertSame(2, $bars['samples_pending']['count']);
        $this->assertSame('samples', $bars['samples_pending']['unit']);
        $this->assertSame(2, $bars['active_eac']['count']);
        $this->assertSame('episodes', $bars['active_eac']['unit']);
    }

    public function test_eac_slices_cover_every_episode_and_add_up_to_100_percent(): void
    {
        $this->seedScenario();

        $eac = $this->service()->eacProgress($this->kapoetaLocation());

        $this->assertSame(5, $eac['total']);
        $this->assertSame('Total EAC episodes', $eac['center_label']);
        $this->assertSame(['active', 'sessions_completed', 'resolved_suppressed', 'flagged_for_regimen_switch'], array_column($eac['slices'], 'key'));
        $this->assertSame([2, 1, 1, 1], array_column($eac['slices'], 'count'));
        $this->assertEquals([40.0, 20.0, 20.0, 20.0], array_column($eac['slices'], 'percent'));
    }

    public function test_empty_eac_slices_are_listed_with_zero_and_unknown_statuses_get_an_other_row(): void
    {
        $p = $this->patient();
        $this->episode($p, $this->vl($p, '2026-09-01', '2026-09-03', 5000), 'active');
        $q = $this->patient();
        $this->episode($q, $this->vl($q, '2026-09-01', '2026-09-03', 5000), 'repeat_vl_done');

        $eac = $this->service()->eacProgress([]);

        $this->assertSame([1, 0, 0, 0, 1], array_column($eac['slices'], 'count'));
        $this->assertSame('Other', $eac['slices'][4]['label']);
        $this->assertEquals(100.0, round(array_sum(array_column($eac['slices'], 'percent')), 1));
        $this->assertSame(2, $eac['total']);
    }

    public function test_active_eac_is_identical_in_the_workload_bar_the_eac_card_and_the_summary_card(): void
    {
        $this->seedScenario();

        foreach ([['facility_id' => $this->kapoeta->id], ['facility_id' => 'all']] as $filter) {
            $response = $this->actingAs($this->admin)->get('/dashboard?'.http_build_query($filter))->assertOk();
            $charts = $response->viewData('charts');
            $metrics = $response->viewData('metrics');

            $workloadActive = collect($charts['workload']['bars'])->firstWhere('key', 'active_eac')['count'];
            $eacActive = collect($charts['eac']['slices'])->firstWhere('key', 'active')['count'];

            $this->assertSame($eacActive, $workloadActive);
            $this->assertSame($eacActive, $metrics['active_eac']);
            $this->assertSame($filter['facility_id'] === 'all' ? 3 : 2, $eacActive);
            $this->assertSame($metrics['eac_sessions_complete'], collect($charts['eac']['slices'])->firstWhere('key', 'sessions_completed')['count']);
        }
    }

    // --- 5. cohorts --------------------------------------------------------------------------------

    public function test_a_pregnant_14_year_old_is_counted_as_pregnant_not_as_a_child(): void
    {
        $this->patient(['age' => 14, 'sex' => 'female', 'is_pregnant' => true]);

        $cohorts = $this->service()->patientsByCohort([]);
        $bars = collect($cohorts['bars'])->keyBy('key');

        $this->assertSame(0, $bars['children']['count']);
        $this->assertSame(0, $bars['adults']['count']);
        $this->assertSame(1, $bars['pmtct']['count']);
    }

    public function test_cohort_groups_do_not_overlap_and_sum_to_the_total_patients_card(): void
    {
        $this->seedScenario();

        $cohorts = $this->service()->patientsByCohort($this->kapoetaLocation());
        $bars = collect($cohorts['bars'])->keyBy('key');

        // children: p5. pregnant/breastfeeding: p3 (14 y), p4, p6. adults: the other 7.
        $this->assertSame(1, $bars['children']['count']);
        $this->assertSame(7, $bars['adults']['count']);
        $this->assertSame(3, $bars['pmtct']['count']);
        $this->assertSame(11, $cohorts['total']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($cohorts['bars'], 'percent')), 0.0001);
        $this->assertEquals([9.1, 63.6, 27.3], array_column($cohorts['bars'], 'percent'));

        $response = $this->actingAs($this->admin)->get('/dashboard?facility_id='.$this->kapoeta->id)->assertOk();
        $this->assertSame(11, $response->viewData('metrics')['total_patients']);
        $this->assertSame(array_sum(array_column($response->viewData('charts')['cohorts']['bars'], 'count')), $response->viewData('metrics')['total_patients']);
    }

    public function test_cohorts_follow_the_same_date_window_as_the_total_patients_card(): void
    {
        $this->patient(['art_start_date' => '2024-01-15']);
        $this->patient(['art_start_date' => '2022-01-15', 'age' => 5]);

        $response = $this->actingAs($this->admin)
            ->get('/dashboard?date_range=custom&start_date=2024-01-01&end_date=2024-12-31&facility_id=all')
            ->assertOk();

        $this->assertSame(1, $response->viewData('metrics')['total_patients']);
        $this->assertSame(1, $response->viewData('charts')['cohorts']['total']);
    }

    // --- general -----------------------------------------------------------------------------------

    public function test_share_percents_always_add_up_to_exactly_100(): void
    {
        $service = $this->service();

        foreach ([[1, 1, 1], [1, 7, 3], [2, 1, 1, 1, 1], [5], [0, 0, 9], [333, 333, 334], [1, 2, 4, 8, 16, 32, 64]] as $counts) {
            $this->assertEqualsWithDelta(100.0, array_sum($service->sharePercents($counts)), 0.0001, json_encode($counts));
        }
        $this->assertSame([0.0, 0.0], $service->sharePercents([0, 0]));
    }

    public function test_empty_database_gives_empty_states_not_errors(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard?facility_id=all')->assertOk();
        $charts = $response->viewData('charts');

        foreach (['line', 'suppression', 'workload', 'eac', 'cohorts'] as $chart) {
            $this->assertFalse($charts[$chart]['has_data'], $chart);
        }
        $response->assertSee('No VL due dates, samples or results in the last 6 months for this filter.');
        $response->assertSee('No viral load results yet for this filter.');
        $response->assertSee('Nothing scheduled, pending or active today.');
        $response->assertSee('No EAC episodes for this filter.');
        $response->assertSee('No patients for this filter.');
        $response->assertDontSee('id="vlActivityChart"', false);
    }

    public function test_dashboard_renders_the_new_chart_layout(): void
    {
        $this->seedScenario();

        $response = $this->actingAs($this->admin)->get('/dashboard?facility_id='.$this->kapoeta->id)->assertOk();

        $response->assertSee('Monthly VL Due vs Samples Collected and Tested');
        $response->assertSee('Last 6 months');
        $response->assertSee('Viral Load Suppression');
        $response->assertSee("Today's Workload");
        $response->assertSee('EAC Episode Progress');
        $response->assertSee('Patients by Age & Cohort');
        $response->assertSee('Total EAC episodes', false);
        foreach (['vlActivityChart', 'suppressionChart', 'workloadChart', 'eacChart', 'cohortChart'] as $canvas) {
            $response->assertSee('id="'.$canvas.'"', false);
        }
        $response->assertSee('js/dashboard-charts.js', false);
        $response->assertSee('VL coverage 72.7%');
    }

    public function test_line_chart_ignores_the_date_range_filter(): void
    {
        $this->seedScenario();
        $base = '/dashboard?facility_id='.$this->kapoeta->id;

        $all = $this->actingAs($this->admin)->get($base)->viewData('charts')['line'];
        $today = $this->actingAs($this->admin)->get($base.'&date_range=today')->viewData('charts')['line'];

        $this->assertSame($all['series'], $today['series']);
    }

    public function test_chart_queries_compile_to_portable_sql_for_postgres(): void
    {
        $facilityId = $this->kapoeta->id;
        $original = config('database.default');
        config(['database.default' => 'pgsql']);
        $pg = DB::connection('pgsql');
        $pg->setPdo(new \PDO('sqlite::memory:'));
        $pg->setReadPdo(new \PDO('sqlite::memory:'));

        try {
            $queries = DB::connection('pgsql')->pretend(function () use ($facilityId) {
                $this->service()->build(['facility_id' => $facilityId], '2026-01-01', '2026-12-31');
            });
        } finally {
            config(['database.default' => $original]);
        }

        $sql = strtolower(implode("\n", array_column($queries, 'query')));

        $this->assertNotEmpty($queries);
        $this->assertStringContainsString('group by "grp"', $sql);
        foreach (['strftime', 'ifnull', 'julianday', 'group_concat', 'datetime(', 'date(\'now', '`'] as $sqliteOnly) {
            $this->assertStringNotContainsString($sqliteOnly, $sql, "SQLite-only construct {$sqliteOnly} found");
        }
    }
}
