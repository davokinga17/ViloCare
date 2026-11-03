<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\DashboardChartsService;
use App\Services\VlDueItem;
use App\Services\VlDueService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Only ACTIVE patients can be due. Transferred out, deceased and lost-to-follow-up patients never
 * appear in any due group, count, report, CSV or reminder, and come back when set to active again.
 */
class InactivePatientsNotDueTest extends TestCase
{
    use RefreshDatabase;

    private const INACTIVE = ['transferred_out', 'deceased', 'lost_to_followup'];

    private User $admin;

    private Facility $facility;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        $this->facility = Facility::where('code', 'KCH-001')->firstOrFail();

        $this->admin = User::create([
            'name' => 'Admin Boss',
            'email' => 'admin@clinic.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('Password@123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function patient(string $status, array $attributes = []): Patient
    {
        $this->seq++;

        return Patient::create($attributes + [
            'art_number' => 'ART-INA-'.$this->seq,
            'first_name' => 'Test',
            'last_name' => 'Patient'.$this->seq,
            'phone' => '+2547000000'.str_pad((string) $this->seq, 2, '0', STR_PAD_LEFT),
            'sex' => 'female',
            'age' => 30,
            'art_start_date' => today()->subYears(2)->toDateString(),
            'facility_id' => $this->facility->id,
            'county_id' => $this->facility->county_id,
            'state_id' => $this->facility->county->state_id,
            'status' => $status,
        ]);
    }

    private function vlResult(Patient $p, ?string $nextDue, int $value = 50): ViralLoadResult
    {
        return ViralLoadResult::create([
            'patient_id' => $p->id,
            'sample_date' => today()->subDays(200)->toDateString(),
            'result_date' => today()->subDays(190)->toDateString(),
            'result_value' => $value,
            'is_suppressed' => $value < 1000,
            'lab_name' => 'Lab',
            'sample_type' => 'Plasma',
            'next_due_date' => $nextDue,
            'algorithm_reason' => 'Test reason',
        ]);
    }

    /** One patient per due group for the given status. @return array<string, Patient> */
    private function onePerGroup(string $status): array
    {
        $out = [];

        $out[VlDueItem::GROUP_PMTCT] = $this->patient($status, [
            'is_pregnant' => true,
            'pregnancy_onset_at' => today()->subDays(20)->toDateString(),
        ]);

        $out[VlDueItem::GROUP_POST_EAC] = $this->patient($status);
        $high = $this->vlResult($out[VlDueItem::GROUP_POST_EAC], null, 5000);
        EacEpisode::create([
            'patient_id' => $out[VlDueItem::GROUP_POST_EAC]->id,
            'viral_load_result_id' => $high->id,
            'repeat_vl_due_date' => today()->subDays(5)->toDateString(),
            'status' => 'sessions_completed',
        ]);

        $out[VlDueItem::GROUP_ROUTINE] = $this->patient($status);
        $this->vlResult($out[VlDueItem::GROUP_ROUTINE], today()->subDays(10)->toDateString());

        $out[VlDueItem::GROUP_BASELINE] = $this->patient($status, ['art_start_date' => today()->subYears(3)->toDateString()]);

        return $out;
    }

    private function dueIds(): array
    {
        return app(VlDueService::class)->due()->map(fn (VlDueItem $i) => $i->patient->id)->all();
    }

    public function test_inactive_patients_are_in_no_group_and_return_when_set_active(): void
    {
        $byStatus = [];
        foreach (self::INACTIVE as $status) {
            $byStatus[$status] = $this->onePerGroup($status);
        }
        $active = $this->onePerGroup('active');

        // Only the four active patients are due.
        $ids = $this->dueIds();
        $this->assertCount(4, $ids);
        foreach ($active as $p) {
            $this->assertContains($p->id, $ids);
        }
        foreach ($byStatus as $patients) {
            foreach ($patients as $p) {
                $this->assertNotContains($p->id, $ids);
            }
        }
        $this->assertSame(4, app(VlDueService::class)->count());
        $this->assertSame(1, app(VlDueService::class)->immediatePmtctPatients()->count());

        // Group by group, for every inactive status.
        $groups = app(VlDueService::class)->due()->groupBy('group')->map->count();
        foreach ([VlDueItem::GROUP_PMTCT, VlDueItem::GROUP_POST_EAC, VlDueItem::GROUP_ROUTINE, VlDueItem::GROUP_BASELINE] as $g) {
            $this->assertSame(1, $groups[$g] ?? 0, "group {$g} should only hold the active patient");
        }

        // Set them all back to active: they all appear again.
        foreach ($byStatus as $patients) {
            foreach ($patients as $p) {
                $p->update(['status' => 'active']);
            }
        }
        $this->assertCount(16, $this->dueIds());
    }

    public function test_open_eac_episode_of_inactive_patient_is_untouched_but_not_due(): void
    {
        $p = $this->patient('transferred_out');
        $high = $this->vlResult($p, null, 5000);
        $episode = EacEpisode::create([
            'patient_id' => $p->id,
            'viral_load_result_id' => $high->id,
            'repeat_vl_due_date' => today()->subDays(5)->toDateString(),
            'status' => 'sessions_completed',
        ]);

        $this->assertCount(0, $this->dueIds());
        $episode->refresh();
        $this->assertSame('sessions_completed', $episode->status);
        $this->assertNotNull($episode->repeat_vl_due_date);
    }

    public function test_dashboard_report_csv_and_chart_exclude_inactive_and_agree(): void
    {
        foreach (self::INACTIVE as $status) {
            $this->onePerGroup($status);
        }
        $active = $this->onePerGroup('active');

        $dashboard = $this->actingAs($this->admin)->get('/dashboard');
        $dashboard->assertOk();
        $this->assertSame(4, $dashboard->viewData('metrics')['due_for_vl']);

        $list = $this->actingAs($this->admin)->get('/reports/due-list');
        $list->assertOk();
        $this->assertSame(4, $list->viewData('duePmtct')->count() + $list->viewData('dueEac')->count()
            + $list->viewData('dueResults')->count() + $list->viewData('dueBaseline')->count());

        $csv = $this->actingAs($this->admin)->get('/reports/due-list?export=csv')->streamedContent();
        $this->assertSame(4, collect($active)->filter(fn ($p) => preg_match("/".preg_quote($p->art_number, "/")."(?!\d)/", $csv))->count());
        $this->assertSame(0, Patient::where('status', '!=', 'active')->get()
            ->filter(fn ($p) => preg_match("/".preg_quote($p->art_number, "/")."(?!\d)/", $csv))->count());
        // header + 4 rows
        $this->assertCount(5, array_filter(explode("\n", trim($csv))));

        $chart = app(DashboardChartsService::class)->monthlyVlActivity([]);
        $dueSeries = collect($chart['series'])->firstWhere('key', 'vl_due')['values'];
        $this->assertLessThanOrEqual(4, array_sum($dueSeries));
    }

    public function test_inactive_patients_never_get_a_reminder(): void
    {
        $inactive = [];
        foreach (self::INACTIVE as $status) {
            $p = $this->patient($status);
            $this->vlResult($p, today()->addDays(2)->toDateString());
            $inactive[] = $p;
        }
        $active = $this->patient('active');
        $this->vlResult($active, today()->addDays(2)->toDateString());

        $this->artisan('sms:send-due-reminders --days=7')->assertExitCode(0);

        $this->assertSame(1, NotificationLog::where('category', 'due_date_reminder')->count());
        $this->assertSame($active->id, NotificationLog::where('category', 'due_date_reminder')->value('patient_id'));
        foreach ($inactive as $p) {
            $this->assertSame(0, NotificationLog::where('patient_id', $p->id)->count());
        }
    }
}
