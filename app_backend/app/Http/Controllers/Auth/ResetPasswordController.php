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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ResetPasswordController extends Controller
{
    public function __construct(protected AccountTokenService $tokens, protected AccountMailer $mailer)
    {
    }

    public function show(string $token): View
    {
        if (! $this->mailer->isCentral() || ! $this->tokens->find($token, AccountTokenService::RESET)) {
            return $this->invalid();
        }

        return view('auth.reset-password', ['token' => $token]);
    }

    public function update(Request $request, string $token): View|RedirectResponse
    {
        if (! $this->mailer->isCentral()) {
            return $this->invalid();
        }

        $row = $this->tokens->find($token, AccountTokenService::RESET);
        if (! $row) {
            return $this->invalid();
        }

        // Same rules as the change-password form. Validation errors keep the link usable.
        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRules::rule()],
        ]);

        $user = User::find($row->user_id);
        if (! $user || ! $user->is_active || ! $this->tokens->consume($row)) {
            return $this->invalid();
        }

        $hash = Hash::make($validated['password']);
        $user->forceFill([
            'password' => $hash,
            'cached_password_hash' => $hash,
            'must_change_password' => false,
            'remember_token' => Str::random(60),
            'last_modified_at' => now(),
        ])->save(); // normal save: marks the row pending and stamps it, so the change syncs

        $this->tokens->invalidate($user);

        // Login lockout counters (per login email and IP; they also decay on their own after a minute).
        foreach (array_filter([$user->email, $user->recovery_email]) as $email) {
            RateLimiter::clear(Str::lower(trim($email)).'|'.$request->ip());
        }

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        AuditLog::log(
            action: 'password_reset_by_email',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: null,
            newValues: ['must_change_password' => false, 'ip' => $request->ip()],
            userId: $user->id
        );

        $this->mailer->sendPasswordChanged($user);

        return redirect()->route('login')->with('success', 'Your password has been changed. Sign in with the new password.');
    }

    protected function invalid(): View
    {
        return view('auth.link-invalid', ['central' => $this->mailer->isCentral()]);
    }
}
