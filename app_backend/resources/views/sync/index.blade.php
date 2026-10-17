@extends('layouts.app')

@section('title', 'Sync Center & Offline Engine')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Sync Center & Offline Engine</h1>
        <p class="page-subtitle">Reconciles local clinic database with central PostgreSQL cloud server using deterministic Last-Write-Wins (LWW).</p>
        @if($stats['app_version'] ?? null)
            <p class="page-subtitle" id="app-version" style="margin-top: 4px;">ViLoCare app version <strong>{{ $stats['app_version'] }}</strong></p>
        @endif
    </div>
    <div class="page-actions">
        <a href="{{ route('sync.conflicts') }}" class="btn btn-secondary">
            <span>⚖️</span> Conflict History ({{ $stats['total_conflicts'] }})
        </a>
        @unless($stats['central'] ?? false)
        <form method="POST" action="{{ route('sync.trigger') }}" style="display: inline;">
            @csrf
            <button type="submit" class="btn btn-primary" id="btn-sync-now" data-running="{{ ($run['running'] ?? false) ? '1' : '0' }}">
                <span>🔄</span> Run Full Sync Now
            </button>
        </form>
        @endunless
    </div>
</div>

@unless($stats['configured'] ?? true)
<div class="card" id="sync-not-configured-banner" style="margin-bottom: 20px; border-left: 4px solid #f59e0b; background: #fffbeb;">
    <div class="card-body" style="padding: 16px 22px;">
        <strong>Sync is not set up.</strong> An administrator can add the connection in Settings.
        @if(auth()->user()->isAdmin())
            <a href="{{ route('settings.sync.edit') }}" style="margin-left: 8px; font-weight: 600;">Open Sync Connection settings &rarr;</a>
        @endif
    </div>
</div>
@endunless

<!-- Connectivity & Sync State Banner -->
<div class="card" style="margin-bottom: 20px; border-left: 4px solid var(--primary); background: linear-gradient(to right, #f0fdfa, #ffffff);">
    <div class="card-body" style="padding: 18px 22px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div style="display: flex; align-items: center; gap: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: #ccfbf1; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                🔄
            </div>
            <div>
                <div style="font-weight: 700; font-size: 1.05rem; color: #0f172a; display: flex; align-items: center; gap: 8px;">
                    <span>Local Clinic Station:</span>
                    <code style="background: #e2e8f0; padding: 2px 8px; border-radius: 4px; font-size: 0.9rem;">{{ $stats['device_id'] }}</code>
                    @if($stats['standalone'])
                        <span class="badge" id="auto-sync-badge" style="font-size: 0.75rem; margin-left: 6px; background: #fef3c7; color: #b45309;" title="VILOCARE_SYNC_STANDALONE is on: records are not sent to the cloud.">⚠️ Standalone mode - cloud sync disabled</span>
                    @else
                        <span class="badge {{ $stats['auto_sync_stalled'] ? '' : 'badge-success' }}" id="auto-sync-badge"
                              data-heartbeat-age="{{ $stats['heartbeat_age_seconds'] ?? '' }}"
                              data-stalled-after="{{ config('sync.stalled_after_seconds', 180) }}"
                              style="font-size: 0.75rem; margin-left: 6px; {{ $stats['auto_sync_stalled'] ? 'background: #fef3c7; color: #b45309;' : '' }}">
                            @if(!$stats['auto_sync_stalled'])
                                ⚡ Auto-Sync Active (Every 60s)
                            @elseif($stats['heartbeat_at'])
                                ⚠️ Auto-sync stalled, last run {{ $stats['heartbeat_at']->diffForHumans(null, true) }} ago
                            @else
                                ⚠️ Auto-sync stalled, no run recorded yet
                            @endif
                        </span>
                    @endif
                </div>
                <div style="font-size: 0.85rem; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span>Target Central Cloud: <strong>{{ $stats['remote_url'] }}</strong></span>
                    @if(isset($stats['ssl_status']))
                        @if($stats['ssl_status']['ca_bundle_exists'])
                            <span class="badge" style="background: #ecfdf5; color: #047857; font-size: 0.72rem; border: 1px solid #a7f3d0;" title="CA Bundle: {{ $stats['ssl_status']['ca_bundle_path'] }}">
                                🔒 Bundled CA Active (No php.ini edits needed)
                            </span>
                        @else
                            <span class="badge" style="background: #fef3c7; color: #b45309; font-size: 0.72rem;" title="System CA store">
                                ⚠️ System CA Store
                            </span>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 20px;">
            <div style="text-align: right;">
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; font-weight: 600;">Last Successful Sync</div>
                <div style="font-weight: 700; color: {{ $stats['last_sync_stale'] ? '#b45309' : '#0f172a' }};" id="stat-last-synced-val"
                     data-last-sync-age="{{ $stats['last_synced_age_seconds'] ?? '' }}"
                     data-stale-after="{{ config('sync.stale_after_seconds', 600) }}">
                    @if($stats['last_synced_at'])
                        {{ $stats['last_synced_at']->diffForHumans() }}
                        <span style="font-size: 0.8rem; font-weight: normal; color: #64748b;">({{ $stats['last_synced_at']->format('Y-m-d H:i') }})</span>
                    @else
                        <span style="color: #ea580c;">Initial Sync Required</span>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Persistent last-run problem, so a failed or partial sync is never shown as an endless "Syncing..." --}}
