@extends('layouts.guest')

@section('title', 'Forgot Password')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #0d9488, #0f766e);">🔑</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">Forgot your password?</h2>
    </div>

    @if(session('status'))
        <div class="alert alert-success"><span>✅</span><div>{{ session('status') }}</div></div>
    @endif

    @if($central)
        <p style="font-size: 0.9rem; color: #475569; margin-bottom: 16px;">
            Enter your sign-in email or your recovery email. If your account has a verified recovery email, we will send a reset link to it.
        </p>

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="form-group">
                <label for="email" class="form-label">Sign-in email or recovery email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus class="form-control" maxlength="255">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px;">Send reset instructions</button>
        </form>
    @else
        <p style="font-size: 0.95rem; color: #475569; margin-bottom: 12px;">
            Use the ViLoCare website to reset your password, or ask an administrator.
        </p>
        @if($serverHost)
            <p style="font-size: 0.95rem; color: #0f172a;">Website: <strong>{{ $serverHost }}</strong></p>
        @endif
    @endif

    <div style="margin-top: 20px; text-align: center;">
        <a href="{{ route('login') }}" style="color: #64748b; font-size: 0.85rem;">Back to sign in</a>
    </div>
</div>
</div>
@endsection
