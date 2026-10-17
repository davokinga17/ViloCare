@extends('layouts.app')

@section('title', 'EAC Adherence Tracking')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Enhanced Adherence Counselling (EAC)</h1>
        <p class="page-subtitle">3-session adherence package and post-counselling viral load monitoring for unsuppressed patients (≥1,000 c/ml).</p>
    </div>
</div>

<!-- EAC KPI Grid -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total EAC Episodes</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">📋</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ $stats['total'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #f59e0b;">
        <div class="stat-header">
            <span class="stat-label">In Active Counselling</span>
            <div class="stat-icon-wrapper" style="background-color: #fffbeb; color: #d97706;">⏳</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #d97706;">{{ $stats['active'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #3b82f6;">
        <div class="stat-header">
            <span class="stat-label">Due for Repeat VL</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb;">🧪</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #2563eb;">{{ $stats['due_repeat_vl'] }}</div>
        <div class="stat-desc">Completed 3 sessions</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #ef4444;">
        <div class="stat-header">
            <span class="stat-label">Flagged: Switch Regimen</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">🚨</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #dc2626;">{{ $stats['flagged'] }}</div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('eac.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by ART # or Patient Name...">
            </div>

            <div style="width: 220px;">
                <select name="status" class="form-select">
                    <option value="">All Episode States</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>In Active Counselling</option>
                    <option value="sessions_completed" {{ request('status') === 'sessions_completed' ? 'selected' : '' }}>Sessions Complete (Due Repeat VL)</option>
                    <option value="resolved_suppressed" {{ request('status') === 'resolved_suppressed' ? 'selected' : '' }}>Resolved (Re-suppressed)</option>
                    <option value="flagged_for_regimen_switch" {{ request('status') === 'flagged_for_regimen_switch' ? 'selected' : '' }}>🚨 Suspected treatment failure: clinical review</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'status']))
                <a href="{{ route('eac.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- EAC Episodes Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Triggering High VL</th>
                    <th>Sessions Progress</th>
                    <th>Episode Status</th>
                    <th>Repeat VL Due Date</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($episodes as $ep)
                    <tr>
                        <td>
                            <a href="{{ route('patients.show', $ep->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $ep->patient->art_number }}
                            </a>
                            <div style="font-size: 0.85rem; color: #0f172a; font-weight: 600;">{{ $ep->patient->full_name }}</div>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $ep->patient->facility?->name }}</div>
                        </td>
                        <td>
                            <strong style="color: #dc2626; font-size: 1rem;">
                                {{ number_format($ep->viralLoadResult?->result_value ?? 0) }} copies/ml
                            </strong>
                            <div style="font-size: 0.75rem; color: #64748b;">Tested: {{ $ep->viralLoadResult?->result_date->format('Y-m-d') }}</div>
                        </td>
                        <td>
                            @php
                                $completed = $ep->completedSessionsCount();
                            @endphp
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <strong style="color: {{ $completed === 3 ? '#059669' : '#d97706' }}; font-size: 0.95rem;">
                                    Session {{ $completed }} of 3
                                </strong>
                            </div>
                            <div style="width: 120px; height: 6px; background-color: #e2e8f0; border-radius: 9999px; margin-top: 4px; overflow: hidden;">
                                <div style="width: {{ ($completed / 3) * 100 }}%; height: 100%; background-color: {{ $completed === 3 ? '#10b981' : '#f59e0b' }};"></div>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $ep->statusBadgeClass() }}">
                                {{ $ep->statusLabel() }}
                            </span>
                        </td>
                        <td>
                            @if($ep->repeat_vl_due_date)
                                <strong style="color: #2563eb;">{{ $ep->repeat_vl_due_date->format('Y-m-d') }}</strong>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">Pending 3rd Session</span>
                            @endif
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('eac.show', $ep) }}" class="btn btn-primary btn-sm">
                                📋 Open Sessions &rarr;
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 36px; color: #64748b;">
                            No active or historical EAC episodes found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($episodes->hasPages())
        <div class="card-footer">
            {{ $episodes->links() }}
        </div>
    @endif
</div>
@endsection
