@extends('layouts.app')

@section('title', 'Viral Load Engine')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Viral Load Engine</h1>
        <p class="page-subtitle">Automated national algorithm monitoring, suppression tracking, and due date scheduling.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('viral-load.create') }}" class="btn btn-primary">
            <span>🔬</span> Record Viral Load Result
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total Tests Logged</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">🧪</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ $stats['total'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Suppressed (&lt;1,000 c/ml)</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">🛡️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #059669;">
            {{ $stats['suppressed'] }} <span style="font-size: 0.9rem; color: #64748b;">({{ $stats['suppression_rate'] }}% of patients, coverage {{ $stats['vl_coverage_percent'] }}%)</span>
        </div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #f59e0b;">
        <div class="stat-header">
            <span class="stat-label">Unsuppressed (EAC)</span>
            <div class="stat-icon-wrapper" style="background-color: #fffbeb; color: #d97706;">⚠️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #d97706;">{{ $stats['unsuppressed'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #ef4444;">
        <div class="stat-header">
            <span class="stat-label">Flagged for Review</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">🚨</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #dc2626;">{{ $stats['flagged'] }}</div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('viral-load.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by ART #, Patient Name...">
            </div>

            <div style="width: 180px;">
                <select name="status" class="form-select">
                    <option value="">All Results</option>
                    <option value="suppressed" {{ request('status') === 'suppressed' ? 'selected' : '' }}>Suppressed (&lt;1,000)</option>
                    <option value="unsuppressed" {{ request('status') === 'unsuppressed' ? 'selected' : '' }}>High VL (≥1,000)</option>
                </select>
            </div>

            <div style="width: 190px;">
                <select name="flagged" class="form-select">
                    <option value="">All Clinical States</option>
                    <option value="1" {{ request('flagged') === '1' ? 'selected' : '' }}>🚨 Suspected treatment failure: clinical review</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'status', 'flagged']))
                <a href="{{ route('viral-load.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Results Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Sample & Result Date</th>
                    <th>Patient</th>
                    <th>Result (Copies/ml)</th>
                    <th>Suppression Status</th>
                    <th>Algorithm Next Due Date</th>
                    <th>Testing Lab</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $res)
                    <tr style="{{ !$res->is_suppressed ? 'background-color: #fffdfa;' : '' }}">
                        <td style="white-space: nowrap;">
                            <strong style="color: #0f172a;">{{ $res->result_date->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">Sample: {{ $res->sample_date->format('Y-m-d') }}</div>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $res->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $res->patient->art_number }}
                            </a>
                            <div style="font-size: 0.8rem; color: #0f172a;">{{ $res->patient->full_name }} ({{ $res->patient->ageCohort() }})</div>
                        </td>
                        <td>
                            <strong style="font-size: 1.05rem; color: {{ $res->is_suppressed ? '#059669' : '#dc2626' }};">
                                {{ $res->formatted_result }}
                            </strong>
                            @if($res->is_post_eac_repeat)
                                <div><span class="badge badge-secondary" style="font-size: 0.7rem;">Post-EAC Repeat Test</span></div>
                            @endif
                        </td>
                        <td>
                            @if($res->is_suppressed)
                                <span class="badge badge-success">🛡️ Suppressed (&lt;1,000)</span>
                            @else
                                <span class="badge badge-warning">⚠️ High VL (EAC Triggered)</span>
                            @endif

                            @if($res->flagged_for_clinical_review)
                                <div style="margin-top: 4px;">
                                    <span class="badge badge-danger">🚨 FLAGGED FOR REVIEW</span>
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($res->next_due_date)
                                <strong style="color: #0f766e;">{{ $res->next_due_date->format('Y-m-d') }}</strong>
                                <div style="font-size: 0.75rem; color: #64748b;">{{ Str::limit($res->algorithm_reason, 45) }}</div>
                            @else
                                <span style="font-size: 0.8rem; color: #d97706; font-weight: 600;">EAC Active (Follow-up)</span>
                            @endif
                        </td>
                        <td>
                            <div style="font-size: 0.8rem; color: #334155;">{{ $res->lab_name }}</div>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $res->sample_type }}</div>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="{{ route('viral-load.show', $res) }}" class="btn btn-secondary btn-sm" title="View Detailed Report">
                                    👁️ View
                                </a>
                                @if(!$res->is_suppressed && $res->eac_episode_id)
                                    <a href="{{ route('eac.show', $res->eac_episode_id) }}" class="btn btn-primary btn-sm" style="background-color: #d97706;">
                                        📋 EAC
                                    </a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                            No viral load results recorded yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($results->hasPages())
        <div class="card-footer">
            {{ $results->links() }}
        </div>
    @endif
</div>
@endsection
