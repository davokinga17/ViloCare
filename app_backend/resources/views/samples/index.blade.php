@extends('layouts.app')

@section('title', 'Sample Management & Specimen Tracking')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Sample Management & Specimen Tracking</h1>
        <p class="page-subtitle">Track blood/plasma specimen collections, lab receptions, and rejection logging with corrective action plans.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('samples.create') }}" class="btn btn-primary">
            <span>➕</span> Collect New Sample
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total Samples Logged</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">🧪</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ $stats['total'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #3b82f6;">
        <div class="stat-header">
            <span class="stat-label">Pending Lab Reception</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb;">⏳</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #2563eb;">{{ $stats['pending_reception'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Received at Lab</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">📥</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #059669;">{{ $stats['received'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #ef4444;">
        <div class="stat-header">
            <span class="stat-label">Rejected Specimens</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">❌</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #dc2626;">
            {{ $stats['rejected'] }} <span style="font-size: 0.85rem; color: #64748b;">({{ $stats['rejection_rate'] }}%)</span>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('samples.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search Sample Code, Patient ART #, Name...">
            </div>

            <div style="width: 180px;">
                <select name="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="collected" {{ request('status') === 'collected' ? 'selected' : '' }}>Collected</option>
                    <option value="in_transit" {{ request('status') === 'in_transit' ? 'selected' : '' }}>In Transit</option>
                    <option value="received_at_lab" {{ request('status') === 'received_at_lab' ? 'selected' : '' }}>Received at Lab</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>

            <div style="width: 160px;">
                <select name="sample_type" class="form-select">
                    <option value="">All Types</option>
                    <option value="Plasma" {{ request('sample_type') === 'Plasma' ? 'selected' : '' }}>Plasma</option>
                    <option value="DBS" {{ request('sample_type') === 'DBS' ? 'selected' : '' }}>DBS</option>
                    <option value="Whole Blood" {{ request('sample_type') === 'Whole Blood' ? 'selected' : '' }}>Whole Blood</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'status', 'sample_type', 'facility_id']))
                <a href="{{ route('samples.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Samples Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Sample Code</th>
                    <th>Patient</th>
                    <th>Specimen Type</th>
                    <th>Collection Date</th>
                    <th>Status</th>
                    <th>Lab Reception Date</th>
                    <th>Facility</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($samples as $sample)
                    <tr style="{{ $sample->isRejected() ? 'background-color: #fef2f2;' : '' }}">
                        <td>
                            <strong style="font-family: monospace; color: #0f172a; font-size: 0.95rem;">
                                {{ $sample->sample_code }}
                            </strong>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $sample->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $sample->patient->art_number }}
                            </a>
                            <div style="font-size: 0.8rem; color: #0f172a;">{{ $sample->patient->full_name }}</div>
                        </td>
                        <td>
                            <span class="badge badge-secondary">{{ $sample->sample_type }}</span>
                        </td>
                        <td>
                            <strong>{{ $sample->collection_date->format('Y-m-d') }}</strong>
                            <div style="font-size: 0.75rem; color: #64748b;">By: {{ $sample->collector?->name ?? ($sample->collector_name ?? 'Phlebotomist') }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $sample->statusBadgeClass() }}">
                                {{ $sample->statusLabel() }}
                            </span>
                            @if($sample->isRejected() && $sample->rejection)
                                <div style="font-size: 0.75rem; color: #b91c1c; margin-top: 3px;">
                                    Reason: {{ Str::limit($sample->rejection->reason, 25) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            @if($sample->sample_reception_date)
                                <span style="color: #059669; font-weight: 600;">{{ $sample->sample_reception_date->format('Y-m-d') }}</span>
                                <div style="font-size: 0.75rem; color: #64748b;">By: {{ $sample->receiver?->name ?? 'Lab Staff' }}</div>
                            @else
                                <span style="color: #94a3b8; font-size: 0.85rem;">Not yet received</span>
                            @endif
                        </td>
                        <td>
                            <div style="font-size: 0.85rem; color: #334155;">{{ $sample->facility?->name ?? 'Central Clinic' }}</div>
                            <div style="font-size: 0.75rem; color: #64748b;">Code: {{ $sample->health_facility_code ?? 'N/A' }}</div>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="{{ route('samples.show', $sample) }}" class="btn btn-secondary btn-sm">
                                    👁️ View
                                </a>
                                @if(in_array(auth()->user()->role->value, ['administrator', 'lab_technician']) && !$sample->isReceived() && !$sample->isRejected())
                                    <form method="POST" action="{{ route('samples.receive', $sample) }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="sample_reception_date" value="{{ now()->toDateString() }}">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: #059669;" title="Quick Receive at Lab">
                                            📥 Receive
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: #64748b;">
                            No sample collection records found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($samples->hasPages())
        <div class="card-footer">
            {{ $samples->links() }}
        </div>
    @endif
</div>
@endsection
