@extends('layouts.app')

@section('title', 'Patient Cohort Report')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Patient Cohort Registry Report</h1>
        <p class="page-subtitle">Ref Code: <strong style="font-family: monospace;">{{ $reportRef }}</strong> &bull; Generated: {{ now()->format('Y-m-d H:i') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('reports.patients', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-primary">
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

<!-- Filters Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <form method="GET" action="{{ route('reports.patients') }}" id="patients-filter-form" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            @if(isset($states) && isset($counties) && isset($facilities))
            <div style="min-width: 150px;">
                <select name="state_id" id="patients-filter-state" class="form-select">
                    <option value="all">All States</option>
                    @foreach($states as $state)
                        <option value="{{ $state->id }}" {{ ($selectedStateId ?? 'all') == $state->id ? 'selected' : '' }}>
                            {{ $state->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="min-width: 160px;">
                <select name="county_id" id="patients-filter-county" class="form-select">
                    <option value="all">All Counties</option>
                    @foreach($counties as $county)
                        <option value="{{ $county->id }}" data-state-id="{{ $county->state_id }}" {{ ($selectedCountyId ?? 'all') == $county->id ? 'selected' : '' }}>
                            {{ $county->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div style="min-width: 180px;">
                <select name="facility_id" id="patients-filter-facility" class="form-select">
                    <option value="all">All Facilities</option>
                    @foreach($facilities as $fac)
                        <option value="{{ $fac->id }}" data-county-id="{{ $fac->county_id }}" data-state-id="{{ $fac->county?->state_id }}" {{ ($selectedFacilityId ?? 'all') == $fac->id ? 'selected' : '' }}>
                            {{ $fac->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            @endif

            <div style="width: 150px;">
                <select name="cohort" class="form-select">
                    <option value="">All Age Cohorts</option>
                    <option value="pediatric" {{ request('cohort') === 'pediatric' ? 'selected' : '' }}>Pediatric (&lt;15 yrs)</option>
                    <option value="adult" {{ request('cohort') === 'adult' ? 'selected' : '' }}>Adult (15+ yrs)</option>
                </select>
            </div>

            <div style="width: 120px;">
                <select name="sex" class="form-select">
                    <option value="">All Sexes</option>
                    <option value="female" {{ request('sex') === 'female' ? 'selected' : '' }}>Female</option>
                    <option value="male" {{ request('sex') === 'male' ? 'selected' : '' }}>Male</option>
                </select>
            </div>

            <div style="width: 150px;">
                <select name="condition" class="form-select">
                    <option value="">All Conditions</option>
                    <option value="pregnant" {{ request('condition') === 'pregnant' ? 'selected' : '' }}>Pregnant</option>
                    <option value="breastfeeding" {{ request('condition') === 'breastfeeding' ? 'selected' : '' }}>Breastfeeding</option>
                </select>
            </div>

            <div style="width: 160px;">
                <select name="status" class="form-select">
                    <option value="">All Patient Statuses</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active in Care</option>
                    <option value="transferred_out" {{ request('status') === 'transferred_out' ? 'selected' : '' }}>Transferred Out</option>
                    <option value="lost_to_followup" {{ request('status') === 'lost_to_followup' ? 'selected' : '' }}>Lost to Follow-up</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['cohort', 'sex', 'condition', 'status', 'state_id', 'county_id', 'facility_id']))
                <a href="{{ route('reports.patients') }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

@if(isset($states) && isset($counties) && isset($facilities))
<script>
document.addEventListener('DOMContentLoaded', function() {
    const stateSelect = document.getElementById('patients-filter-state');
    const countySelect = document.getElementById('patients-filter-county');
    const facilitySelect = document.getElementById('patients-filter-facility');

    if (!stateSelect || !countySelect || !facilitySelect) return;

    const allCounties = Array.from(countySelect.querySelectorAll('option')).map(opt => ({
        value: opt.value,
        text: opt.textContent.trim(),
        stateId: opt.getAttribute('data-state-id') || ''
    }));

    const allFacilities = Array.from(facilitySelect.querySelectorAll('option')).map(opt => ({
        value: opt.value,
        text: opt.textContent.trim(),
        countyId: opt.getAttribute('data-county-id') || '',
        stateId: opt.getAttribute('data-state-id') || ''
    }));

    function filterCounties(selectedStateId, desiredCountyVal) {
        const currentVal = desiredCountyVal !== undefined ? desiredCountyVal : countySelect.value;
        countySelect.innerHTML = '';
        allCounties.forEach(c => {
            if (c.value === 'all' || selectedStateId === 'all' || !selectedStateId || c.stateId === selectedStateId) {
                const opt = document.createElement('option');
                opt.value = c.value;
                opt.textContent = c.text;
                if (c.stateId) opt.setAttribute('data-state-id', c.stateId);
                if (c.value === currentVal) opt.selected = true;
                countySelect.appendChild(opt);
            }
        });
        if (!countySelect.querySelector(`option[value="${currentVal}"]`)) countySelect.value = 'all';
    }

    function filterFacilities(selectedStateId, selectedCountyId, desiredFacilityVal) {
        const currentVal = desiredFacilityVal !== undefined ? desiredFacilityVal : facilitySelect.value;
        facilitySelect.innerHTML = '';
        allFacilities.forEach(f => {
            let matches = false;
            if (f.value === 'all') matches = true;
            else if (selectedCountyId && selectedCountyId !== 'all') matches = (f.countyId === selectedCountyId);
            else if (selectedStateId && selectedStateId !== 'all') matches = (f.stateId === selectedStateId);
            else matches = true;

            if (matches) {
                const opt = document.createElement('option');
                opt.value = f.value;
                opt.textContent = f.text;
                if (f.countyId) opt.setAttribute('data-county-id', f.countyId);
                if (f.stateId) opt.setAttribute('data-state-id', f.stateId);
                if (f.value === currentVal) opt.selected = true;
                facilitySelect.appendChild(opt);
            }
        });
        if (!facilitySelect.querySelector(`option[value="${currentVal}"]`)) facilitySelect.value = 'all';
    }

    filterCounties(stateSelect.value, '{{ $selectedCountyId ?? "all" }}');
    filterFacilities(stateSelect.value, '{{ $selectedCountyId ?? "all" }}', '{{ $selectedFacilityId ?? "all" }}');

    stateSelect.addEventListener('change', function() {
        filterCounties(this.value, 'all');
        filterFacilities(this.value, 'all', 'all');
    });

    countySelect.addEventListener('change', function() {
        filterFacilities(stateSelect.value, this.value, 'all');
    });
});
</script>
@endif

<!-- Report Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>ART Number</th>
                    <th>Full Name</th>
                    <th>Sex / Age</th>
                    <th>Cohort</th>
                    <th>ART Start Date</th>
                    <th>Current Regimen</th>
                    <th>Latest VL Result</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($patients as $p)
                    <tr>
                        <td>
                            <strong style="color: #0f172a;">{{ $p->art_number }}</strong>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $p) }}" style="font-weight: 600; color: var(--primary);">
                                {{ $p->full_name }}
                            </a>
                            @if($p->phone)
                                <div style="font-size: 0.75rem; color: #64748b;">📞 {{ $p->phone }}</div>
                            @endif
                        </td>
                        <td>
                            {{ ucfirst($p->sex) }}, {{ $p->age }} yrs
                        </td>
                        <td>
                            @if($p->isPediatric())
                                <span class="badge" style="background: #fdf4ff; color: #a855f7;">Child (&lt;15)</span>
                            @else
                                <span class="badge badge-secondary">Adult (15+)</span>
                            @endif

                            @if($p->is_pregnant)
                                <span class="badge" style="background: #fff1f2; color: #e11d48;">Pregnant</span>
                            @endif
                            @if($p->is_breastfeeding)
                                <span class="badge" style="background: #eff6ff; color: #2563eb;">Breastfeeding</span>
                            @endif
                        </td>
                        <td>
                            {{ $p->art_start_date->format('Y-m-d') }}
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $p->monthsOnArt() }} mos</div>
                        </td>
                        <td>
                            @if($p->activeRegimen)
                                <strong>{{ $p->activeRegimen->regimen_name }}</strong>
                                <div style="font-size: 0.75rem; color: #64748b;">{{ $p->activeRegimen->regimen_line }}</div>
                            @else
                                <span style="color: #94a3b8;">None</span>
                            @endif
                        </td>
                        <td>
                            @if($p->latestViralLoadResult)
                                <strong style="color: {{ $p->latestViralLoadResult->is_suppressed ? '#059669' : '#dc2626' }};">
                                    {{ $p->latestViralLoadResult->formatted_result }}
                                </strong>
                                <div style="font-size: 0.75rem; color: #64748b;">{{ $p->latestViralLoadResult->result_date->format('Y-m-d') }}</div>
                            @else
                                <span style="color: #94a3b8; font-size: 0.8rem;">No tests</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $p->status === 'active' ? 'badge-success' : 'badge-danger' }}">
                                {{ ucfirst(str_replace('_', ' ', $p->status)) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 36px; color: #64748b;">
                            No patients found matching criteria.
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
