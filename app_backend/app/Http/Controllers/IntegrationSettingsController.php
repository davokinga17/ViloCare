<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AiInsightService;
use App\Services\IntegrationTester;
use App\Services\Sms\AfricasTalkingProvider;
use App\Services\Sms\SmsProviderFactory;
use App\Services\Sms\TwilioProvider;
use App\Support\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Integrations" settings (administrators only; see routes/web.php): Gemini and Africa's Talking credentials.
 * Values live in the local .env like the Sync connection; keys are never shown, logged, audited or flashed back.
 * A saved change applies to the next request / scheduled run because each PHP process re-reads .env.
 */
class IntegrationSettingsController extends Controller
{
    public function __construct(protected IntegrationTester $tester) {}

    public function edit(): View
    {
        $sandbox = EnvFile::get('AFRICASTALKING_SANDBOX');

        return view('settings.integrations', [
            'hasGeminiKey' => EnvFile::get('GEMINI_API_KEY') !== null,
            'geminiModel' => $this->currentGeminiModel(),
            'smsProviders' => SmsProviderFactory::PROVIDERS,
            'smsProvider' => EnvFile::get('SMS_PROVIDER') ?: SmsProviderFactory::DEFAULT,
            'hasTwilioSid' => EnvFile::get('TWILIO_ACCOUNT_SID') !== null,
            'hasTwilioToken' => EnvFile::get('TWILIO_AUTH_TOKEN') !== null,
            'twilioSender' => EnvFile::get('TWILIO_SENDER'),
            'hasSmsKey' => EnvFile::get('AFRICASTALKING_API_KEY') !== null,
            'smsUsername' => EnvFile::get('AFRICASTALKING_USERNAME') ?? 'sandbox',
            'smsSenderId' => EnvFile::get('AFRICASTALKING_SENDER_ID'),
            'smsSandbox' => $sandbox === null ? true : filter_var($sandbox, FILTER_VALIDATE_BOOLEAN),
            'mask' => str_repeat('•', 12),
        ]);
    }

    public function updateGemini(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'gemini_key' => [EnvFile::get('GEMINI_API_KEY') === null ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[^"\\\\\s]+$/'],
            'gemini_model' => $this->modelRules(),
        ], $this->messages());

        $values = [];
        if (! empty($data['gemini_key'])) {
            $values['GEMINI_API_KEY'] = $data['gemini_key'];
        }
        $modelChanged = ! empty($data['gemini_model']) && $data['gemini_model'] !== $this->savedGeminiModel();
        if ($modelChanged) {
            $values['VILOCARE_GEMINI_MODEL'] = $data['gemini_model'];
        }

        if ($values !== []) {
            if (! $this->save($values)) {
                return back()->withErrors(['gemini_key' => $this->saveFailed()]);
            }
            // A "key missing" or "model not available" notice may have been cached as today's summary.
            Cache::forget(AiInsightService::CACHE_KEY_DAILY_SUMMARY);
            Cache::forget(AiInsightService::CACHE_KEY_DAILY_SUMMARY_TIME);
            $this->audit('updated_gemini_integration', ['key_changed' => isset($values['GEMINI_API_KEY']), 'model' => $values['VILOCARE_GEMINI_MODEL'] ?? null]);
        }

        return redirect()->route('settings.integrations.edit')->with('success', 'Gemini settings saved. They are used from the next request.');
    }

