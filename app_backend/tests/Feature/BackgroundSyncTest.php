<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SyncService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackgroundSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'name' => 'Clin', 'email' => 'clin@example.org', 'role' => UserRole::CLINICIAN,
            'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    // ---- "Sync now" ----

    public function test_sync_now_starts_one_background_process_and_returns_at_once(): void
    {
        config(['sync.configured' => true]);
        Http::fake();

        $this->actingAs($this->user)->postJson(route('sync.trigger'))
            ->assertOk()
            ->assertJson(['success' => true, 'started' => true, 'already_running' => false])
            ->assertJsonPath('run.running', true)
            ->assertJsonPath('run.starting', true);

        $this->assertSame(1, $this->backgroundSyncStarts);
        Http::assertNothingSent(); // the request itself never talks to the cloud

        // A second click while it is starting/running does not start another process.
        $this->actingAs($this->user)->postJson(route('sync.trigger'))
            ->assertJson(['started' => false, 'already_running' => true]);
        $this->assertSame(1, $this->backgroundSyncStarts);
    }

    public function test_sync_now_form_post_redirects_with_a_message(): void
    {
        config(['sync.configured' => true]);

        $this->actingAs($this->user)->post(route('sync.trigger'))
            ->assertRedirect(route('sync.index'))
            ->assertSessionHas('success');
        $this->assertSame(1, $this->backgroundSyncStarts);
    }

    public function test_sync_now_does_nothing_when_sync_is_not_set_up(): void
    {
        config(['sync.configured' => false, 'sync.standalone' => false]);

        $this->actingAs($this->user)->postJson(route('sync.trigger'))
            ->assertJson(['success' => false, 'started' => false, 'mode' => 'not_configured']);
        $this->assertSame(0, $this->backgroundSyncStarts);
    }

    public function test_a_request_that_never_started_stops_showing_as_running_after_30_seconds(): void
    {
        config(['sync.configured' => true]);
        $service = app(SyncService::class);
        $service->markRunRequested();
        $this->assertTrue($service->runStatus()['running']);

        Carbon::setTestNow(now()->addSeconds(31));
        $this->assertFalse($service->runStatus()['running']);
        Carbon::setTestNow();
    }

    // ---- progress / result ----

    public function test_run_status_reports_progress_and_the_last_result(): void
    {
        config(['sync.standalone' => true]);
        $service = app(SyncService::class);
        $this->assertFalse($service->runStatus()['running']);
        $this->assertNull($service->runStatus()['last_result']);

        $service->markRunRequested();
        $service->runFullSync();

        $status = $service->runStatus();
        $this->assertFalse($status['running']);
        $this->assertTrue($status['last_result']['success']);
        $this->assertSame('local_standalone', $status['last_result']['mode']);
    }

    public function test_status_check_light_returns_progress_without_the_heavy_counts(): void
    {
        $this->actingAs($this->user)->getJson(route('sync.status-check', ['light' => 1]))
            ->assertOk()
            ->assertJsonStructure(['success', 'run' => ['running', 'phase', 'step', 'last_result'], 'summary' => ['state', 'label', 'title']])
            ->assertJsonMissingPath('stats');
    }

    // ---- header badge shows the scheduler's last status ----

    public function test_header_badge_reflects_the_schedulers_last_run(): void
    {
        config(['sync.remote_url' => 'https://central.test/api/v1/sync']);
        $service = new SyncService;

        config(['sync.configured' => false, 'sync.standalone' => false]);
        $this->assertSame('offline', $service->statusSummary()['state']);
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee('Offline mode (sync not set up)');

        config(['sync.configured' => true]);
        $this->assertSame('Waiting for first sync', $service->statusSummary()['label']);

        // A run that succeeds (standalone path writes the same state keys as a cloud run).
        config(['sync.standalone' => false]);
        Http::fake(['*' => Http::response(['next' => null, 'tables' => [], 'synced_ids' => [], 'server_time' => now()->toIso8601String()], 200)]);
        $service->markAllPendingAsSynced();
        $service->runFullSync();
        $summary = (new SyncService)->statusSummary();
        $this->assertSame('online', $summary['state'], json_encode($summary));
        $this->assertStringStartsWith('Synced', $summary['label']);
        $this->actingAs($this->user)->get(route('dashboard'))->assertSee($summary['label']);

        // A failing run shows the problem.
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: x')]);
        (new SyncService)->runFullSync();
        $summary = (new SyncService)->statusSummary();
        $this->assertSame('warning', $summary['state']);
        $this->assertNotSame('', $summary['label']);

        // Scheduler stopped: the badge says so instead of pretending.
        Carbon::setTestNow(now()->addMinutes(5));
        $summary = (new SyncService)->statusSummary();
        $this->assertSame('warning', $summary['state']);
        $this->assertStringStartsWith('Sync not running, last run', $summary['label']);
        Carbon::setTestNow();
    }

    // ---- the browser no longer starts syncs ----

    public function test_the_sync_script_has_no_automatic_triggers(): void
    {
        $js = file_get_contents(public_path('js/vilocare-sync.js'));

        $this->assertStringNotContainsString('/sync/auto', $js);
        $this->assertStringNotContainsString("addEventListener('online'", $js);
        $this->assertStringNotContainsString('periodic_timer', $js);
        $this->assertStringNotContainsString('initial_page_load', $js);
        $this->assertStringNotContainsString('post_mutation', $js);
        $this->assertStringNotContainsString('sessionStorage', $js);
        // The only repeating timer relabels ages already on the page and makes no request.
        $this->assertSame(1, substr_count($js, 'setInterval('));
        $this->assertStringContainsString("'/sync/status-check?light=1'", $js);
    }
}
