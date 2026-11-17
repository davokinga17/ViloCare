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
     * Asks for the next pull to start from scratch. Migrations that add columns to (or create) a synced table
     * call this: rows already pulled never change on the server, so the pull cursor would skip them and the
     * new fields would never arrive. Pulling is idempotent, so a one-time full re-pull is always safe.
     */
    public function requestFullRepull(string $reason): void
    {
        $this->put('needs_full_repull', $reason);
    }

    /**
     * What migrations call after adding columns to / creating a synced table. A computer that has never
     * pulled (fresh install) has nothing to skip, so it needs no flag.
     */
    public function requestFullRepullIfPulledBefore(string $reason): void
    {
        if ($this->get('pull_cursor') !== null || $this->get('pull_progress') !== null) {
            $this->requestFullRepull($reason);
        }
    }

    public function needsFullRepull(): bool
    {
        return $this->get('needs_full_repull') !== null;
    }

    /**
     * Returns true exactly once per request: the caller must then re-pull everything.
     */
    public function consumeFullRepullRequest(): bool
    {
        if (! $this->needsFullRepull()) {
            return false;
        }

        $this->forget('needs_full_repull');

        return true;
    }

    /**
     * Records that a sync run (scheduler, browser timer or manual) actually started.
     */
    public function touchHeartbeat(): void
    {
        $this->put('heartbeat_at', now()->toIso8601String());
    }
}
