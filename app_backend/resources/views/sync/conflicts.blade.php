@extends('layouts.app')

@section('title', 'Sync Conflict Logs & Resolution')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Sync Conflict History & Audit</h1>
        <p class="page-subtitle">Detailed records of multi-device write collisions resolved via Last-Write-Wins (LWW) or manual override.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('sync.index') }}" class="btn btn-secondary">
            &larr; Back to Sync Center
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total Conflict Incidents</span>
            <div class="stat-icon-wrapper" style="background-color: #f1f5f9; color: #334155;">⚖️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ $totalCount }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid {{ $unresolvedCount > 0 ? '#ef4444' : '#10b981' }};">
        <div class="stat-header">
            <span class="stat-label">Unreviewed / Open</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">⏳</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: {{ $unresolvedCount > 0 ? '#dc2626' : '#059669' }};">
            {{ $unresolvedCount }}
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('sync.conflicts') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="width: 200px;">
                <select name="status" class="form-select">
                    <option value="">All Review Statuses</option>
                    <option value="unresolved" {{ request('status') === 'unresolved' ? 'selected' : '' }}>Open / Unreviewed</option>
                    <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>Resolved</option>
                </select>
            </div>

            <div style="width: 200px;">
                <select name="table" class="form-select">
                    <option value="">All Tables</option>
                    <option value="patients" {{ request('table') === 'patients' ? 'selected' : '' }}>Patients</option>
                    <option value="viral_load_results" {{ request('table') === 'viral_load_results' ? 'selected' : '' }}>Viral Load Results</option>
                    <option value="appointments" {{ request('table') === 'appointments' ? 'selected' : '' }}>Appointments</option>
                    <option value="eac_sessions" {{ request('table') === 'eac_sessions' ? 'selected' : '' }}>EAC Sessions</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['status', 'table']))
                <a href="{{ route('sync.conflicts') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Conflicts Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Table & Record ID</th>
                    <th>Strategy</th>
                    <th>Payload Diffs (Local vs Remote)</th>
                    <th>Resolution Notes</th>
                    <th>Status</th>
                    @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
                        <th style="text-align: right;">Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($conflicts as $item)
                    <tr>
                        <td>
                            <strong style="color: #0f172a;">{{ $item->created_at->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $item->created_at->format('H:i:s') }}</div>
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-size: 0.75rem;">{{ $item->table_name }}</span>
                            <div style="font-family: monospace; font-size: 0.75rem; color: #475569; margin-top: 4px;">
                                {{ Str::limit($item->record_id, 18) }}
                            </div>
                        </td>
                        <td>
                            <span class="badge" style="background: #e0e7ff; color: #3730a3; font-size: 0.75rem;">
                                {{ strtoupper(str_replace('_', ' ', $item->resolution_strategy)) }}
                            </span>
                        </td>
                        <td style="max-width: 320px; font-size: 0.75rem;">
                            <details>
                                <summary style="cursor: pointer; color: var(--primary); font-weight: 600;">View JSON Diffs</summary>
                                <div style="margin-top: 8px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #f8fafc; padding: 8px; border-radius: 4px; border: 1px solid #e2e8f0;">
                                    <div>
                                        <strong style="color: #ea580c;">Local Payload:</strong>
                                        <pre style="max-height: 120px; overflow: auto; font-size: 0.7rem; margin-top: 4px; white-space: pre-wrap;">{{ json_encode($item->local_payload, JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                    <div>
                                        <strong style="color: #059669;">Remote Payload:</strong>
                                        <pre style="max-height: 120px; overflow: auto; font-size: 0.7rem; margin-top: 4px; white-space: pre-wrap;">{{ json_encode($item->remote_payload, JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                </div>
                            </details>
                        </td>
                        <td style="font-size: 0.8rem; color: #475569; max-width: 220px;">
                            {{ $item->resolution_notes }}
                        </td>
                        <td>
                            @if($item->resolved_at)
                                <span class="badge badge-success">Resolved</span>
                                @if($item->resolver)
                                    <div style="font-size: 0.7rem; color: #64748b; margin-top: 2px;">by {{ $item->resolver->name }}</div>
                                @endif
                            @else
                                <span class="badge badge-warning">Auto LWW Applied</span>
                            @endif
                        </td>
                        @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
                            <td style="text-align: right;">
                                @if(!$item->resolved_at)
                                    <form method="POST" action="{{ route('sync.conflicts.resolve', $item) }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="strategy" value="mark_resolved">
                                        <button type="submit" class="btn btn-sm btn-secondary" title="Confirm and mark reviewed">
                                            ✓ Acknowledge
                                        </button>
                                    </form>
                                @else
                                    <span style="color: #94a3b8; font-size: 0.8rem;">Reviewed</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                            No sync conflicts recorded. All offline edits synchronized cleanly!
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($conflicts->hasPages())
        <div class="card-footer">
            {{ $conflicts->links() }}
        </div>
    @endif
</div>
@endsection
