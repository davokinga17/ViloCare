<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SyncService;
use App\Support\DeviceIdentity;
use App\Support\EnvFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "This computer" settings on the Sync Connection page (administrators only; see routes/web.php):
 * the device ID shown on the Sync Center and whether this computer sends SMS reminders.
 * Both live in the local .env and apply from the next request.
 */
class DeviceSettingsController extends Controller
{
    public function __construct(protected SyncService $syncService) {}

    public function update(Request $request): RedirectResponse
    {
        abort_if($this->syncService->isCentral(), 403, 'This is the central server. It sends SMS reminders automatically and has no device settings.');

        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:60', function ($attribute, $value, $fail) {
                if (! DeviceIdentity::isValid((string) $value)) {
                    $fail('Use 3 to 60 letters, numbers, dots, dashes or underscores, and not the shared default "'.DeviceIdentity::DEFAULT_ID.'".');
                }
            }],
            'sends_sms' => ['nullable', 'boolean'],
        ]);

        $old = ['device_id' => config('sync.device_id'), 'sends_sms' => (bool) config('sync.sends_sms')];
        $new = ['device_id' => $data['device_id'], 'sends_sms' => (bool) ($data['sends_sms'] ?? false)];

        try {
            EnvFile::set([
                'VILOCARE_DEVICE_ID' => $new['device_id'],
                'VILOCARE_SENDS_SMS' => $new['sends_sms'] ? 'true' : 'false',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors(['device_id' => 'The settings could not be saved on this computer. Check the logs folder for details.']);
        }

        AuditLog::log(
            action: 'updated_device_settings',
            tableName: 'settings',
            recordId: 'DEVICE',
            oldValues: $old,
            newValues: $new,
            userId: auth()->id()
        );

        return redirect()->route('settings.sync.edit')->with('success', 'Saved. The new device ID and SMS setting apply from the next sync.');
    }
}
