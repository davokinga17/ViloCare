<?php

namespace App\Support;

use App\Services\Sms\SmsProviderFactory;

/**
 * Whether the third-party keys are present in this process's configuration (used for the "key missing" notices).
 */
class Integrations
{
    public static function geminiConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * The Gemini model in use: the one saved on Settings > Integrations, else GEMINI_MODEL, else the config default.
     */
    public static function geminiModel(): string
    {
        return (string) (config('services.gemini.model_override')
            ?: config('services.gemini.model')
            ?: config('services.gemini.default_model'));
    }

    /** Whether the SMS provider selected for this computer has all its credentials. */
    public static function smsConfigured(): bool
    {
        return SmsProviderFactory::make()->isConfigured();
    }

    /** Name of the selected SMS provider for screens, e.g. "Twilio". */
    public static function smsProviderLabel(): string
    {
        return SmsProviderFactory::make()->label();
    }
}
