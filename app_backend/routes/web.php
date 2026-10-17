<?php

use App\Http\Controllers\AiInsightController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppUpdateController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EacController;
use App\Http\Controllers\IntegrationSettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RegimenHistoryController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SampleController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SyncSettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ViralLoadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Guest Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Forced First-Login Password Change Routes (exempted from redirect)
    Route::get('/password/change', [PasswordChangeController::class, 'show'])->name('password.change');
    Route::post('/password/change', [PasswordChangeController::class, 'update'])->name('password.change.update');

    // Protected Routes (enforces must_change_password)
    Route::middleware('password.changed')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('home');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Staff Profile Management
        Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Patient Management (Admin, Clinician, Data Clerk)
        Route::middleware('role:administrator,clinician,data_clerk')->group(function () {
            Route::get('/patients/import', [PatientController::class, 'importForm'])->name('patients.import');
            Route::post('/patients/import', [PatientController::class, 'import'])->name('patients.import.process');
            Route::get('/patients/download-template', [PatientController::class, 'downloadTemplate'])->name('patients.download-template');
            Route::resource('patients', PatientController::class);

            // Regimen Switching (Clinician & Admin only)
            Route::post('/patients/{patient}/regimen', [RegimenHistoryController::class, 'store'])
                ->middleware('role:administrator,clinician')
                ->name('patients.regimen.store');
        });

        // Viral Load Engine (Admin, Clinician, Lab Technician)
        Route::middleware('role:administrator,clinician,lab_technician')->group(function () {
            Route::resource('viral-load', ViralLoadController::class)->only(['index', 'create', 'store', 'show']);
        });

        // EAC (Enhanced Adherence Counselling) (Admin, Clinician)
        Route::middleware('role:administrator,clinician')->group(function () {
            Route::get('/eac', [EacController::class, 'index'])->name('eac.index');
            Route::get('/eac/{eac}', [EacController::class, 'show'])->name('eac.show');
            Route::put('/eac/sessions/{session}', [EacController::class, 'updateSession'])->name('eac.sessions.update');
        });

        // User Management (Admin & Clinician only)
        Route::middleware('role:administrator,clinician')->group(function () {
            Route::resource('users', UserController::class)->except(['show']);
            Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        });

        // Sample Management (Admin, Clinician, Lab Technician, Data Clerk)
        Route::middleware('role:administrator,clinician,lab_technician,data_clerk')->group(function () {
            Route::get('/samples', [SampleController::class, 'index'])->name('samples.index');
            Route::get('/samples/create', [SampleController::class, 'create'])->name('samples.create');
            Route::post('/samples', [SampleController::class, 'store'])->name('samples.store');
            Route::get('/samples/{sample}', [SampleController::class, 'show'])->name('samples.show');

            // Lab Reception (Admin & Lab Tech only)
            Route::post('/samples/{sample}/receive', [SampleController::class, 'receive'])
                ->middleware('role:administrator,lab_technician')
                ->name('samples.receive');

            // Rejection Logging (Admin, Lab Tech, Clinician)
            Route::post('/samples/{sample}/reject', [SampleController::class, 'reject'])
                ->middleware('role:administrator,lab_technician,clinician')
                ->name('samples.reject');
        });

        // Appointments Management (Admin, Clinician, Data Clerk)
        Route::middleware('role:administrator,clinician,data_clerk')->group(function () {
            Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
            Route::get('/appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
            Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
            Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
            Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->name('appointments.status');
            Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
        });

        // Module 10: SMS Notifications (Admin, Clinician, Data Clerk)
        Route::middleware('role:administrator,clinician,data_clerk')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::get('/notifications/create', [NotificationController::class, 'create'])->name('notifications.create');
            Route::post('/notifications', [NotificationController::class, 'store'])->name('notifications.store');
            Route::post('/notifications/{notification}/retry', [NotificationController::class, 'retry'])->name('notifications.retry');
            Route::post('/notifications/bulk-due', [NotificationController::class, 'bulkDueReminders'])->name('notifications.bulk-due');
        });

        // Reports & MoH Registry (All Staff)
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/patients', [ReportController::class, 'patientList'])->name('reports.patients');
        Route::get('/reports/viral-load', [ReportController::class, 'viralLoadSummary'])->name('reports.viral-load');
        Route::get('/reports/eac', [ReportController::class, 'eacStatus'])->name('reports.eac');
        Route::get('/reports/due-list', [ReportController::class, 'dueList'])->name('reports.due-list');
        Route::get('/reports/verify', [ReportController::class, 'verify'])->name('reports.verify');

        // Module 11: Sync Engine Center (All Staff for view/sync, Admin/Clinician for conflict resolution)
        Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
        Route::post('/sync/trigger', [SyncController::class, 'triggerSync'])->name('sync.trigger');
        Route::get('/sync/status-check', [SyncController::class, 'statusCheck'])->name('sync.status-check');
        Route::get('/sync/conflicts', [SyncController::class, 'conflicts'])->name('sync.conflicts');
        Route::post('/sync/conflicts/{conflict}/resolve', [SyncController::class, 'resolveConflict'])
            ->middleware('role:administrator,clinician')
            ->name('sync.conflicts.resolve');

        // Module 12: AI-Powered Insights & Staff Q&A Assistant (Admin, Clinician only)
        Route::middleware('role:administrator,clinician')->group(function () {
            Route::get('/insights/summary', [AiInsightController::class, 'getSummary'])->name('insights.summary.get');
            Route::post('/insights/summary/generate', [AiInsightController::class, 'generateSummary'])->name('insights.summary.generate');
            Route::get('/insights/qa', [AiInsightController::class, 'qaIndex'])->name('insights.qa.index');
            Route::post('/insights/qa', [AiInsightController::class, 'askQa'])
                ->middleware('throttle:30,1')
                ->name('insights.qa.ask');
            Route::get('/insights/preview-payload', [AiInsightController::class, 'previewPayload'])->name('insights.preview-payload');
        });

        // Audit Logs (Admin only)
        Route::middleware('role:administrator')->group(function () {
            Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

            // Sync connection settings (URL + token in the local .env)
            Route::get('/settings/sync', [SyncSettingsController::class, 'edit'])->name('settings.sync.edit');
            Route::put('/settings/sync', [SyncSettingsController::class, 'update'])->name('settings.sync.update');
            Route::post('/settings/sync/test', [SyncSettingsController::class, 'test'])->middleware('throttle:20,1')->name('settings.sync.test');

            // Desktop app: administrator approval for "Restart and update now" (see AppUpdateController)
            Route::post('/app-update/approval', [AppUpdateController::class, 'approval'])->middleware('throttle:10,1')->name('app-update.approval');

            // Integrations (Gemini + Africa's Talking credentials in the local .env)
            Route::get('/settings/integrations', [IntegrationSettingsController::class, 'edit'])->name('settings.integrations.edit');
            Route::put('/settings/integrations/gemini', [IntegrationSettingsController::class, 'updateGemini'])->name('settings.integrations.gemini.update');
            Route::put('/settings/integrations/sms', [IntegrationSettingsController::class, 'updateSms'])->name('settings.integrations.sms.update');
            Route::post('/settings/integrations/gemini/test', [IntegrationSettingsController::class, 'testGemini'])->middleware('throttle:20,1')->name('settings.integrations.gemini.test');
            Route::post('/settings/integrations/sms/test', [IntegrationSettingsController::class, 'testSms'])->middleware('throttle:20,1')->name('settings.integrations.sms.test');
            // Sends one real SMS: tighter limit so it cannot be used to spam a phone.
            Route::post('/settings/integrations/sms/send-test', [IntegrationSettingsController::class, 'sendTestSms'])->middleware('throttle:5,1')->name('settings.integrations.sms.send-test');
        });
    });
});
