<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'africastalking' => [
        'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
        'sandbox' => env('AFRICASTALKING_SANDBOX', true),
    ],

    // Which SMS gateway sends messages: africastalking or twilio. Chosen on Settings > Integrations (saved in the
    // local .env) or set as an environment variable (e.g. on Render).
    'sms' => [
        'provider' => env('SMS_PROVIDER', 'africastalking'),
    ],

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        // Alphanumeric sender ID, a phone number (+...) or a Messaging Service SID (MG...).
        'sender' => env('TWILIO_SENDER'),
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'enabled' => env('RECAPTCHA_ENABLED', false),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // The one place a default model name lives. Order of use (see App\Support\Integrations::geminiModel()):
        // 1. model_override: chosen on Settings > Integrations, saved in this computer's .env
        // 2. model: GEMINI_MODEL from the environment (e.g. Render)
        // 3. default_model
        'default_model' => 'gemini-3.5-flash',
        'model' => env('GEMINI_MODEL'),
        'model_override' => env('VILOCARE_GEMINI_MODEL'),
        'timeout' => env('GEMINI_TIMEOUT', 30),
        'max_tokens' => env('GEMINI_MAX_TOKENS', 2048),
    ],

];
