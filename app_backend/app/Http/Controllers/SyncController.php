<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SyncConflict;
use App\Services\BackgroundSyncLauncher;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SyncController extends Controller
{
    protected SyncService $syncService;

    public function __construct(SyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Display the Sync Center dashboard with real-time stats, connection status, and pending changes.
     */
    public function index(): View
    {
        $stats = $this->syncService->getSyncStats();
        $pendingPayload = $this->syncService->getPendingLocalPayload();
        $recentConflicts = SyncConflict::latest()->limit(5)->get();

        return view('sync.index', [
            'stats' => $stats,
            'pendingPayload' => $pendingPayload,
            'recentConflicts' => $recentConflicts,
            'run' => $this->syncService->runStatus(),
        ]);
    }

    /**
     * "Sync now": starts a sync in a detached background process and returns at once. The scheduler does the
     * regular syncing; progress is read from status-check. Never runs the sync inside this request, because the
     * desktop web server handles one request at a time and a slow link would freeze every page.
     */
    public function triggerSync(Request $request, BackgroundSyncLauncher $launcher): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->isJson() || $request->header('X-Requested-With') === 'XMLHttpRequest';

        if ($this->syncService->isCentral()) {
            $message = 'This is the central server. Clinic computers sync to it; it does not sync out.';

            return $wantsJson
                ? response()->json(['success' => false, 'started' => false, 'message' => $message, 'mode' => 'central_server'])
                : redirect()->route('sync.index')->with('warning', $message);
        }

        if (! $this->syncService->isConfigured() && ! config('sync.standalone', false)) {
            $message = 'Sync is not set up. An administrator can add the connection in Settings.';

            return $wantsJson
                ? response()->json(['success' => false, 'started' => false, 'message' => $message, 'mode' => 'not_configured'])
                : redirect()->route('sync.index')->with('warning', $message);
        }

        $alreadyRunning = $this->syncService->runStatus()['running'];
        $started = false;
        if (! $alreadyRunning) {
            $this->syncService->markRunRequested();
            $started = $launcher->start();
        }

        if ($started) {
            AuditLog::log(
                action: 'triggered_sync_cycle',
                tableName: 'sync_conflicts',
                recordId: 'SYNC-RUN',
                oldValues: null,
                newValues: ['mode' => 'background_started'],
                userId: auth()->id()
            );
        }

        $message = match (true) {
            $alreadyRunning => 'A sync is already running. Its progress is shown below.',
            $started => 'Sync started in the background. You can keep working.',
            default => 'The sync could not be started on this computer. The automatic sync will still run every minute.',
        };

        if ($wantsJson) {
            return response()->json([
                'success' => $alreadyRunning || $started,
                'started' => $started,
                'already_running' => $alreadyRunning,
                'message' => $message,
                'run' => $this->syncService->runStatus(),
            ]);
        }

        return redirect()->route('sync.index')->with($alreadyRunning || $started ? 'success' : 'warning', $message);
    }

    /**
     * Sync status for the Sync Center. `?light=1` returns only the run progress and header summary
     * (a few small reads), which is what the progress display polls while a manual sync is running.
     */
    public function statusCheck(Request $request): JsonResponse
    {
        $payload = [
            'success' => true,
            'run' => $this->syncService->runStatus(),
            'summary' => $this->syncService->statusSummary(),
            'server_time' => now()->toIso8601String(),
        ];

        if (! $request->boolean('light')) {
            $payload['stats'] = $this->syncService->getSyncStats();
        }

        return response()->json($payload);
    }

    /**
     * View all logged sync conflicts with side-by-side local vs remote payloads.
     */
    public function conflicts(Request $request): View
    {
        $query = SyncConflict::with('resolver')->latest();

        if ($status = $request->input('status')) {
            if ($status === 'unresolved') {
                $query->whereNull('resolved_at');
            } elseif ($status === 'resolved') {
                $query->whereNotNull('resolved_at');
            }
        }

        if ($table = $request->input('table')) {
            $query->where('table_name', $table);
        }

        $conflicts = $query->paginate(15)->withQueryString();

        return view('sync.conflicts', [
            'conflicts' => $conflicts,
            'unresolvedCount' => SyncConflict::whereNull('resolved_at')->count(),
            'totalCount' => SyncConflict::count(),
        ]);
    }

    /**
     * Manually resolve or override a recorded sync conflict.
     */
    public function resolveConflict(Request $request, SyncConflict $conflict): RedirectResponse
    {
        $validated = $request->validate([
            'strategy' => ['required', 'string', 'in:keep_local,keep_remote,mark_resolved'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $this->syncService->resolveConflict(
            conflict: $conflict,
            strategy: $validated['strategy'],
            overrideData: null,
            userId: auth()->id()
        );

        AuditLog::log(
            action: 'resolved_sync_conflict',
            tableName: 'sync_conflicts',
            recordId: (string) $conflict->id,
            oldValues: ['resolved_at' => null],
            newValues: ['strategy' => $validated['strategy'], 'notes' => $validated['notes'] ?? ''],
            userId: auth()->id()
        );

        return redirect()->route('sync.conflicts')
            ->with('success', "Conflict #{$conflict->id} has been resolved using strategy '{$validated['strategy']}'.");
    }
}
