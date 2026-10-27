<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncApiController extends Controller
{
    protected SyncService $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Verify the sync API authentication token.
     */
    protected function authorizeSyncToken(Request $request): ?JsonResponse
    {
        $expectedToken = (string) config('sync.api_token', '');

        // No token configured on this server: refuse everything instead of accepting a well-known default.
        if ($expectedToken === '') {
            return response()->json([
                'success' => false,
                'error' => 'Sync is not configured on this server (VILOCARE_SYNC_API_TOKEN is empty).',
            ], 503);
        }

        $providedToken = $request->header('X-Sync-Token') ?? $request->bearerToken() ?? $request->input('api_token');

        if (empty($providedToken) || !hash_equals($expectedToken, (string) $providedToken)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized: Invalid or missing X-Sync-Token.',
            ], 401);
        }

        return null;
    }

    /**
     * Push endpoint: Receives client batch data and applies Last-Write-Wins (LWW) resolution.
     */
    public function push(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeSyncToken($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'tables' => ['required', 'array'],
        ]);

        $this->syncService->recordClinicContact($validated['device_id'], $request->header('X-Client-Version'));

        try {
            $result = $this->syncService->processServerPush($validated, $validated['device_id']);
            return response()->json($result);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process sync push: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pull endpoint: Sends server records modified since a timestamp.
     */
    public function pull(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeSyncToken($request)) {
            return $authError;
        }

        $this->syncService->recordClinicContact($request->header('X-Device-Id') ?? $request->query('device_id'), $request->header('X-Client-Version'));

        $since = $request->query('since');

        // Optional continuation point for paging; older clients omit it and receive page one.
        $resume = $request->query('resume_table')
            ? [
                'table' => (string) $request->query('resume_table'),
                'updated_at' => $request->query('resume_updated_at'),
                'id' => $request->query('resume_id'),
            ]
            : null;

        try {
            $result = $this->syncService->processServerPull($since, $resume);
            return response()->json($result);
        } catch (Exception $e) {
            \Illuminate\Support\Facades\Log::error('SyncApiController::pull exception: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to process sync pull: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Counts endpoint: lightweight per-table row counts for the read-only compare diagnostic.
     */
    public function counts(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeSyncToken($request)) {
            return $authError;
        }

        if ($request->header('X-Device-Id')) {
            $this->syncService->recordClinicContact($request->header('X-Device-Id'), $request->header('X-Client-Version'));
        }

        try {
            return response()->json($this->syncService->processServerCounts());
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to count records: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Locations endpoint: the complete state/county/facility reference list (read-only).
     */
    public function locations(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeSyncToken($request)) {
            return $authError;
        }

        try {
            return response()->json($this->syncService->processServerLocations());
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to read locations: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Combined Full Sync endpoint: Handles both push and pull in a single round-trip.
     */
    public function full(Request $request): JsonResponse
    {
        if ($authError = $this->authorizeSyncToken($request)) {
            return $authError;
        }

        $validated = $request->validate([
            'device_id' => ['required', 'string'],
            'timestamp' => ['required', 'string'],
            'tables' => ['required', 'array'],
            'since' => ['nullable', 'string'],
        ]);

        $this->syncService->recordClinicContact($validated['device_id'], $request->header('X-Client-Version'));

        try {
            $pushResult =$this->syncService->processServerPush($validated, $validated['device_id']);
            $pullResult = $this->syncService->processServerPull($validated['since'] ?? null);

            return response()->json([
                'success' => true,
                'push' => $pushResult,
                'pull' => $pullResult,
                'server_time' => now()->toIso8601String(),
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Full sync operation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Server Health & Sync Status endpoint.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'ViLoCare Central Sync Engine (PostgreSQL/Cloud)',
            'server_time' => now()->toIso8601String(),
            'version' => '1.0.0',
            'auth_required' => true,
        ]);
    }
}
