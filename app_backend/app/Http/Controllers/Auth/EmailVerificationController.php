<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountMailer;
use App\Services\AccountTokenService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Confirms a recovery email. Opening the link only shows a button; the POST does the work, so mail scanners
 * that open links cannot use up the single-use token.
 */
class EmailVerificationController extends Controller
{
    public function __construct(protected AccountTokenService $tokens, protected AccountMailer $mailer)
    {
    }

    public function show(string $token): View
    {
        if (! $this->mailer->isCentral() || ! $this->tokens->find($token, AccountTokenService::VERIFY)) {
            return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
        }

        return view('auth.verify-email', ['token' => $token]);
    }

    public function confirm(Request $request, string $token): View
    {
        $row = $this->mailer->isCentral() ? $this->tokens->find($token, AccountTokenService::VERIFY) : null;
        $user = $row ? User::find($row->user_id) : null;

        if (! $row || ! $user || ! $user->recovery_email || ! $this->tokens->consume($row)) {
            return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
        }

        $user->forceFill(['recovery_email_verified_at' => now(), 'last_modified_at' => now()])->save();

        AuditLog::log(
            action: 'recovery_email_verified',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: null,
            newValues: ['verified' => true, 'ip' => $request->ip()],
            userId: $user->id
        );

        return view('auth.email-verified');
    }
}
