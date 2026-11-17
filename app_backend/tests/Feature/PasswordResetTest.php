<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Mail\AccountNotice;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Self-service "Forgot password". Mail::fake() only: no real email is ever sent. */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        Mail::fake();
        config([
            'sync.role' => 'central',
            'mail.default' => 'smtp', // "configured"; Mail::fake() intercepts everything
            'app.url' => 'https://vilocare.test',
            'vilocare.reset_min_response_ms' => 0,
        ]);
    }

    private function user(string $email, array $extra = []): User
    {
        return User::create($extra + [
            'name' => 'Staff '.$email, 'email' => $email, 'role' => UserRole::CLINICIAN,
            'facility_id' => Facility::first()->id,
            'password' => Hash::make('OldPassword1'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function verified(string $login = 'nurse@vilocare.org', string $recovery = 'nurse.real@example.org', array $extra = []): User
    {
        return $this->user($login, $extra + ['recovery_email' => $recovery, 'recovery_email_verified_at' => now()]);
    }

    private function ask(string $identifier)
    {
        return $this->post('/password/forgot', ['email' => $identifier]);
    }

    private function resetLink(): string
    {
        $mail = Mail::sent(AccountNotice::class, fn ($m) => $m->kind === 'reset')->last();
        $this->assertNotNull($mail, 'a reset email was sent');

        return substr($mail->link, strlen('https://vilocare.test'));
    }

    private function token(string $link): string
    {
        return basename($link);
    }

    public function test_reset_is_sent_only_to_the_verified_recovery_email(): void
    {
        $this->verified();

        $this->ask('nurse@vilocare.org')->assertRedirect(route('password.request'));

        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'reset' && $m->hasTo('nurse.real@example.org'));
        Mail::assertNotSent(AccountNotice::class, fn ($m) => $m->hasTo('nurse@vilocare.org'));
        Mail::assertSent(AccountNotice::class, 1);
    }

    public function test_either_the_login_email_or_the_recovery_email_is_accepted(): void
    {
        $this->verified();
        $this->ask('NURSE.real@example.org');
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->hasTo('nurse.real@example.org'));
    }

    public function test_the_link_is_built_from_app_url_and_the_mail_holds_no_password_or_login_email(): void
    {
        $this->verified();
        $this->ask('nurse@vilocare.org');

        $mail = Mail::sent(AccountNotice::class)->first();
        $this->assertStringStartsWith('https://vilocare.test/password/reset/', $mail->link);
        $text = $mail->render();
        $this->assertStringNotContainsString('nurse@vilocare.org', $text);
        $this->assertStringNotContainsString('OldPassword1', $text);
    }

    public function test_a_placeholder_login_email_without_a_recovery_email_gets_nothing_and_is_audited(): void
    {
        $user = $this->user('placeholder@vilocare.org');

        $this->ask('placeholder@vilocare.org')->assertSessionHas('status', ForgotPasswordController::NEUTRAL);

        Mail::assertNothingSent();
        $audit = AuditLog::where('action', 'reset_requested_no_recovery_email')->first();
        $this->assertNotNull($audit);
        $this->assertSame($user->id, $audit->record_id);
    }

    public function test_an_unverified_recovery_email_gets_nothing(): void
    {
        $this->user('u@vilocare.org', ['recovery_email' => 'unverified@example.org']);

        $this->ask('u@vilocare.org');
        $this->ask('unverified@example.org');

        Mail::assertNothingSent();
        $this->assertSame(2, AuditLog::where('action', 'reset_requested_no_recovery_email')->count());
    }

    public function test_the_reply_is_the_same_for_existing_missing_and_unverified_accounts(): void
    {
        $this->verified();
        $this->user('plain@vilocare.org');

        $replies = [];
        foreach (['nurse@vilocare.org', 'nobody@nowhere.org', 'plain@vilocare.org', 'not an email', ''] as $identifier) {
            $response = $this->ask($identifier);
            $response->assertRedirect(route('password.request'));
            $replies[] = [session('status'), session('errors') ? session('errors')->count() : 0];
        }

        $this->assertCount(1, array_unique($replies, SORT_REGULAR));
        $this->assertSame(ForgotPasswordController::NEUTRAL, $replies[0][0]);
    }

    public function test_every_outcome_takes_about_the_same_minimum_time(): void
    {
        config(['vilocare.reset_min_response_ms' => 300]);
        $this->verified();
        $this->user('plain@vilocare.org');

        $times = [];
        foreach (['nurse@vilocare.org', 'nobody@nowhere.org', 'plain@vilocare.org'] as $identifier) {
            $start = microtime(true);
            $this->ask($identifier);
            $times[] = microtime(true) - $start;
        }

        foreach ($times as $t) {
            $this->assertGreaterThanOrEqual(0.29, $t);
        }
        $this->assertLessThan(0.25, max($times) - min($times), 'the paths cannot be told apart by timing');
    }

    public function test_a_deactivated_user_gets_nothing(): void
    {
        $this->verified(extra: ['is_active' => false]);

        $this->ask('nurse@vilocare.org')->assertSessionHas('status', ForgotPasswordController::NEUTRAL);

        Mail::assertNothingSent();
    }

    public function test_the_token_is_random_stored_hashed_and_expires_after_60_minutes(): void
    {
        $this->verified();
        $this->ask('nurse@vilocare.org');
        $link = $this->resetLink();
        $token = $this->token($link);

        $this->assertSame(64, strlen($token));
        $this->assertSame(0, DB::table('account_tokens')->where('token_hash', $token)->count(), 'the plain token is not stored');
        $this->assertSame(1, DB::table('account_tokens')->where('token_hash', hash('sha256', $token))->count());

        $this->travel(59)->minutes();
        $this->get($link)->assertOk()->assertSee('Choose a new password');

        $this->travel(2)->minutes();
        $this->get($link)->assertOk()->assertSee('expired or was already used');
        $this->post($link, ['password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])
            ->assertOk()->assertSee('expired or was already used');
        $this->assertTrue(Hash::check('OldPassword1', User::first()->password));
    }

    public function test_the_token_works_once(): void
    {
        $this->verified();
        $this->ask('nurse@vilocare.org');
        $link = $this->resetLink();

        $this->post($link, ['password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])->assertRedirect(route('login'));
        $this->post($link, ['password' => 'Another2Pass', 'password_confirmation' => 'Another2Pass'])->assertSee('expired or was already used');

        $this->assertTrue(Hash::check('NewPassword1', User::first()->password));
    }

    public function test_a_new_request_kills_the_older_link(): void
    {
        $this->verified();
        $this->ask('nurse@vilocare.org');
        $old = $this->resetLink();
        $this->ask('nurse@vilocare.org');
        $new = $this->resetLink();

        $this->assertNotSame($old, $new);
        $this->get($old)->assertSee('expired or was already used');
        $this->get($new)->assertSee('Choose a new password');
    }

    public function test_requests_are_limited_per_account_without_changing_the_reply(): void
    {
        $this->verified();

        for ($i = 0; $i < 7; $i++) {
            $this->ask('nurse@vilocare.org')->assertSessionHas('status', ForgotPasswordController::NEUTRAL);
        }

        Mail::assertSent(AccountNotice::class, 5);
    }

    public function test_requests_are_limited_per_ip(): void
    {
        $this->verified();
        for ($i = 0; $i < 20; $i++) {
            $this->ask("nobody{$i}@nowhere.org");
        }

        $this->ask('nurse@vilocare.org')->assertSessionHas('status', ForgotPasswordController::NEUTRAL);

        Mail::assertNothingSent();
    }

    public function test_a_successful_reset_changes_the_password_and_everything_around_it(): void
    {
        $user = $this->verified(extra: ['must_change_password' => true]);
        DB::table('users')->where('id', $user->id)->update(['sync_status' => 'synced', 'last_modified_at' => '2026-01-01 00:00:00']);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time()]);
        for ($i = 0; $i < 5; $i++) {
            RateLimiter::hit('nurse@vilocare.org|127.0.0.1', 60);
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('nurse@vilocare.org|127.0.0.1', 5));

        $this->ask('nurse@vilocare.org');
        $link = $this->resetLink();
        Mail::fake(); // forget the reset email: only the notice should follow

        $this->post($link, ['password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])
            ->assertRedirect(route('login'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewPassword1', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame('pending', $fresh->sync_status, 'the change will sync');
        $this->assertTrue($fresh->last_modified_at->gt('2026-01-02'));
        $this->assertFalse(RateLimiter::tooManyAttempts('nurse@vilocare.org|127.0.0.1', 5), 'login lockout cleared');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(), 'other sessions removed');
        $this->assertSame(0, DB::table('account_tokens')->where('user_id', $user->id)->whereNull('used_at')->count());
        $this->assertNotNull(AuditLog::where('action', 'password_reset_by_email')->first());

        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'changed' && $m->hasTo('nurse.real@example.org'));
        $this->assertStringNotContainsString('NewPassword1', Mail::sent(AccountNotice::class)->first()->render());

        $this->post('/login', ['email' => 'nurse@vilocare.org', 'password' => 'NewPassword1'])->assertRedirect();
        $this->assertAuthenticatedAs($fresh);
    }

    public function test_the_new_password_must_follow_the_same_rules_as_the_change_password_form(): void
    {
        $this->verified();
        $this->ask('nurse@vilocare.org');
        $link = $this->resetLink();

        foreach (['short1', 'onlyletterss', '123456789'] as $weak) {
            $this->from($link)->post($link, ['password' => $weak, 'password_confirmation' => $weak])
                ->assertSessionHasErrors('password');
        }
        $this->from($link)->post($link, ['password' => 'NewPassword1', 'password_confirmation' => 'Different1'])
            ->assertSessionHasErrors('password');

        $this->get($link)->assertSee('Choose a new password'); // the link survives a rejected password
    }

    public function test_a_user_deactivated_after_asking_cannot_use_the_link(): void
    {
        $user = $this->verified();
        $this->ask('nurse@vilocare.org');
        $link = $this->resetLink();
        $user->update(['is_active' => false]);

        $this->post($link, ['password' => 'NewPassword1', 'password_confirmation' => 'NewPassword1'])->assertSee('expired or was already used');
        $this->assertTrue(Hash::check('OldPassword1', $user->fresh()->password));
    }

    public function test_unknown_tokens_show_the_same_expired_page(): void
    {
        $this->get('/password/reset/'.str_repeat('a', 64))->assertOk()->assertSee('expired or was already used');
    }

    public function test_a_clinic_computer_never_sends_and_shows_the_server_instructions(): void
    {
        config(['sync.role' => 'clinic', 'sync.remote_url' => 'https://vilocare-central.onrender.com/api/v1/sync']);
        $this->verified();

        $this->get('/password/forgot')->assertOk()
            ->assertSee('Use the ViLoCare website to reset your password, or ask an administrator')
            ->assertSee('vilocare-central.onrender.com')
            ->assertDontSee('/api/v1/sync');

        $this->ask('nurse@vilocare.org');
        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('account_tokens')->count());
    }

    public function test_the_login_page_links_to_forgot_password_and_has_a_show_password_button(): void
    {
        $this->get('/login')->assertOk()->assertSee('Forgot password?')->assertSee('pw-toggle', false);
        $this->get('/password/forgot')->assertOk();
    }

    public function test_the_administrator_fallback_reset_still_works(): void
    {
        $admin = $this->user('admin@vilocare.org', ['role' => UserRole::ADMINISTRATOR, 'facility_id' => null]);
        $staff = $this->user('staff@vilocare.org');

        $this->actingAs($admin)->post(route('users.reset-password', $staff))->assertRedirect(route('users.index'));

        $fresh = $staff->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertFalse(Hash::check('OldPassword1', $fresh->password));
        Mail::assertNothingSent();
    }
}
