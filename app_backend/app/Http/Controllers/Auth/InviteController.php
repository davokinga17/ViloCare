<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountMailer;
use App\Services\AccountTokenService;
use App\Support\PasswordRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * The page an invited staff member opens from the invitation email to choose their own password.
 * Setting it also proves they own the mailbox, so the address becomes a verified recovery email.
 */
class InviteController extends Controller
{
    public function __construct(protected AccountTokenService $tokens, protected AccountMailer $mailer)
    {
    }

    public function show(string $token): View
    {
        if (! $this->mailer->isCentral() || ! $this->tokens->find($token, AccountTokenService::INVITE)) {
            return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
        }

        return view('auth.accept-invite', ['token' => $token]);
    }

    public function accept(Request $request, string $token): View|RedirectResponse
    {
        $row = $this->mailer->isCentral() ? $this->tokens->find($token, AccountTokenService::INVITE) : null;
        if (! $row) {
            return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRules::rule()],
        ]);

        $user = User::find($row->user_id);
        if (! $user || ! $user->is_active || ! $user->recovery_email || ! $this->tokens->consume($row)) {
            return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
        }

        $hash = Hash::make($validated['password']);
        $user->forceFill([
            'password' => $hash,
            'cached_password_hash' => $hash,
            'must_change_password' => false,
            'recovery_email_verified_at' => now(),
            'last_modified_at' => now(),
        ])->save();

        $this->tokens->invalidate($user);

        AuditLog::log(
            action: 'invite_accepted',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: null,
            newValues: ['must_change_password' => false, 'recovery_email_verified' => true, 'ip' => $request->ip()],
            userId: $user->id
        );

        return redirect()->route('login')->with('success', 'Your password is set. Sign in with your email and the new password.');
    }
}
