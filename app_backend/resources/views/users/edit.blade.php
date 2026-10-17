@extends('layouts.app')

@section('title', 'Edit Staff Member')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Staff Account: {{ $user->name }}</h1>
        <p class="page-subtitle">Update contact information, roles, and access state.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('users.index') }}" class="btn btn-secondary">
            &larr; Back to Staff List
        </a>
    </div>
</div>

<div class="card" style="max-width: 720px;">
    <div class="card-header">
        <h2 class="card-title">Edit Account Details</h2>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('users.update', $user) }}">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="name" class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required class="form-control">
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                    <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required class="form-control">
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Contact Phone Number</label>
                    <input type="tel" inputmode="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" class="form-control">
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="role" class="form-label">Role <span class="required">*</span></label>
                    <select id="role" name="role" required class="form-select">
                        @foreach($assignableRoles as $role)
                            <option value="{{ $role->value }}" {{ old('role', $user->role->value ?? $user->role) === $role->value ? 'selected' : '' }}>
                                {{ $role->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="form-group">
                    <label for="is_active" class="form-label">Account Status <span class="required">*</span></label>
                    <select id="is_active" name="is_active" required class="form-select">
                        <option value="1" {{ old('is_active', $user->is_active) ? 'selected' : '' }}>Active (Can Sign In)</option>
                        <option value="0" {{ !old('is_active', $user->is_active) ? 'selected' : '' }}>Deactivated (Access Revoked)</option>
                    </select>
                </div>
            </div>

            <div style="margin-top: 24px; display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
