@extends('layouts.app')

@section('title', 'Viral Load Suppression Report')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Viral Load Suppression & Cascade Report</h1>
        <p class="page-subtitle">Ref Code: <strong style="font-family: monospace;">{{ $reportRef }}</strong> &bull; Generated: {{ now()->format('Y-m-d H:i') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('reports.viral-load', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-primary">
            📥 Download CSV
        </a>
        <button onclick="window.print()" class="btn btn-secondary">
            🖨️ Print / Save PDF
        </button>
        <a href="{{ route('reports.index') }}" class="btn btn-secondary">
            &larr; Reports Hub
        </a>
    </div>
</div>

<!-- Cascading Location Filter Bar -->
@include('partials.location-filter-bar', [
    'action' => route('reports.viral-load'),
    'formId' => 'vl-report-filter-form',
    'states' => $states,
    'counties' => $counties,
    'facilities' => $facilities,
    'selectedStateId' => $selectedStateId,
    'selectedCountyId' => $selectedCountyId,
    'selectedFacilityId' => $selectedFacilityId,
    'showDateFilter' => false,
    'resetUrl' => route('reports.viral-load'),
])

<!-- Suppression Cascade Metrics -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card" style="padding: 16px; border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Overall Suppression Rate</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">🛡️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #059669;">{{ $stats['suppression_rate'] }}%</div>
        <div class="stat-desc">{{ $stats['suppressed'] }} of {{ $stats['total'] }} patients suppressed (latest result) &bull; VL coverage {{ $stats['vl_coverage_percent'] }}%</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #8b5cf6;">
        <div class="stat-header">
            <span class="stat-label">Pediatric Suppression (&lt;15)</span>
            <div class="stat-icon-wrapper" style="background-color: #f5f3ff; color: #7c3aed;">🧒</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #7c3aed;">{{ $stats['pediatric_rate'] }}%</div>
        <div class="stat-desc">Target: &gt;95%</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #0d9488;">
        <div class="stat-header">
            <span class="stat-label">Adult Suppression (15+)</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">🧑</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #0d9488;">{{ $stats['adult_rate'] }}%</div>
        <div class="stat-desc">General adult cohort</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #ec4899;">
        <div class="stat-header">
            <span class="stat-label">PMTCT Suppression</span>
            <div class="stat-icon-wrapper" style="background-color: #fdf2f8; color: #db2777;">🤰</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #db2777;">{{ $stats['pmtct_rate'] }}%</div>
        <div class="stat-desc">Pregnant & breastfeeding</div>
    </div>
</div>

<!-- Visual Analytics & Trend Charts -->
@include('partials.analytics-charts', [
    'chartPrefix' => 'vl-report',
    'monthlyTrend' => $monthlyTrend,
    'cohortBreakdown' => $cohortBreakdown,
])

<!-- Table Card -->
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">🔬 Viral Load Test Records (MoH Format)</h2>
        <span class="badge badge-info">{{ $results->total() }} Total Test Records</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Sample & Result Date</th>
                    <th>Patient ART # / Name</th>
                    <th>Cohort</th>
                    <th>Result (Copies/ml)</th>
                    <th>Suppression Status</th>
                    <th>Algorithm Rationale & Next Due</th>
                    <th>Flagged Review</th>
                </tr>
            </thead>
            <tbody>
                @forelse($results as $r)
                    <tr style="{{ !$r->is_suppressed ? 'background-color: #fffdfa;' : '' }}">
                        <td>
                            <strong>{{ $r->result_date->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">Sample: {{ $r->sample_date->format('Y-m-d') }}</div>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $r->patient) }}" style="font-weight: 600; color: var(--primary);">
                                {{ $r->patient->art_number }}
                            </a>
                            <div style="font-size: 0.8rem; color: #0f172a;">{{ $r->patient->full_name }}</div>
                        </td>
                        <td>
                            {{ $r->patient->ageCohort() }}
                            @if($r->patient->is_pregnant)
                                <div><span class="badge" style="background: #fff1f2; color: #e11d48; font-size: 0.7rem;">Pregnant</span></div>
                            @endif
                        </td>
                        <td>
                            <strong style="color: {{ $r->is_suppressed ? '#059669' : '#dc2626' }}; font-size: 1rem;">
                                {{ $r->formatted_result }}
                            </strong>
                            @if($r->is_post_eac_repeat)
                                <div><span class="badge badge-secondary" style="font-size: 0.7rem;">Post-EAC Repeat</span></div>
                            @endif
                        </td>
                        <td>
                            @if($r->is_suppressed)
                                <span class="badge badge-success">🛡️ Suppressed</span>
                            @else
                                <span class="badge badge-warning">⚠️ High VL (EAC)</span>
                            @endif
                        </td>
                        <td>
                            @if($r->next_due_date)
                                <strong style="color: #0f766e;">Due: {{ $r->next_due_date->format('Y-m-d') }}</strong>
                            @else
                                <span style="color: #d97706; font-size: 0.8rem;">EAC Active</span>
                            @endif
                            <div style="font-size: 0.75rem; color: #64748b;">{{ Str::limit($r->algorithm_reason, 40) }}</div>
                        </td>
                        <td>
                            @if($r->flagged_for_clinical_review)
                                <span class="badge badge-danger">🚨 Clinical review</span>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">Routine</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 36px; color: #64748b;">
                            No viral load records found.
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
