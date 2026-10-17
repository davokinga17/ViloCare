<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\County;
use App\Models\Facility;
use App\Models\NotificationLog;
use App\Models\Patient;
use App\Models\State;
use App\Models\User;
use App\Services\IntegrationTester;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\SmsProviderFactory;
use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\SmsResult;
use App\Services\Sms\TwilioProvider;
use App\Services\SmsService;
use App\Support\PhoneNumber;
use App\Support\SmsSegments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The switchable SMS layer: both providers are mocked (no real gateway is ever called).
 */
class SmsProviderTest extends TestCase
{
    use RefreshDatabase;

    private const SID = 'AC11111111111111111111111111111111';

    private const TOKEN = 'twilio-token-secret-xyz';

    private string $envDir;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $state = State::create(['name' => 'Eastern Equatoria', 'code' => 'EES']);
        $county = County::create(['name' => 'Kapoeta South County', 'code' => 'KPS', 'state_id' => $state->id]);
        $facility = Facility::create(['name' => 'Kapoeta Civil Hospital', 'code' => 'KCH-001', 'county_id' => $county->id]);
        $this->patient = Patient::create([
            'art_number' => 'ART-SMS-1', 'first_name' => 'Alice', 'last_name' => 'Wanjiku', 'sex' => 'female', 'age' => 28,
            'phone' => '+211911000001', 'art_start_date' => '2023-01-15',
            'state_id' => $state->id, 'county_id' => $county->id, 'facility_id' => $facility->id,
        ]);

