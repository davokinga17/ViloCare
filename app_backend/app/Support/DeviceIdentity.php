<?php

namespace App\Support;

use App\Services\SyncStateStore;
use Illuminate\Support\Str;

/**
 * The unique ID of this installation, shown on the Sync Center and sent to the central server.
 *
 * Normally set at first-run setup (VILOCARE_DEVICE_ID in the local .env, editable in Settings). An install that
 * still has the old shared default gets one generated ID, stored once in the local database, so two clinic
 * computers never both report "clinic-desktop-01".
 */
class DeviceIdentity
{
    public const DEFAULT_ID = 'clinic-desktop-01';

    public static function resolve(SyncStateStore $state): string
    {
        $configured = trim((string) config('sync.device_id', ''));
        if ($configured !== '' && $configured !== self::DEFAULT_ID) {
            return $configured;
        }

        // The central server is not a clinic computer; it keeps whatever it was given.
        if (config('sync.role', 'clinic') === 'central') {
            return $configured !== '' ? $configured : self::DEFAULT_ID;
        }

        $stored = $state->get('device_id');
        if ($stored !== null && $stored !== '' && $stored !== self::DEFAULT_ID) {
            return $stored;
        }

        $generated = self::generate();
        $state->put('device_id', $generated);

        return $generated;
    }

    public static function generate(): string
    {
        return 'clinic-'.strtolower(Str::random(8));
    }

    /** Rules for an ID typed in Settings. Goes into .env, so no quotes, spaces or backslashes. */
    public static function isValid(string $id): bool
    {
        return $id !== self::DEFAULT_ID && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,59}$/', $id);
    }
}
