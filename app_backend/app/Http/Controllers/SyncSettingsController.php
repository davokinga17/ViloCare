<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SyncService;
use App\Support\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Sync connection" settings (administrators only; see routes/web.php).
 * The URL and token live in the local .env; the token is never shown, logged or audited.
 * A saved change applies to the next request / scheduled sync because each PHP process re-reads .env.
 */
class SyncSettingsController extends Controller
{
    public function __construct(protected SyncService $syncService) {}

    public function edit(): View
    {
        if ($this->syncService->isCentral()) {
            $contact = $this->syncService->lastClinicContact();

            return view('settings.sync', [
                'central' => true,
                'endpoint' => rtrim((string) config('app.url'), '/').'/api/v1/sync',
                'lastClinicSyncAt' => $contact['at'],
                'lastClinicDevice' => $contact['device'],
            ]);
        }

        $token = EnvFile::get('VILOCARE_SYNC_API_TOKEN');

        return view('settings.sync', [
            'url' => EnvFile::get('VILOCARE_REMOTE_SYNC_URL'),
            'hasToken' => $token !== null,
            'maskedToken' => $token !== null ? str_repeat('•', 12) : null,
            'configured' => $this->syncService->isConfigured(),
            'deviceId' => $this->syncService->deviceId(),
            'sendsSms' => (bool) config('sync.sends_sms', false),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if($this->syncService->isCentral(), 403, 'This is the central server. It has no sync URL to save.');

        $data = $this->validated($request, tokenRequired: EnvFile::get('VILOCARE_SYNC_API_TOKEN') === null);
        $oldUrl = EnvFile::get('VILOCARE_REMOTE_SYNC_URL');

        $values = ['VILOCARE_REMOTE_SYNC_URL' => rtrim($data['url'], '/')];
        if (! empty($data['sync_token'])) {
            $values['VILOCARE_SYNC_API_TOKEN'] = $data['sync_token'];
        }

        try {
            EnvFile::set($values);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput($request->except('sync_token'))->withErrors(['url' => 'The settings could not be saved on this computer. Check the logs folder for details.']);
        }

        AuditLog::log(
            action: 'updated_sync_connection',
            tableName: 'settings',
            recordId: 'SYNC-CONNECTION',
            oldValues: ['url' => $oldUrl],
            newValues: ['url' => $values['VILOCARE_REMOTE_SYNC_URL'], 'token_changed' => isset($values['VILOCARE_SYNC_API_TOKEN'])],
            userId: auth()->id()
        );

        return redirect()->route('settings.sync.edit')->with('success', 'Sync connection saved. It is used from the next sync (within a minute).');
    }

    public function test(Request $request): JsonResponse
    {
        abort_if($this->syncService->isCentral(), 403, 'This is the central server. It does not connect out.');

        $data = $this->validated($request, tokenRequired: false);
        $token = ! empty($data['sync_token']) ? $data['sync_token'] : EnvFile::get('VILOCARE_SYNC_API_TOKEN');

        if ($token === null) {
            return response()->json(['ok' => false, 'message' => 'Enter the sync token first.']);
        }

        return response()->json($this->syncService->testConnection($data['url'], $token));
    }

    /**
     * @return array{url: string, sync_token: ?string}
     */
    protected function validated(Request $request, bool $tokenRequired): array
    {
        return $request->validate([
            'url' => ['required', 'url:http,https', 'max:255', 'regex:/^[^"\\\\\s]+$/'],
            'sync_token' => [$tokenRequired ? 'required' : 'nullable', 'string', 'max:255', 'regex:/^[^"\\\\\r\n]+$/'],
        ], [
            'url.url' => 'Enter a full address starting with https:// (or http://).',
            'url.regex' => 'The address cannot contain spaces, quotes or backslashes.',
            'sync_token.regex' => 'The token cannot contain quotes, backslashes or line breaks.',
        ]);
    }
}