        // The Integrations page writes to a throwaway .env, never the real one.
        $this->envDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vilocare-sms-'.uniqid();
        mkdir($this->envDir);
        file_put_contents($this->envDir.'/.env', "APP_NAME=Test\nAFRICASTALKING_USERNAME=\"sandbox\"\nAFRICASTALKING_API_KEY=\"at-secret-key-abcdef\"\nAFRICASTALKING_SANDBOX=\"true\"\n");
        $this->app->useEnvironmentPath($this->envDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->envDir.'/.env');
        @rmdir($this->envDir);
        parent::tearDown();
    }

    private function env(): string
    {
        return file_get_contents($this->envDir.'/.env');
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'admin@example.org', 'role' => UserRole::ADMINISTRATOR,
            'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true,
        ]);
    }

    private function twilio(?string $sender = 'ViLoCare'): TwilioProvider
    {
        return new TwilioProvider(self::SID, self::TOKEN, $sender);
    }

    private function africasTalking(): AfricasTalkingProvider
    {
        return new AfricasTalkingProvider('sandbox', 'at-key', null, true);
    }

    /** A provider that records what it is asked to send instead of calling a gateway. */
    private function mockProvider(string $name, ?SmsResult $result = null, bool $configured = true): SmsProviderInterface
    {
        return new class($name, $result, $configured) implements SmsProviderInterface
        {
            public array $sent = [];

            public function __construct(private string $name, private ?SmsResult $result, private bool $configured) {}

            public function name(): string
            {
                return $this->name;
            }

            public function label(): string
            {
                return ucfirst($this->name);
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function notConfiguredReason(): string
            {
                return "Not sent: {$this->name} is not configured.";
            }

            public function send(string $to, string $message): SmsResult
            {
                $this->sent[] = [$to, $message];

                return $this->result ?? SmsResult::sent('MOCK-1');
            }
        };
    }

    // ---- providers (HTTP mocked) ----

    public function test_twilio_sends_a_form_request_with_basic_auth_and_returns_the_message_sid(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMabc123', 'status' => 'queued'], 201)]);

        $result = $this->twilio()->send('+211911000001', 'Hello Alice, routine wellness review.');

        $this->assertTrue($result->success);
        $this->assertSame('SMabc123', $result->messageId);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::SID.'/Messages.json'
            && $r['To'] === '+211911000001'
            && $r['From'] === 'ViLoCare'
            && $r['Body'] === 'Hello Alice, routine wellness review.'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::SID.':'.self::TOKEN)));
    }

    public function test_twilio_uses_messaging_service_sid_field_and_phone_number_sender(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $this->twilio('MG'.str_repeat('a', 32))->send('+211911000001', 'Hello');
        Http::assertSent(fn (HttpRequest $r) => ($r['MessagingServiceSid'] ?? null) === 'MG'.str_repeat('a', 32) && ! isset($r['From']));

        $this->twilio('+12025550123')->send('+211911000001', 'Hello');
        Http::assertSent(fn (HttpRequest $r) => ($r['From'] ?? null) === '+12025550123');
    }

    public function test_twilio_failure_carries_twilios_own_error_text(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21408, 'message' => 'Permission to send an SMS has not been enabled for the region indicated by the To number.', 'status' => 400], 400)]);

        $result = $this->twilio()->send('+211911000001', 'Hello');

        $this->assertFalse($result->success);
        $this->assertFalse($result->deferred);
        $this->assertStringContainsString('Twilio error 21408: Permission to send an SMS has not been enabled', $result->error);
        $this->assertStringContainsString('HTTP 400', $result->error);
        $this->assertStringNotContainsString(self::TOKEN, $result->error);
    }

    public function test_twilio_unreachable_is_deferred_and_hides_the_account_sid(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host api.twilio.com/Accounts/'.self::SID)]);

        $result = $this->twilio()->send('+211911000001', 'Hello');

        $this->assertTrue($result->deferred);
        $this->assertStringNotContainsString(self::SID, $result->error);
    }

    public function test_africas_talking_provider_results(): void
    {
        Http::fake(['api.sandbox.africastalking.com/*' => Http::sequence()
            ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'status' => 'Success', 'messageId' => 'ATXid_1']]]], 201)
            ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 403, 'status' => 'InvalidPhoneNumber']]]], 201)
            ->push('boom', 500)]);

        $ok = $this->africasTalking()->send('+211911000001', 'Hello');
        $this->assertTrue($ok->success);
        $this->assertSame('ATXid_1', $ok->messageId);

        $refused = $this->africasTalking()->send('+211911000001', 'Hello');
        $this->assertFalse($refused->success);
        $this->assertStringContainsString('InvalidPhoneNumber', $refused->error);

        $http = $this->africasTalking()->send('+211911000001', 'Hello');
        $this->assertSame('HTTP 500: boom', $http->error);
    }

    // ---- SmsService with the selected provider ----

    public function test_the_provider_is_chosen_by_configuration(): void
    {
        config(['services.sms.provider' => 'twilio']);
        $this->assertSame('twilio', SmsProviderFactory::make()->name());
        $this->assertSame('twilio', (new SmsService)->provider()->name());

        config(['services.sms.provider' => 'africastalking']);
        $this->assertSame('africastalking', (new SmsService)->provider()->name());

        config(['services.sms.provider' => 'carrier-pigeon']);
        $this->assertSame('africastalking', SmsProviderFactory::make()->name());
    }

    public function test_service_sends_through_twilio_and_logs_the_provider_and_message_id(): void
    {
        $this->fakeTwilioGateway();

        $log = app(SmsService::class)->send('0911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('sent', $log->status);
        $this->assertSame('twilio', $log->provider);
        $this->assertSame('SM'.str_repeat('1', 32), $log->provider_message_id);
        $this->assertSame('+211911000001', $log->recipient_phone);
        $this->assertDatabaseHas('notification_logs', ['id' => $log->id, 'provider' => 'twilio', 'status' => 'sent']);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.twilio.com'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'africastalking'));
    }

    public function test_service_still_defaults_to_africas_talking_and_logs_it(): void
    {
        $this->fakeSmsGateway();

        $log = app(SmsService::class)->send('+211911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('sent', $log->status);
        $this->assertSame('africastalking', $log->provider);
        $this->assertSame('ATXid_test', $log->provider_message_id);
    }

    public function test_discretion_rules_block_forbidden_words_for_both_providers_before_any_gateway_call(): void
    {
        foreach (['africastalking', 'twilio'] as $name) {
            $provider = $this->mockProvider($name);
            $service = new SmsService($provider);

            foreach (['Hello Alice, your HIV test results are ready.', 'Your viral load is due.', 'Please collect your ARV refill.', 'Your CD4 count is in.'] as $bad) {
                $log = $service->send('+211911000001', $bad, 'custom', $this->patient);

                $this->assertSame('failed', $log->status);
                $this->assertSame($name, $log->provider);
                $this->assertStringContainsString('Privacy Violation', $log->error_message);
                $this->assertDatabaseHas('notification_logs', ['id' => $log->id, 'status' => 'failed', 'provider' => $name]);
            }
            $this->assertSame([], $provider->sent, "{$name} must never receive a forbidden message");
        }
    }

    public function test_a_queued_message_is_rechecked_for_forbidden_words_before_dispatch(): void
    {
        $provider = $this->mockProvider('twilio');
        $log = NotificationLog::create([
            'recipient_phone' => '+211911000001', 'category' => 'custom', 'message_body' => 'Your HIV result', 'status' => 'queued', 'provider' => 'africastalking',
        ]);

        (new SmsService($provider))->dispatchToProvider($log);

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame([], $provider->sent);
    }

    public function test_facility_names_are_still_sanitised_before_they_reach_the_provider(): void
    {
        $provider = $this->mockProvider('twilio');
        $facility = Facility::create(['name' => 'ViLoCare Main ART Centre', 'code' => 'VLC-ART', 'county_id' => $this->patient->county_id]);
        $this->patient->update(['facility_id' => $facility->id]);

        $log = (new SmsService($provider))->sendResultReadyNotification($this->patient->fresh());

        $this->assertSame('sent', $log->status);
        $this->assertStringContainsString('ViLoCare Main Health Centre', $provider->sent[0][1]);
    }

    public function test_provider_failure_leaves_a_failed_row_with_the_providers_reason(): void
    {
        foreach ([['twilio', 'Twilio error 21211: The To number is not a valid phone number. (HTTP 400)'], ['africastalking', 'HTTP 500: gateway down']] as [$name, $error]) {
            $log = (new SmsService($this->mockProvider($name, SmsResult::failed($error))))->send('+211911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

            $this->assertSame('failed', $log->status);
            $this->assertSame($error, $log->error_message);
            $this->assertSame($name, $log->provider);
            $this->assertNull($log->sent_at);
            $this->assertNull($log->provider_message_id);
            $this->assertDatabaseHas('notification_logs', ['id' => $log->id, 'status' => 'failed', 'error_message' => $error]);
        }
    }

    public function test_real_twilio_http_failure_ends_as_a_failed_row_with_twilios_text(): void
    {
        // The first registered fake wins, so register the failure before the helper's accepting one.
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'Authenticate', 'status' => 401], 401)]);
        $this->fakeTwilioGateway();

        $log = app(SmsService::class)->send('+211911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Twilio error 20003: Authenticate', $log->error_message);
        $this->assertSame('twilio', $log->provider);
    }

    public function test_an_unreachable_gateway_keeps_the_message_queued_with_a_reason(): void
    {
        $log = (new SmsService($this->mockProvider('twilio', SmsResult::deferred('Network/Connection deferred: timeout'))))
            ->send('+211911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('queued', $log->status);
        $this->assertStringContainsString('timeout', $log->error_message);
        $this->assertDatabaseHas('notification_logs', ['id' => $log->id, 'status' => 'queued', 'provider' => 'twilio']);
    }

    public function test_missing_credentials_never_record_a_send_and_name_the_providers_settings(): void
    {
        config(['services.sms.provider' => 'twilio', 'services.twilio.account_sid' => null, 'services.twilio.auth_token' => null, 'services.twilio.sender' => null]);
        Http::fake();

        $log = app(SmsService::class)->send('+211911000001', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('failed', $log->status);
        $this->assertSame(TwilioProvider::NOT_CONFIGURED_REASON, $log->error_message);
        $this->assertSame('twilio', $log->provider);
        Http::assertNothingSent();
    }

    public function test_missing_phone_still_logs_a_failed_row_with_the_selected_provider(): void
    {
        $log = (new SmsService($this->mockProvider('twilio')))->send('', 'Hello Alice, routine wellness review notice.', 'custom', $this->patient);

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Missing or invalid recipient phone number', $log->error_message);
        $this->assertSame('twilio', $log->provider);
    }

    public function test_queued_message_is_retried_through_the_provider_selected_now_and_the_row_follows(): void
    {
        $log = NotificationLog::create([
            'recipient_phone' => '+211911000001', 'category' => 'custom', 'message_body' => 'Hello Alice, routine wellness check-up.',
            'status' => 'queued', 'provider' => 'africastalking',
        ]);
        $twilio = $this->mockProvider('twilio', SmsResult::sent('SMretry'));

        (new SmsService($twilio))->dispatchToProvider($log);

        $log->refresh();
        $this->assertSame('sent', $log->status);
        $this->assertSame('twilio', $log->provider);
        $this->assertSame('SMretry', $log->provider_message_id);
        $this->assertCount(1, $twilio->sent);
    }

    // ---- phone numbers and segments ----

    public function test_south_sudan_numbers_are_normalised_to_plus_211(): void
    {
        foreach ([
            '0911000001' => '+211911000001',
            '0 911 000 001' => '+211911000001',
            '911000001' => '+211911000001',
            '111000001' => '+211111000001',
            '211911000001' => '+211911000001',
            '+211911000001' => '+211911000001',
            '+211 (0) 911000001' => '+211911000001',
            '00211911000001' => '+211911000001',
            '+2110911000001' => '+211911000001',
            '+254722000001' => '+254722000001',
            '' => '',
            'NO_PHONE' => '',
        ] as $input => $expected) {
            $this->assertSame($expected, PhoneNumber::normalise((string) $input), "input: '{$input}'");
        }

        $this->assertTrue(PhoneNumber::isValid('+211911000001'));
        $this->assertFalse(PhoneNumber::isValid('+2119'));
        $this->assertSame('+211911000001', app(SmsService::class)->formatPhoneNumber('0911000001'));
    }

    public function test_segment_counting_uses_160_for_gsm_and_70_for_special_characters(): void
    {
        $this->assertSame(1, SmsSegments::analyse(str_repeat('a', 160))['segments']);
        $two = SmsSegments::analyse(str_repeat('a', 161));
        $this->assertSame(2, $two['segments']);
        $this->assertTrue($two['multipart']);
        $this->assertSame(2, SmsSegments::analyse(str_repeat('a', 306))['segments']);
        $this->assertSame(3, SmsSegments::analyse(str_repeat('a', 307))['segments']);

        // A character outside the GSM alphabet makes the whole message UCS-2: 70 per part.
        $this->assertSame('ucs2', SmsSegments::analyse('Hello ć')['encoding']);
        $this->assertSame(1, SmsSegments::analyse(str_repeat('ć', 70))['segments']);
        $this->assertSame(2, SmsSegments::analyse(str_repeat('ć', 71))['segments']);
        $this->assertSame(2, SmsSegments::analyse('😀'.str_repeat('a', 69))['segments']); // emoji = 2 units: 71

        // Extended GSM characters count double.
        $this->assertSame(1, SmsSegments::analyse(str_repeat('[', 80))['segments']);
        $this->assertSame(2, SmsSegments::analyse(str_repeat('[', 81))['segments']);

        $this->assertNull(SmsSegments::warning('Short and neutral.', 'twilio'));
        $this->assertStringContainsString('does not support multi-part', SmsSegments::warning(str_repeat('a', 161), 'twilio'));
        $this->assertStringNotContainsString('does not support multi-part', SmsSegments::warning(str_repeat('a', 161), 'africastalking'));
    }

    public function test_composing_a_long_message_warns_about_the_extra_part(): void
    {
        $this->fakeTwilioGateway();
        $clinician = User::create(['name' => 'Dr J', 'email' => 'j@example.org', 'role' => UserRole::CLINICIAN, 'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true]);

        $response = $this->actingAs($clinician)->post(route('notifications.store'), [
            'patient_id' => $this->patient->id, 'recipient_phone' => '0911000001', 'category' => 'custom',
            'message_body' => 'Hello Alice, your clinic visit is ćć'.str_repeat('x', 60), // special character: limit 70
        ]);

        $response->assertRedirect(route('notifications.index'));
        $this->assertStringContainsString('SMS parts', session('success'));
        $this->assertStringContainsString('Twilio does not support multi-part', session('success'));
    }

    // ---- Integrations page ----

    public function test_page_shows_only_the_chosen_providers_fields(): void
    {
        $admin = $this->admin();

        $page = $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertOk()->assertSee('SMS provider');
        $this->assertMatchesRegularExpression('/data-sms-provider="twilio"\s+hidden/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-sms-provider="africastalking"\s+hidden/', $page->getContent());

        file_put_contents($this->envDir.'/.env', $this->env()."SMS_PROVIDER=\"twilio\"\n");
        $page = $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertOk();
        $this->assertDoesNotMatchRegularExpression('/data-sms-provider="twilio"\s+hidden/', $page->getContent());
        $this->assertMatchesRegularExpression('/data-sms-provider="africastalking"\s+hidden/', $page->getContent());
    }

    public function test_twilio_settings_are_saved_on_this_computer_and_never_shown_again(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), [
            'sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare',
        ])->assertRedirect(route('settings.integrations.edit'))->assertSessionHasNoErrors();

        $this->assertStringContainsString('SMS_PROVIDER="twilio"', $this->env());
        $this->assertStringContainsString('TWILIO_ACCOUNT_SID="'.self::SID.'"', $this->env());
        $this->assertStringContainsString('TWILIO_AUTH_TOKEN="'.self::TOKEN.'"', $this->env());
        $this->assertStringContainsString('TWILIO_SENDER="ViLoCare"', $this->env());
        $this->assertStringContainsString('AFRICASTALKING_API_KEY="at-secret-key-abcdef"', $this->env()); // other provider untouched

        $page = $this->actingAs($admin)->get(route('settings.integrations.edit'))->assertOk();
        foreach ([self::SID, self::TOKEN, substr(self::SID, -4), substr(self::TOKEN, -4), 'at-secret-key-abcdef'] as $secret) {
            $page->assertDontSee($secret, false);
        }
        $page->assertSee('value="ViLoCare"', false); // the sender is not a secret

        $audit = json_encode(AuditLog::all()->toArray());
        $this->assertStringNotContainsString(self::TOKEN, $audit);
        $this->assertStringNotContainsString(self::SID, $audit);
        $this->assertTrue(AuditLog::where('action', 'updated_sms_integration')->exists());

        // Blank secrets keep the saved ones.
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), ['sms_provider' => 'twilio', 'twilio_sid' => '', 'twilio_token' => '', 'twilio_sender' => '+12025550123'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('TWILIO_AUTH_TOKEN="'.self::TOKEN.'"', $this->env());
        $this->assertStringContainsString('TWILIO_SENDER="+12025550123"', $this->env());
    }

    public function test_switching_back_to_africas_talking_keeps_the_twilio_secrets(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), ['sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare']);

        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), ['sms_provider' => 'africastalking', 'sms_username' => 'sandbox', 'sms_key' => '', 'sandbox' => '1'])->assertSessionHasNoErrors();

        $this->assertStringContainsString('SMS_PROVIDER="africastalking"', $this->env());
        $this->assertStringContainsString('TWILIO_AUTH_TOKEN="'.self::TOKEN.'"', $this->env());
    }

    public function test_twilio_fields_are_validated(): void
    {
        $admin = $this->admin();
        $base = ['sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare'];

        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['twilio_sid' => 'not-a-sid']))->assertSessionHasErrors('twilio_sid');
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['twilio_token' => 'bad"token']))->assertSessionHasErrors('twilio_token');
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['twilio_sender' => 'Far Too Long Sender']))->assertSessionHasErrors('twilio_sender');
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['twilio_sender' => '12345']))->assertSessionHasErrors('twilio_sender');
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['twilio_sid' => '', 'twilio_token' => '']))->assertSessionHasErrors(['twilio_sid', 'twilio_token']);
        $this->actingAs($admin)->put(route('settings.integrations.sms.update'), array_merge($base, ['sms_provider' => 'pigeon']))->assertSessionHasErrors('sms_provider');
        $this->assertStringNotContainsString('TWILIO', $this->env());
    }

    public function test_sms_actions_are_admin_only(): void
    {
        $clinician = User::create(['name' => 'Dr J', 'email' => 'j@example.org', 'role' => UserRole::CLINICIAN, 'password' => 'Password-12345', 'must_change_password' => false, 'is_active' => true]);
        Http::fake();

        $this->actingAs($clinician)->postJson(route('settings.integrations.sms.send-test'), ['sms_provider' => 'twilio', 'test_phone' => '0911000001'])->assertForbidden();
        Http::assertNothingSent();
    }

    // ---- Test buttons ----

    public function test_twilio_credential_test_reads_the_account_without_sending_an_sms(): void
    {
        Http::fake(['api.twilio.com/*' => Http::sequence()
            ->push(['sid' => self::SID, 'status' => 'active'], 200)
            ->push(['code' => 20003, 'message' => 'Authenticate', 'status' => 401], 401)]);
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('settings.integrations.sms.test'), ['sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN])
            ->assertOk()->assertJson(['ok' => true])
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'No SMS was sent'));
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/Accounts/'.self::SID.'.json') && ! str_contains($r->url(), self::TOKEN));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'Messages'));

        $bad = $this->actingAs($admin)->postJson(route('settings.integrations.sms.test'), ['sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN])
            ->assertJson(['ok' => false]);
        $this->assertStringContainsString('rejected', $bad->json('message'));
        $this->assertStringNotContainsString(self::TOKEN, $bad->getContent());
    }

    public function test_send_test_sms_through_twilio_normalises_the_number_and_reports_the_message_id(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SMtest1', 'status' => 'queued'], 201)]);

        $response = $this->actingAs($this->admin())->postJson(route('settings.integrations.sms.send-test'), [
            'sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare', 'test_phone' => '0911000001',
        ])->assertOk()->assertJson(['ok' => true, 'to' => '+211911000001', 'message_id' => 'SMtest1']);

        $this->assertStringContainsString('Twilio accepted the test SMS for +211911000001', $response->json('message'));
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        Http::assertSent(fn (HttpRequest $r) => $r['To'] === '+211911000001' && $r['Body'] === IntegrationTester::TEST_MESSAGE);
        // It is a diagnostic: it uses typed credentials and does not write a patient log row.
        $this->assertSame(0, NotificationLog::count());
    }

    public function test_send_test_sms_shows_the_providers_real_error_text(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 21408, 'message' => 'Permission to send an SMS has not been enabled for the region indicated by the To number.', 'status' => 400], 400)]);

        $response = $this->actingAs($this->admin())->postJson(route('settings.integrations.sms.send-test'), [
            'sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare', 'test_phone' => '+211911000001',
        ])->assertOk()->assertJson(['ok' => false]);

        $this->assertStringContainsString('Twilio could not send the test SMS to +211911000001', $response->json('message'));
        $this->assertStringContainsString('Twilio error 21408: Permission to send an SMS has not been enabled', $response->json('message'));
    }

    public function test_send_test_sms_through_africas_talking_uses_the_saved_key(): void
    {
        Http::fake(['api.sandbox.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'status' => 'Success', 'messageId' => 'ATXid_9']]]], 201)]);

        $this->actingAs($this->admin())->postJson(route('settings.integrations.sms.send-test'), [
            'sms_provider' => 'africastalking', 'sms_username' => 'sandbox', 'sms_key' => '', 'sandbox' => true, 'test_phone' => '911000001',
        ])->assertOk()->assertJson(['ok' => true, 'to' => '+211911000001', 'message_id' => 'ATXid_9']);

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('apiKey', 'at-secret-key-abcdef') && $r['to'] === '+211911000001');
    }

    public function test_send_test_sms_rejects_a_bad_number_and_missing_credentials_without_calling_a_gateway(): void
    {
        Http::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('settings.integrations.sms.send-test'), ['sms_provider' => 'twilio', 'twilio_sid' => self::SID, 'twilio_token' => self::TOKEN, 'twilio_sender' => 'ViLoCare', 'test_phone' => '12'])
            ->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'valid mobile number'));

        $this->actingAs($admin)->postJson(route('settings.integrations.sms.send-test'), ['sms_provider' => 'twilio', 'test_phone' => '0911000001'])
            ->assertJson(['ok' => false])->assertJsonPath('message', fn ($m) => str_contains($m, 'Twilio Account SID'));

        $this->actingAs($admin)->postJson(route('settings.integrations.sms.send-test'), ['sms_provider' => 'twilio', 'test_phone' => ''])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_the_test_message_itself_passes_the_privacy_rules(): void
    {
        app(SmsService::class)->validateDiscretion(IntegrationTester::TEST_MESSAGE);

        $this->assertSame(1, SmsSegments::analyse(IntegrationTester::TEST_MESSAGE)['segments']);
    }

    // ---- delivery comparison ----

    public function test_logs_page_shows_and_filters_by_provider(): void
    {
        $base = ['recipient_phone' => '+211911000001', 'category' => 'custom', 'status' => 'sent'];
        NotificationLog::create($base + ['message_body' => 'Hello via twilio row', 'provider' => 'twilio']);
        NotificationLog::create($base + ['message_body' => 'Hello via at row', 'provider' => 'africastalking']);
        $admin = $this->admin();

        $all = $this->actingAs($admin)->get(route('notifications.index'))->assertOk();
        $all->assertSee('Hello via twilio row')->assertSee('Hello via at row')->assertSee('Twilio')->assertSee("Africa's Talking");

        $this->actingAs($admin)->get(route('notifications.index', ['provider' => 'twilio']))
            ->assertSee('Hello via twilio row')->assertDontSee('Hello via at row');
    }
}
