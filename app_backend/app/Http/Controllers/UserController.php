<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Facility;
use App\Models\TestingLaboratory;
use App\Services\AccessScope;
use App\Services\AccountMailer;
use App\Support\RecoveryEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        $query = User::query()->with(['creator', 'facility', 'testingLaboratory']);

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

        // Clinicians see only the lab technicians and data clerks of their own facility.
        if ($currentUser->isClinician()) {
            $query->whereIn('role', [UserRole::LAB_TECHNICIAN->value, UserRole::DATA_CLERK->value])
                ->where('facility_id', $currentUser->facility_id ?: AccessScope::NO_MATCH);
        }

        $users = $query->latest()->paginate(15)->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'currentUser' => $currentUser,
            // Shown to administrators on the central server when no real mail server is set up.
            'mailNotConfigured' => $currentUser->isAdmin() && app(AccountMailer::class)->isCentral() && ! app(AccountMailer::class)->isConfigured(),
            // Administrators are warned about staff who would see no data until a facility is assigned.
            'unassigned' => $currentUser->isAdmin()
                ? User::where('is_active', true)->where('role', '!=', UserRole::ADMINISTRATOR->value)
                    ->whereNull('facility_id')
                    ->where(fn ($q) => $q->whereNull('testing_laboratory_id')->orWhere('role', '!=', UserRole::LAB_TECHNICIAN->value))
                    ->orderBy('name')->get(['id', 'name', 'email', 'role'])
                : collect(),
        ]);
    }

    /** Facility and laboratory choices for the staff forms. */
    private function assignmentChoices(User $actor): array
    {
        return [
            'facilitiesByCounty' => $actor->isAdmin()
                ? Facility::with('county')->orderBy('name')->get()->groupBy(fn ($f) => $f->county?->name ?? 'Other')->sortKeys()
                : collect(),
            'standaloneLabs' => TestingLaboratory::active()->whereNull('facility_id')->get(['id', 'name']),
            'ownFacility' => $actor->isAdmin() ? null : $actor->facility,
        ];
    }

    /**
     * The facility / laboratory a new or edited user gets, decided on the server (never trusting the form):
     *  - administrators need no assignment;
     *  - a clinician can only add staff to their own facility, whatever the request says;
     *  - an administrator picks a facility (clinician, data clerk) or exactly one of facility / laboratory (lab technician).
     *
     * @return array{0: ?string, 1: ?string} [facility_id, testing_laboratory_id]
     */
    private function assignment(Request $request, User $actor, UserRole $role): array
    {
        if ($role === UserRole::ADMINISTRATOR) {
            return [null, null];
        }

        if (! $actor->isAdmin()) {
            if (! $actor->facility_id) {
                abort(403, 'No facility assigned. Ask an administrator.');
            }

            return [$actor->facility_id, null];
        }

        $facilityId = $request->input('facility_id') ?: null;
        $labId = $request->input('testing_laboratory_id') ?: null;

        if ($role === UserRole::LAB_TECHNICIAN) {
            if (($facilityId && $labId) || (! $facilityId && ! $labId)) {
                throw ValidationException::withMessages(['facility_id' => 'A lab technician needs exactly one of: a facility, or a testing laboratory.']);
            }
        } else {
            $labId = null;
            if (! $facilityId) {
                throw ValidationException::withMessages(['facility_id' => 'Choose the facility this staff member works at.']);
            }
        }

        if ($facilityId && ! Facility::whereKey($facilityId)->exists()) {
            throw ValidationException::withMessages(['facility_id' => 'The selected facility does not exist.']);
        }
        if ($labId && ! TestingLaboratory::whereKey($labId)->whereNull('facility_id')->exists()) {
            throw ValidationException::withMessages(['testing_laboratory_id' => 'Choose a stand-alone testing laboratory such as the National Public Health Laboratory.']);
        }

        return [$facilityId, $labId];
    }

    public function create(): View
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();
        $assignableRoles = $currentUser->role->assignableRoles();

        return view('users.create', [
            'assignableRoles' => $assignableRoles,
            'canInvite' => $this->canInvite($currentUser),
        ] + $this->assignmentChoices($currentUser));
    }

    /** Inviting by email needs an administrator, the central server and a working mail setup. */
    private function canInvite(User $actor): bool
    {
        return $actor->isAdmin() && app(AccountMailer::class)->isConfigured();
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
            'recovery_email' => RecoveryEmail::rules(),
            'access_mode' => ['nullable', Rule::in(['invite', 'temporary'])],
        ]);

        // Real personal email -> invitation; no real email -> placeholder login with a temporary password.
        $invite = ($validated['access_mode'] ?? 'temporary') === 'invite';
        if ($invite) {
            if (! $this->canInvite($currentUser)) {
                throw ValidationException::withMessages(['access_mode' => 'Invitations by email are not available here (central server with email configured, administrators only). Use a temporary password instead.']);
            }
            validator(['recovery_email' => $validated['email']], ['recovery_email' => RecoveryEmail::rules()])->validate();
        }

        [$facilityId, $labId] = $this->assignment($request, $currentUser, UserRole::from($validated['role']));

        $tempPassword = ($validated['temporary_password'] ?? null) ?: 'Vilo@'.Str::upper(Str::random(5)).rand(100, 999);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => UserRole::from($validated['role']),
            'facility_id' => $facilityId,
            'testing_laboratory_id' => $labId,
            // An invited user sets their own password from the link; until then the stored one is unguessable.
            'password' => Hash::make($invite ? Str::random(48) : $tempPassword),
            'cached_password_hash' => Hash::make($invite ? Str::random(48) : $tempPassword),
            'recovery_email' => $invite ? $validated['email'] : null,
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
            newValues: ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'facility_id' => $facilityId, 'testing_laboratory_id' => $labId],
            userId: $currentUser->id
        );

        if ($invite) {
            $sent = app(AccountMailer::class)->sendInvite($user->refresh(), $request->ip());

            return redirect()->route('users.index')->with($sent ? 'success' : 'error', $sent
                ? "Invitation sent to '{$user->email}'. They set their own password from the link (valid for 7 days). No temporary password was created."
                : "Account for '{$user->name}' created, but the invitation email could not be sent. Use 'Resend invite' on the Users page, or 'Reset Pass' to give a temporary password.");
        }

        $recoveryNote = '';
        if ($currentUser->isAdmin() && ! empty($validated['recovery_email'])) {
            $recoveryNote = ' '.RecoveryEmail::message(RecoveryEmail::change($user, $validated['recovery_email'], $currentUser->id, $request->ip()));
        }

        return redirect()->route('users.index')
            ->with('success', "Staff account for '{$user->name}' created successfully! Temporary password: '{$tempPassword}' (User will be required to change it upon first login).".$recoveryNote);
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
        ] + $this->assignmentChoices($currentUser));
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
            'recovery_email' => RecoveryEmail::rules($user->id),
        ]);
        $recoveryEmail = $validated['recovery_email'] ?? null;
        unset($validated['recovery_email']); // handled below, and kept out of the audit log

        [$facilityId, $labId] = $this->assignment($request, $currentUser, UserRole::from($validated['role']));

        $oldValues = [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
            'is_active' => $user->is_active,
            'facility_id' => $user->facility_id,
            'testing_laboratory_id' => $user->testing_laboratory_id,
        ];

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'role' => UserRole::from($validated['role']),
            'facility_id' => $facilityId,
            'testing_laboratory_id' => $labId,
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

        $recoveryNote = '';
        if ($currentUser->isAdmin() && $request->has('recovery_email')) {
            $state = RecoveryEmail::change($user->refresh(), $recoveryEmail, $currentUser->id, $request->ip());
            $recoveryNote = $state === 'unchanged' ? '' : ' '.RecoveryEmail::message($state);
        }

        return redirect()->route('users.index')
            ->with('success', "Staff account for '{$user->name}' updated successfully.".$recoveryNote);
    }

    /** Administrators: send the recovery-email confirmation link again (central server only). */
    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        /** @var User $currentUser */
        $currentUser = auth()->user();

        if (! $currentUser->isAdmin() || ! $currentUser->canManageUser($user)) {
            abort(403, 'Only administrators can resend a verification email.');
        }

        $mailer = app(AccountMailer::class);

        if (! $user->recovery_email) {
            return back()->with('error', 'This user has no recovery email.');
        }
        if ($user->recovery_email_verified_at) {
            return back()->with('success', 'This recovery email is already verified.');
        }
        if (! $mailer->isCentral()) {
            return back()->with('warning', 'Email is sent from the central server. The confirmation is sent when this computer syncs with it, or resend it from the website.');
        }
        if (! $mailer->isConfigured()) {
            return back()->with('error', 'Email is not configured on the server.');
        }

        if ($user->isPendingInvite()) {
            return $mailer->sendInvite($user, $request->ip())
                ? back()->with('success', 'Invitation sent again.')
                : back()->with('error', 'The invitation could not be sent. Check the mail settings.');
        }

        return $mailer->sendVerification($user, $request->ip())
            ? back()->with('success', 'Verification email sent.')
            : back()->with('error', 'The verification email could not be sent. Check the mail settings.');
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
