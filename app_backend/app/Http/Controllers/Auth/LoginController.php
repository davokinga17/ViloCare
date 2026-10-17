<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Maximum allowed failed login attempts before lockout.
     */
    protected int $maxAttempts = 5;

    /**
     * Lockout decay duration in seconds.
     */
    protected int $decaySeconds = 60;

    public function showLoginForm(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        // 1. Honeypot Bot Trap: Automated spam bots often fill all hidden form fields
        if ($request->filled('_hp_token')) {
            AuditLog::log(
                action: 'bot_honeypot_triggered',
                tableName: 'users',
                recordId: 'bot-honeypot',
                oldValues: null,
                newValues: [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'honeypot_value' => $request->input('_hp_token'),
                ],
                userId: null
            );

            usleep(300000); // 300ms delay to deter rapid automated bot attempts
            throw ValidationException::withMessages([
                'email' => 'Invalid email address or password provided.',
            ]);
        }

        // 2. Check Brute-Force Rate Limiting Lockout
        $this->ensureIsNotRateLimited($request);

        $rules = [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];

        // 3. Optional Google reCAPTCHA verification if enabled in config
        if (config('services.recaptcha.enabled') && !empty(config('services.recaptcha.secret_key'))) {
            $rules['g-recaptcha-response'] = ['required', 'string'];
        }

        $credentials = $request->validate($rules);

        if (config('services.recaptcha.enabled') && !empty(config('services.recaptcha.secret_key'))) {
            $captchaValid = $this->verifyRecaptcha($request->input('g-recaptcha-response'), $request->ip());
            if (!$captchaValid) {
                RateLimiter::hit($this->throttleKey($request), $this->decaySeconds);
                throw ValidationException::withMessages([
                    'email' => 'Bot security check failed. Please complete the reCAPTCHA challenge.',
                ]);
            }
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            // Increment failed attempt count against rate limiter
            RateLimiter::hit($this->throttleKey($request), $this->decaySeconds);

            $attemptsLeft = RateLimiter::retriesLeft($this->throttleKey($request), $this->maxAttempts);

            $errorMessage = 'Invalid email address or password provided.';
            if ($attemptsLeft <= 2 && $attemptsLeft > 0) {
                $errorMessage .= " ({$attemptsLeft} attempt(s) remaining before temporary lockout).";
            }

            return back()->withErrors([
                'email' => $errorMessage,
            ])->onlyInput('email');
        }

        if (!$user->is_active) {
            return back()->withErrors([
                'email' => 'This account has been deactivated. Please contact your clinic administrator.',
            ])->onlyInput('email');
        }

        // Clear rate limiter on successful authentication
        RateLimiter::clear($this->throttleKey($request));

        // Cache hash for offline credential verification if missing or changed
        if ($user->cached_password_hash !== $user->password) {
            $user->cached_password_hash = $user->password;
            $user->saveQuietly();
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // Record audit log
        AuditLog::log(
            action: 'login',
            tableName: 'users',
            recordId: (string) $user->id,
            oldValues: null,
            newValues: ['ip' => $request->ip(), 'user_agent' => $request->userAgent()],
            userId: $user->id
        );

        if ($user->must_change_password) {
            return redirect()->route('password.change')
                ->with('warning', 'Please update your temporary password to a secure personal password.');
        }

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request): void
    {
        $key = $this->throttleKey($request);

        if (!RateLimiter::tooManyAttempts($key, $this->maxAttempts)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        AuditLog::log(
            action: 'login_lockout',
            tableName: 'users',
            recordId: 'login-lockout',
            oldValues: null,
            newValues: [
                'email' => (string) $request->input('email'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'lockout_seconds' => $seconds,
            ],
            userId: null
        );

        throw ValidationException::withMessages([
            'email' => "Too many failed sign-in attempts. For clinic security, access from your terminal is temporarily suspended. Please wait {$seconds} seconds before trying again.",
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    protected function throttleKey(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email'))) . '|' . $request->ip();
    }

    /**
     * Verify token with Google reCAPTCHA API.
     */
    protected function verifyRecaptcha(string $token, ?string $ip): bool
    {
        try {
            $response = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('services.recaptcha.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]);

            return (bool) $response->json('success', false);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('reCAPTCHA verification error: ' . $e->getMessage());
            return false;
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = Auth::user()) {
            AuditLog::log(
                action: 'logout',
                tableName: 'users',
                recordId: (string) $user->id,
                oldValues: null,
                newValues: null,
                userId: $user->id
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been safely logged out.');
    }
}
