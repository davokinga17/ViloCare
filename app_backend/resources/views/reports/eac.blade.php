@extends('layouts.app')

@section('title', 'EAC Adherence Report')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Enhanced Adherence Counselling (EAC) Status Report</h1>
        <p class="page-subtitle">Ref Code: <strong style="font-family: monospace;">{{ $reportRef }}</strong> &bull; Generated: {{ now()->format('Y-m-d H:i') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('reports.eac', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-primary">
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
    'action' => route('reports.eac'),
    'formId' => 'eac-report-filter-form',
    'states' => $states,
    'counties' => $counties,
    'facilities' => $facilities,
    'selectedStateId' => $selectedStateId,
    'selectedCountyId' => $selectedCountyId,
    'selectedFacilityId' => $selectedFacilityId,
    'showDateFilter' => false,
    'resetUrl' => route('reports.eac'),
])

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Patient ART # / Name</th>
                    <th>Cohort</th>
                    <th>Initial High VL</th>
                    <th>EAC Status</th>
                    <th>Sessions Completed</th>
                    <th>Repeat VL Due Date</th>
                    <th>Repeat VL Result</th>
                    <th>Outcome Rationale</th>
                </tr>
            </thead>
            <tbody>
                @forelse($episodes as $e)
                    <tr style="{{ $e->status === 'flagged_for_regimen_switch' ? 'background-color: #fef2f2;' : '' }}">
                        <td>
                            <a href="{{ route('patients.show', $e->patient) }}" style="font-weight: 600; color: var(--primary);">
                                {{ $e->patient->art_number }}
                            </a>
                            <div style="font-size: 0.8rem; color: #0f172a;">{{ $e->patient->full_name }}</div>
                        </td>
                        <td>
                            {{ $e->patient->ageCohort() }}, {{ ucfirst($e->patient->sex) }}
                        </td>
                        <td>
                            <strong style="color: #dc2626;">{{ number_format($e->viralLoadResult?->result_value ?? 0) }} c/ml</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $e->viralLoadResult?->result_date?->format('Y-m-d') }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $e->statusBadgeClass() }}">
                                {{ $e->statusLabel() }}
                            </span>
                        </td>
                        <td>
                            <strong>{{ $e->completedSessionsCount() }} / 3</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">Sessions</div>
                        </td>
                        <td>
                            @if($e->repeat_vl_due_date)
                                <strong style="color: #0d9488;">{{ $e->repeat_vl_due_date->format('Y-m-d') }}</strong>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">Pending 3 sessions</span>
                            @endif
                        </td>
                        <td>
                            @if($e->repeatViralLoadResult)
                                <strong style="color: {{ $e->repeatViralLoadResult->is_suppressed ? '#059669' : '#dc2626' }};">
                                    {{ $e->repeatViralLoadResult->formatted_result }}
                                </strong>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">Awaiting repeat</span>
                            @endif
                        </td>
                        <td>
                            @if($e->status === 'flagged_for_regimen_switch')
                                <span class="badge badge-danger">🚨 Suspected treatment failure: clinical review</span>
                            @elseif($e->status === 'resolved_suppressed')
                                <span class="badge badge-success">🛡️ Re-suppressed & Resolved</span>
                            @else
                                <span class="badge badge-warning">Adherence In Progress</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: #64748b;">
                            No EAC episodes recorded.
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
