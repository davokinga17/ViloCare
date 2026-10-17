<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FirstRunAndSyncSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $envDir;

    private const TOKEN = 'supersecrettoken1234';

    protected function setUp(): void
    {
        parent::setUp();

        // Point the "Sync connection" page at a throwaway .env, never the real one.
        $this->envDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vilocare-env-'.uniqid();
        mkdir($this->envDir);
        file_put_contents($this->envDir.DIRECTORY_SEPARATOR.'.env', "APP_NAME=Test\nVILOCARE_REMOTE_SYNC_URL=\"https://central.test/api/v1/sync\"\nVILOCARE_SYNC_API_TOKEN=\"".self::TOKEN."\"\n");
        $this->app->useEnvironmentPath($this->envDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.DIRECTORY_SEPARATOR.'.env');
        @rmdir($this->envDir);
        parent::tearDown();
    }

    private function makeUser(UserRole $role, string $email): User
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

    private function runFirstAdmin(array $input, array $options = []): int
    {
        $this->app->instance('first-admin.input', json_encode($input));

        return Artisan::call('vilocare:first-admin', $options);
    }

    private function validAdmin(array $override = []): array
    {
        return array_merge([
            'name' => 'Clinic Admin',
            'email' => 'Central.Admin@Example.org',
            'password' => 'a-long-secret-1',
            'password_confirmation' => 'a-long-secret-1',
        ], $override);
    }

    // ---- first administrator ----

    public function test_first_admin_is_created_when_there_are_no_users(): void
    {
        $this->assertSame(0, $this->runFirstAdmin($this->validAdmin()));

        $this->assertSame(1, User::count());
        $admin = User::first();
        $this->assertTrue($admin->isAdmin());
        $this->assertSame('central.admin@example.org', $admin->email);
        $this->assertTrue(Hash::check('a-long-secret-1', $admin->password));
        $this->assertSame('pending', $admin->sync_status);
        // Old timestamp so a newer central record of the same email wins at the first sync.
        $this->assertTrue($admin->last_modified_at->year === 2000);
    }

    public function test_first_admin_is_refused_when_any_user_exists(): void
    {
        $this->makeUser(UserRole::CLINICIAN, 'doc@example.org');

        $this->assertSame(10, $this->runFirstAdmin($this->validAdmin()));
        $this->assertSame(1, User::count());
        $this->assertFalse(User::where('email', 'central.admin@example.org')->exists());
        $this->assertSame(10, Artisan::call('vilocare:first-admin', ['--check' => true]));
    }

    public function test_check_option_reports_empty_users_table(): void
    {
        $this->assertSame(0, Artisan::call('vilocare:first-admin', ['--check' => true]));
        $this->assertSame(0, User::count());
    }

    public function test_first_admin_validates_password_and_email(): void
    {
        $this->assertSame(1, $this->runFirstAdmin($this->validAdmin(['password' => 'short', 'password_confirmation' => 'short'])));
        $this->assertSame(1, $this->runFirstAdmin($this->validAdmin(['password_confirmation' => 'different-secret-1'])));
        $this->assertSame(1, $this->runFirstAdmin($this->validAdmin(['email' => 'not-an-email'])));
        $this->assertSame(0, User::count());
    }

    public function test_first_admin_cannot_be_reached_from_the_web(): void
    {
        $paths = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode(' ');
        $this->assertStringNotContainsString('first-admin', $paths);
        $this->assertStringNotContainsString('first-run', $paths);
        $this->post('/first-admin', $this->validAdmin())->assertNotFound();
    }

    // ---- Sync connection settings ----

    public function test_settings_page_is_admin_only(): void
    {
        foreach ([UserRole::CLINICIAN, UserRole::LAB_TECHNICIAN, UserRole::DATA_CLERK] as $role) {
            $user = $this->makeUser($role, $role->value.'@example.org');
            $this->actingAs($user)->get(route('settings.sync.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('settings.sync.update'), ['url' => 'https://x.test/api', 'sync_token' => 'abc'])->assertForbidden();
            $this->actingAs($user)->postJson(route('settings.sync.test'), ['url' => 'https://x.test/api'])->assertForbidden();
        }

        $this->assertStringContainsString(self::TOKEN, file_get_contents($this->envDir.'/.env'));
        $this->assertStringNotContainsString('x.test', file_get_contents($this->envDir.'/.env'));
    }

    public function test_settings_page_masks_the_token(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin@example.org');

        $response = $this->actingAs($admin)->get(route('settings.sync.edit'))->assertOk();
        $response->assertSee('https://central.test/api/v1/sync');
        $response->assertDontSee(self::TOKEN);
        $response->assertDontSee(substr(self::TOKEN, -4));
        $response->assertSee('••••');
    }

    public function test_admin_can_save_connection_without_leaking_or_logging_the_token(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin@example.org');
        $newToken = 'brand-new-token-9876';

        $this->actingAs($admin)
            ->put(route('settings.sync.update'), ['url' => 'https://new.test/api/v1/sync/', 'sync_token' => $newToken])
            ->assertRedirect(route('settings.sync.edit'));

        $env = file_get_contents($this->envDir.'/.env');
        $this->assertStringContainsString('VILOCARE_REMOTE_SYNC_URL="https://new.test/api/v1/sync"', $env);
        $this->assertStringContainsString('VILOCARE_SYNC_API_TOKEN="'.$newToken.'"', $env);
        $this->assertStringContainsString('APP_NAME=Test', $env); // other lines untouched

        $this->assertStringNotContainsString($newToken, json_encode(AuditLog::all()->toArray()));
        $this->actingAs($admin)->get(route('settings.sync.edit'))->assertDontSee($newToken);

        // A blank token keeps the saved one.
        $this->actingAs($admin)->put(route('settings.sync.update'), ['url' => 'https://other.test/api/v1/sync', 'sync_token' => ''])->assertRedirect();
        $this->assertStringContainsString('VILOCARE_SYNC_API_TOKEN="'.$newToken.'"', file_get_contents($this->envDir.'/.env'));
    }

    public function test_invalid_input_is_rejected_and_token_is_not_flashed_back(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin@example.org');

        $this->actingAs($admin)->from(route('settings.sync.edit'))
            ->put(route('settings.sync.update'), ['url' => 'not a url', 'sync_token' => 'typed-token-5555'])
            ->assertSessionHasErrors('url')
            ->assertSessionMissing('_old_input.sync_token');

        $this->actingAs($admin)
            ->put(route('settings.sync.update'), ['url' => 'https://ok.test/api', 'sync_token' => 'bad"token'])
            ->assertSessionHasErrors('sync_token');
        $this->assertStringContainsString(self::TOKEN, file_get_contents($this->envDir.'/.env'));
    }

    public function test_connection_test_reports_plain_language_results(): void
    {
        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin@example.org');
        $payload = ['url' => 'https://central.test/api/v1/sync', 'sync_token' => 'typed-token'];

        $mode = 200;
        Http::fake(['central.test/*' => function () use (&$mode) {
            if ($mode === 0) {
                throw new ConnectionException('cURL error 6: Could not resolve host: central.test');
            }

            return Http::response(['ok' => true], $mode);
        }]);
        $this->actingAs($admin)->postJson(route('settings.sync.test'), $payload)
            ->assertOk()->assertJson(['ok' => true]);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Sync-Token', 'typed-token') && str_ends_with($r->url(), '/counts'));

        $mode = 401;
        $this->actingAs($admin)->postJson(route('settings.sync.test'), $payload)
            ->assertOk()->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'rejected the token'));

        $mode = 0;
        $response = $this->actingAs($admin)->postJson(route('settings.sync.test'), $payload)->assertOk()->assertJson(['ok' => false]);
        $this->assertStringNotContainsString('typed-token', $response->getContent());

        // Blank token falls back to the saved one.
        $mode = 200;
        $this->actingAs($admin)->postJson(route('settings.sync.test'), ['url' => $payload['url'], 'sync_token' => ''])
            ->assertJson(['ok' => true]);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Sync-Token', self::TOKEN));
    }

    // ---- Offline mode banner ----

    public function test_banner_shows_when_sync_is_not_configured(): void
    {
        config(['sync.configured' => false]);
        $clinician = $this->makeUser(UserRole::CLINICIAN, 'doc@example.org');

        $this->actingAs($clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertSee('Sync is not set up.')
            ->assertSee('An administrator can add the connection in Settings.')
            ->assertDontSee('Open Sync Connection settings'); // link is for administrators only

        $admin = $this->makeUser(UserRole::ADMINISTRATOR, 'admin@example.org');
        $this->actingAs($admin)->get(route('sync.index'))->assertSee('Open Sync Connection settings');
    }

    public function test_banner_is_hidden_when_sync_is_configured(): void
    {
        config(['sync.configured' => true]);
        $clinician = $this->makeUser(UserRole::CLINICIAN, 'doc@example.org');

        $this->actingAs($clinician)->get(route('sync.index'))
            ->assertOk()
            ->assertDontSee('Sync is not set up.');
    }

    public function test_unconfigured_sync_runs_offline_and_keeps_records_pending(): void
    {
        config(['sync.configured' => false]);
        Http::fake();
        $user = $this->makeUser(UserRole::CLINICIAN, 'doc@example.org');

        $result = app(SyncService::class)->runFullSync();

        $this->assertSame('not_configured', $result['mode']);
        Http::assertNothingSent();
        $this->assertSame('pending', $user->fresh()->sync_status);
    }
}
