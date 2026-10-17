<?php

namespace App\Services\Sms;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AfricasTalkingProvider implements SmsProviderInterface
{
    public const NOT_CONFIGURED_REASON = "Not sent: no Africa's Talking API key is set. An administrator can add it under Settings > Integrations.";

    public function __construct(
        protected string $username,
        protected ?string $apiKey,
        protected ?string $senderId,
        protected bool $sandbox,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.africastalking.username', 'sandbox'),
            config('services.africastalking.api_key'),
            config('services.africastalking.sender_id'),
            (bool) config('services.africastalking.sandbox', true),
        );
    }

    public function name(): string
    {
        return 'africastalking';
    }

    public function label(): string
    {
        return "Africa's Talking";
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function notConfiguredReason(): string
    {
        return self::NOT_CONFIGURED_REASON;
    }

    public function send(string $to, string $message): SmsResult
    {
        $endpoint = $this->sandbox
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        try {
            $payload = ['username' => $this->username, 'to' => $to, 'message' => $message];
            if (! empty($this->senderId)) {
                $payload['from'] = $this->senderId;
            }

            $response = Http::asForm()
                ->withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                ->withOptions(['verify' => ! $this->sandbox && ! app()->isLocal()])
                ->timeout(10)
                ->post($endpoint, $payload);

            if ($response->successful()) {
                $recipient = $response->json('SMSMessageData.Recipients.0');

                // Africa's Talking answers 201 even when it refuses the number: codes 400+ are refusals.
                if (is_array($recipient) && (int) ($recipient['statusCode'] ?? 0) >= 400) {
                    $error = "Africa's Talking refused the message: ".($recipient['status'] ?? 'unknown reason').' (code '.$recipient['statusCode'].').';
                    Log::error($error);

                    return SmsResult::failed($error);
                }

                return SmsResult::sent($recipient['messageId'] ?? 'AT-'.uniqid());
            }

            $error = 'HTTP '.$response->status().': '.$response->body();
            Log::error("Africa's Talking API Error: {$error}");

            return SmsResult::failed($error);
        } catch (Exception $e) {
            Log::warning("Africa's Talking SMS delivery offline/failure: ".$e->getMessage());

            // The message stays queued for a retry when connectivity returns.
            return SmsResult::deferred('Network/Connection deferred: '.$e->getMessage());
        }
    }
}
