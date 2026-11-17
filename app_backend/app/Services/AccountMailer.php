<?php

namespace App\Services;

use App\Mail\AccountNotice;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the account emails. Only the central server sends: clinic computers are often offline and never
 * try. Failures are logged by class only (no addresses, no tokens) and never reach the user.
 */
class AccountMailer
{
    public const RESET_MINUTES = 60;
    public const VERIFY_MINUTES = 1440;
    public const INVITE_MINUTES = 10080; // 7 days

    public function __construct(protected AccountTokenService $tokens)
    {
    }

    public function isCentral(): bool
    {
        return config('sync.role', 'clinic') === 'central';
    }

    /** True when real mail can be sent: central server and a mailer other than the log/array ones. */
    public function isConfigured(): bool
    {
        return $this->isCentral() && ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
    }

    /** Sends the verification link for the user's current recovery email. False when nothing was sent. */
    public function sendVerification(User $user, ?string $ip = null): bool
    {
        if (! $this->isConfigured() || ! $user->recovery_email || $user->recovery_email_verified_at) {
            return false;
        }

        $token = $this->tokens->issue($user, AccountTokenService::VERIFY, self::VERIFY_MINUTES, $ip);

        return $this->deliver($user->recovery_email, new AccountNotice(
            'verify',
            'Confirm your ViLoCare recovery email',
            [
                'Someone asked to use this address as the recovery email for a ViLoCare account.',
                'To confirm it, open this link (valid for 24 hours, works once). If you did not expect this, ignore this email.',
            ],
            $this->url('email.verify', $token)
        ));
    }

    /**
     * Invitation for a staff member who has a real personal email: a link to set their own password (no
     * password is ever emailed). Opening it and setting a password also proves the mailbox, so it verifies it.
     */
    public function sendInvite(User $user, ?string $ip = null): bool
    {
        if (! $this->isConfigured() || ! $user->recovery_email || $user->recovery_email_verified_at) {
            return false;
        }

        $token = $this->tokens->issue($user, AccountTokenService::INVITE, self::INVITE_MINUTES, $ip);

        return $this->deliver($user->recovery_email, new AccountNotice(
            'invite',
            'You have been invited to ViLoCare',
            [
                'An administrator created a ViLoCare account for this email address.',
                'To choose your password and sign in, open this link (valid for 7 days, works once). If you did not expect this, ignore this email.',
            ],
            $this->url('invite.accept', $token)
        ));
    }

    /** Sends the reset link to a VERIFIED recovery email only. */
    public function sendReset(User $user, ?string $ip = null): bool
    {
        if (! $this->isConfigured() || ! $user->recovery_email || ! $user->recovery_email_verified_at) {
            return false;
        }

        $token = $this->tokens->issue($user, AccountTokenService::RESET, self::RESET_MINUTES, $ip);

        return $this->deliver($user->recovery_email, new AccountNotice(
            'reset',
            'Reset your ViLoCare password',
            [
                'A password reset was requested for a ViLoCare account linked to this address.',
                'To choose a new password, open this link (valid for 60 minutes, works once). If you did not ask for this, ignore this email; your password has not changed.',
            ],
            $this->url('password.reset', $token)
        ));
    }

    public function sendPasswordChanged(User $user): bool
    {
        if (! $this->isConfigured() || ! $user->recovery_email || ! $user->recovery_email_verified_at) {
            return false;
        }

        return $this->deliver($user->recovery_email, new AccountNotice(
            'changed',
            'Your ViLoCare password was changed',
            [
                'The password of the ViLoCare account linked to this address was just changed.',
                'If this was not you, contact your clinic administrator at once.',
            ]
        ));
    }

    protected function url(string $route, string $token): string
    {
        // Built from APP_URL, never from the request, so a forged Host header cannot redirect the link.
        return rtrim((string) config('app.url'), '/').route($route, ['token' => $token], false);
    }

    protected function deliver(string $to, AccountNotice $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Account email failed: '.get_class($e));

            return false;
        }
    }
}
