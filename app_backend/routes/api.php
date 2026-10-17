<?php

use App\Http\Controllers\Api\SyncApiController;
use App\Http\Middleware\EnforceMinClientVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ViLoCare Central Sync API Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/sync')->group(function () {
    // Health check stays open to every version; everything that moves data checks X-Client-Version.
    Route::get('/status', [SyncApiController::class, 'status']);
    Route::middleware(EnforceMinClientVersion::class)->group(function () {
        Route::post('/push', [SyncApiController::class, 'push']);
        Route::get('/pull', [SyncApiController::class, 'pull']);
        Route::get('/counts', [SyncApiController::class, 'counts']);
        Route::get('/locations', [SyncApiController::class, 'locations']);
        Route::post('/full', [SyncApiController::class, 'full']);
    });
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'healthy',
        'service' => 'ViLoCare Sync API',
        'timestamp' => now()->toIso8601String(),
    ]);
});
