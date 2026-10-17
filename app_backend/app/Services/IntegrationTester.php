<?php

namespace App\Services;

use App\Services\Sms\SmsProviderInterface;
use App\Services\Sms\TwilioProvider;
use App\Support\Integrations;
use App\Support\PhoneNumber;
use App\Support\SmsSegments;
use Illuminate\Support\Facades\Http;

/**
 * Credential checks for the Integrations settings page. Neither check sends an SMS, and keys travel only in
 * request headers, so they can never appear in a URL, an exception message, a log line or a response.
 */
class IntegrationTester
{
    /** Neutral wording on purpose: it passes the same privacy rules as patient messages. */
    public const TEST_MESSAGE = 'Test message from ViLoCare. Your SMS settings are working.';

    /**
     * Lists the models this key can use (Google's list-models endpoint; nothing is generated) and checks that
     * $model is one of those that support generateContent.
     *
     * @return array{ok: bool, message: string, models?: array<int, array{name: string, label: string}>, model?: string, model_available?: bool, suggestion?: ?string, warning?: bool}
     */
    public function testGemini(string $apiKey, ?string $model = null): array
    {
        $model = $model ?: Integrations::geminiModel();

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->connectTimeout(5)
                ->timeout(20)
                ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1000]);
        } catch (\Throwable $e) {
            return $this->connectionFailure($e);
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => match (true) {
                in_array($response->status(), [400, 401, 403], true) => 'Google rejected the key. Check that it is a valid Google AI Studio key (it normally starts with AIza) and try again.',
                $response->status() === 429 => 'The key was reached but Google says the rate or quota limit is used up. Try again in a minute.',
                $response->serverError() => 'Google reported a problem (HTTP '.$response->status().'). Try again in a few minutes.',
                default => 'Google answered with an unexpected response (HTTP '.$response->status().').',
            }];
        }

        $models = collect($response->json('models', []))
            ->filter(fn ($m) => is_array($m) && in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
            ->map(fn ($m) => ['name' => preg_replace('#^models/#', '', (string) ($m['name'] ?? '')), 'label' => (string) ($m['displayName'] ?? '')])
            ->filter(fn ($m) => $m['name'] !== '')
            ->sortBy(fn ($m) => (str_contains($m['name'], 'flash') ? '0' : '1').$m['name'])
            ->values()
            ->all();

        $names = array_column($models, 'name');
        $available = in_array($model, $names, true);
        $suggestion = $available ? null : $this->closestFlashModel($model, $names);

        $message = match (true) {
            $models === [] => 'Connected, but this key has no models that support generateContent.',
            $available => 'Connected. Google accepted the key and "'.$model.'" is available ('.count($models).' models can be used).',
            $suggestion !== null => 'Connected, but the saved model "'.$model.'" is not available to this key. The closest flash model is "'.$suggestion.'": choose it below and save.',
            default => 'Connected, but the saved model "'.$model.'" is not available to this key. Choose one from the list below and save.',
        };

        return [
            'ok' => true,
            'message' => $message,
            'models' => $models,
            'model' => $model,
            'model_available' => $available,
            'suggestion' => $suggestion,
            'warning' => ! $available,
        ];
    }

    /**
     * The "flash" model whose name is most like $wanted; plain flash models beat lite/image/audio/preview variants.
     *
     * @param  array<int, string>  $names
     */
    protected function closestFlashModel(string $wanted, array $names): ?string
    {
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach ($names as $name) {
            if (! str_contains($name, 'flash')) {
                continue;
            }
            $score = levenshtein($wanted, $name) + (preg_match('/lite|image|tts|audio|live|embed|preview|exp|latest/', $name) ? 20 : 0);
            if ($score < $bestScore) {
                $best = $name;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Reads the account record (GET /version1/user), which validates username + key without sending anything.
     *
     * @return array{ok: bool, message: string}
     */
    public function testAfricasTalking(string $username, string $apiKey, bool $sandbox): array
    {
        $base = $sandbox ? 'https://api.sandbox.africastalking.com' : 'https://api.africastalking.com';

        try {
            $response = Http::withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->connectTimeout(5)
                ->timeout(15)
                ->get("{$base}/version1/user", ['username' => $username]);
        } catch (\Throwable $e) {
            return $this->connectionFailure($e);
        }

        if ($response->successful()) {
            $balance = $response->json('UserData.balance');
            $suffix = is_string($balance) && $balance !== '' ? " Account balance: {$balance}." : '';

            return ['ok' => true, 'message' => "Connected. Africa's Talking accepted the username and API key ".($sandbox ? '(sandbox)' : '(live)').".{$suffix} No SMS was sent."];
        }

        return ['ok' => false, 'message' => match (true) {
            in_array($response->status(), [401, 403], true) => "Africa's Talking rejected the username or API key for the ".($sandbox ? 'sandbox' : 'live').' environment. Sandbox keys only work with sandbox switched on, and live keys only with it off.',
            $response->serverError() => "Africa's Talking reported a problem (HTTP ".$response->status().'). Try again in a few minutes.',
            default => "Africa's Talking answered with an unexpected response (HTTP ".$response->status().').',
        }];
    }

    /**
     * Reads the Twilio account record, which validates the Account SID + Auth Token without sending anything.
     *
     * @return array{ok: bool, message: string}
     */
    public function testTwilio(string $accountSid, string $authToken): array
    {
        try {
            $response = Http::withBasicAuth($accountSid, $authToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}.json");
        } catch (\Throwable $e) {
            return $this->connectionFailure(new \RuntimeException(str_replace($accountSid, '[account]', $e->getMessage())));
        }

        if ($response->successful()) {
            $status = (string) $response->json('status');
            $note = $status !== '' && $status !== 'active' ? " The account status is \"{$status}\"." : '';

            return ['ok' => true, 'message' => "Connected. Twilio accepted the Account SID and Auth Token.{$note} No SMS was sent."];
        }

        return ['ok' => false, 'message' => match (true) {
            in_array($response->status(), [401, 403, 404], true) => 'Twilio rejected the Account SID or Auth Token ('.TwilioProvider::errorText($response->status(), $response->json(), $response->body()).').',
            $response->serverError() => 'Twilio reported a problem (HTTP '.$response->status().'). Try again in a few minutes.',
            default => 'Twilio answered with an unexpected response ('.TwilioProvider::errorText($response->status(), $response->json(), $response->body()).').',
        }];
    }

    /**
     * Sends one real SMS through $provider and reports the provider's own words. It bypasses the patient message
     * queue on purpose: it uses the typed (maybe not yet saved) credentials and writes no notification log row.
     *
     * @return array{ok: bool, message: string, to?: string, message_id?: ?string, warning?: ?string}
     */
    public function sendTestSms(SmsProviderInterface $provider, string $phone): array
    {
        $to = PhoneNumber::normalise($phone);
        if (! PhoneNumber::isValid($to)) {
            return ['ok' => false, 'message' => 'Enter a valid mobile number, for example 0911000000 or +211911000000.'];
        }

        if (! $provider->isConfigured()) {
            return ['ok' => false, 'message' => $provider->notConfiguredReason()];
        }

        $warning = SmsSegments::warning(self::TEST_MESSAGE, $provider->name());
        $result = $provider->send($to, self::TEST_MESSAGE);

        if ($result->success) {
            return [
                'ok' => true,
                'message' => "{$provider->label()} accepted the test SMS for {$to}".($result->messageId ? " (message id {$result->messageId})" : '').'. Check that the phone receives it: delivery is not confirmed here.',
                'to' => $to,
                'message_id' => $result->messageId,
                'warning' => $warning,
            ];
        }

        return [
            'ok' => false,
            'message' => $result->deferred
                ? "Could not reach {$provider->label()}. {$result->error}"
                : "{$provider->label()} could not send the test SMS to {$to}: {$result->error}",
            'to' => $to,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    protected function connectionFailure(\Throwable $e): array
    {
        $friendly = SyncService::formatConnectionError($e->getMessage());

        return ['ok' => false, 'message' => $friendly['friendly_message'].' '.$friendly['action_hint']];
    }
}
