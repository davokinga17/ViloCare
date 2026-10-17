@extends('layouts.app')

@section('title', 'Patients Directory')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Patients Directory</h1>
        <p class="page-subtitle">Track ART patient enrollment, demographics, treatment history and cohort categorization.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.import') }}" class="btn btn-secondary">
            <span>📥</span> Bulk CSV Import
        </a>
        <a href="{{ route('patients.create') }}" class="btn btn-primary">
            <span>➕</span> Register New Patient
        </a>
    </div>
</div>

<!-- Quick Stats Header -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total Enrolled</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">👥</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ $stats['total'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Pediatric (&lt;15 yrs)</span>
            <div class="stat-icon-wrapper" style="background-color: #fdf4ff; color: #a855f7;">🧒</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #a855f7;">{{ $stats['pediatric'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Pregnant Women</span>
            <div class="stat-icon-wrapper" style="background-color: #fff1f2; color: #e11d48;">🤰</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #e11d48;">{{ $stats['pregnant'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Breastfeeding</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb;">🤱</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #2563eb;">{{ $stats['breastfeeding'] }}</div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('patients.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by ART #, Name, Phone...">
            </div>

            <div style="width: 130px;">
                <select name="sex" class="form-select">
                    <option value="">All Sex</option>
                    <option value="female" {{ request('sex') === 'female' ? 'selected' : '' }}>Female</option>
                    <option value="male" {{ request('sex') === 'male' ? 'selected' : '' }}>Male</option>
                </select>
            </div>

            <div style="width: 160px;">
                <select name="cohort" class="form-select">
                    <option value="">All Age Cohorts</option>
                    <option value="pediatric" {{ request('cohort') === 'pediatric' ? 'selected' : '' }}>Child (&lt;15 yrs)</option>
                    <option value="adult" {{ request('cohort') === 'adult' ? 'selected' : '' }}>Adult (15+ yrs)</option>
                </select>
            </div>

            <div style="width: 170px;">
                <select name="condition" class="form-select">
                    <option value="">All Conditions</option>
                    <option value="pregnant" {{ request('condition') === 'pregnant' ? 'selected' : '' }}>Pregnant</option>
                    <option value="breastfeeding" {{ request('condition') === 'breastfeeding' ? 'selected' : '' }}>Breastfeeding</option>
                </select>
            </div>

            <div style="width: 180px;">
                <select name="facility_id" class="form-select">
                    <option value="">All Facilities</option>
                    @foreach($facilities as $facility)
                        <option value="{{ $facility->id }}" {{ request('facility_id') == $facility->id ? 'selected' : '' }}>
                            {{ $facility->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'sex', 'cohort', 'condition', 'facility_id', 'status']))
                <a href="{{ route('patients.index') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Patient List Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ART Number</th>
                    <th>Patient Name</th>
                    <th>Age & Sex</th>
                    <th>Cohort / Flags</th>
                    <th>Active Regimen</th>
                    <th>ART Start Date</th>
                    <th>Facility</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($patients as $patient)
                    <tr>
                        <td>
                            <a href="{{ route('patients.show', $patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $patient->art_number }}
                            </a>
                        </td>
                        <td>
                            <strong style="color: #0f172a;">{{ $patient->full_name }}</strong>
                            @if($patient->phone)
                                <div style="color: #64748b; font-size: 0.75rem;">📞 {{ $patient->phone }}</div>
                            @endif
                        </td>
                        <td>
                            <strong>{{ $patient->age }} yrs</strong>
                            <span style="color: #64748b; text-transform: capitalize;">({{ $patient->sex }})</span>
                        </td>
                        <td>
                            @if($patient->isPediatric())
                                <span class="badge" style="background: #fdf4ff; color: #a855f7; border: 1px solid #f0abfc;">🧒 Child (&lt;15)</span>
                            @else
                                <span class="badge badge-secondary">Adult (15+)</span>
                            @endif

                            @if($patient->is_pregnant)
                                <span class="badge" style="background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3;">🤰 Pregnant</span>
                            @endif

                            @if($patient->is_breastfeeding)
                                <span class="badge" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">🤱 Breastfeeding</span>
                            @endif
                        </td>
                        <td>
                            @if($patient->activeRegimen)
                                <span class="badge badge-info" style="font-size: 0.75rem;">
                                    {{ $patient->activeRegimen->regimen_name }}
                                </span>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">No active regimen</span>
                            @endif
                        </td>
                        <td>
                            <div style="font-size: 0.8rem; color: #334155;">{{ $patient->art_start_date->format('Y-m-d') }}</div>
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $patient->monthsOnArt() }} months on ART</div>
                        </td>
                        <td>
                            <div style="font-size: 0.8rem; color: #334155;">{{ $patient->facility?->name ?? '—' }}</div>
                            <div style="font-size: 0.7rem; color: #64748b;">{{ $patient->county?->name ?? '—' }}</div>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary btn-sm" title="View Patient Profile">
                                    👁️ View
                                </a>
                                <a href="{{ route('patients.edit', $patient) }}" class="btn btn-secondary btn-sm" title="Edit Patient">
                                    ✏️ Edit
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: #64748b;">
                            No patient records found matching your filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($patients->hasPages())
        <div class="card-footer">
            {{ $patients->links() }}
        </div>
    @endif
</div>
@endsection
