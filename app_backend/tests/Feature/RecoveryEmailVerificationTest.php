<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\AccountNotice;
use App\Models\Facility;
use App\Models\User;
use App\Services\SyncService;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Recovery email setup and confirmation. Mail::fake() only: no real email is ever sent. */
class RecoveryEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        Mail::fake();
        config(['sync.role' => 'central', 'mail.default' => 'smtp', 'app.url' => 'https://vilocare.test']);
    }

    private function user(string $email, UserRole $role = UserRole::CLINICIAN, array $extra = []): User
    {
        return User::create($extra + [
            'name' => 'Staff '.$email, 'email' => $email, 'role' => $role,
            'facility_id' => $role === UserRole::ADMINISTRATOR ? null : Facility::first()->id,
            'password' => Hash::make('OldPassword1'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function path(): string
    {
        $mail = Mail::sent(AccountNotice::class, fn ($m) => $m->kind === 'verify')->last();
        $this->assertNotNull($mail, 'a verification email was sent');

        return substr($mail->link, strlen('https://vilocare.test'));
    }

    private function setOwn(User $user, ?string $email, string $password = 'OldPassword1')
    {
        return $this->actingAs($user)->put('/profile/recovery-email', ['recovery_email' => $email, 'current_password' => $password]);
    }

    public function test_a_user_sets_their_recovery_email_with_the_current_password_and_gets_a_verification_link(): void
    {
        $user = $this->user('a@vilocare.org');

        $this->setOwn($user, 'Real.Mailbox@Example.org', 'wrong')->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->recovery_email);

        $this->setOwn($user, 'Real.Mailbox@Example.org')->assertRedirect(route('profile.show'));

        $fresh = $user->fresh();
        $this->assertSame('real.mailbox@example.org', $fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('real.mailbox@example.org'));
        $this->assertStringStartsWith('/email/verify/', $this->path());
    }

    public function test_the_link_confirms_the_address_once(): void
    {
        $user = $this->user('a@vilocare.org');
        $this->setOwn($user, 'real@example.org');
        $path = $this->path();
        auth()->logout();

        $this->get($path)->assertOk()->assertSee('Confirm your recovery email');
        $this->assertNull($user->fresh()->recovery_email_verified_at, 'opening the link alone changes nothing');

        $this->post($path)->assertOk()->assertSee('Recovery email confirmed');
        $this->assertNotNull($user->fresh()->recovery_email_verified_at);
        $this->assertSame('pending', $user->fresh()->sync_status, 'the confirmation syncs');

        $this->post($path)->assertSee('expired or was already used');
        $this->get($path)->assertSee('expired or was already used');
    }

    public function test_the_verification_link_expires_after_24_hours(): void
    {
        $user = $this->user('a@vilocare.org');
        $this->setOwn($user, 'real@example.org');
        $path = $this->path();

        $this->travel(23)->hours();
        $this->get($path)->assertSee('Confirm your recovery email');
        $this->travel(2)->hours();
        $this->post($path)->assertSee('expired or was already used');
        $this->assertNull($user->fresh()->recovery_email_verified_at);
    }

    public function test_changing_the_recovery_email_unverifies_it_and_kills_the_old_link(): void
    {
        $user = $this->user('a@vilocare.org');
        $this->setOwn($user, 'first@example.org');
        $oldPath = $this->path();
        $this->post($oldPath);
        $this->assertNotNull($user->fresh()->recovery_email_verified_at);

        $this->setOwn($user->fresh(), 'second@example.org');

        $fresh = $user->fresh();
        $this->assertSame('second@example.org', $fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('second@example.org'));
        $this->assertNotSame($oldPath, $this->path());

        // Saving the same address again changes nothing and sends nothing more.
        Mail::fake();
        $this->setOwn($user->fresh(), 'SECOND@example.org');
        Mail::assertNothingSent();
    }

    public function test_an_address_already_used_by_someone_else_is_refused_in_any_letter_case(): void
    {
        $this->user('a@vilocare.org', extra: ['recovery_email' => 'taken@example.org']);
        $other = $this->user('b@vilocare.org');

        $this->setOwn($other, 'TAKEN@example.org')->assertSessionHasErrors('recovery_email');
        $this->assertNull($other->fresh()->recovery_email);
    }

    public function test_the_database_itself_refuses_a_duplicate_recovery_email(): void
    {
        $this->user('a@vilocare.org', extra: ['recovery_email' => 'taken@example.org']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->user('b@vilocare.org', extra: ['recovery_email' => 'Taken@Example.org']);
    }

    public function test_an_administrator_sets_it_on_the_edit_form_and_the_users_page_shows_the_status(): void
    {
        $admin = $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);
        $staff = $this->user('s@vilocare.org');

        $this->actingAs($admin)->put(route('users.update', $staff), [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'clinician', 'is_active' => 1,
            'facility_id' => $staff->facility_id, 'recovery_email' => 'staff.real@example.org',
        ])->assertRedirect(route('users.index'));

        $this->assertSame('staff.real@example.org', $staff->fresh()->recovery_email);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('staff.real@example.org'));

        $this->actingAs($admin)->get(route('users.index'))->assertOk()
            ->assertSee('Unverified')->assertSee('Resend verification')->assertSee('None');

        $staff->forceFill(['recovery_email_verified_at' => now()])->save();
        $this->actingAs($admin)->get(route('users.index'))->assertSee('Verified')->assertDontSee('Resend verification');
    }

    public function test_the_administrator_can_resend_and_only_an_administrator(): void
    {
        $admin = $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);
        $clinician = $this->user('c@vilocare.org');
        $staff = $this->user('s@vilocare.org', UserRole::DATA_CLERK, ['recovery_email' => 'staff.real@example.org']);

        $this->actingAs($clinician)->post(route('users.resend-verification', $staff))->assertForbidden();
        Mail::assertNothingSent();

        $this->actingAs($admin)->post(route('users.resend-verification', $staff))->assertSessionHas('success');
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('staff.real@example.org'));
    }

    public function test_a_clinician_cannot_change_a_recovery_email_through_the_user_form(): void
    {
        $clinician = $this->user('c@vilocare.org');
        $clerk = $this->user('d@vilocare.org', UserRole::DATA_CLERK, ['recovery_email' => 'keep@example.org']);

        $this->actingAs($clinician)->put(route('users.update', $clerk), [
            'name' => $clerk->name, 'email' => $clerk->email, 'role' => 'data_clerk', 'is_active' => 1,
            'recovery_email' => 'attacker@example.org',
        ]);

        $this->assertSame('keep@example.org', $clerk->fresh()->recovery_email);
    }

    public function test_the_users_page_warns_the_administrator_when_email_is_not_configured(): void
    {
        $admin = $this->user('admin@vilocare.org', UserRole::ADMINISTRATOR);

        config(['mail.default' => 'log']);
        $this->actingAs($admin)->get(route('users.index'))->assertSee('Email is not configured');

        config(['mail.default' => 'smtp']);
        $this->actingAs($admin)->get(route('users.index'))->assertDontSee('Email is not configured');
    }

    public function test_without_mail_configured_nothing_is_sent_and_the_user_is_told(): void
    {
        config(['mail.default' => 'log']);
        $user = $this->user('a@vilocare.org');

        $this->setOwn($user, 'real@example.org')->assertSessionHas('success', fn ($m) => str_contains($m, 'email is not configured'));

        Mail::assertNothingSent();
        $this->assertSame('real@example.org', $user->fresh()->recovery_email);
    }

    public function test_a_failing_mail_server_is_logged_by_class_only_and_does_not_break_the_page(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp.secret-host.example refused user@secret.example'));
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->once()->withArgs(fn ($m) => $m === 'Account email failed: RuntimeException');
        $user = $this->user('a@vilocare.org');

        $this->setOwn($user, 'real@example.org')->assertRedirect(route('profile.show'));

        $this->assertSame('real@example.org', $user->fresh()->recovery_email);
    }

    public function test_a_clinic_computer_saves_the_address_but_never_sends(): void
    {
        config(['sync.role' => 'clinic']);
        $user = $this->user('a@vilocare.org');

        $this->setOwn($user, 'real@example.org')
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'when this computer reaches the central server'));

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('account_tokens')->count());
        $fresh = $user->fresh();
        $this->assertSame('real@example.org', $fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);
        $this->assertSame('pending', $fresh->sync_status, 'it will sync to the server');
    }

    public function test_the_server_sends_the_confirmation_when_a_clinic_pushes_a_new_recovery_email(): void
    {
        $user = $this->user('a@vilocare.org');
        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $row['recovery_email'] = 'pushed@example.org';
        $row['recovery_email_verified_at'] = '2026-11-01 00:00:00'; // a clinic cannot claim a verification
        $row['last_modified_at'] = now()->addMinute()->toDateTimeString();

        (new SyncService())->processServerPush(['tables' => ['users' => [$row]]], 'clinic-1');

        $fresh = $user->fresh();
        $this->assertSame('pushed@example.org', $fresh->recovery_email);
        $this->assertNull($fresh->recovery_email_verified_at);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('pushed@example.org'));

        // A second push of the same row does not send again while the link is still valid.
        Mail::fake();
        $row['last_modified_at'] = now()->addMinutes(2)->toDateTimeString();
        (new SyncService())->processServerPush(['tables' => ['users' => [$row]]], 'clinic-1');
        Mail::assertNothingSent();
    }

    public function test_a_verified_address_stays_verified_when_a_clinic_pushes_an_unrelated_edit(): void
    {
        $user = $this->user('a@vilocare.org', extra: ['recovery_email' => 'ok@example.org', 'recovery_email_verified_at' => '2026-11-01 00:00:00']);
        $row = (array) DB::table('users')->where('id', $user->id)->first();
        $row['name'] = 'Renamed';
        $row['recovery_email_verified_at'] = null; // a stale clinic copy
        $row['last_modified_at'] = now()->addMinute()->toDateTimeString();

        (new SyncService())->processServerPush(['tables' => ['users' => [$row]]], 'clinic-1');

        $fresh = $user->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertNotNull($fresh->recovery_email_verified_at);
        Mail::assertNothingSent();
    }

    public function test_an_older_app_that_pushes_without_the_new_columns_changes_nothing(): void
    {
        $user = $this->user('a@vilocare.org', extra: ['recovery_email' => 'ok@example.org', 'recovery_email_verified_at' => '2026-11-01 00:00:00']);
        $row = (array) DB::table('users')->where('id', $user->id)->first();
        unset($row['recovery_email'], $row['recovery_email_verified_at']);
        $row['name'] = 'Renamed By Old App';
        $row['last_modified_at'] = now()->addMinute()->toDateTimeString();

        $result = (new SyncService())->processServerPush(['tables' => ['users' => [$row]]], 'old-clinic');

        $this->assertContains($user->id, $result['synced_ids']['users']);
        $fresh = $user->fresh();
        $this->assertSame('Renamed By Old App', $fresh->name);
        $this->assertSame('ok@example.org', $fresh->recovery_email);
        $this->assertNotNull($fresh->recovery_email_verified_at);
    }
}
