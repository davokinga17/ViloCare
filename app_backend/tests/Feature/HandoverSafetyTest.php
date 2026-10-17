<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, UserRole $role, bool $active = true): User
    {
        return User::create([
            'name' => 'Test '.$email,
            'email' => $email,
            'phone' => '+211900000000',
            'role' => $role,
            'password' => 'a-long-test-value-1',
            'is_active' => $active,
        ]);
    }

    public function test_sync_api_answers_503_when_no_token_is_configured(): void
    {
        config(['sync.api_token' => '']);

        $this->postJson('/api/v1/sync/push', ['device_id' => 'd', 'timestamp' => now()->toIso8601String(), 'tables' => []], ['X-Sync-Token' => ''])
            ->assertStatus(503);

        $this->getJson('/api/v1/sync/counts', ['X-Sync-Token' => 'anything'])->assertStatus(503);
    }

    public function test_sync_api_still_rejects_a_wrong_token_when_one_is_configured(): void
    {
        config(['sync.api_token' => 'configured-for-this-test']);

        $this->getJson('/api/v1/sync/counts', ['X-Sync-Token' => 'wrong'])->assertStatus(401);
    }

    public function test_user_seeder_creates_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        (new UserSeeder)->run();

        $this->assertSame(0, User::count());
    }

    public function test_disable_demo_accounts_is_a_dry_run_by_default(): void
    {
        $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);
        $this->user('real.admin@example.org', UserRole::ADMINISTRATOR);

        $this->artisan('vilocare:disable-demo-accounts')->assertSuccessful();

        $this->assertTrue(User::where('email', 'admin@vilocare.org')->value('is_active'));
    }

    public function test_disable_demo_accounts_applies_only_to_demo_emails(): void
    {
        $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);
        $this->user('clinician@vilocare.org', UserRole::CLINICIAN);
        $this->user('real.admin@example.org', UserRole::ADMINISTRATOR);

        $this->artisan('vilocare:disable-demo-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertFalse((bool) User::where('email', 'admin@vilocare.org')->value('is_active'));
        $this->assertFalse((bool) User::where('email', 'clinician@vilocare.org')->value('is_active'));
        $this->assertTrue((bool) User::where('email', 'real.admin@example.org')->value('is_active'));
    }

    public function test_disable_demo_accounts_refuses_to_remove_the_last_administrator(): void
    {
        $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);

        $this->artisan('vilocare:disable-demo-accounts', ['--apply' => true])->assertFailed();

        $this->assertTrue((bool) User::where('email', 'admin@vilocare.org')->value('is_active'));
    }
}