    public function updateSms(Request $request): RedirectResponse
    {
        $provider = $this->chosenSmsProvider($request);
        $old = ['provider' => EnvFile::get('SMS_PROVIDER') ?? SmsProviderFactory::DEFAULT];

        if ($provider === 'twilio') {
            $data = $request->validate($this->twilioRules(secretsRequired: true), $this->messages());
            $values = ['SMS_PROVIDER' => 'twilio', 'TWILIO_SENDER' => $data['twilio_sender']];
            foreach (['twilio_sid' => 'TWILIO_ACCOUNT_SID', 'twilio_token' => 'TWILIO_AUTH_TOKEN'] as $field => $envKey) {
                if (! empty($data[$field])) {
                    $values[$envKey] = $data[$field];
                }
            }
            $old['sender'] = EnvFile::get('TWILIO_SENDER');
            $new = ['provider' => 'twilio', 'sender' => $values['TWILIO_SENDER'], 'secrets_changed' => isset($values['TWILIO_ACCOUNT_SID']) || isset($values['TWILIO_AUTH_TOKEN'])];
            $label = 'Twilio';
        } else {
            $data = $request->validate($this->smsRules(keyRequired: EnvFile::get('AFRICASTALKING_API_KEY') === null), $this->messages());
            $sandbox = $request->boolean('sandbox');
            $this->assertSandboxUsername($data['sms_username'], $sandbox);

            $values = [
                'SMS_PROVIDER' => 'africastalking',
                'AFRICASTALKING_USERNAME' => $data['sms_username'],
                'AFRICASTALKING_SENDER_ID' => $data['sms_sender_id'] ?? '',
                'AFRICASTALKING_SANDBOX' => $sandbox ? 'true' : 'false',
            ];
            if (! empty($data['sms_key'])) {
                $values['AFRICASTALKING_API_KEY'] = $data['sms_key'];
            }
            $old += ['username' => EnvFile::get('AFRICASTALKING_USERNAME'), 'sender_id' => EnvFile::get('AFRICASTALKING_SENDER_ID'), 'sandbox' => EnvFile::get('AFRICASTALKING_SANDBOX')];
            $new = ['provider' => 'africastalking', 'username' => $values['AFRICASTALKING_USERNAME'], 'sender_id' => $values['AFRICASTALKING_SENDER_ID'], 'sandbox' => $sandbox, 'key_changed' => isset($values['AFRICASTALKING_API_KEY'])];
            $label = "Africa's Talking";
        }

        if (! $this->save($values)) {
            return back()->withErrors([$provider === 'twilio' ? 'twilio_sender' : 'sms_username' => $this->saveFailed()]);
        }
        $this->audit('updated_sms_integration', $new, $old);

        return redirect()->route('settings.integrations.edit')->with('success', "SMS provider set to {$label} and its settings saved. They are used from the next request.");
    }

    public function testGemini(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gemini_key' => ['nullable', 'string', 'max:255', 'regex:/^[^"\\\\\s]+$/'],
            'gemini_model' => $this->modelRules(),
        ], $this->messages());
        $key = ! empty($data['gemini_key']) ? $data['gemini_key'] : EnvFile::get('GEMINI_API_KEY');

        if ($key === null) {
            return response()->json(['ok' => false, 'message' => 'Enter the Gemini API key first.']);
        }

