<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio Programmable Messaging over the REST API (no SDK).
 * The sender may be an alphanumeric sender ID, a phone number (+...) or a Messaging Service SID (MG...).
 */
class TwilioProvider implements SmsProviderInterface
{
    public const NOT_CONFIGURED_REASON = 'Not sent: the Twilio Account SID, Auth Token or Sender is not set. An administrator can add them under Settings > Integrations.';

    public function __construct(
        protected ?string $accountSid,
        protected ?string $authToken,
        protected ?string $sender,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('services.twilio.account_sid'),
            config('services.twilio.auth_token'),
            config('services.twilio.sender'),
        );
    }

    public function name(): string
    {
        return 'twilio';
    }

    public function label(): string
    {
        return 'Twilio';
    }

    public function isConfigured(): bool
    {
        return filled($this->accountSid) && filled($this->authToken) && filled($this->sender);
    }

    public function notConfiguredReason(): string
    {
        return self::NOT_CONFIGURED_REASON;
    }

    public function send(string $to, string $message): SmsResult
    {
        $payload = ['To' => $to, 'Body' => $message];
        // A Messaging Service SID goes in its own field; sender IDs and numbers both go in From.
        $payload[preg_match('/^MG[0-9a-f]{32}$/i', (string) $this->sender) ? 'MessagingServiceSid' : 'From'] = $this->sender;

        try {
            $response = Http::asForm()
                ->withBasicAuth((string) $this->accountSid, (string) $this->authToken)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", $payload);
        } catch (\Throwable $e) {
            $reason = $this->scrub($e->getMessage());
            Log::warning('Twilio SMS delivery offline/failure: '.$reason);

            return SmsResult::deferred('Network/Connection deferred: '.$reason);
        }

        if ($response->successful()) {
            return SmsResult::sent($response->json('sid'));
        }

        $error = $this->errorText($response->status(), $response->json(), $response->body());
        Log::error("Twilio API Error: {$error}");

        return SmsResult::failed($error);
    }

    /** Twilio's own words: its error code and message (e.g. "21608 ..."), never just a status number. */
    public static function errorText(int $status, mixed $json, string $body): string
    {
        if (is_array($json) && isset($json['message'])) {
            return 'Twilio error '.($json['code'] ?? 'n/a').': '.$json['message'].' (HTTP '.$status.')';
        }

        return 'HTTP '.$status.': '.mb_substr($body, 0, 300);
    }

    /** Keep the Account SID out of any text that reaches the log or the screen. */
    protected function scrub(string $text): string
    {
        return $this->accountSid ? str_replace($this->accountSid, '[account]', $text) : $text;
    }
}
