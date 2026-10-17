<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        $query = User::query()->with(['creator']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        if ($request->has('status') && $request->input('status') !== '') {
            $query->where('is_active', (bool) $request->input('status'));
        }

        // Clinicians can only view clinicians, lab techs, and data clerks
        if ($currentUser->isClinician()) {
            $query->whereIn('role', [
                UserRole::CLINICIAN->value,
                UserRole::LAB_TECHNICIAN->value,
                UserRole::DATA_CLERK->value,
            ]);
        }

        $users = $query->latest()->paginate(15)->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'currentUser' => $currentUser,
        ]);
    }

    public function create(): View
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();
        $assignableRoles = $currentUser->role->assignableRoles();

        return view('users.create', [
            'assignableRoles' => $assignableRoles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();
        $allowedRoles = array_map(fn ($r) => $r->value, $currentUser->role->assignableRoles());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'temporary_password' => ['nullable', 'string', 'min:8'],
        ]);

        $tempPassword = $validated['temporary_password'] ?: 'Vilo@'.Str::upper(Str::random(5)).rand(100, 999);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => UserRole::from($validated['role']),
            'password' => Hash::make($tempPassword),
            'cached_password_hash' => Hash::make($tempPassword),
            'must_change_password' => true,
            'is_active' => true,
            'created_by' => $currentUser->id,
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'created_user',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: null,
            newValues: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value],
            userId: $currentUser->id
        );

        return redirect()->route('users.index')
            ->with('success', "Staff account for '{$user->name}' created successfully! Temporary password: '{$tempPassword}' (User will be required to change it upon first login).");
    }

    public function edit(User $user): View|RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if (!$currentUser->canManageUser($user)) {
            abort(403, 'You do not have authorization to manage this user account.');
        }

        return view('users.edit', [
            'user' => $user,
            'assignableRoles' => $currentUser->role->assignableRoles(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if (!$currentUser->canManageUser($user)) {
            abort(403, 'You do not have authorization to update this user account.');
        }

        $allowedRoles = array_map(fn ($r) => $r->value, $currentUser->role->assignableRoles());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
            'is_active' => ['required', 'boolean'],
        ]);

        $oldValues = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
            'is_active' => $user->is_active,
        ];

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => UserRole::from($validated['role']),
            'is_active' => (bool) $validated['is_active'],
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'updated_user',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: $oldValues,
            newValues: $validated,
            userId: $currentUser->id
        );

        return redirect()->route('users.index')
            ->with('success', "Staff account for '{$user->name}' updated successfully.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if (!$currentUser->canManageUser($user)) {
            abort(403, 'You do not have permission to reset credentials for this user.');
        }

        $tempPassword = 'Reset@'.Str::upper(Str::random(5)).rand(100, 999);

        $user->forceFill([
            'password' => Hash::make($tempPassword),
            'cached_password_hash' => Hash::make($tempPassword),
            'must_change_password' => true,
            'last_modified_at' => now(),
        ])->save();

        AuditLog::log(
            action: 'reset_password_by_admin',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: ['must_change_password' => false],
            newValues: ['must_change_password' => true],
            userId: $currentUser->id
        );

        return redirect()->route('users.index')
            ->with('success', "Temporary password for '{$user->name}' reset to: '{$tempPassword}'. The user will be required to change it on next login.");
    }

    public function destroy(User $user): RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if ($currentUser->id === $user->id) {
            return back()->with('error', 'You cannot deactivate or delete your own logged-in account.');
        }

        if (!$currentUser->canManageUser($user)) {
            abort(403, 'You do not have authorization to remove this user account.');
        }

        $user->update([
            'is_active' => false,
            'last_modified_at' => now(),
        ]);

        AuditLog::log(
            action: 'deactivated_user',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
            userId: $currentUser->id
        );

        return redirect()->route('users.index')
            ->with('success', "Staff account for '{$user->name}' has been deactivated.");
    }
}
