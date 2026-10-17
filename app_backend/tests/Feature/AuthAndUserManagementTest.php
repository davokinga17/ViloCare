<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthAndUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertSee('ViLoCare ART Portal');
    }

    public function test_users_can_authenticate_with_valid_credentials(): void
    {
        $user = User::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('SecretPass123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'admin@test.org',
            'password' => 'SecretPass123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));

        // Check that login audit log was recorded
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'login',
            'table_name' => 'users',
        ]);
    }

    public function test_deactivated_users_cannot_authenticate(): void
    {
        User::create([
            'name' => 'Disabled User',
            'email' => 'disabled@test.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('SecretPass123'),
            'must_change_password' => false,
            'is_active' => false,
        ]);

        $response = $this->post('/login', [
            'email' => 'disabled@test.org',
            'password' => 'SecretPass123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_user_with_temporary_password_is_redirected_to_change_password(): void
    {
        $user = User::create([
            'name' => 'New Staff',
            'email' => 'newstaff@test.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('TempPass123'),
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $response = $this->post('/login', [
            'email' => 'newstaff@test.org',
            'password' => 'TempPass123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('password.change'));

        // Accessing dashboard directly should also redirect to password change
        $dashResponse = $this->actingAs($user)->get('/dashboard');
        $dashResponse->assertRedirect(route('password.change'));
    }

    public function test_user_can_successfully_change_temporary_password_and_gain_full_access(): void
    {
        $user = User::create([
            'name' => 'New Staff',
            'email' => 'newstaff@test.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('TempPass123'),
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post('/password/change', [
            'current_password' => 'TempPass123',
            'password' => 'NewSecurePassword123',
            'password_confirmation' => 'NewSecurePassword123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewSecurePassword123', $user->password));

        // Audit log recorded
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'password_changed',
        ]);
    }

    public function test_admin_can_view_users_list_and_create_all_roles(): void
    {
        $admin = User::create([
            'name' => 'Master Admin',
            'email' => 'masteradmin@test.org',
            'role' => UserRole::ADMINISTRATOR,
            'password' => Hash::make('AdminPass123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)->get('/users');
        $response->assertStatus(200);

        // Admin creates a Clinician
        $createResponse = $this->actingAs($admin)->post('/users', [
            'name' => 'Dr. Jane Clinician',
            'email' => 'jane@clinic.org',
            'phone' => '+254711223344',
            'role' => UserRole::CLINICIAN->value,
            'temporary_password' => 'TempClinicPass1',
        ]);

        $createResponse->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'jane@clinic.org',
            'role' => UserRole::CLINICIAN->value,
            'must_change_password' => true,
            'created_by' => $admin->id,
        ]);
    }

    public function test_clinician_can_only_create_lab_tech_and_data_clerk_users(): void
    {
        $clinician = User::create([
            'name' => 'Dr. Mercy',
            'email' => 'mercy@clinic.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('DoctorPass123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        // Clinician creates a Lab Technician (Allowed)
        $allowedResponse = $this->actingAs($clinician)->post('/users', [
            'name' => 'John LabTech',
            'email' => 'john.lab@clinic.org',
            'role' => UserRole::LAB_TECHNICIAN->value,
            'temporary_password' => 'LabTechPass1',
        ]);

        $allowedResponse->assertRedirect(route('users.index'));
        $this->assertDatabaseHas('users', ['email' => 'john.lab@clinic.org']);

        // Clinician tries to create an Administrator (Forbidden)
        $forbiddenResponse = $this->actingAs($clinician)->post('/users', [
            'name' => 'Rogue Admin',
            'email' => 'rogue@clinic.org',
            'role' => UserRole::ADMINISTRATOR->value,
            'temporary_password' => 'RoguePass1',
        ]);

        $forbiddenResponse->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'rogue@clinic.org']);
    }

    public function test_data_clerk_cannot_access_user_management(): void
    {
        $clerk = User::create([
            'name' => 'Sam Clerk',
            'email' => 'sam@clinic.org',
            'role' => UserRole::DATA_CLERK,
            'password' => Hash::make('ClerkPass123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $response = $this->actingAs($clerk)->get('/users');
        $response->assertStatus(403);
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        \Illuminate\Support\Facades\RateLimiter::clear('attacker@clinic.org|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->post('/login', [
                'email' => 'attacker@clinic.org',
                'password' => 'WrongPass999',
            ]);
            $response->assertSessionHasErrors('email');
        }

        // 6th attempt should trigger rate limiter lockout
        $blockedResponse = $this->post('/login', [
            'email' => 'attacker@clinic.org',
            'password' => 'WrongPass999',
        ]);

        $blockedResponse->assertSessionHasErrors('email');
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_lockout',
        ]);
    }

    public function test_bot_honeypot_trap_blocks_automated_submission(): void
    {
        $response = $this->post('/login', [
            'email' => 'bot@spammer.org',
            'password' => 'AnyPassword123',
            '_hp_token' => 'I am an automated bot script',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'bot_honeypot_triggered',
        ]);
    }

    public function test_quick_role_switcher_panel_is_completely_absent_in_production(): void
    {
        config(['app.env' => 'production']);

        $response = $this->get('/login');
        $response->assertStatus(200);
        $response->assertDontSee('Quick Role Switcher');
        $response->assertDontSee('Password@123');
        $response->assertDontSee('TempPass@2026');
        $response->assertDontSee('fillCredentials');
    }

    public function test_user_can_upload_profile_photo_and_see_it_rendered(): void
    {
        Storage::fake('public');

        $user = User::create([
            'name' => 'Dr. Mercy Achieng',
            'email' => 'mercy.photo@test.org',
            'role' => UserRole::CLINICIAN,
            'password' => Hash::make('DoctorPass123'),
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $file = UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($user)->put('/profile', [
            'name' => 'Dr. Mercy Achieng',
            'phone' => '+211910112233',
            'photo' => $file,
        ]);

        $response->assertRedirect(route('profile.show'));
        $user->refresh();

        $this->assertNotNull($user->photo_path);
        Storage::disk('public')->assertExists($user->photo_path);
        $this->assertEquals(Storage::disk('public')->url($user->photo_path), $user->photo_url);

        // View profile page and verify <img> tag is rendered
        $profileResponse = $this->actingAs($user)->get('/profile');
        $profileResponse->assertStatus(200);
        $profileResponse->assertSee('<img src="' . $user->photo_url . '" alt="Dr. Mercy Achieng" class="user-avatar-img"', false);
    }
}
