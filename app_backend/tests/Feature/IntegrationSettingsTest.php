<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ai\GeminiProvider;
use App\Services\AiInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegrationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $envDir;

    private const GEMINI = 'AIzaSyGeminiSecret1234567890';

    private const SMS = 'at-secret-key-abcdef123456';

    protected function setUp(): void
    {
        parent::setUp();

        // Point the page at a throwaway .env, never the real one.
        $this->envDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vilocare-env-'.uniqid();
        mkdir($this->envDir);
        file_put_contents($this->envDir.DIRECTORY_SEPARATOR.'.env', "APP_NAME=Test\nGEMINI_API_KEY=\"".self::GEMINI."\"\nAFRICASTALKING_USERNAME=\"sandbox\"\nAFRICASTALKING_API_KEY=\"".self::SMS."\"\nAFRICASTALKING_SANDBOX=\"true\"\n");
        $this->app->useEnvironmentPath($this->envDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.DIRECTORY_SEPARATOR.'.env');
        @rmdir($this->envDir);
        parent::tearDown();
    }

    private function env(): string
    {
        return file_get_contents($this->envDir.'/.env');
    }

    private function makeUser(UserRole $role): User
    {
        return User::create([
            'name' => ucfirst($role->value),
            'email' => $role->value.'@example.org',
            'role' => $role,
            'password' => 'Password-12345',
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        return $this->makeUser(UserRole::ADMINISTRATOR);
    }

    // ---- access ----

    public function test_page_and_actions_are_admin_only(): void
    {
        foreach ([UserRole::CLINICIAN, UserRole::LAB_TECHNICIAN, UserRole::DATA_CLERK] as $role) {
            $user = $this->makeUser($role);
            $this->actingAs($user)->get(route('settings.integrations.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('settings.integrations.gemini.update'), ['gemini_key' => 'x'])->assertForbidden();
            $this->actingAs($user)->put(route('settings.integrations.sms.update'), ['sms_username' => 'x', 'sms_key' => 'y'])->assertForbidden();
            $this->actingAs($user)->postJson(route('settings.integrations.gemini.test'), [])->assertForbidden();
            $this->actingAs($user)->postJson(route('settings.integrations.sms.test'), ['sms_username' => 'x'])->assertForbidden();
        }

        $this->assertStringContainsString(self::GEMINI, $this->env());
        $this->assertStringNotContainsString('"x"', $this->env());
    }

    public function test_sidebar_link_is_shown_to_administrators_only(): void
    {
        $this->actingAs($this->admin())->get(route('dashboard'))->assertSee(route('settings.integrations.edit'));
        $this->actingAs($this->makeUser(UserRole::CLINICIAN))->get(route('dashboard'))->assertDontSee(route('settings.integrations.edit'));
    }

    // ---- masking / saving ----

    public function test_page_masks_both_keys(): void
    {
        $response = $this->actingAs($this->admin())->get(route('settings.integrations.edit'))->assertOk();

        foreach ([self::GEMINI, self::SMS, substr(self::GEMINI, -4), substr(self::SMS, -4)] as $secret) {
            $response->assertDontSee($secret);
        }
        $response->assertSee('••••');
        $response->assertSee('value="sandbox"', false);
        $response->assertSee('name="sandbox" value="1" id="sandbox" checked', false);
    }

    public function test_gemini_key_is_saved_without_leaking_and_clears_a_cached_warning(): void
    {
        $admin = $this->admin();
        $new = 'AIzaSyBrandNewKey987654321';
        Cache::put(AiInsightService::CACHE_KEY_DAILY_SUMMARY, 'stale');
        Cache::put(AiInsightService::CACHE_KEY_DAILY_SUMMARY_TIME, 'stale');

        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_key' => $new])
            ->assertRedirect(route('settings.integrations.edit'));

        $this->assertStringContainsString('GEMINI_API_KEY="'.$new.'"', $this->env());
        $this->assertStringContainsString('APP_NAME=Test', $this->env());
        $this->assertStringContainsString('AFRICASTALKING_API_KEY="'.self::SMS.'"', $this->env());
        $this->assertFalse(Cache::has(AiInsightService::CACHE_KEY_DAILY_SUMMARY));
        $this->assertStringNotContainsString($new, json_encode(AuditLog::all()->toArray()));
        $this->assertTrue(AuditLog::where('action', 'updated_gemini_integration')->exists());
        $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertDontSee($new);

        // Blank keeps the saved key.
        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_key' => ''])->assertRedirect();
        $this->assertStringContainsString('GEMINI_API_KEY="'.$new.'"', $this->env());
    }

    public function test_sms_settings_are_saved_and_the_key_is_not_audited(): void
    {
        $admin = $this->admin();
        $new = 'live-key-zyxwvu987654';

        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), [
            'sms_username' => 'kapoeta_clinic', 'sms_key' => $new, 'sms_sender_id' => 'ViLoCare',
        ])->assertRedirect(route('settings.integrations.edit')); // sandbox box unticked = live

        $env = $this->env();
        $this->assertStringContainsString('AFRICASTALKING_USERNAME="kapoeta_clinic"', $env);
        $this->assertStringContainsString('AFRICASTALKING_API_KEY="'.$new.'"', $env);
        $this->assertStringContainsString('AFRICASTALKING_SENDER_ID="ViLoCare"', $env);
        $this->assertStringContainsString('AFRICASTALKING_SANDBOX="false"', $env);
        $this->assertStringNotContainsString($new, json_encode(AuditLog::all()->toArray()));

        // Blank key keeps it; ticking sandbox with the sandbox username; blank sender ID clears it.
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), [
            'sms_username' => 'sandbox', 'sms_key' => '', 'sms_sender_id' => '', 'sandbox' => '1',
        ])->assertRedirect(route('settings.integrations.edit'));

        $env = $this->env();
        $this->assertStringContainsString('AFRICASTALKING_API_KEY="'.$new.'"', $env);
        $this->assertStringContainsString('AFRICASTALKING_SANDBOX="true"', $env);
        $this->assertStringContainsString('AFRICASTALKING_SENDER_ID=""', $env);
        $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertDontSee($new);
    }

    public function test_invalid_input_is_rejected_and_keys_are_not_flashed_back(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->from(route('settings.integrations.edit'))
            ->put(route('settings.integrations.gemini.update'), ['gemini_key' => 'bad"key'])
            ->assertSessionHasErrors('gemini_key')
            ->assertSessionMissing('_old_input.gemini_key');

        $this->actingAs($admin)->from(route('settings.integrations.edit'))
            ->put(route('settings.integrations.sms.update'), ['sms_username' => 'sandbox', 'sms_key' => 'has space', 'sms_sender_id' => 'WAY-TOO-LONG-SENDER'])
            ->assertSessionHasErrors(['sms_key', 'sms_sender_id'])
            ->assertSessionMissing('_old_input.sms_key');

        // Sandbox only works with the "sandbox" username.
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), ['sms_username' => 'myclinic', 'sms_key' => 'abc', 'sandbox' => '1'])
            ->assertSessionHasErrors('sms_username');

        $this->assertStringContainsString('GEMINI_API_KEY="'.self::GEMINI.'"', $this->env());
        $this->assertStringContainsString('AFRICASTALKING_API_KEY="'.self::SMS.'"', $this->env());
    }

    public function test_a_key_is_required_when_none_is_saved(): void
    {
        file_put_contents($this->envDir.'/.env', "APP_NAME=Test\n");
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_key' => ''])->assertSessionHasErrors('gemini_key');
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), ['sms_username' => 'sandbox', 'sms_key' => ''])->assertSessionHasErrors('sms_key');
        $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), [])->assertJson(['ok' => false]);
        Http::assertNothingSent();
    }

    // ---- Test buttons ----

    private function fakeModelList(): void
    {
        $model = fn (string $name, array $methods = ['generateContent']) => ['name' => 'models/'.$name, 'displayName' => $name, 'supportedGenerationMethods' => $methods];

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['models' => [
            $model('gemini-3.5-pro'),
            $model('gemini-3.5-flash-lite'),
            $model('gemini-3.5-flash'),
            $model('text-embedding-004', ['embedContent']),
        ]], 200)]);
    }

    public function test_gemini_test_lists_models_that_support_generate_content_with_the_key_in_a_header_only(): void
    {
        $this->fakeModelList();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), ['gemini_key' => 'AIzaTypedKey123', 'gemini_model' => 'gemini-3.5-flash'])
            ->assertOk()->assertJson(['ok' => true, 'model_available' => true, 'warning' => false]);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_ends_with(strtok($r->url(), '?'), '/v1beta/models')
            && $r->hasHeader('x-goog-api-key', 'AIzaTypedKey123')
            && ! str_contains($r->url(), 'AIzaTypedKey123'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), ':generateContent'));

        // Only generateContent models, flash first.
        $this->assertSame(['gemini-3.5-flash', 'gemini-3.5-flash-lite', 'gemini-3.5-pro'], array_column($response->json('models'), 'name'));
        $this->assertStringNotContainsString('AIzaTypedKey123', $response->getContent());

        // Blank key falls back to the saved one.
        $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), ['gemini_key' => ''])->assertJson(['ok' => true]);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', self::GEMINI));
    }

    public function test_gemini_test_says_when_the_saved_model_is_missing_and_suggests_the_closest_flash_model(): void
    {
        $this->fakeModelList();

        $response = $this->actingAs($this->admin())->postJson(route('settings.integrations.gemini.test'), ['gemini_model' => 'gemini-2.5-flash'])
            ->assertOk()
            ->assertJson(['ok' => true, 'model_available' => false, 'warning' => true, 'suggestion' => 'gemini-3.5-flash']);

        $this->assertStringContainsString('"gemini-2.5-flash" is not available to this key', $response->json('message'));
        $this->assertStringContainsString('"gemini-3.5-flash"', $response->json('message'));
    }

    public function test_gemini_test_checks_the_saved_model_when_none_is_typed(): void
    {
        $this->fakeModelList();
        file_put_contents($this->envDir.'/.env', "VILOCARE_GEMINI_MODEL=\"gemini-3.5-pro\"\nGEMINI_API_KEY=\"".self::GEMINI."\"\n");

        $this->actingAs($this->admin())->postJson(route('settings.integrations.gemini.test'), [])
            ->assertJson(['ok' => true, 'model' => 'gemini-3.5-pro', 'model_available' => true]);
    }

    public function test_model_is_prefilled_saved_next_to_the_key_and_validated(): void
    {
        $admin = $this->admin();

        // Nothing saved: the page shows the single config default.
        $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertOk()
            ->assertSee('name="gemini_model"', false)
            ->assertSee('<option value="'.config('services.gemini.default_model').'" selected>', false);

        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_key' => '', 'gemini_model' => 'gemini-3.5-pro'])
            ->assertRedirect(route('settings.integrations.edit'));
        $this->assertStringContainsString('VILOCARE_GEMINI_MODEL="gemini-3.5-pro"', $this->env());
        $this->assertStringContainsString('GEMINI_API_KEY="'.self::GEMINI.'"', $this->env());
        $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertSee('<option value="gemini-3.5-pro" selected>', false);

        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_model' => 'bad"model'])->assertSessionHasErrors('gemini_model');
        $this->actingAs($admin)->put(route('settings.integrations.gemini.update'), ['gemini_model' => 'bad model'])->assertSessionHasErrors('gemini_model');
        $this->assertStringContainsString('VILOCARE_GEMINI_MODEL="gemini-3.5-pro"', $this->env());
        $this->assertTrue(AuditLog::where('action', 'updated_gemini_integration')->exists());
    }

    public function test_saved_model_takes_priority_over_the_env_value_and_the_default(): void
    {
        $fake = ['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Fine.']]]]]], 200)];
        $url = fn () => Http::recorded()->last()[0]->url();
        config(['services.gemini.api_key' => 'k', 'services.gemini.model' => 'env-model', 'services.gemini.model_override' => 'saved-model']);

        Http::fake($fake);
        (new GeminiProvider)->generateClinicalSummary(['a' => 1]);
        $this->assertStringContainsString('/models/saved-model:generateContent', $url());

        // No saved choice: GEMINI_MODEL from the environment is used.
        config(['services.gemini.model_override' => null]);
        Http::fake($fake);
        (new GeminiProvider)->generateClinicalSummary(['a' => 1]);
        $this->assertStringContainsString('/models/env-model:generateContent', $url());

        // Neither: the one default in config.
        config(['services.gemini.model' => null]);
        Http::fake($fake);
        (new GeminiProvider)->generateClinicalSummary(['a' => 1]);
        $this->assertStringContainsString('/models/'.config('services.gemini.default_model').':generateContent', $url());
    }

    public function test_the_default_model_name_is_written_in_one_place_only(): void
    {
        $hits = [];
        $roots = [app_path(), resource_path('views'), base_path('routes')];
        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && preg_match('/gemini-\d/', (string) file_get_contents($file->getPathname()))) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $hits, 'A Gemini model name is hard-coded outside config/services.php');
        $this->assertMatchesRegularExpression('/^gemini-/', config('services.gemini.default_model'));
    }

    public function test_a_404_from_google_gives_the_choose_another_model_message(): void
    {
        config(['services.gemini.api_key' => 'k', 'services.gemini.model_override' => 'gone-model']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'models/gone-model is not found']], 404)]);

        $message = (new GeminiProvider)->answerStaffQuestion('How many patients?', ['a' => 1]);

        $this->assertStringContainsString('`gone-model`', $message);
        $this->assertStringContainsString("isn't available to your key", $message);
        $this->assertStringContainsString('Settings > Integrations', $message);
        $this->assertStringNotContainsString('1.5-flash', $message);
        $this->assertStringNotContainsString('2.0-flash', $message);
    }

    public function test_gemini_test_reports_plain_language_failures_without_the_key(): void
    {
        $admin = $this->admin();
        $mode = 400;
        Http::fake(['generativelanguage.googleapis.com/*' => function (HttpRequest $r) use (&$mode) {
            if ($mode === 0) {
                throw new ConnectionException('cURL error 6: Could not resolve host for '.$r->url().' key='.$r->header('x-goog-api-key')[0]);
            }

            return Http::response(['error' => ['message' => 'API key not valid']], $mode);
        }]);

        $bad = $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), ['gemini_key' => 'AIzaTypedKey123'])
            ->assertOk()->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'rejected the key'));
        $this->assertStringNotContainsString('AIzaTypedKey123', $bad->getContent());

        $mode = 429;
        $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), [])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'quota'));

        $mode = 0;
        $offline = $this->actingAs($admin)->postJson(route('settings.integrations.gemini.test'), ['gemini_key' => 'AIzaTypedKey123'])
            ->assertOk()->assertJson(['ok' => false]);
        $this->assertStringNotContainsString('AIzaTypedKey123', $offline->getContent());
    }

    public function test_sms_test_validates_credentials_without_sending_an_sms(): void
    {
        $admin = $this->admin();
        $status = 200;
        Http::fake(['api.sandbox.africastalking.com/*' => function () use (&$status) {
            return Http::response(['UserData' => ['balance' => 'USD 0.5000']], $status);
        }, 'api.africastalking.com/*' => Http::response([], 401)]);

        $this->actingAs($admin)->postJson(route('settings.integrations.sms.test'), ['sms_username' => 'sandbox', 'sms_key' => 'typed-sms-key', 'sandbox' => true])
            ->assertOk()->assertJson(['ok' => true])->assertJsonPath('message', fn ($m) => str_contains($m, 'No SMS was sent') && str_contains($m, 'USD 0.5000'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
            && str_contains($r->url(), 'api.sandbox.africastalking.com/version1/user')
            && $r->hasHeader('apiKey', 'typed-sms-key')
            && ! str_contains($r->url(), 'typed-sms-key'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), '/messaging') || $r->method() === 'POST');

        // Saved key + live endpoint.
        $this->actingAs($admin)->postJson(route('settings.integrations.sms.test'), ['sms_username' => 'myclinic', 'sms_key' => ''])
            ->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'rejected'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'https://api.africastalking.com/version1/user') && $r->hasHeader('apiKey', self::SMS));
    }

    public function test_sms_test_failure_does_not_echo_the_key(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('settings.integrations.sms.test'), ['sms_username' => 'sandbox', 'sms_key' => 'typed-sms-key', 'sandbox' => true])
            ->assertOk()->assertJson(['ok' => false]);
        $this->assertStringNotContainsString('typed-sms-key', $response->getContent());
    }

    // ---- missing-key notices ----

    public function test_dashboard_ai_card_shows_notice_with_link_for_admin_when_key_missing(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('AI Insights is not set up.')
            ->assertSee('Open Integrations settings');

        $this->actingAs($this->makeUser(UserRole::CLINICIAN))->get(route('dashboard'))
            ->assertSee('AI Insights is not set up.')
            ->assertDontSee('Open Integrations settings')
            ->assertSee('An administrator can add the key');
    }

    public function test_notice_is_hidden_when_keys_are_configured(): void
    {
        config(['services.gemini.api_key' => 'k', 'services.africastalking.api_key' => 'k']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('AI Insights is not set up.');
        $this->actingAs($admin)->get(route('notifications.index'))->assertDontSee('SMS is not set up.');
        $this->actingAs($admin)->get(route('notifications.create'))->assertDontSee('SMS is not set up.');
        $this->actingAs($admin)->get(route('insights.qa.index'))->assertDontSee('AI Insights is not set up.');
    }

    public function test_sms_and_qa_pages_show_notice_when_key_missing(): void
    {
        config(['services.gemini.api_key' => null, 'services.africastalking.api_key' => null]);
        $admin = $this->admin();

        foreach (['notifications.index', 'notifications.create'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk()
                ->assertSee('SMS is not set up.')->assertSee(route('settings.integrations.edit'));
        }
        $this->actingAs($admin)->get(route('insights.qa.index'))->assertOk()
            ->assertSee('AI Insights is not set up.')->assertSee(route('settings.integrations.edit'));
    }

    // ---- provider behaviour ----

    public function test_gemini_provider_sends_the_key_in_a_header_not_the_url(): void
    {
        config(['services.gemini.api_key' => 'AIzaProviderKey555']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Fine.']]]]]], 200)]);

        $this->assertSame('Fine.', (new GeminiProvider)->generateClinicalSummary(['a' => 1]));
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', 'AIzaProviderKey555') && ! str_contains($r->url(), 'AIzaProviderKey555'));
    }

    public function test_missing_key_warning_is_not_cached_so_adding_a_key_takes_effect_immediately(): void
    {
        Cache::forget(AiInsightService::CACHE_KEY_DAILY_SUMMARY);
        config(['services.gemini.api_key' => null]);
        $this->app->forgetInstance(AiInsightService::class);

        $first = app(AiInsightService::class)->getClinicalSummary(false);
        $this->assertStringContainsString('Integrations', $first['summary']);
        $this->assertFalse(Cache::has(AiInsightService::CACHE_KEY_DAILY_SUMMARY));
    }
}
