@extends('layouts.guest')

@section('title', 'Confirm Recovery Email')

@section('content')
<div class="auth-wrapper">
<div class="auth-card" style="max-width: 480px;">
    <div class="auth-header">
        <div class="auth-logo" style="background: linear-gradient(135deg, #0d9488, #0f766e);">✉️</div>
        <h2 style="font-size: 1.35rem; font-weight: 700; color: #0f172a;">Confirm your recovery email</h2>
    </div>
    <p style="font-size: 0.9rem; color: #475569; margin-bottom: 16px;">
        Press the button to confirm that this address can be used to reset the password of your ViLoCare account.
    </p>
    <form method="POST" action="{{ route('email.verify.confirm', ['token' => $token]) }}">
        @csrf
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 11px;">Confirm email</button>
    </form>
</div>
</div>
@endsection
