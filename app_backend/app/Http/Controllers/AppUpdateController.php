<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

/**
 * Desktop app only. Administrators (route group `role:administrator`) get a short-lived signed approval for
 * "Restart and update now"; the Electron shell verifies it before it installs anything. Non-administrators can
 * never obtain one, so the role check is enforced here on the server and not just by hiding a button.
 */
class AppUpdateController extends Controller
{
    public const PURPOSE = 'approve-update';

    public const TTL_SECONDS = 60;

    public function approval(): JsonResponse
    {
        $secret = config('sync.update_secret');
        if (! $secret) {
            abort(404); // not running inside the desktop app
        }

        $expires = time() + self::TTL_SECONDS;
        $signature = hash_hmac('sha256', self::PURPOSE.'|'.$expires, (string) $secret);

        AuditLog::log(
            action: 'approved_app_update',
            tableName: 'settings',
            recordId: 'APP-UPDATE',
            oldValues: null,
            newValues: ['app_version' => config('sync.client_version')],
            userId: auth()->id()
        );

        return response()->json(['token' => $expires.'.'.$signature]);
    }
}
