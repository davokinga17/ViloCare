<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central server: refuse sync requests from app versions below VILOCARE_MIN_CLIENT_VERSION (HTTP 426).
 * Off while the setting is empty. Once it is set, a request with no X-Client-Version header is treated as
 * an old client (versions before 1.1.0 never send it) and refused too.
 */
class EnforceMinClientVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $minimum = trim((string) config('sync.min_client_version', ''));
        if ($minimum === '') {
            return $next($request);
        }

        $version = trim((string) $request->header('X-Client-Version', ''));
        if ($version === '' || version_compare($this->normalise($version), $this->normalise($minimum), '<')) {
            return response()->json([
                'success' => false,
                'error' => "This version of ViLoCare is out of date. Update to {$minimum} or newer to sync.",
                'minimum_version' => $minimum,
                'client_version' => $version === '' ? null : $version,
            ], 426);
        }

        return $next($request);
    }

    /** "1.1.1-beta.1" -> "1.1.1-beta.1" is understood by version_compare (pre-release sorts below the release). */
    private function normalise(string $v): string
    {
        return ltrim($v, 'vV');
    }
}
