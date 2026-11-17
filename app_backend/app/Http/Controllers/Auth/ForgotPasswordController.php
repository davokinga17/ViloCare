<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Forgot password?". The answer is always the same neutral message, takes about the same time, and email goes
 * only to a VERIFIED recovery address of an active account: never to the login email (often a placeholder).
 */
class ForgotPasswordController extends Controller
{
    public const NEUTRAL = 'If an account matches, we sent instructions to its recovery email. If you do not receive anything, ask an administrator to reset your password.';

    public function __construct(protected AccountMailer $mailer)
    {
    }

    public function show(): View
    {
        return view('auth.forgot-password', [
            'central' => $this->mailer->isCentral(),
            'serverHost' => $this->serverHost(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $started = microtime(true);

        // A clinic computer never sends mail.
        if (! $this->mailer->isCentral()) {
            return redirect()->route('password.request');
        }

        $identifier = mb_strtolower(trim((string) $request->input('email')));
        $ip = (string) $request->ip();

        // Every request is counted, whether or not the account exists, and an over-limit request gets the
        // very same reply, so the limits reveal nothing.
        $allowed = $this->hit('pwreset:ip:'.$ip, 20);
        $allowed = $this->hit('pwreset:acct:'.sha1($identifier), 5) && $allowed;

        if ($allowed && $identifier !== '' && mb_strlen($identifier) <= 255) {
            foreach ($this->matchingUsers($identifier) as $user) {
                if (! $this->hit('pwreset:user:'.$user->id, 5)) {
                    continue;
                }
                $this->handle($user, $ip);
            }
        }

        $this->padTo($started);

        return redirect()->route('password.request')->with('status', self::NEUTRAL);
    }

    /** One account per login email or recovery email; both may match different people. */
    protected function matchingUsers(string $identifier)
    {
        return User::whereRaw('lower(email) = ?', [$identifier])
            ->orWhereRaw('lower(recovery_email) = ?', [$identifier])
            ->get();
    }

    protected function handle(User $user, string $ip): void
    {
        if (! $user->is_active) {
            return; // deactivated accounts cannot reset: same reply, no email
        }

        if (! $user->recovery_email || ! $user->recovery_email_verified_at) {
            AuditLog::log(
                action: 'reset_requested_no_recovery_email',
                tableName: 'users',
                recordId: (string) $user->id,
                oldValues: null,
                newValues: ['ip' => $ip, 'recovery_email' => $user->recovery_email ? 'unverified' : 'none'],
                userId: null
            );

            return;
        }

        // Sent after the response, so a slow mail server cannot show that an account exists.
        $done = false;
        app()->terminating(function () use ($user, $ip, &$done) {
            if (! $done) {
                $done = true; // terminating callbacks stay registered on a long-lived app: send once
                $this->mailer->sendReset($user, $ip);
            }
        });
    }

    protected function hit(string $key, int $max): bool
    {
        $ok = ! RateLimiter::tooManyAttempts($key, $max);
        RateLimiter::hit($key, 3600);

        return $ok;
    }

    /** Makes every outcome take at least the same minimum time. */
    protected function padTo(float $started): void
    {
        $minimum = (int) config('vilocare.reset_min_response_ms', 600);
        $elapsed = (int) ((microtime(true) - $started) * 1000);
        if ($elapsed < $minimum) {
            usleep(($minimum - $elapsed) * 1000);
        }
    }

    /** Host of the saved central sync URL, for the "use the website" hint on clinic computers. */
    protected function serverHost(): ?string
    {
        $host = parse_url((string) config('sync.remote_url', ''), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
