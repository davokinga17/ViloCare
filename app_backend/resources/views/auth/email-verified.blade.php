@extends('layouts.guest')

@section('title', 'Email Confirmed')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #0d9488, #0f766e);">✅</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">Recovery email confirmed</h2>
    </div>
    <p style="font-size: 0.9rem; color: #475569;">
        If you ever forget your password, use "Forgot password?" on the sign-in page and we will send a reset link to this address.
    </p>
    <div style="margin-top: 20px; text-align: center;">
        <a href="{{ route('login') }}" class="btn btn-primary">Go to sign in</a>
    </div>
</div>
</div>
@endsection
