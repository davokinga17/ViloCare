@extends('layouts.guest')

@section('title', 'Link Not Valid')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #f59e0b, #d97706);">⏳</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">This link has expired or was already used</h2>
    </div>
    <p style="font-size: 0.9rem; color: #475569;">
        Links work once and for a limited time. You can ask for a new one from the sign-in page, or ask an administrator to reset your password.
    </p>
    <div style="margin-top: 20px; text-align: center;">
        <a href="{{ route('password.request') }}" class="btn btn-primary">Request a new link</a>
        <div style="margin-top: 12px;"><a href="{{ route('login') }}" style="color: #64748b; font-size: 0.85rem;">Back to sign in</a></div>
    </div>
</div>
</div>
@endsection
