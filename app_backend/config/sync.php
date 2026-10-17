<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ViLoCare Central Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for offline-first synchronization between local
    | clinic SQLite databases and central PostgreSQL on Render.
    |
    */

    /*
    | What this installation is: "clinic" (default, the offline-first desktop app) or "central" (the Render
    | server that clinics sync to). Set explicitly with VILOCARE_ROLE; never guessed from the database.
    | A central server never runs the outbound sync and has no sync URL/token form.
    */
    'role' => strtolower((string) env('VILOCARE_ROLE', 'clinic')) === 'central' ? 'central' : 'clinic',

    /*
    | Version of the desktop app running this PHP process (set by the Electron shell as VILOCARE_APP_VERSION).
    | Null on the web/central server and in tests. Sent to the central server as X-Client-Version.
    */
    'client_version' => env('VILOCARE_APP_VERSION') ?: null,

    /*
    | Central server only: clinics older than this version get HTTP 426 on every sync endpoint.
    | Empty (default) = no enforcement. A request without X-Client-Version counts as "unknown/old" once this is set.
    */
    'min_client_version' => trim((string) env('VILOCARE_MIN_CLIENT_VERSION', '')),

    /*
    | Secret generated per run by the Electron shell (never written to .env). Lets Laravel hand an administrator a
    | short-lived, signed approval for "Restart and update now"; the shell checks it. Null outside the desktop app.
    */
    'update_secret' => env('VILOCARE_UPDATE_SECRET') ?: null,

    // How long the "update hold" (no new sync starts) stays valid, in minutes, if the shell never releases it.
    'update_hold_minutes' => 15,

    'remote_url' => env('VILOCARE_REMOTE_SYNC_URL', ''),

    'api_token' => env('VILOCARE_SYNC_API_TOKEN', ''),

    /*
    | True only when the clinic's own settings supply BOTH the sync URL and token (not the built-in
    | fallbacks above). When false the app runs offline: records stay pending, nothing is sent, and the
    | Sync Center shows a "Sync is not set up" banner.
    */
    'configured' => filled(env('VILOCARE_REMOTE_SYNC_URL')) && filled(env('VILOCARE_SYNC_API_TOKEN')),

    'device_id' => env('VILOCARE_DEVICE_ID', 'clinic-desktop-01'),

    'batch_size' => (int) env('VILOCARE_SYNC_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Standalone Mode
    |--------------------------------------------------------------------------
    |
    | When true, sync never contacts a server and simply marks pending records
    | as synced. This must be switched on explicitly. An endpoint that points at
    | this very computer (localhost / 127.0.0.1) without this flag is reported as
    | a misconfiguration instead of silently discarding the sync.
    |
    */
    'standalone' => (bool) env('VILOCARE_SYNC_STANDALONE', false),

    // Safety overlap (seconds) subtracted from the server-assigned cursor so rows
    // committed in the same second as the cursor are never skipped.
    'cursor_overlap_seconds' => (int) env('VILOCARE_SYNC_CURSOR_OVERLAP', 5),

    // Upper bound of pages (push or pull) processed in a single run. Progress is
    // persisted, so the next run resumes where this one stopped.
    'max_pages_per_run' => (int) env('VILOCARE_SYNC_MAX_PAGES', 200),

    // Seconds before the overlap lock of a crashed run expires.
    'lock_seconds' => (int) env('VILOCARE_SYNC_LOCK_SECONDS', 600),

    // UI health thresholds (seconds).
    'stalled_after_seconds' => 180,
    'stale_after_seconds' => 600,

    /*
    |--------------------------------------------------------------------------
    | SSL Certificate Verification & Bundled CA Authority
    |--------------------------------------------------------------------------
    |
    | On Windows clinic machines, PHP cURL typically lacks system CA root
    | certificates by default, causing "cURL error 60: unable to get local
    | issuer certificate". To avoid requiring manual php.ini edits on clinic
    | computers, ViLoCare bundles a verified Mozilla CA bundle directly with
    | the application and references it in code.
    |
    */
    'ca_bundle' => env('VILOCARE_CA_BUNDLE', base_path('certs/cacert.pem')),

    'ssl_verify' => env('VILOCARE_SSL_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Synced Tables & Model Mappings (in topological dependency order)
    |--------------------------------------------------------------------------
    */
    'synced_models' => [
        // Location reference data comes first: patients and samples reference it.
        'states' => \App\Models\State::class,
        'counties' => \App\Models\County::class,
        'facilities' => \App\Models\Facility::class,
        // Testing laboratories reference facilities (optional link), so they follow them.
        'testing_laboratories' => \App\Models\TestingLaboratory::class,
        'users' => \App\Models\User::class,
        'patients' => \App\Models\Patient::class,
        'regimen_histories' => \App\Models\RegimenHistory::class,
        'viral_load_results' => \App\Models\ViralLoadResult::class,
        'eac_episodes' => \App\Models\EacEpisode::class,
        'eac_sessions' => \App\Models\EacSession::class,
        'sample_collections' => \App\Models\SampleCollection::class,
        'sample_rejections' => \App\Models\SampleRejection::class,
        'appointments' => \App\Models\Appointment::class,
        'notification_logs' => \App\Models\NotificationLog::class,
        'audit_logs' => \App\Models\AuditLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull-only Tables
    |--------------------------------------------------------------------------
    |
    | The cloud is the source of truth for these tables. Clinics never push them
    | (and the server ignores any pushed rows); new states, counties and
    | facilities are added on the cloud and reach clinics through sync.
    |
    */
    'pull_only_tables' => ['states', 'counties', 'facilities', 'testing_laboratories'],

    /*
    |--------------------------------------------------------------------------
    | Retired Reference Rows
    |--------------------------------------------------------------------------
    |
    | Deletion is not synced. A row listed here (table => business codes) was removed on purpose,
    | so a pull must never recreate it, even from an older server or a full re-pull.
    | NPHL-017 was a "facility" only so the lab could appear in a dropdown; it is now a
    | testing laboratory.
    |
    */
    'retired_locations' => [
        'facilities' => ['NPHL-017'],
    ],

];
