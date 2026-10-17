<?php

namespace App\Services\Sms;

/**
 * Picks the SMS provider from configuration (SMS_PROVIDER, saved from Settings > Integrations).
 */
class SmsProviderFactory
{
    public const DEFAULT = 'africastalking';

    /** @var array<string, string> key => label */
    public const PROVIDERS = [
        'africastalking' => "Africa's Talking",
        'twilio' => 'Twilio',
    ];

    public static function selected(): string
    {
        $name = strtolower((string) config('services.sms.provider'));

        return isset(self::PROVIDERS[$name]) ? $name : self::DEFAULT;
    }

    public static function make(?string $name = null): SmsProviderInterface
    {
        return match ($name ?? self::selected()) {
            'twilio' => TwilioProvider::fromConfig(),
            default => AfricasTalkingProvider::fromConfig(),
        };
    }
}
