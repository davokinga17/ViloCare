<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SyncRoleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin@example.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => 'Password-12345',
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    // ---- Central server ----

    public function test_central_badge_is_neutral_central_server(): void
    {
        config(['sync.role' => 'central', 'sync.configured' => false]);

        $summary = app(SyncService::class)->statusSummary();
        $this->assertSame('neutral', $summary['state']);
        $this->assertSame('Central server', $summary['label']);

        $this->actingAs($this->admin())->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Central server')
            ->assertDontSee('Offline mode')
            ->assertDontSee('Sync is not set up.')
            ->assertDontSee('btn-sync-now');
    }

    public function test_central_settings_page_has_no_form_and_shows_endpoint_and_last_clinic_sync(): void
    {
        config(['sync.role' => 'central', 'app.url' => 'https://central.test']);
        app(SyncService::class)->recordClinicContact('kapoeta-clinic-desktop-01');

        $this->actingAs($this->admin())->get(route('settings.sync.edit'))
            ->assertOk()
            ->assertSee("This is the central server. Clinic computers connect to it using the sync token set in this server's environment settings.", false)
            ->assertSee('https://central.test/api/v1/sync')
            ->assertSee('kapoeta-clinic-desktop-01')
            ->assertDontSee('sync-settings-form')
            ->assertDontSee('name="url"', false)
            ->assertDontSee('name="sync_token"', false);
    }

    public function test_central_settings_page_says_so_when_no_clinic_has_synced(): void
    {
        config(['sync.role' => 'central']);

        $this->actingAs($this->admin())->get(route('settings.sync.edit'))
            ->assertSee('No clinic has synced yet.');
    }

    public function test_central_refuses_to_save_or_test_a_sync_url(): void
    {
        config(['sync.role' => 'central']);
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.sync.update'), ['url' => 'https://other.test/api/v1/sync', 'sync_token' => 'abcdefgh'])
            ->assertForbidden();
        $this->actingAs($admin)->postJson(route('settings.sync.test'), ['url' => 'https://other.test/api/v1/sync', 'sync_token' => 'abcdefgh'])
            ->assertForbidden();
    }

    public function test_central_never_runs_outbound_sync(): void
    {
        config(['sync.role' => 'central', 'sync.configured' => true]);
        Http::fake();
        $user = $this->admin();

        $result = app(SyncService::class)->runFullSync();

        $this->assertSame('central_server', $result['mode']);
        Http::assertNothingSent();
        $this->assertSame('pending', $user->fresh()->sync_status);

        $this->assertSame(0, Artisan::call('vilocare:sync'));
        Http::assertNothingSent();
    }

    public function test_central_sync_now_does_nothing_and_scheduler_skips_sync_job(): void
    {
        config(['sync.role' => 'central', 'sync.configured' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('sync.trigger'))
            ->assertJson(['success' => false, 'started' => false, 'mode' => 'central_server']);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'vilocare:sync'));
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_push_records_last_clinic_sync_on_central(): void
    {
        config(['sync.role' => 'central', 'sync.api_token' => 'tok-123456789']);

        $this->postJson('/api/v1/sync/push', ['device_id' => 'clinic-a', 'timestamp' => now()->toIso8601String(), 'tables' => ['patients' => []]], ['X-Sync-Token' => 'tok-123456789'])
            ->assertOk();

        $contact = app(SyncService::class)->lastClinicContact();
        $this->assertSame('clinic-a', $contact['device']);
        $this->assertNotNull($contact['at']);
    }

    // ---- Clinic computer (unchanged behaviour) ----

    public function test_role_defaults_to_clinic(): void
    {
        $this->assertSame('clinic', config('sync.role'));
        $this->assertFalse(app(SyncService::class)->isCentral());
    }

    public function test_clinic_badge_and_settings_form_are_unchanged(): void
    {
        config(['sync.role' => 'clinic', 'sync.configured' => false, 'sync.standalone' => false]);
        $this->assertSame('Offline mode (sync not set up)', app(SyncService::class)->statusSummary()['label']);

        $this->actingAs($this->admin())->get(route('settings.sync.edit'))
            ->assertOk()
            ->assertSee('sync-settings-form')
            ->assertSee('name="sync_token"', false)
            ->assertDontSee('This is the central server.');
    }

    public function test_clinic_scheduler_still_runs_sync_job(): void
    {
        config(['sync.role' => 'clinic']);

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'vilocare:sync'));
        $this->assertNotNull($event);
        $this->assertTrue($event->filtersPass($this->app));
    }
}

