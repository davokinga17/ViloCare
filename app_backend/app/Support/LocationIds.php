<?php

namespace App\Support;

use Ramsey\Uuid\Uuid;

/**
 * Deterministic (UUIDv5) primary keys for location reference data, derived from the business
 * code. Two databases that create the same state, county or facility independently therefore end
 * up with the same id. Rows that already existed before this scheme keep their historical id.
 */
class LocationIds
{
    /** Fixed namespace. Never change it: every derived id would change with it. */
    public const NAMESPACE = '7d0d1f4e-3a52-4c1b-8e44-5a7b0c2d1e90';

    /** Ids that predate the scheme and are already in production on both sides. */
    private const CANONICAL = [
        'state:EES' => '3666f0f5-c0b9-45d6-9924-368a2bf8fece',
        'county:EES:KPS' => '6927016f-4233-46ba-b21a-c4c4f2df72c9',
        'facility:KCH-001' => '25ca7235-b901-4215-b2ea-733c059c7c7f',
    ];

    public static function state(string $stateCode): string
    {
        return self::derive('state:'.$stateCode);
    }

    public static function county(string $stateCode, string $countyCode): string
    {
        return self::derive('county:'.$stateCode.':'.$countyCode);
    }

    public static function facility(string $facilityCode): string
    {
        return self::derive('facility:'.$facilityCode);
    }

    public static function laboratory(string $laboratoryCode): string
    {
        return self::derive('laboratory:'.$laboratoryCode);
    }

    private static function derive(string $key): string
    {
        return self::CANONICAL[$key] ?? Uuid::uuid5(self::NAMESPACE, $key)->toString();
    }
}
