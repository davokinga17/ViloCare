<?php

namespace App\Http\Middleware;

use App\Services\AccessScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the sync API and the sync centre: every clinic computer holds ALL data, so syncing must never be
 * narrowed to the logged-in user's facility (it would duplicate or lose records).
 */
class UnscopedAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        AccessScope::suspend();
        try {
            return $next($request);
        } finally {
            AccessScope::resume();
        }
    }
}
