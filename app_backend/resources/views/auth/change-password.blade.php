@extends('layouts.guest')

@section('title', 'Change Temporary Password')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #f59e0b, #d97706);">🔑</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">Password Update Required</h2>
        <p style="font-size: 0.85rem; color: #64748b; margin-top: 4px;">
            Hello <strong>{{ $user->name }}</strong>, you are currently using a temporary password. To protect clinical patient data, please set a new personal password.
        </p>
    </div>

    @if(session('warning'))
        <div class="alert alert-warning">
            <span>⚠️</span>
            <div>{{ session('warning') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <span>❌</span>
            <div>{{ $errors->first() }}</div>
        </div>
    @endif

    <form method="POST" action="{{ route('password.change.update') }}">
        @csrf

        <div class="form-group">
            <label for="current_password" class="form-label">Current Temporary Password <span class="required">*</span></label>
            <input type="password" id="current_password" name="current_password" required class="form-control" placeholder="Enter temporary password">
        </div>

        <div class="form-group">
            <label for="password" class="form-label">New Secure Password <span class="required">*</span></label>
            <input type="password" id="password" name="password" required class="form-control" placeholder="At least 8 characters with letters & numbers">
            <div class="form-help">Must contain letters and numbers (min. 8 characters).</div>
        </div>

        <div class="form-group">
            <label for="password_confirmation" class="form-label">Confirm New Password <span class="required">*</span></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required class="form-control" placeholder="Re-type new password">
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px; font-size: 0.95rem; margin-top: 8px;">
            Save Password & Enter System
        </button>
    </form>

    <div style="margin-top: 20px; text-align: center;">
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit" style="background: none; border: none; color: #64748b; font-size: 0.85rem; cursor: pointer; text-decoration: underline;">
                Cancel & Sign Out
            </button>
        </form>
    </div>
</div>
</div>
@endsection
