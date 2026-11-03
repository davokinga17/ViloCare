<?php

namespace App\Http\Middleware;

use App\Services\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A non-administrator with no facility (or laboratory) assigned sees no data at all: every page except their
 * profile and the sign-out shows a clear message instead. Never "show everything by default".
 */
class EnsureFacilityAssigned
{
    private const ALLOWED_ROUTES = ['logout', 'profile.show', 'profile.update', 'profile.password', 'password.change', 'password.change.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && AccessScope::forUser($user)->isNone()
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'No facility assigned. Ask an administrator.'], 403);
            }

            return response()->view('errors.no-facility', ['user' => $user], 403);
        }

        return $next($request);
    }
}
