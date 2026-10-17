<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'role' => EnsureUserRole::class,
            'password.changed' => EnsurePasswordChanged::class,
        ]);

        $middleware->appendToGroup('web', [
            EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never flash the sync token or integration keys back into a form (or session) after a validation error.
        $exceptions->dontFlash(['sync_token', 'gemini_key', 'sms_key', 'twilio_sid', 'twilio_token']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// The desktop shell keeps its .env outside the install folder so updates never touch it.
if ($envDir = getenv('VILOCARE_ENV_DIR')) {
    $app->useEnvironmentPath($envDir);
}

return $app;
