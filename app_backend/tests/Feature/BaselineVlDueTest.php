<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\DueReminderService;
use App\Services\VlDueItem;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A patient with NO viral load result is scheduled from the ART start date (ViralLoadEngine::calculateInitialDueDate).
 */
class BaselineVlDueTest extends TestCase
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

    private function patient(?string $artStart, array $attributes = [], ?Facility $facility = null): Patient
    {
        $facility ??= $this->kapoeta;
        $this->seq++;

        return Patient::create($attributes + [
            'art_number' => 'ART-BL-'.$this->seq,
            'first_name' => 'Test',
            'last_name' => 'Patient'.$this->seq,
            'sex' => 'male',
            'age' => 34,
            'phone' => '+2547220000'.str_pad((string) $this->seq, 2, '0', STR_PAD_LEFT),
            'art_start_date' => $artStart,
            'facility_id' => $facility->id,
            'county_id' => $facility->county_id,
            'state_id' => $facility->county->state_id,
            'status' => 'active',
        ]);
    }

    private function monthsAgo(int $months): string
    {
        return today()->subMonthsNoOverflow($months)->toDateString();
    }

    private function due(array $location = [], int $days = 30)
    {
        return app(VlDueService::class)->due($location, $days);
    }

    private function resultFor(Patient $patient, string $sampleDate, ?string $nextDue): ViralLoadResult
    {
        return ViralLoadResult::create([
            'patient_id' => $patient->id,
            'sample_date' => $sampleDate,
            'result_date' => $sampleDate,
            'result_value' => 50,
            'is_suppressed' => true,
            'lab_name' => 'Kapoeta Civil Hospital',
            'sample_type' => 'Plasma',
            'next_due_date' => $nextDue,
            'algorithm_reason' => 'Test reason',
        ]);
    }

    public function test_art_started_seven_months_ago_is_overdue_baseline_with_original_date(): void
    {
        $patient = $this->patient($this->monthsAgo(7));

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_BASELINE, $due[0]->group);
        $this->assertSame($patient->id, $due[0]->patient->id);
        $this->assertSame(today()->subMonthsNoOverflow(1)->toDateString(), $due[0]->next_due_date->toDateString());
        $this->assertTrue($due[0]->isOverdue());
        $this->assertGreaterThanOrEqual(28, $due[0]->daysOverdue());
        $this->assertLessThanOrEqual(31, $due[0]->daysOverdue());
    }

    public function test_art_started_five_months_ago_is_due_within_default_window_only(): void
    {
        // Due date about 20 days from now (5 months ago would be 28-31 days, too close to the 30 day edge).
        $this->patient(today()->addDays(20)->subMonthsNoOverflow(6)->toDateString());

        $this->assertCount(1, $this->due());
        $this->assertCount(0, $this->due([], 0));
    }

    public function test_art_started_today_is_not_due_yet(): void
    {
        $this->patient(today()->toDateString());

        $this->assertCount(0, $this->due([], 30));
    }

    public function test_newly_initiated_pregnant_patient_is_due_at_three_months(): void
    {
        $this->patient($this->monthsAgo(3), ['sex' => 'female', 'is_pregnant' => true]);

        $due = $this->due([], 0);

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_BASELINE, $due[0]->group);
        $this->assertSame(today()->toDateString(), $due[0]->next_due_date->toDateString());
        $this->assertStringContainsString('3 months', $due[0]->algorithm_reason);
    }

    public function test_pregnant_with_onset_and_no_result_is_pmtct_and_listed_once(): void
    {
        $patient = $this->patient($this->monthsAgo(7), [
            'sex' => 'female', 'is_pregnant' => true, 'pregnancy_onset_at' => today()->subDays(10)->toDateString(),
        ]);

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_PMTCT, $due[0]->group);
        $this->assertSame($patient->id, $due[0]->patient->id);
    }

    public function test_patient_with_a_result_is_handled_only_by_the_routine_group(): void
    {
        $patient = $this->patient($this->monthsAgo(12));
        $this->resultFor($patient, today()->subMonths(5)->toDateString(), today()->addDays(5)->toDateString());

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_ROUTINE, $due[0]->group);
    }

    public function test_patient_with_a_result_not_yet_due_is_not_pulled_in_as_baseline(): void
    {
        $patient = $this->patient($this->monthsAgo(12));
        $this->resultFor($patient, today()->subDays(10)->toDateString(), today()->addMonths(6)->toDateString());

        $this->assertCount(0, $this->due());
    }

    public function test_sample_collected_without_result_is_still_baseline(): void
    {
        $patient = $this->patient($this->monthsAgo(7));
        SampleCollection::create([
            'sample_code' => 'SMP-BL-1',
            'patient_id' => $patient->id,
            'collection_date' => today()->toDateString(),
            'sample_type' => 'Plasma',
            'facility_id' => $this->kapoeta->id,
            'status' => 'collected',
        ]);

        $due = $this->due();

        $this->assertCount(1, $due);
        $this->assertSame(VlDueItem::GROUP_BASELINE, $due[0]->group);
    }

    public function test_patient_without_art_start_date_is_skipped_without_error(): void
    {
        // The column is NOT NULL, so a null date only exists on an in-memory model (old/imported data
        // from before the constraint). The service's whereNotNull guard and the engine must both cope.
        $patient = $this->patient(today()->toDateString());
        $patient->art_start_date = null;

        $this->assertNull(app(\App\Services\ViralLoadEngine::class)->calculateInitialDueDate($patient));
        $this->assertCount(0, $this->due());
        $this->assertNull($patient->effectiveNextDueDate());
        $this->assertSame('Baseline VL test pending.', $patient->currentVlDueReason());
    }

    public function test_children_and_breastfeeding_patients_are_baseline_at_six_months(): void
    {
        $this->patient($this->monthsAgo(6), ['age' => 8]);
        $this->patient($this->monthsAgo(6), ['sex' => 'female', 'is_breastfeeding' => true]);

        $due = $this->due([], 0);

        $this->assertCount(2, $due);
        foreach ($due as $item) {
            $this->assertSame(VlDueItem::GROUP_BASELINE, $item->group);
            $this->assertSame(today()->toDateString(), $item->next_due_date->toDateString());
        }
    }

    public function test_location_filters_apply_to_baseline_patients(): void
    {
        $here = $this->patient($this->monthsAgo(7));
        $this->patient($this->monthsAgo(7), [], $this->torit);

        $this->assertCount(2, $this->due());

        $byFacility = $this->due(['facility_id' => $this->kapoeta->id]);
        $this->assertCount(1, $byFacility);
        $this->assertSame($here->id, $byFacility[0]->patient->id);

        $this->assertCount(1, $this->due(['county_id' => $this->torit->county_id, 'facility_id' => 'all']));
        $this->assertCount(2, $this->due(['state_id' => $this->kapoeta->county->state_id, 'county_id' => 'all', 'facility_id' => 'all']));
    }

    public function test_patient_model_returns_baseline_date_and_reason_when_no_result(): void
    {
        $patient = $this->patient($this->monthsAgo(4));

        $this->assertSame(
            today()->subMonthsNoOverflow(4)->addMonths(6)->toDateString(),
            $patient->effectiveNextDueDate()->toDateString()
        );
        $this->assertStringContainsString('6 months', $patient->currentVlDueReason());
    }

    public function test_reminder_service_includes_baseline_patient_once_and_does_not_duplicate(): void
    {
        $this->fakeSmsGateway();
        // Baseline due date lands about 3 days from now.
        $patient = $this->patient(today()->addDays(3)->subMonthsNoOverflow(6)->toDateString());

        $candidates = app(DueReminderService::class)->candidates(7);
        $this->assertCount(1, $candidates);
        $this->assertSame(VlDueItem::GROUP_BASELINE, $candidates[0]->group);
        $this->assertSame($patient->id, $candidates[0]->patient->id);

        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('Found 1 due patients.')
            ->expectsOutputToContain('1 reminders dispatched')
            ->assertExitCode(0);
        $this->artisan('sms:send-due-reminders --days=7')
            ->expectsOutputToContain('0 reminders dispatched, 1 skipped')
            ->assertExitCode(0);

        $this->assertSame(1, NotificationLog::where('patient_id', $patient->id)->where('category', 'due_date_reminder')->count());
    }

    public function test_monthly_vl_due_chart_includes_baseline_patients_and_matches_due_service(): void
    {
        $this->patient($this->monthsAgo(6));                                   // due today: current month
        $this->patient(today()->subMonthsNoOverflow(2)->subMonthsNoOverflow(6)->toDateString()); // due ~2 months ago
        $this->patient($this->monthsAgo(6), [], $this->torit);                 // other facility
        $this->patient(today()->toDateString());                               // not due

        $location = ['facility_id' => $this->kapoeta->id];
        $chart = app(\App\Services\DashboardChartsService::class)->monthlyVlActivity($location);
        $values = collect($chart['series'])->firstWhere('key', 'vl_due')['values'];
        $months = collect($chart['months']);

        $currentIndex = $months->search(fn ($m) => $m['is_current']);
        $pastKey = today()->subMonthsNoOverflow(2)->format('Y-m');
        $pastIndex = $months->search(fn ($m) => $m['key'] === $pastKey);

        $this->assertSame(1, $values[$currentIndex]);
        $this->assertSame(1, $values[$pastIndex]);
        $this->assertTrue($chart['has_data']);

        // The current-month figure is exactly the due service's patients dated this month.
        $thisMonth = app(VlDueService::class)->due($location)
            ->filter(fn (VlDueItem $i) => $i->next_due_date->format('Y-m') === today()->format('Y-m'))->count();
        $this->assertSame($thisMonth, $values[$currentIndex]);
    }

    public function test_dashboard_due_list_report_and_csv_counts_agree(): void
    {
        $this->patient($this->monthsAgo(7));                    // baseline overdue
        $this->patient(today()->addDays(20)->subMonthsNoOverflow(6)->toDateString()); // baseline due soon
        $this->patient(today()->toDateString());                // not due
        $routine = $this->patient($this->monthsAgo(12));
        $this->resultFor($routine, today()->subMonths(6)->toDateString(), today()->addDays(3)->toDateString());
        $this->patient($this->monthsAgo(7), [], $this->torit);  // other facility

        $filter = ['facility_id' => $this->kapoeta->id];
        $expected = 3;

        $dashboard = $this->actingAs($this->admin)->get('/dashboard?'.http_build_query($filter));
        $dashboard->assertOk();
        $this->assertSame($expected, $dashboard->viewData('metrics')['due_for_vl']);
        $this->assertCount($expected, $dashboard->viewData('duePatientsList'));

        $list = $this->actingAs($this->admin)->get('/reports/due-list?'.http_build_query($filter));
        $list->assertOk();
        $this->assertCount(2, $list->viewData('dueBaseline'));
        $this->assertCount(1, $list->viewData('dueResults'));
        $this->assertSame($expected, $list->viewData('duePmtct')->count() + $list->viewData('dueEac')->count()
            + $list->viewData('dueResults')->count() + $list->viewData('dueBaseline')->count());
        $list->assertSee('Total Due: 3 Patients');

        $csv = $this->actingAs($this->admin)->get('/reports/due-list?'.http_build_query($filter + ['export' => 'csv']))->streamedContent();
        $rows = array_filter(explode("\n", trim($csv)));
        $this->assertCount($expected + 1, $rows, 'header + one line per patient');
        $this->assertStringContainsString('Baseline Viral Load Test', $csv);
    }
}
