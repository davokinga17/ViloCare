<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\AiInsightService;
use App\Services\VlDueItem;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Due for VL" is counted per PATIENT, from the latest result only, once, in one place.
 */
class VlDueConsistencyTest extends TestCase
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
            'art_number' => 'ART-DUE-'.$this->seq,
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

    private function vlResult(Patient $patient, string $sampleDate, ?string $nextDue, int $value = 50, array $extra = []): ViralLoadResult
    {
        return ViralLoadResult::create($extra + [
            'patient_id' => $patient->id,
            'sample_date' => $sampleDate,
            'result_date' => $sampleDate,
            'result_value' => $value,
            'is_suppressed' => $value < 1000,
            'lab_name' => 'Kapoeta Civil Hospital',
            'sample_type' => 'Plasma',
            'next_due_date' => $nextDue,
            'algorithm_reason' => 'Test reason',
        ]);
    }

    private function days(int $offset): string
    {
        return today()->addDays($offset)->toDateString();
    }

    private function due(array $location = [], int $days = 30)
    {
        return app(VlDueService::class)->due($location, $days);
    }

    public function test_patient_with_three_old_results_all_past_due_counts_once(): void
    {
        $patient = $this->patient();
        $this->vlResult($patient, $this->days(-700), $this->days(-520));
        $this->vlResult($patient, $this->days(-500), $this->days(-320));
        $this->vlResult($patient, $this->days(-300), $this->days(-120));

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame($patient->id, $due[0]->patient->id);
        // Only the latest result's due date is used.
        $this->assertSame($this->days(-120), $due[0]->next_due_date->toDateString());
        $this->assertSame(VlDueItem::GROUP_ROUTINE, $due[0]->group);
    }

    public function test_old_overdue_results_are_ignored_when_the_latest_result_is_not_due(): void
    {
        $patient = $this->patient();
        $this->vlResult($patient, $this->days(-700), $this->days(-520));
        $this->vlResult($patient, $this->days(-20), $this->days(160));

        $this->assertCount(0, $this->due());
    }

    public function test_latest_result_ties_are_broken_by_created_time(): void
    {
        $patient = $this->patient();
        $first = $this->vlResult($patient, $this->days(-30), $this->days(-5));
        $second = $this->vlResult($patient, $this->days(-30), $this->days(150));
        // Force a deterministic order: the second row was created later.
        ViralLoadResult::whereKey($first->id)->update(['created_at' => now()->subHour()]);
        ViralLoadResult::whereKey($second->id)->update(['created_at' => now()]);

        $this->assertCount(0, $this->due(), 'the later-created result (not due) is the latest');
    }

    public function test_a_result_without_created_at_loses_the_tie_to_one_with_it(): void
    {
        $patient = $this->patient();
        $stamped = $this->vlResult($patient, $this->days(-30), $this->days(150));
        $unstamped = $this->vlResult($patient, $this->days(-30), $this->days(-5));
        ViralLoadResult::whereKey($stamped->id)->update(['created_at' => now()]);
        ViralLoadResult::whereKey($unstamped->id)->update(['created_at' => null]);

        $this->assertCount(0, $this->due(), 'NULL created_at is the oldest, so the stamped (not due) result is latest');
        $this->assertSame(1, ViralLoadResult::latestPerPatient()->count());
    }

    /**
     * Regression: COALESCE(created_at, '') ran on SQLite but PostgreSQL rejects '' as a timestamp,
     * which made the Dashboard, Reports and AI Insights return 500 on Render. The due-for-VL queries
     * must contain only portable SQL. Compiled here with the PostgreSQL grammar (no server needed).
     */
    public function test_due_queries_contain_only_portable_sql(): void
    {
        config(['database.connections.pgsql_compile' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'x',
            'username' => 'x', 'password' => 'x', 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
        ]]);

        $sql = (new ViralLoadResult)->setConnection('pgsql_compile')->newQuery()
            ->latestPerPatient()->whereNotNull('next_due_date')->where('next_due_date', '<=', '2026-11-07')->toSql();

        $this->assertStringContainsString('"newer"."created_at"', $sql, 'the compiled PostgreSQL query was inspected');
        $this->assertDoesNotMatchRegularExpression("/coalesce|ifnull|julianday|strftime|datetime\\(|date\\(|datediff|''/i", $sql);

        // And the service/scope source stays free of hand-written SQL fragments.
        foreach (['app/Services/VlDueService.php', 'app/Services/VlDueItem.php'] as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/whereRaw|selectRaw|orderByRaw|groupByRaw|havingRaw|DB::raw|DB::select|DB::statement|julianday|strftime/i',
                file_get_contents(base_path($file)),
                "{$file} must use only query-builder comparisons"
            );
        }
    }

    public function test_missed_due_date_stays_the_original_date_and_shows_once_as_overdue(): void
    {
        $patient = $this->patient();
        $this->vlResult($patient, $this->days(-220), $this->days(-40));

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame($this->days(-40), $due[0]->next_due_date->toDateString());
        $this->assertTrue($due[0]->isOverdue());
        $this->assertSame(40, $due[0]->daysOverdue());
        $this->assertSame('Overdue by 40 days', $due[0]->dueLabel());

        // Still the same single, original date with a long look-ahead (no "due again" at a later point).
        $long = $this->due([], 400);
        $this->assertCount(1, $long);
        $this->assertSame($this->days(-40), $long[0]->next_due_date->toDateString());
    }

    public function test_due_labels_for_today_and_upcoming(): void
    {
        $today = $this->patient();
        $soon = $this->patient();
        $this->vlResult($today, $this->days(-180), $this->days(0));
        $this->vlResult($soon, $this->days(-170), $this->days(1));

        $labels = $this->due()->mapWithKeys(fn ($i) => [$i->patient->id => $i->dueLabel()]);

        $this->assertSame('Due today', $labels[$today->id]);
        $this->assertSame('Due in 1 day', $labels[$soon->id]);
    }

    public function test_recording_a_new_result_replaces_the_due_date_using_the_engine(): void
    {
        $patient = $this->patient();
        $this->vlResult($patient, $this->days(-300), $this->days(-120));
        $this->assertSame($this->days(-120), $this->due()[0]->next_due_date->toDateString());

        $this->actingAs($this->admin)->post('/viral-load', [
            'patient_id' => $patient->id,
            'sample_date' => $this->days(0),
            'result_date' => $this->days(0),
            'result_value' => 40,
            'sample_type' => 'Plasma',
            'lab_name' => 'Kapoeta Civil Hospital',
        ])->assertRedirect();

        $new = ViralLoadResult::where('patient_id', $patient->id)->orderByDesc('sample_date')->first();
        $this->assertNotNull($new->next_due_date, 'the engine sets the next due date');
        $this->assertGreaterThan(today(), $new->next_due_date);
        // Not due any more: the new result's engine-calculated date is months away.
        $this->assertCount(0, $this->due());
        $this->assertCount(1, $this->due([], 400), 'and it is that new date, not an old one, that applies');
        $this->assertSame($new->next_due_date->toDateString(), $this->due([], 400)[0]->next_due_date->toDateString());
    }

    public function test_patient_in_post_eac_and_routine_groups_counts_once_as_post_eac(): void
    {
        $patient = $this->patient();
        $high = $this->vlResult($patient, $this->days(-120), $this->days(-60), 5000);
        EacEpisode::create([
            'patient_id' => $patient->id,
            'viral_load_result_id' => $high->id,
            'repeat_vl_due_date' => $this->days(-10),
            'status' => 'sessions_completed',
        ]);

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_POST_EAC, $due[0]->group);
        $this->assertSame($this->days(-10), $due[0]->next_due_date->toDateString());
    }

    public function test_active_eac_with_unsuppressed_result_and_no_due_date_is_not_routine_due(): void
    {
        $patient = $this->patient();
        $high = $this->vlResult($patient, $this->days(-20), null, 8000);
        EacEpisode::create([
            'patient_id' => $patient->id,
            'viral_load_result_id' => $high->id,
            'status' => 'active',
        ]);

        $this->assertCount(0, $this->due());
    }

    public function test_immediate_pmtct_patient_counts_once_with_priority_over_routine(): void
    {
        $patient = $this->patient(null, [
            'sex' => 'female',
            'is_pregnant' => true,
            'pregnancy_onset_at' => $this->days(-30),
        ]);
        // Last VL was before the pregnancy and is also routine-due.
        $this->vlResult($patient, $this->days(-100), $this->days(-20));

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_PMTCT, $due[0]->group);
        $this->assertSame(today()->toDateString(), $due[0]->next_due_date->toDateString());
        $this->assertSame(1, app(VlDueService::class)->immediatePmtctPatients()->count());
    }

    public function test_filters_by_facility_county_and_state_still_work(): void
    {
        $inKapoeta = $this->patient($this->kapoeta);
        $inTorit = $this->patient($this->torit);
        $this->vlResult($inKapoeta, $this->days(-200), $this->days(-20));
        $this->vlResult($inTorit, $this->days(-200), $this->days(-20));

        $this->assertCount(2, $this->due());
        $this->assertSame([$inKapoeta->id], $this->due(['facility_id' => $this->kapoeta->id])->pluck('patient.id')->all());
        $this->assertSame([$inTorit->id], $this->due(['facility_id' => $this->torit->id])->pluck('patient.id')->all());
        $this->assertSame([$inTorit->id], $this->due(['county_id' => $this->torit->county_id])->pluck('patient.id')->all());
        // Both are in Eastern Equatoria; 'all' means no filter.
        $this->assertCount(2, $this->due(['state_id' => $this->kapoeta->county->state_id, 'county_id' => 'all', 'facility_id' => 'all']));
    }

    public function test_dashboard_report_due_list_csv_and_ai_counts_all_agree(): void
    {
        // Routine: 3 patients, one of them with several old overdue results.
        $many = $this->patient();
        $this->vlResult($many, $this->days(-700), $this->days(-520));
        $this->vlResult($many, $this->days(-500), $this->days(-320));
        $this->vlResult($many, $this->days(-300), $this->days(-120));
        $soon = $this->patient();
        $this->vlResult($soon, $this->days(-170), $this->days(10));
        $notDue = $this->patient();
        $this->vlResult($notDue, $this->days(-10), $this->days(170));
        // Post-EAC repeat, who is also routine overdue.
        $eacPatient = $this->patient();
        $high = $this->vlResult($eacPatient, $this->days(-90), $this->days(-30), 4000);
        EacEpisode::create([
            'patient_id' => $eacPatient->id, 'viral_load_result_id' => $high->id,
            'repeat_vl_due_date' => $this->days(-5), 'status' => 'sessions_completed',
        ]);
        // Immediate PMTCT, also routine overdue.
        $pregnant = $this->patient(null, ['sex' => 'female', 'is_pregnant' => true, 'pregnancy_onset_at' => $this->days(-20)]);
        $this->vlResult($pregnant, $this->days(-120), $this->days(-15));
        // A different facility, excluded by the Kapoeta filter.
        $elsewhere = $this->patient($this->torit);
        $this->vlResult($elsewhere, $this->days(-200), $this->days(-20));

        $expectedKapoeta = 4; // many, soon, eacPatient, pregnant - each once
        $filter = ['facility_id' => $this->kapoeta->id];

        $dashboard = $this->actingAs($this->admin)->get('/dashboard?'.http_build_query($filter));
        $dashboard->assertOk();
        $this->assertSame($expectedKapoeta, $dashboard->viewData('metrics')['due_for_vl']);
        $this->assertCount($expectedKapoeta, $dashboard->viewData('duePatientsList'));
        $dashboard->assertSee('Overdue by 120 days');
        $dashboard->assertSee('Due in 10 days');

        $report = $this->actingAs($this->admin)->get('/reports?'.http_build_query($filter));
        $this->assertSame($expectedKapoeta, $report->viewData('stats')['due_vl']);

        $list = $this->actingAs($this->admin)->get('/reports/due-list?'.http_build_query($filter));
        $list->assertOk();
        $this->assertCount(1, $list->viewData('duePmtct'));
        $this->assertCount(1, $list->viewData('dueEac'));
        $this->assertCount(2, $list->viewData('dueResults'));
        $this->assertSame($expectedKapoeta, $list->viewData('duePmtct')->count() + $list->viewData('dueEac')->count() + $list->viewData('dueResults')->count());
        $list->assertSee('Total Due: 4 Patients');

        $csv = $this->actingAs($this->admin)->get('/reports/due-list?'.http_build_query($filter + ['export' => 'csv']))->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));
        $this->assertCount($expectedKapoeta + 1, $rows, 'header + one line per patient');
        $this->assertSame($expectedKapoeta, count(array_unique(array_map(fn ($r) => str_getcsv($r)[2], array_slice($rows, 1)))));

        // The shorter horizon on the report still applies: only overdue + due within 7 days.
        $week = $this->actingAs($this->admin)->get('/reports/due-list?'.http_build_query($filter + ['days' => 7]));
        $this->assertSame(3, $week->viewData('duePmtct')->count() + $week->viewData('dueEac')->count() + $week->viewData('dueResults')->count());

        // AI insights are clinic-wide (no location filter): all five due patients, overdue split.
        $ai = (new AiInsightService)->getAggregatedData()['appointments_and_due_monitoring'];
        $this->assertSame(5, $ai['total_patients_due_for_vl']);
        // Overdue: many, post-EAC, elsewhere. Not overdue: soon (in 10 days) and PMTCT (due today).
        $this->assertSame(3, $ai['patients_overdue_for_vl_testing']);
        $this->assertSame(2, $ai['patients_due_for_routine_vl_next_30_days']);
        $this->assertSame(
            $ai['total_patients_due_for_vl'],
            $ai['patients_overdue_for_vl_testing'] + $ai['patients_due_for_routine_vl_next_30_days']
        );
        $this->assertSame(app(VlDueService::class)->count(), $ai['total_patients_due_for_vl']);
        $this->assertSame(1, $ai['immediate_pmtct_vl_required_count']);
    }
}
