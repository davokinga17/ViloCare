<?php

namespace App\Support;

/**
 * Which computers may send reminder SMS. With several clinic computers syncing the same data, every one of them
 * would otherwise send the same reminders, so by default only the central server (VILOCARE_ROLE=central) does.
 * An offline clinic opts in with the setting "This computer sends SMS reminders" (VILOCARE_SENDS_SMS).
 */
class SmsRole
{
    public static function enabled(): bool
    {
        return config('sync.role', 'clinic') === 'central' || (bool) config('sync.sends_sms', false);
    }
}
