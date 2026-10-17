<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            $allowedRoutes = [
                'password.change',
                'password.change.update',
                'logout',
            ];

            $currentRouteName = $request->route()?->getName();

            if (!in_array($currentRouteName, $allowedRoutes, true)) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'You must change your temporary password before proceeding.',
                        'must_change_password' => true,
                    ], 403);
                }

                return redirect()->route('password.change')
                    ->with('warning', 'You are logging in with a temporary password. Please set a new personal password to continue.');
            }
        }

        return $next($request);
    }
}
