<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountMailer;
use App\Services\AccountTokenService;
use Illuminate\Support\Facades\DB;

/** Validation and saving of a user's recovery email (used by the user edit form and My Profile). */
class RecoveryEmail
{
    /** @return array<int, mixed> */
    public static function rules(?string $ignoreUserId = null): array
    {
        return [
            'nullable', 'string', 'email', 'max:255',
            function (string $attribute, mixed $value, \Closure $fail) use ($ignoreUserId) {
                if ($value === null || $value === '') {
                    return;
                }
                $taken = DB::table('users')
                    ->whereRaw('lower(recovery_email) = ?', [mb_strtolower(trim($value))])
                    ->when($ignoreUserId, fn ($q) => $q->where('id', '!=', $ignoreUserId))
                    ->exists();
                if ($taken) {
                    $fail('This address cannot be used as a recovery email.');
                }
            },
        ];
    }

    /**
     * Saves a new recovery email (null/'' clears it). A changed address is always unverified again.
     *
     * @return string unchanged | cleared | sent | waiting_for_server | not_configured
     */
    public static function change(User $user, ?string $new, ?string $actorId, ?string $ip = null): string
    {
        $new = $new === null || trim($new) === '' ? null : mb_strtolower(trim($new));
        $old = $user->recovery_email;

        if ($new === ($old === null ? null : mb_strtolower($old))) {
            return 'unchanged';
        }

        $user->forceFill(['recovery_email' => $new, 'recovery_email_verified_at' => null, 'last_modified_at' => now()])->save();

        $tokens = app(AccountTokenService::class);
        $tokens->invalidate($user, AccountTokenService::VERIFY);
        $tokens->invalidate($user, AccountTokenService::RESET);
        $tokens->invalidate($user, AccountTokenService::INVITE);

        AuditLog::log(
            action: 'recovery_email_changed',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: ['had_recovery_email' => $old !== null],
            newValues: ['has_recovery_email' => $new !== null, 'verified' => false],
            userId: $actorId
        );

        if ($new === null) {
            return 'cleared';
        }

        $mailer = app(AccountMailer::class);
        if (! $mailer->isCentral()) {
            return 'waiting_for_server';
        }

        return $mailer->sendVerification($user->refresh(), $ip) ? 'sent' : 'not_configured';
    }

    public static function message(string $state): string
    {
        return match ($state) {
            'cleared' => 'Recovery email removed.',
            'sent' => 'Recovery email saved. A confirmation link was sent to it (valid for 24 hours).',
            'waiting_for_server' => 'Recovery email saved. The confirmation email is sent when this computer reaches the central server.',
            'not_configured' => 'Recovery email saved, but email is not configured on the server, so no confirmation could be sent.',
            default => 'No change to the recovery email.',
        };
    }
}
