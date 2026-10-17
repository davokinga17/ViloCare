@extends('layouts.app')

@section('title', 'Due for Viral Load Services Report')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Due for Viral Load Services & Call-up Roster</h1>
        <p class="page-subtitle">Ref Code: <strong style="font-family: monospace;">{{ $reportRef }}</strong> &bull; Generated: {{ now()->format('Y-m-d H:i') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('reports.due-list', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-primary">
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
    'action' => route('reports.due-list'),
    'formId' => 'due-list-filter-form',
    'states' => $states,
    'counties' => $counties,
    'facilities' => $facilities,
    'selectedStateId' => $selectedStateId,
    'selectedCountyId' => $selectedCountyId,
    'selectedFacilityId' => $selectedFacilityId,
    'showDateFilter' => false,
    'resetUrl' => route('reports.due-list'),
    'extraActions' => '<input type="hidden" name="days" value="' . $daysAhead . '">'
        . ($isToday ? '<input type="hidden" name="period" value="today">' : ''),
])

<!-- Time Horizon Selector -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; gap: 8px; align-items: center;">
            <span style="font-size: 0.85rem; font-weight: 700; color: #475569;">Time Horizon:</span>
            <a href="{{ route('reports.due-list', array_merge(request()->query(), ['period' => 'today'])) }}" class="btn btn-sm {{ $isToday ? 'btn-primary' : 'btn-secondary' }}">Due Today</a>
            <a href="{{ route('reports.due-list', array_merge(request()->query(), ['days' => 7, 'period' => null])) }}" class="btn btn-sm {{ ! $isToday && $daysAhead === 7 ? 'btn-primary' : 'btn-secondary' }}">Next 7 Days</a>
            <a href="{{ route('reports.due-list', array_merge(request()->query(), ['days' => 14, 'period' => null])) }}" class="btn btn-sm {{ ! $isToday && $daysAhead === 14 ? 'btn-primary' : 'btn-secondary' }}">Next 14 Days</a>
            <a href="{{ route('reports.due-list', array_merge(request()->query(), ['days' => 30, 'period' => null])) }}" class="btn btn-sm {{ ! $isToday && $daysAhead === 30 ? 'btn-primary' : 'btn-secondary' }}">Next 30 Days (Standard)</a>
            <a href="{{ route('reports.due-list', array_merge(request()->query(), ['days' => 60, 'period' => null])) }}" class="btn btn-sm {{ ! $isToday && $daysAhead === 60 ? 'btn-primary' : 'btn-secondary' }}">Next 60 Days</a>
        </div>

        <div>
            <span class="badge badge-info" style="font-size: 0.85rem;">
                Total Due: {{ count($duePmtct) + count($dueResults) + count($dueEac) }} Patients
            </span>
        </div>
    </div>
</div>

<!-- Section 0: Immediate PMTCT (pregnant since last VL) -->
@if(count($duePmtct) > 0)
<div class="card" style="margin-bottom: 24px; border-left: 4px solid #e11d48;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">🤰 Immediate Viral Load Tests Due - PMTCT ({{ count($duePmtct) }})</h2>
        <span class="badge badge-danger">Pregnant since last viral load</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Due Date</th>
                    <th>Patient ART #</th>
                    <th>Full Name</th>
                    <th>Phone</th>
                    <th>Active Regimen</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($duePmtct as $item)
                    <tr>
                        <td>
                            <strong style="color: #e11d48; font-size: 0.95rem;">{{ $item->next_due_date->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.72rem; color: #64748b;">{{ $item->dueLabel() }}</div>
                        </td>
                        <td><a href="{{ route('patients.show', $item->patient) }}" style="font-weight: 700; color: var(--primary);">{{ $item->patient->art_number }}</a></td>
                        <td><strong style="color: #0f172a;">{{ $item->patient->full_name }}</strong></td>
                        <td>{{ $item->patient->phone ?? 'N/A' }}</td>
                        <td><span class="badge badge-secondary">{{ $item->patient->activeRegimen?->regimen_name ?? 'N/A' }}</span></td>
                        <td style="text-align: right;">
                            <a href="{{ route('samples.create', ['patient_id' => $item->patient->id]) }}" class="btn btn-sm btn-primary" style="background-color: #3b82f6;">🧪 Draw Sample</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<!-- Section 1: Routine Viral Load Due List -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">🔬 Routine Viral Load Tests Due ({{ count($dueResults) }})</h2>
        <span class="badge badge-secondary">6-month / 12-month algorithm schedule</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Due Date</th>
                    <th>Patient ART #</th>
                    <th>Full Name</th>
                    <th>Cohort</th>
                    <th>Phone</th>
                    <th>Active Regimen</th>
                    <th>Algorithm Rationale</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dueResults as $res)
                    <tr>
                        <td>
                            <strong style="color: #0f766e; font-size: 0.95rem;">
                                {{ $res->next_due_date->format('Y-m-d') }}
                            </strong>
                            <div style="font-size: 0.72rem; {{ $res->isOverdue() ? 'color: #b91c1c; font-weight: 700;' : 'color: #64748b;' }}">{{ $res->dueLabel() }}</div>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $res->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $res->patient->art_number }}
                            </a>
                        </td>
                        <td>
                            <strong style="color: #0f172a;">{{ $res->patient->full_name }}</strong>
                        </td>
                        <td>
                            {{ $res->patient->ageCohort() }}
                            @if($res->patient->is_pregnant)
                                <div><span class="badge" style="background: #fff1f2; color: #e11d48; font-size: 0.7rem;">Pregnant</span></div>
                            @endif
                        </td>
                        <td>
                            {{ $res->patient->phone ?? 'N/A' }}
                        </td>
                        <td>
                            <span class="badge badge-secondary">{{ $res->patient->activeRegimen?->regimen_name ?? 'N/A' }}</span>
                        </td>
                        <td style="font-size: 0.8rem; color: #475569;">
                            {{ $res->algorithm_reason }}
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('samples.create', ['patient_id' => $res->patient->id]) }}" class="btn btn-sm btn-primary" style="background-color: #3b82f6;">
                                🧪 Draw Sample
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                            No patients due for routine viral load testing in this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Section 2: Post-EAC Repeat Tests Due List -->
<div class="card" style="border-left: 4px solid #f59e0b;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="card-title">📋 Post-EAC Repeat Tests Due ({{ count($dueEac) }})</h2>
        <span class="badge badge-warning">Adherence sessions completed</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Repeat Due Date</th>
                    <th>Patient ART #</th>
                    <th>Full Name</th>
                    <th>Cohort</th>
                    <th>Phone</th>
                    <th>Initial High VL</th>
                    <th>Completed EAC</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($dueEac as $eac)
                    <tr>
                        <td>
                            <strong style="color: #d97706; font-size: 0.95rem;">
                                {{ $eac->next_due_date->format('Y-m-d') }}
                            </strong>
                            <div style="font-size: 0.72rem; {{ $eac->isOverdue() ? 'color: #b91c1c; font-weight: 700;' : 'color: #64748b;' }}">{{ $eac->dueLabel() }}</div>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $eac->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $eac->patient->art_number }}
                            </a>
                        </td>
                        <td>
                            <strong style="color: #0f172a;">{{ $eac->patient->full_name }}</strong>
                        </td>
                        <td>
                            {{ $eac->patient->ageCohort() }}, {{ ucfirst($eac->patient->sex) }}
                        </td>
                        <td>
                            {{ $eac->patient->phone ?? 'N/A' }}
                        </td>
                        <td>
                            <strong style="color: #dc2626;">{{ number_format($eac->eacEpisode?->viralLoadResult?->result_value ?? 0) }} c/ml</strong>
                        </td>
                        <td>
                            <span class="badge badge-success">3 / 3 Sessions</span>
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('viral-load.create', ['patient_id' => $eac->patient->id, 'eac_episode_id' => $eac->eacEpisode?->id, 'is_post_eac_repeat' => 1]) }}" class="btn btn-sm btn-primary" style="background-color: #2563eb;">
                                🧪 Repeat VL Test
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 24px; color: #94a3b8;">
                            No post-EAC repeat viral load tests due in this period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