        return response()->json($this->tester->testGemini($key, (! empty($data['gemini_model']) ? $data['gemini_model'] : $this->currentGeminiModel())));
    }

    /** The model saved on this computer from this page, if any. */
    protected function savedGeminiModel(): ?string
    {
        return EnvFile::get('VILOCARE_GEMINI_MODEL');
    }

    /** What the page shows and the Test button checks: saved choice, else GEMINI_MODEL in .env, else the config default. */
    protected function currentGeminiModel(): string
    {
        return $this->savedGeminiModel() ?? EnvFile::get('GEMINI_MODEL') ?? (string) config('services.gemini.default_model');
    }

    /**
     * @return array<int, string>
     */
    protected function modelRules(): array
    {
        return ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'];
    }

    /** "Test": checks the credentials of the chosen provider without sending an SMS. */
    public function testSms(Request $request): JsonResponse
    {
        if ($this->chosenSmsProvider($request) === 'twilio') {
            $data = $request->validate($this->twilioRules(secretsRequired: false), $this->messages());
            $sid = ! empty($data['twilio_sid']) ? $data['twilio_sid'] : EnvFile::get('TWILIO_ACCOUNT_SID');
            $token = ! empty($data['twilio_token']) ? $data['twilio_token'] : EnvFile::get('TWILIO_AUTH_TOKEN');

            if ($sid === null || $token === null) {
                return response()->json(['ok' => false, 'message' => 'Enter the Twilio Account SID and Auth Token first.']);
            }

            return response()->json($this->tester->testTwilio($sid, $token));
        }

        $data = $request->validate($this->smsRules(keyRequired: false), $this->messages());
        $key = ! empty($data['sms_key']) ? $data['sms_key'] : EnvFile::get('AFRICASTALKING_API_KEY');

        if ($key === null) {
            return response()->json(['ok' => false, 'message' => "Enter the Africa's Talking API key first."]);
        }

        $sandbox = $request->boolean('sandbox');
        $this->assertSandboxUsername($data['sms_username'], $sandbox);

        return response()->json($this->tester->testAfricasTalking($data['sms_username'], $key, $sandbox));
    }

    /** "Send test SMS": one real message through the chosen provider, using typed or saved credentials. */
    public function sendTestSms(Request $request): JsonResponse
    {
        $provider = $this->chosenSmsProvider($request);
        $rules = ['test_phone' => ['required', 'string', 'max:25']];

        if ($provider === 'twilio') {
            $data = $request->validate($rules + $this->twilioRules(secretsRequired: false), $this->messages());
            $gateway = new TwilioProvider(
                ! empty($data['twilio_sid']) ? $data['twilio_sid'] : EnvFile::get('TWILIO_ACCOUNT_SID'),
                ! empty($data['twilio_token']) ? $data['twilio_token'] : EnvFile::get('TWILIO_AUTH_TOKEN'),
                ! empty($data['twilio_sender']) ? $data['twilio_sender'] : EnvFile::get('TWILIO_SENDER'),
            );
        } else {
            $data = $request->validate($rules + $this->smsRules(keyRequired: false), $this->messages());
            $sandbox = $request->boolean('sandbox');
            $this->assertSandboxUsername($data['sms_username'], $sandbox);
            $gateway = new AfricasTalkingProvider(
                $data['sms_username'],
                ! empty($data['sms_key']) ? $data['sms_key'] : EnvFile::get('AFRICASTALKING_API_KEY'),
                $data['sms_sender_id'] ?? null,
                $sandbox,
            );
        }

        return response()->json($this->tester->sendTestSms($gateway, $data['test_phone']));
    }

    /** The provider picked in the form, else the one saved on this computer, else Africa's Talking. */
    protected function chosenSmsProvider(Request $request): string
    {
        $request->validate(['sms_provider' => ['nullable', Rule::in(array_keys(SmsProviderFactory::PROVIDERS))]]);

        return $request->input('sms_provider') ?: (EnvFile::get('SMS_PROVIDER') ?: SmsProviderFactory::DEFAULT);
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function twilioRules(bool $secretsRequired): array
    {
        $needs = fn (string $envKey) => $secretsRequired && EnvFile::get($envKey) === null ? 'required' : 'nullable';

        return [
            'twilio_sid' => [$needs('TWILIO_ACCOUNT_SID'), 'string', 'max:64', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'twilio_token' => [$needs('TWILIO_AUTH_TOKEN'), 'string', 'max:100', 'regex:/^[^"\\\\\s]+$/'],
            // An alphanumeric sender ID (max 11, with a letter), a phone number (+...) or a Messaging Service SID (MG...).
            'twilio_sender' => [$secretsRequired ? 'required' : 'nullable', 'string', 'max:40', 'regex:/^(\+[1-9]\d{6,14}|MG[0-9a-fA-F]{32}|(?=.*[A-Za-z])[A-Za-z0-9 ._-]{1,11})$/'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function smsRules(bool $keyRequired): array
    {
        return [
            'sms_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'sms_key' => [$keyRequired ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[^"\\\\\s]+$/'],
            'sms_sender_id' => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9 ._-]+$/'],
        ];
    }

    protected function assertSandboxUsername(string $username, bool $sandbox): void
    {
        if ($sandbox && strtolower($username) !== 'sandbox') {
            throw ValidationException::withMessages([
                'sms_username' => 'Sandbox mode only works with the username "sandbox". Switch sandbox off to use your live username.',
            ]);
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function save(array $values): bool
    {
        try {
            EnvFile::set($values);

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function saveFailed(): string
    {
        return 'The settings could not be saved on this computer. Check the logs folder for details.';
    }

    /**
     * @param  array<string, mixed>  $new  never contains a key, only "key_changed"
     * @param  array<string, mixed>  $old
     */
    protected function audit(string $action, array $new, array $old = []): void
    {
        AuditLog::log(
            action: $action,
            tableName: 'settings',
            recordId: 'INTEGRATIONS',
            oldValues: $old,
            newValues: $new,
            userId: auth()->id()
        );
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'gemini_key.regex' => 'The key cannot contain spaces, quotes, backslashes or line breaks.',
            'twilio_sid.regex' => 'The Account SID starts with AC followed by 32 letters or digits.',
            'twilio_token.regex' => 'The Auth Token cannot contain spaces, quotes, backslashes or line breaks.',
            'twilio_sender.regex' => 'The sender must be a phone number starting with + (e.g. +12025550123), a Messaging Service SID (starts with MG), or a sender ID of up to 11 letters and digits that includes at least one letter.',
            'gemini_model.regex' => 'The model name can only contain letters, numbers, dots, dashes and underscores.',
            'sms_key.regex' => 'The key cannot contain spaces, quotes, backslashes or line breaks.',
            'sms_username.regex' => 'The username can only contain letters, numbers, dots, dashes and underscores.',
            'sms_sender_id.regex' => 'The sender ID can only contain letters, numbers, spaces, dots, dashes and underscores (maximum 11 characters).',
        ];
    }
}
