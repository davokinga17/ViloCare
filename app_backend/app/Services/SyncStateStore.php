<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Durable, device-local key/value store for sync bookkeeping (pull cursor,
 * resume position, heartbeat, last error). Lives in the `sync_states` table so
 * that clearing the cache can never silently reset the sync position.
 * Falls back to the cache only if the table is unavailable (migration not run).
 */
class SyncStateStore
{
    public function get(string $key, ?string $default = null): ?string
    {
        try {
            $value = DB::table('sync_states')->where('key', $key)->value('value');

            return $value ?? $default;
        } catch (\Throwable $e) {
            return Cache::get('sync_state:'.$key, $default);
        }
    }

    public function put(string $key, ?string $value): void
    {
        try {
            DB::table('sync_states')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()]
            );
        } catch (\Throwable $e) {
            Cache::put('sync_state:'.$key, $value, now()->addDays(30));
        }
    }

    public function forget(string $key): void
    {
        try {
            DB::table('sync_states')->where('key', $key)->delete();
        } catch (\Throwable $e) {
            Cache::forget('sync_state:'.$key);
        }
    }

    public function getJson(string $key): ?array
    {
        $raw = $this->get($key);
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function putJson(string $key, array $value): void
    {
        $this->put($key, json_encode($value));
    }

    public function getTime(string $key): ?Carbon
    {
        $raw = $this->get($key);

        try {
            return $raw ? Carbon::parse($raw) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Records that a sync run (scheduler, browser timer or manual) actually started.
     */
    public function touchHeartbeat(): void
    {
        $this->put('heartbeat_at', now()->toIso8601String());
    }
}
