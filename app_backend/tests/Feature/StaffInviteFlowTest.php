<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\AccountNotice;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\User;
use Database\Seeders\LocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Two ways to create staff. Real personal email: invitation (they choose their own password). No real email:
 * the old way (placeholder sign-in + temporary password, recovery email can be added later). Mail::fake() only.
 */
class StaffInviteFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LocationSeeder::class);
        Mail::fake();
        config(['sync.role' => 'central', 'mail.default' => 'smtp', 'app.url' => 'https://vilocare.test', 'vilocare.reset_min_response_ms' => 0]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@vilocare.org', 'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('AdminPass1'), 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function create(array $extra)
    {
        return $this->actingAs($this->admin)->post(route('users.store'), $extra + [
            'name' => 'New Staff', 'role' => 'clinician', 'facility_id' => Facility::first()->id,
        ]);
    }

    private function invitePath(): string
    {
        $mail = Mail::sent(AccountNotice::class, fn ($m) => $m->kind === 'invite')->last();
        $this->assertNotNull($mail, 'an invitation was sent');

        return substr($mail->link, strlen('https://vilocare.test'));
    }

    public function test_a_real_email_gets_an_invitation_and_no_temporary_password(): void
    {
        $this->create(['email' => 'Jane.Real@Example.org', 'access_mode' => 'invite', 'temporary_password' => 'ShouldBeIgnored1'])
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Invitation sent') && ! str_contains($m, 'Temporary password') && ! str_contains($m, 'ShouldBeIgnored1'));

        $user = User::where('email', 'Jane.Real@Example.org')->first();
        $this->assertSame('jane.real@example.org', $user->recovery_email);
        $this->assertNull($user->recovery_email_verified_at);
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('ShouldBeIgnored1', $user->password));
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'invite' && $m->hasTo('jane.real@example.org'));
        $this->assertStringNotContainsString('assword:', Mail::sent(AccountNotice::class)->first()->render());
    }

    public function test_accepting_the_invitation_sets_the_password_and_verifies_the_address(): void
    {
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite']);
        $user = User::where('email', 'jane@example.org')->first();
        $path = $this->invitePath();
        auth()->logout();

        $this->get($path)->assertOk()->assertSee('Choose your password');

        $this->from($path)->post($path, ['password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->post($path, ['password' => 'MyOwnPass1', 'password_confirmation' => 'MyOwnPass1'])->assertRedirect(route('login'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('MyOwnPass1', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->recovery_email_verified_at, 'opening the invitation proves the mailbox');
        $this->assertSame('pending', $fresh->sync_status);
        $this->assertNotNull(AuditLog::where('action', 'invite_accepted')->first());

        $this->post('/login', ['email' => 'jane@example.org', 'password' => 'MyOwnPass1'])->assertRedirect();
        $this->assertAuthenticatedAs($fresh);

        // The same address now works for "Forgot password?".
        Mail::fake();
        auth()->logout();
        $this->post('/password/forgot', ['email' => 'jane@example.org']);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'reset' && $m->hasTo('jane@example.org'));
    }

    public function test_the_invitation_works_once_and_expires_after_seven_days(): void
    {
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite']);
        $path = $this->invitePath();

        $this->travel(6)->days();
        $this->get($path)->assertSee('Choose your password');
        $this->travel(2)->days();
        $this->get($path)->assertSee('expired or was already used');
        $this->post($path, ['password' => 'MyOwnPass1', 'password_confirmation' => 'MyOwnPass1'])->assertSee('expired or was already used');
        $this->assertTrue(User::where('email', 'jane@example.org')->first()->must_change_password);
    }

    public function test_an_accepted_invitation_cannot_be_used_again(): void
    {
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite']);
        $path = $this->invitePath();

        $this->post($path, ['password' => 'MyOwnPass1', 'password_confirmation' => 'MyOwnPass1'])->assertRedirect();
        $this->post($path, ['password' => 'Hijacked999', 'password_confirmation' => 'Hijacked999'])->assertSee('expired or was already used');
        $this->assertTrue(Hash::check('MyOwnPass1', User::where('email', 'jane@example.org')->first()->password));
    }

    public function test_the_administrator_can_resend_a_pending_invitation_and_the_old_link_dies(): void
    {
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite']);
        $old = $this->invitePath();
        $user = User::where('email', 'jane@example.org')->first();

        $this->actingAs($this->admin)->get(route('users.index'))->assertSee('Resend invite');
        $this->actingAs($this->admin)->post(route('users.resend-verification', $user))->assertSessionHas('success', 'Invitation sent again.');

        $new = $this->invitePath();
        $this->assertNotSame($old, $new);
        $this->get($old)->assertSee('expired or was already used');
        $this->get($new)->assertSee('Choose your password');
    }

    public function test_no_real_email_keeps_the_old_way(): void
    {
        $this->create(['email' => 'placeholder@vilocare.org', 'access_mode' => 'temporary', 'temporary_password' => 'Temp1234x'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, "Temporary password: 'Temp1234x'"));

        $user = User::where('email', 'placeholder@vilocare.org')->first();
        $this->assertNull($user->recovery_email);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Temp1234x', $user->password));
        Mail::assertNothingSent();

        // A recovery email can be added later, then confirmed by link.
        $this->actingAs($this->admin)->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'role' => 'clinician', 'is_active' => 1,
            'facility_id' => $user->facility_id, 'recovery_email' => 'later@example.org',
        ]);
        Mail::assertSent(AccountNotice::class, fn ($m) => $m->kind === 'verify' && $m->hasTo('later@example.org'));
    }

    public function test_omitting_the_choice_means_the_old_way(): void
    {
        $this->create(['email' => 'placeholder2@vilocare.org'])->assertSessionHas('success', fn ($m) => str_contains($m, 'Temporary password'));
        Mail::assertNothingSent();
    }

    public function test_an_invitation_is_refused_when_email_is_not_configured_or_on_a_clinic_computer(): void
    {
        config(['mail.default' => 'log']);
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite'])->assertSessionHasErrors('access_mode');
        $this->assertNull(User::where('email', 'jane@example.org')->first());

        config(['mail.default' => 'smtp', 'sync.role' => 'clinic']);
        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite'])->assertSessionHasErrors('access_mode');
        $this->assertNull(User::where('email', 'jane@example.org')->first());
        Mail::assertNothingSent();
    }

    public function test_the_form_offers_the_choice_only_when_inviting_is_possible(): void
    {
        $this->actingAs($this->admin)->get(route('users.create'))->assertSee('Invite by email')->assertSee('No real email');

        config(['mail.default' => 'log']);
        $this->actingAs($this->admin)->get(route('users.create'))->assertDontSee('Invite by email');
    }

    public function test_an_invitation_for_an_address_already_used_as_a_recovery_email_is_refused(): void
    {
        User::create([
            'name' => 'Other', 'email' => 'other@vilocare.org', 'role' => UserRole::CLINICIAN, 'facility_id' => Facility::first()->id,
            'recovery_email' => 'jane@example.org', 'password' => Hash::make('x'), 'must_change_password' => false, 'is_active' => true,
        ]);

        $this->create(['email' => 'jane@example.org', 'access_mode' => 'invite'])->assertSessionHasErrors('recovery_email');
        $this->assertNull(User::where('email', 'jane@example.org')->first());
    }

    public function test_a_clinician_cannot_invite(): void
    {
        $clinician = User::create([
            'name' => 'C', 'email' => 'c@vilocare.org', 'role' => UserRole::CLINICIAN, 'facility_id' => Facility::first()->id,
            'password' => Hash::make('x'), 'must_change_password' => false, 'is_active' => true,
        ]);

        $this->actingAs($clinician)->post(route('users.store'), [
            'name' => 'N', 'email' => 'n@example.org', 'role' => 'data_clerk', 'access_mode' => 'invite',
        ])->assertSessionHasErrors('access_mode');
        Mail::assertNothingSent();
    }
}
