@extends('layouts.guest')

@section('title', 'Reset Password')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #0d9488, #0f766e);">🔑</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">Choose a new password</h2>
    </div>

    @if($errors->any())
        <div class="alert alert-danger"><span>❌</span><div>{{ $errors->first() }}</div></div>
    @endif

    <form method="POST" action="{{ route('password.reset.update', ['token' => $token]) }}">
        @csrf
        <div class="form-group">
            <label for="password" class="form-label">New password <span class="required">*</span></label>
            <input type="password" id="password" name="password" required autofocus class="form-control" autocomplete="new-password">
            <div class="form-help">Must contain letters and numbers (min. 8 characters).</div>
        </div>
        <div class="form-group">
            <label for="password_confirmation" class="form-label">Confirm new password <span class="required">*</span></label>
            <input type="password" id="password_confirmation" name="password_confirmation" required class="form-control" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px;">Change password</button>
    </form>
</div>
</div>
@endsection
