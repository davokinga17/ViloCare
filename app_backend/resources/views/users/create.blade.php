@extends('layouts.app')

@section('title', 'Add New Staff Member')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Add New Staff Member</h1>
        <p class="page-subtitle">Register a new healthcare worker or clinic operator with specific role permissions.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('users.index') }}" class="btn btn-secondary">
            &larr; Back to Staff List
        </a>
    </div>
</div>

<div class="card" style="max-width: 720px;">
    <div class="card-header">
        <h2 class="card-title">Staff Account Details</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('users.store') }}">
            @csrf

            <div class="form-group">
                <label for="name" class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name') }}" required class="form-control" placeholder="e.g. Dr. Jane Doe or Alex Mukasa">
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required class="form-control" placeholder="staff@vilocare.org">
                    <div class="form-help">Must be a unique clinic email address.</div>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Contact Phone Number</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="+211910000000">
                </div>
            </div>

            <div class="form-group">
                <label for="role" class="form-label">Assign System Role <span class="required">*</span></label>
                <select id="role" name="role" required class="form-select">
                    <option value="">-- Select Role --</option>
                    @foreach($assignableRoles as $role)
                        <option value="{{ $role->value }}" {{ old('role') === $role->value ? 'selected' : '' }}>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </select>
                <div class="form-help">
                    <strong>Role Access Rules:</strong><br>
                    &bull; <em>Clinician:</em> Patients, Samples, EAC Management, VL Review, Reports.<br>
                    &bull; <em>Lab Technician:</em> Viral Load Result Entry, Sample Processing, Reports.<br>
                    &bull; <em>Data Clerk:</em> Patient Registration, Appointments, Basic Data Entry.
                </div>
            </div>

            <div class="form-group">
                <label for="temporary_password" class="form-label">Custom Temporary Password (Optional)</label>
                <input type="text" id="temporary_password" name="temporary_password" value="{{ old('temporary_password') }}" class="form-control" placeholder="Leave blank to auto-generate a secure random password">
                <div class="form-help">The user will be required to change this temporary password upon their first login.</div>
            </div>

            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary">Create Staff Account</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
