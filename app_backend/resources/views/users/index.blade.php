@extends('layouts.app')

@section('title', 'Staff & User Management')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Staff & Access Control</h1>
        <p class="page-subtitle">Manage clinic user accounts, roles, temporary credentials and security status.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('users.create') }}" class="btn btn-primary">
            <span>➕</span> Add New Staff
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('users.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 1; min-width: 200px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by name, email, phone...">
            </div>
            <div style="width: 180px;">
                <select name="role" class="form-select">
                    <option value="">All Roles</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->value }}" {{ request('role') === $role->value ? 'selected' : '' }}>
                            {{ $role->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="width: 140px;">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="1" {{ request('status') === '1' ? 'selected' : '' }}>Active</option>
                    <option value="0" {{ request('status') === '0' ? 'selected' : '' }}>Deactivated</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'role', 'status']))
                <a href="{{ route('users.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Staff Name & Email</th>
                    <th>Role</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Temporary Password?</th>
                    <th>Created By</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem; overflow: hidden; border-radius: 50%; flex-shrink: 0;">
                                    @if($user->photo_url)
                                        <img src="{{ $user->photo_url }}" alt="{{ $user->name }}" class="user-avatar-img" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%; display: block;">
                                    @else
                                        {{ strtoupper(substr($user->name, 0, 2)) }}
                                    @endif
                                </div>
                                <div>
                                    <strong style="color: #0f172a;">{{ $user->name }}</strong>
                                    <div style="color: #64748b; font-size: 0.8rem;">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            @php
                                $roleBadge = match($user->role->value ?? (string)$user->role) {
                                    'administrator' => 'badge-danger',
                                    'clinician' => 'badge-info',
                                    'lab_technician' => 'badge-warning',
                                    'data_clerk' => 'badge-success',
                                    default => 'badge-secondary',
                                };
                            @endphp
                            <span class="badge {{ $roleBadge }}">
                                {{ $user->role instanceof \App\Enums\UserRole ? $user->role->label() : ucfirst($user->role) }}
                            </span>
                        </td>
                        <td>{{ $user->phone ?? '—' }}</td>
                        <td>
                            @if($user->is_active)
                                <span class="badge badge-success">Active</span>
                            @else
                                <span class="badge badge-danger">Deactivated</span>
                            @endif
                        </td>
                        <td>
                            @if($user->must_change_password)
                                <span class="badge badge-warning">⚠️ Must Reset on Login</span>
                            @else
                                <span class="badge badge-secondary">Active Password</span>
                            @endif
                        </td>
                        <td>
                            <span style="font-size: 0.8rem; color: #64748b;">
                                {{ $user->creator?->name ?? 'System / Seed' }}
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                @if($currentUser->canManageUser($user))
                                    <a href="{{ route('users.edit', $user) }}" class="btn btn-secondary btn-sm" title="Edit Staff Details">
                                        ✏️ Edit
                                    </a>
                                    <form action="{{ route('users.reset-password', $user) }}" method="POST" onsubmit="return confirm('Generate a new temporary password for this user? They will be required to change it on next login.');">
                                        @csrf
                                        <button type="submit" class="btn btn-secondary btn-sm" title="Reset temporary password">
                                            🔑 Reset Pass
                                        </button>
                                    </form>
                                    @if($user->id !== $currentUser->id && $user->is_active)
                                        <form action="{{ route('users.destroy', $user) }}" method="POST" onsubmit="return confirm('Deactivate this staff account?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-secondary btn-sm" style="color: #dc2626;" title="Deactivate Account">
                                                🚫
                                            </button>
                                        </form>
                                    @endif
                                @else
                                    <span style="font-size: 0.75rem; color: #94a3b8; font-style: italic;">Protected</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 32px; color: #64748b;">
                            No staff accounts found matching your query.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
        <div class="card-footer">
            {{ $users->links() }}
        </div>
    @endif
</div>
@endsection