@if(!empty($stats['last_error']) && !session('sync_error_details'))
    <div class="card" id="sync-last-error" style="margin-bottom: 20px; border-left: 4px solid #f59e0b; background: #fffbeb;">
        <div class="card-body" style="padding: 16px 20px;">
            <h3 style="margin: 0 0 6px 0; font-size: 1rem; color: #92400e; font-weight: 700;">
                📡 {{ $stats['last_error']['title'] ?? 'Sync Notice' }}
                <span style="font-weight: normal; font-size: 0.8rem; color: #a16207;">
                    ({{ \Illuminate\Support\Carbon::parse($stats['last_error']['at'])->diffForHumans() }})
                </span>
            </h3>
            <p style="margin: 0 0 8px 0; font-size: 0.9rem; color: #78350f; line-height: 1.5;">{{ $stats['last_error']['friendly_message'] ?? '' }}</p>
            <div style="background: #fef3c7; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; color: #92400e; display: inline-block;">
                ✅ {{ $stats['last_error']['action_hint'] ?? 'Local clinic data is safely stored.' }}
            </div>
        </div>
    </div>
@endif

@if(session('sync_error_details'))
    @php
        $errDetails = session('sync_error_details');
    @endphp
    <div class="card" style="margin-bottom: 20px; border-left: 4px solid #f59e0b; background: #fffbeb;">
        <div class="card-body" style="padding: 16px 20px;">
            <div style="display: flex; align-items: flex-start; gap: 12px;">
                <span style="font-size: 1.5rem; line-height: 1;">
                    @if(($errDetails['type'] ?? '') === 'ssl_certificate')
                        🛡️
                    @else
                        📡
                    @endif
                </span>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 6px 0; font-size: 1rem; color: #92400e; font-weight: 700;">
                        {{ $errDetails['title'] ?? 'Cloud Sync Notice' }}
                    </h3>
                    <p style="margin: 0 0 8px 0; font-size: 0.9rem; color: #78350f; line-height: 1.5;">
                        {{ $errDetails['friendly_message'] ?? '' }}
                    </p>
                    <div style="background: #fef3c7; padding: 8px 12px; border-radius: 6px; font-size: 0.85rem; color: #92400e; display: inline-block;">
                        ✅ <strong>Data Safety Guarantee:</strong> {{ $errDetails['action_hint'] ?? 'Local offline data is safely preserved in SQLite.' }}
                    </div>

                    @if(!empty($errDetails['raw_error']))
                        <details style="margin-top: 10px; font-size: 0.8rem; color: #6b7280; cursor: pointer;">
                            <summary style="font-weight: 600; color: #b45309;">Technical Diagnostic Details (For IT / System Administrator)</summary>
                            <div style="margin-top: 6px; padding: 8px; background: #ffffff; border: 1px solid #fde68a; border-radius: 4px; font-family: monospace; font-size: 0.75rem; word-break: break-all; color: #374151;">
                                {{ $errDetails['raw_error'] }}
                            </div>
                        </details>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card" id="stat-pending-card" style="padding: 18px; border-left: 4px solid {{ $stats['total_pending'] > 0 ? '#ea580c' : '#10b981' }};">
        <div class="stat-header">
            <span class="stat-label">Pending Local Changes</span>
            <div class="stat-icon-wrapper" style="background-color: #fff7ed; color: #ea580c;">📤</div>
        </div>
        <div class="stat-val" id="stat-pending-val" style="font-size: 1.8rem; color: {{ $stats['total_pending'] > 0 ? '#ea580c' : '#059669' }};">
            {{ $stats['total_pending'] }}
        </div>
        <div class="stat-desc" id="stat-pending-desc">
            {{ $stats['total_pending'] > 0 ? 'Ready to upload on next connection' : 'All local changes synchronized' }}
        </div>
    </div>

    <div class="stat-card" style="padding: 18px; border-left: 4px solid #0d9488;">
        <div class="stat-header">
            <span class="stat-label">Synced Records (Total)</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">💾</div>
        </div>
        <div class="stat-val" id="stat-synced-val" style="font-size: 1.8rem; color: #0f766e;">
            {{ $stats['total_synced'] }}
        </div>
        <div class="stat-desc">Synchronized with central PostgreSQL</div>
    </div>

    <div class="stat-card" style="padding: 18px; border-left: 4px solid {{ $stats['unresolved_conflicts'] > 0 ? '#dc2626' : '#64748b' }};">
        <div class="stat-header">
            <span class="stat-label">Sync Conflicts</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">⚖️</div>
        </div>
        <div class="stat-val" id="stat-conflicts-val" style="font-size: 1.8rem; color: {{ $stats['unresolved_conflicts'] > 0 ? '#dc2626' : '#334155' }};">
            {{ $stats['total_conflicts'] }}
        </div>
        <div class="stat-desc">
            @if($stats['unresolved_conflicts'] > 0)
                <span style="color: #dc2626; font-weight: 600;">{{ $stats['unresolved_conflicts'] }} require attention</span>
            @else
                <span>All resolved automatically via LWW</span>
            @endif
        </div>
    </div>
