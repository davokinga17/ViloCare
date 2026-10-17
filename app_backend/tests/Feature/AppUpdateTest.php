<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SyncService;
use App\Services\SyncStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok-123456789';

    private function user(UserRole $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role->value),
            'email' => $email,
            'role' => $role,
            'password' => 'Password-12345',
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function push(array $headers = [])
    {
        return $this->postJson('/api/v1/sync/push', [
            'device_id' => 'clinic-a',
            'timestamp' => now()->toIso8601String(),
            'tables' => ['patients' => []],
        ], ['X-Sync-Token' => self::TOKEN] + $headers);
    }

    // ---- Central server: minimum client version ----

    public function test_client_at_or_above_the_minimum_is_allowed(): void
    {
        config(['sync.api_token' => self::TOKEN, 'sync.min_client_version' => '1.1.0']);

        $this->push(['X-Client-Version' => '1.1.0'])->assertOk();
        $this->push(['X-Client-Version' => '1.2.3'])->assertOk();
    }

    public function test_client_below_the_minimum_gets_426_with_the_minimum_version(): void
    {
        config(['sync.api_token' => self::TOKEN, 'sync.min_client_version' => '1.1.0']);

        $this->push(['X-Client-Version' => '1.0.1'])
            ->assertStatus(426)
            ->assertJson(['success' => false, 'minimum_version' => '1.1.0', 'client_version' => '1.0.1']);
        // A pre-release is older than its release.
        $this->push(['X-Client-Version' => '1.1.0-beta.1'])->assertStatus(426);
        $this->getJson('/api/v1/sync/pull', ['X-Sync-Token' => self::TOKEN, 'X-Client-Version' => '1.0.1'])->assertStatus(426);
    }

    public function test_empty_setting_enforces_nothing(): void
    {
        config(['sync.api_token' => self::TOKEN, 'sync.min_client_version' => '']);

        $this->push(['X-Client-Version' => '0.0.1'])->assertOk();
        $this->push()->assertOk(); // no header at all
    }

    public function test_missing_header_counts_as_unknown_old_client_once_a_minimum_is_set(): void
    {
        config(['sync.api_token' => self::TOKEN, 'sync.min_client_version' => '1.1.0']);

        $this->push()->assertStatus(426)->assertJson(['minimum_version' => '1.1.0', 'client_version' => null]);
    }

    public function test_status_health_endpoint_is_never_blocked(): void
    {
        config(['sync.api_token' => self::TOKEN, 'sync.min_client_version' => '9.9.9']);

        $this->getJson('/api/v1/sync/status', ['X-Sync-Token' => self::TOKEN])->assertOk();
    }

    // ---- Clinic app: header, 426 handling, banner ----

    public function test_clinic_sends_its_version_on_every_sync_request(): void
    {
        config(['sync.configured' => true, 'sync.standalone' => false, 'sync.client_version' => '1.1.0', 'sync.remote_url' => 'https://central.test/api/v1/sync']);
        Http::fake(['*' => Http::response(['success' => true, 'synced_ids' => [], 'tables' => [], 'next' => null], 200)]);
        $this->user(UserRole::CLINICIAN, 'doc@example.org');

        app(SyncService::class)->runFullSync('https://central.test/api/v1/sync');

        Http::assertSent(fn ($request) => $request->hasHeader('X-Client-Version', '1.1.0'));
    }

    public function test_no_version_header_when_the_version_is_unknown(): void
    {
        config(['sync.configured' => true, 'sync.client_version' => null]);
        Http::fake(['*' => Http::response(['success' => true, 'synced_ids' => [], 'tables' => [], 'next' => null], 200)]);
        $this->user(UserRole::CLINICIAN, 'doc@example.org');

        app(SyncService::class)->runFullSync('https://central.test/api/v1/sync');

        Http::assertSent(fn ($request) => ! $request->hasHeader('X-Client-Version'));
    }

    public function test_a_426_pauses_sync_keeps_records_pending_and_shows_the_banner_to_everyone(): void
    {
        config(['sync.configured' => true, 'sync.standalone' => false, 'sync.client_version' => '1.0.1', 'sync.update_secret' => 'run-secret']);
        Http::fake(['*' => Http::response(['success' => false, 'minimum_version' => '1.1.0'], 426)]);
        $clinician = $this->user(UserRole::CLINICIAN, 'doc@example.org');

        $result = app(SyncService::class)->runFullSync('https://central.test/api/v1/sync');

        $this->assertFalse($result['success']);
        $this->assertSame('update_required', $result['error_type']);
        $this->assertSame('pending', $clinician->fresh()->sync_status); // nothing lost, nothing marked synced
        $this->assertSame('1.1.0', app(SyncService::class)->updateRequired()['minimum']);
        $this->assertSame('Update required – sync paused', app(SyncService::class)->statusSummary()['label']);

        $this->actingAs($clinician)->get(route('sync.index'))
            ->assertSee('This version is out of date. Sync is paused until you update.')
            ->assertSee('Please ask an administrator to approve the update.');

        $admin = $this->user(UserRole::ADMINISTRATOR, 'admin@example.org');
        $this->actingAs($admin)->get(route('sync.index'))
            ->assertSee('This version is out of date. Sync is paused until you update.')
            ->assertSee('Restart and update now')
            ->assertDontSee('Please ask an administrator to approve the update.');
    }

    public function test_the_banner_disappears_once_the_app_is_new_enough(): void
    {
        config(['sync.client_version' => '1.0.1']);
        $sync = app(SyncService::class);
        app(SyncStateStore::class)->putJson('update_required', ['minimum' => '1.1.0', 'at' => now()->toIso8601String()]);
        $this->assertNotNull($sync->updateRequired());

        config(['sync.client_version' => '1.1.0']);
        $this->assertNull($sync->updateRequired());
    }

    // ---- Update hold (shell installs only when no sync is running) ----

    public function test_update_hold_stops_new_syncs_and_release_resumes_them(): void
    {
        config(['sync.configured' => true]);
        Http::fake();

        $this->assertSame(0, Artisan::call('vilocare:update-gate', ['action' => 'hold']));
        $result = app(SyncService::class)->runFullSync();
        $this->assertSame('update_pending', $result['mode']);
        Http::assertNothingSent();

        Artisan::call('vilocare:update-gate', ['action' => 'release']);
        $this->assertFalse(app(SyncService::class)->updateHoldActive());
    }

    public function test_update_gate_reports_a_running_sync_with_exit_code_20(): void
    {
        $store = app(SyncStateStore::class);
        $store->put('run_started_at', now()->toIso8601String());
        $store->forget('run_finished_at');

        $this->assertSame(20, Artisan::call('vilocare:update-gate', ['action' => 'hold']));
        Artisan::call('vilocare:update-gate', ['action' => 'status']);
        $this->assertTrue(json_decode(trim(Artisan::output()), true)['sync_running']);
    }

    // ---- Administrator-only approval ----

    public function test_only_administrators_get_a_signed_approval(): void
    {
        config(['sync.update_secret' => 'run-secret']);

        $admin = $this->user(UserRole::ADMINISTRATOR, 'admin@example.org');
        $token = $this->actingAs($admin)->postJson(route('app-update.approval'))->assertOk()->json('token');
        [$expires, $signature] = explode('.', $token);
        $this->assertSame(hash_hmac('sha256', 'approve-update|'.$expires, 'run-secret'), $signature);
        $this->assertEqualsWithDelta(time() + 60, (int) $expires, 5);

        foreach ([UserRole::CLINICIAN, UserRole::LAB_TECHNICIAN, UserRole::DATA_CLERK] as $i => $role) {
            $other = $this->user($role, "staff{$i}@example.org");
            $this->actingAs($other)->postJson(route('app-update.approval'))->assertForbidden();
        }
    }

    public function test_approval_does_not_exist_outside_the_desktop_app(): void
    {
        config(['sync.update_secret' => null]);
        $admin = $this->user(UserRole::ADMINISTRATOR, 'admin@example.org');

        $this->actingAs($admin)->postJson(route('app-update.approval'))->assertNotFound();
    }

    public function test_update_notice_is_rendered_for_administrators_only(): void
    {
        config(['sync.update_secret' => 'run-secret']);

        $admin = $this->user(UserRole::ADMINISTRATOR, 'admin@example.org');
        $this->actingAs($admin)->get(route('sync.index'))
            ->assertSee('app-update-notice', false)
            ->assertSee('Restart and update now')
            ->assertSee('viloUpdater', false);

        foreach ([UserRole::CLINICIAN, UserRole::LAB_TECHNICIAN, UserRole::DATA_CLERK] as $i => $role) {
            $other = $this->user($role, "staff{$i}@example.org");
            $this->actingAs($other)->get(route('sync.index'))
                ->assertOk()
                ->assertDontSee('app-update-notice', false)
                ->assertDontSee('Restart and update now')
                ->assertDontSee('viloUpdater', false);
        }
    }

    public function test_update_notice_is_not_rendered_outside_the_desktop_app(): void
    {
        config(['sync.update_secret' => null]);
        $admin = $this->user(UserRole::ADMINISTRATOR, 'admin@example.org');

        $this->actingAs($admin)->get(route('sync.index'))->assertDontSee('app-update-notice', false);
    }

    // ---- Version display ----

    public function test_sync_center_shows_the_app_version(): void
    {
        config(['sync.client_version' => '1.1.0']);
        $user = $this->user(UserRole::CLINICIAN, 'doc@example.org');

        $this->actingAs($user)->get(route('sync.index'))->assertSee('ViLoCare app version')->assertSee('1.1.0');
    }
}
