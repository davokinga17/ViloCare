<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return view('auth.change-password', [
            'user' => $user,
            'isForced' => (bool) $user->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'The provided current password does not match our records.',
            ]);
        }

        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'New password cannot be the same as the current temporary password.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'cached_password_hash' => Hash::make($validated['password']),
            'must_change_password' => false,
            'last_modified_at' => now(),
        ])->save();

        AuditLog::log(
            action: 'password_changed',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: ['must_change_password' => true],
            newValues: ['must_change_password' => false],
            userId: $user->id
        );

        return redirect()->route('dashboard')->with('success', 'Your password has been changed successfully. You now have full access.');
    }
}
