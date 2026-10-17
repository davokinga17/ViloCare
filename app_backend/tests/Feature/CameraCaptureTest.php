<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\AssetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Take photo" on the profile page is the reusable camera button (public/js/camera-capture.js). The browser behaviour
 * itself is exercised in a real browser with a fake camera; these tests cover what the server side must guarantee.
 */
class CameraCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create(['name' => 'Dr Jane', 'email' => 'jane@example.org', 'role' => UserRole::CLINICIAN, 'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true]);
    }

    public function test_profile_take_photo_uses_the_reusable_camera_button_with_a_versioned_script(): void
    {
        $page = $this->actingAs($this->user())->get(route('profile.show'))->assertOk();

        $page->assertSee('data-camera-capture', false);
        $page->assertSee('data-target="#photo"', false);
        $page->assertSee('js/camera-capture.js?v='.AssetVersion::hash('js/camera-capture.js'), false);
        // The page no longer hard-codes a phone-only capture input: the component creates one only on touch devices.
        $page->assertDontSee('capture="user"', false);
        $page->assertDontSee('mobile-camera-input', false);
        // "Choose a file" remains the normal file field.
        $page->assertSee('<input type="file" id="photo" name="photo"', false);
    }

    public function test_the_component_keeps_its_promises(): void
    {
        $js = (string) file_get_contents(public_path('js/camera-capture.js'));

        foreach (['getUserMedia', "'image/jpeg'", 'Choose a file', 'Retake', 'Use photo', 'Capture', 'Cancel',
            'getTracks().forEach', 'pagehide', 'beforeunload', "setAttribute('capture', 'user')",
            'No camera found', 'Camera is blocked: check Windows Settings > Privacy > Camera'] as $needle) {
            $this->assertStringContainsString($needle, $js, "camera-capture.js should contain: {$needle}");
        }
        // Audio is never requested.
        $this->assertStringContainsString('audio: false', $js);
    }

    public function test_a_camera_photo_uploads_through_the_unchanged_profile_form(): void
    {
        Storage::fake('public');
        $user = $this->user();

        // What the component produces: a reduced JPEG named camera-<time>.jpg in the same "photo" field.
        $photo = UploadedFile::fake()->image('camera-1791936186348.jpg', 1024, 768);

        $this->actingAs($user)->put(route('profile.update'), ['name' => 'Dr Jane', 'photo' => $photo])
            ->assertRedirect()->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNotNull($user->photo_path);
        Storage::disk('public')->assertExists($user->photo_path);
    }
}
