@extends('layouts.guest')

@section('title', 'Sign In')
@section('body-class', 'login-page')

@section('content')
<div class="background-orb orb-one"></div>
<div class="background-orb orb-two"></div>
<main class="login-shell">
    <section class="brand-panel">
        <img class="brand-logo" src="{{ asset('images/vilocarelogo.png') }}" alt="ViLoCare" />
        <h1 class="brand-title">Smarter Viral Load Care Starts Here</h1>
        <p class="brand-copy">Securely access <strong>ViLoCare ART Portal</strong> to manage patient viral load results, coordinate follow-up, and improve care outcomes.</p>
        <div class="badge-row">
            <span class="mini-badge">Secure Access</span>
            <span class="mini-badge">Care Coordination</span>
            <span class="mini-badge">Data Accuracy</span>
        </div>
    </section>

    <section class="form-panel">
        <h2 class="form-title">Welcome back</h2>
        <p class="form-subtitle">Sign in to your account to continue.</p>

        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" autocomplete="off">
            @csrf

            {{-- Anti-bot honeypot field --}}
            <div style="position: absolute; left: -9999px; opacity: 0; pointer-events: none;" aria-hidden="true">
                <input type="text" name="_hp_token" tabindex="-1" autocomplete="off" value="">
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" id="email"
                    placeholder="name@vilocare.org" value="{{ old('email') }}" required autofocus />
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" class="form-control" id="password"
                    placeholder="Enter your password" required />
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember">Remember me</label>
            </div>

            @if(config('services.recaptcha.enabled') && !empty(config('services.recaptcha.site_key')))
                <div class="mb-3">
                    <div class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}"></div>
                </div>
                <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            @endif

            <button type="submit" class="btn login-submit-btn w-100">Sign in</button>

            <div class="text-center mt-3">
                @if(Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="forgot-link">Forgot password?</a>
                @endif
            </div>
        </form>

        {{-- Development-Only Quick Role Switcher: Gated strictly to local development --}}
        @if(app()->environment('local'))
        <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--line);">
            <div style="font-size: 0.72rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; text-align: center;">
                Quick Role Switcher (Local Development Only)
            </div>
            <div class="quick-login-grid">
                <button type="button" class="quick-login-btn" onclick="fillCredentials('admin@vilocare.org', 'Password@123')">
                    <strong>Administrator</strong>
                    <span>admin@vilocare.org</span>
                </button>
                <button type="button" class="quick-login-btn" onclick="fillCredentials('clinician@vilocare.org', 'Password@123')">
                    <strong>Clinician</strong>
                    <span>clinician@vilocare.org</span>
                </button>
                <button type="button" class="quick-login-btn" onclick="fillCredentials('labtech@vilocare.org', 'Password@123')">
                    <strong>Lab Tech</strong>
                    <span>labtech@vilocare.org</span>
                </button>
                <button type="button" class="quick-login-btn" onclick="fillCredentials('dataclerk@vilocare.org', 'Password@123')">
                    <strong>Data Clerk</strong>
                    <span>dataclerk@vilocare.org</span>
                </button>
                <button type="button" class="quick-login-btn" style="grid-column: span 2; border-color: #fde68a; background-color: #fffbeb;" onclick="fillCredentials('newstaff@vilocare.org', 'TempPass@2026')">
                    <strong style="color: #92400e;">⚠️ New Staff (Password Reset Demo)</strong>
                    <span style="color: #b45309;">newstaff@vilocare.org</span>
                </button>
            </div>
        </div>

        <script>
        function fillCredentials(email, pass) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = pass;
        }
        </script>
        @endif
    </section>
</main>
@endsection
