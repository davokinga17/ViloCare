<?php

namespace App\Support;

use App\Models\Facility;
use Illuminate\Support\Collection;

/**
 * The installation's "own" facility, from configuration (config/vilocare.php) instead of a hard-coded clinic.
 */
class ClinicDefaults
{
    /**
     * The facility named by VILOCARE_DEFAULT_FACILITY_CODE, else the first facility, else null.
     *
     * @param Collection<int, Facility>|null $facilities An already loaded, ordered list (saves a query)
     */
    public static function facility(?Collection $facilities = null): ?Facility
    {
        $code = trim((string) config('vilocare.default_facility_code', ''));

        if ($code !== '') {
            $match = $facilities
                ? $facilities->firstWhere('code', $code)
                : Facility::where('code', $code)->first();
            if ($match) {
                return $match;
            }
        }

        return $facilities ? $facilities->first() : Facility::orderBy('name')->first();
    }

    public static function name(): string
    {
        $configured = trim((string) config('vilocare.clinic_name', ''));

        return $configured !== '' ? $configured : (self::facility()?->name ?? (string) config('app.name', 'ViLoCare'));
    }
}