</div>

<!-- Synced Tables Breakdown -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2 class="card-title">Synchronized Clinical Tables & Status</h2>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Clinical Domain Table</th>
                    <th>Pending Upload</th>
                    <th>Synced Records</th>
                    <th>Total Records</th>
                    <th>Sync Status</th>
                </tr>
            </thead>
            <tbody id="sync-tables-tbody">
                @foreach($stats['tables'] as $tableName => $tableInfo)
                    <tr id="sync-row-{{ $tableName }}">
                        <td>
                            <strong style="color: #0f172a;">{{ $tableInfo['label'] }}</strong>
                            <div style="font-size: 0.75rem; font-family: monospace; color: #64748b;">{{ $tableName }}</div>
                        </td>
                        <td id="table-pending-{{ $tableName }}">
                            @if($tableInfo['pending'] > 0)
                                <span class="badge" style="background: #ffedd5; color: #9a3412; font-weight: 700;">
                                    {{ $tableInfo['pending'] }} pending
                                </span>
                            @else
                                <span style="color: #94a3b8; font-size: 0.85rem;">0</span>
                            @endif
                        </td>
                        <td id="table-synced-{{ $tableName }}">
                            <span style="font-weight: 600; color: #0f766e;">{{ $tableInfo['synced'] }}</span>
                        </td>
                        <td id="table-total-{{ $tableName }}">
                            <span style="color: #334155; font-weight: 500;">{{ $tableInfo['total'] }}</span>
                        </td>
                        <td>
                            @if($tableInfo['pending'] > 0)
                                <span class="badge badge-warning" id="table-status-{{ $tableName }}">Awaiting Sync</span>
                            @else
                                <span class="badge badge-success" id="table-status-{{ $tableName }}">Up to Date</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Architecture & Conflict Rule Guide -->
<div class="card" style="border-left: 4px solid #3b82f6;">
    <div class="card-header">
        <h2 class="card-title">💡 ViLoCare Offline-First Sync Architecture Rules</h2>
    </div>
    <div class="card-body" style="font-size: 0.875rem; color: #475569; line-height: 1.6;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
                <strong style="color: #0f172a;">1. Client-Generated UUIDs:</strong>
                <p style="margin-top: 4px;">
                    Every clinical record receives a stable UUID locally at creation time. This guarantees zero ID collisions when merging records from multiple offline clinic computers.
                </p>
            </div>
            <div>
                <strong style="color: #0f172a;">2. Last-Write-Wins (LWW) Resolution:</strong>
                <p style="margin-top: 4px;">
                    When reconciling simultaneous modifications to the same patient or test result, the version with the most recent <code>last_modified_at</code> timestamp takes precedence.
                </p>
            </div>
            <div>
                <strong style="color: #0f172a;">3. Zero Silent Discard:</strong>
                <p style="margin-top: 4px;">
                    Whenever a server or client overwrites a conflicting edit, both versions are recorded in the <code>sync_conflicts</code> table for full clinical auditability and manual review.
                </p>
            </div>
            <div>
                <strong style="color: #0f172a;">4. Offline SMS & Auth Resiliency:</strong>
                <p style="margin-top: 4px;">
                    Staff can log in offline using cached credential hashes. Patient SMS messages created offline are safely queued and automatically dispatched once connectivity returns.
                </p>
            </div>
            <div>
                <strong style="color: #0f172a;">5. Zero-Config Bundled SSL CA:</strong>
                <p style="margin-top: 4px;">
                    Windows clinic stations sync securely over HTTPS using the bundled <code>certs/cacert.pem</code> authority, eliminating cURL error 60 without requiring manual <code>php.ini</code> edits on clinic machines.
                </p>
            </div>
        </div>
    </div>
</div>
@endsection
