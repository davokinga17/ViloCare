<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reset and email-verification tokens. Server-only (table `account_tokens`, not synced). Only the sha256 of a
 * token is stored, so a copy of the database cannot be used to reset anyone's password.
 */
class AccountTokenService
{
    public const RESET = 'reset';
    public const VERIFY = 'verify_email';
    public const INVITE = 'invite';

    /** Creates a token and invalidates every older unused token of the same purpose for the user. Returns the plain token. */
    public function issue(User $user, string $purpose, int $minutes, ?string $ip = null): string
    {
        $this->invalidate($user, $purpose);

        $token = Str::random(64);
        DB::table('account_tokens')->insert([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($minutes),
            'used_at' => null,
            'created_ip' => $ip,
            'created_at' => now(),
        ]);

        return $token;
    }

    /** The unused, unexpired token row, or null. Does not consume it. */
    public function find(string $token, string $purpose): ?object
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        return DB::table('account_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Marks the token used. False when it was already used (or expired) a moment ago: single use even under a race. */
    public function consume(object $row): bool
    {
        return DB::table('account_tokens')
            ->where('id', $row->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['used_at' => now()]) === 1;
    }

    public function invalidate(User $user, ?string $purpose = null): void
    {
        $query = DB::table('account_tokens')->where('user_id', $user->id)->whereNull('used_at');
        if ($purpose !== null) {
            $query->where('purpose', $purpose);
        }
        $query->update(['used_at' => now()]);
    }

    public function hasLive(User $user, string $purpose): bool
    {
        return DB::table('account_tokens')
            ->where('user_id', $user->id)->where('purpose', $purpose)
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->exists();
    }
}
