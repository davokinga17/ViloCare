<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(): View
    {
        return view('profile.show', [
            'user' => auth()->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ]);

        $photoPath = $user->photo_path;

        if ($request->hasFile('photo')) {
            if ($photoPath && Storage::disk('public')->exists($photoPath)) {
                Storage::disk('public')->delete($photoPath);
            }
            $photoPath = $request->file('photo')->store('avatars', 'public');
        }

        $oldValues = ['name' => $user->name, 'phone' => $user->phone];

        $user->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? null,
            'photo_path' => $photoPath,
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'updated_profile',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: $oldValues,
            newValues: ['name' => $user->name, 'phone' => $user->phone],
            userId: $user->id
        );

        return redirect()->route('profile.show')->with('success', 'Profile updated successfully.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'Current password does not match.',
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'cached_password_hash' => Hash::make($validated['password']),
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'changed_own_password',
            tableName: 'users',
            recordId: (string) $user->id,
            userId: $user->id
        );

        return redirect()->route('profile.show')->with('success', 'Password updated successfully.');
    }
}
