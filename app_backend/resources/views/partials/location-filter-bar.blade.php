@php
    $formId = $formId ?? 'location-filter-form';
    $showDateFilter = $showDateFilter ?? true;
    $selectedDateRange = $selectedDateRange ?? 'all';
    $selectedStateId = $selectedStateId ?? 'all';
    $selectedCountyId = $selectedCountyId ?? 'all';
    $selectedFacilityId = $selectedFacilityId ?? 'all';
    $startDate = $startDate ?? null;
    $endDate = $endDate ?? null;
@endphp

<!-- Cascading Location Filter Bar -->
<div class="card location-filter-card" style="margin-bottom: 20px; padding: 14px 18px; background: #ffffff; border: 1px solid var(--border); box-shadow: var(--shadow-sm); border-radius: var(--radius-md);">
    <form method="GET" action="{{ $action }}" id="{{ $formId }}" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; flex: 1;">
            
            <!-- State Dropdown -->
            <div class="filter-item" style="display: flex; align-items: center; gap: 6px;">
                <label for="{{ $formId }}-state" style="font-size: 0.8rem; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">
                    🌍 State:
                </label>
                <select name="state_id" id="{{ $formId }}-state" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; border-radius: var(--radius-sm); min-width: 160px; max-width: 220px;">
                    <option value="all" {{ $selectedStateId === 'all' ? 'selected' : '' }}>All States</option>
                    @foreach($states as $state)
                        <option value="{{ $state->id }}" {{ $selectedStateId == $state->id ? 'selected' : '' }}>
                            {{ $state->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- County Dropdown -->
            <div class="filter-item" style="display: flex; align-items: center; gap: 6px;">
                <label for="{{ $formId }}-county" style="font-size: 0.8rem; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">
                    🏛️ County:
                </label>
                <select name="county_id" id="{{ $formId }}-county" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; border-radius: var(--radius-sm); min-width: 170px; max-width: 230px;">
                    <option value="all" {{ $selectedCountyId === 'all' ? 'selected' : '' }}>All Counties</option>
                    @foreach($counties as $county)
                        <option value="{{ $county->id }}" data-state-id="{{ $county->state_id }}" {{ $selectedCountyId == $county->id ? 'selected' : '' }}>
                            {{ $county->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <!-- Facility Dropdown -->
            <div class="filter-item" style="display: flex; align-items: center; gap: 6px;">
                <label for="{{ $formId }}-facility" style="font-size: 0.8rem; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">
                    🏥 Facility:
                </label>
                <select name="facility_id" id="{{ $formId }}-facility" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; border-radius: var(--radius-sm); min-width: 190px; max-width: 260px;">
                    <option value="all" {{ $selectedFacilityId === 'all' ? 'selected' : '' }}>All Facilities</option>
                    @foreach($facilities as $facility)
                        <option value="{{ $facility->id }}" data-county-id="{{ $facility->county_id }}" data-state-id="{{ $facility->county?->state_id }}" {{ $selectedFacilityId == $facility->id ? 'selected' : '' }}>
                            {{ $facility->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            @if($showDateFilter)
            <!-- Date Range Dropdown -->
            <div class="filter-item" style="display: flex; align-items: center; gap: 6px;">
                <label for="{{ $formId }}-date-range" style="font-size: 0.8rem; font-weight: 700; color: #475569; margin: 0; white-space: nowrap;">
                    📅 Period:
                </label>
                <select name="date_range" id="{{ $formId }}-date-range" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; border-radius: var(--radius-sm); min-width: 130px;" onchange="toggleFilterCustomDates('{{ $formId }}', this.value)">
                    <option value="all" {{ $selectedDateRange === 'all' ? 'selected' : '' }}>All Time</option>
                    <option value="today" {{ $selectedDateRange === 'today' ? 'selected' : '' }}>Today</option>
                    <option value="this_month" {{ $selectedDateRange === 'this_month' ? 'selected' : '' }}>This Month</option>
                    <option value="30_days" {{ $selectedDateRange === '30_days' ? 'selected' : '' }}>Last 30 Days</option>
                    <option value="custom" {{ $selectedDateRange === 'custom' ? 'selected' : '' }}>Custom Range...</option>
                </select>
            </div>

            <!-- Custom Date Inputs -->
            <div id="{{ $formId }}-custom-date-container" style="display: {{ $selectedDateRange === 'custom' ? 'flex' : 'none' }}; align-items: center; gap: 6px;">
                <input type="date" name="start_date" class="form-control" style="font-size: 0.8rem; padding: 5px 8px;" value="{{ $startDate }}" placeholder="From">
                <span style="color: #94a3b8; font-size: 0.8rem;">to</span>
                <input type="date" name="end_date" class="form-control" style="font-size: 0.8rem; padding: 5px 8px;" value="{{ $endDate }}" placeholder="To">
            </div>
            @endif

            <button type="submit" class="btn btn-sm btn-primary" style="padding: 6px 14px; font-size: 0.82rem; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                <span>🔍</span> Filter
            </button>
        </div>

        <div style="display: flex; align-items: center; gap: 8px;">
            @if($selectedStateId !== 'all' || $selectedCountyId !== 'all' || $selectedFacilityId !== 'all' || $selectedDateRange !== 'all')
                <a href="{{ $resetUrl ?? $action }}" class="btn btn-sm btn-secondary" style="font-size: 0.75rem; padding: 5px 10px; color: #64748b;" title="Reset filters to default">
                    ↺ Reset Filters
                </a>
            @endif
            @if(isset($extraActions))
                {!! $extraActions !!}
            @endif
        </div>
    </form>
</div>

<script>
(function() {
    function setupCascadingForForm(formId) {
        const form = document.getElementById(formId);
        if (!form) return;

        const stateSelect = form.querySelector('[name="state_id"]');
        const countySelect = form.querySelector('[name="county_id"]');
        const facilitySelect = form.querySelector('[name="facility_id"]');

        if (!stateSelect || !countySelect || !facilitySelect) return;

        // Cache all county and facility options if not already cached
        if (!countySelect._allCounties) {
            countySelect._allCounties = Array.from(countySelect.querySelectorAll('option')).map(opt => ({
                value: opt.value,
                text: opt.textContent.trim(),
                stateId: opt.getAttribute('data-state-id') || ''
            }));
        }

        if (!facilitySelect._allFacilities) {
            facilitySelect._allFacilities = Array.from(facilitySelect.querySelectorAll('option')).map(opt => ({
                value: opt.value,
                text: opt.textContent.trim(),
                countyId: opt.getAttribute('data-county-id') || '',
                stateId: opt.getAttribute('data-state-id') || ''
            }));
        }

        function filterCounties(selectedStateId, desiredCountyVal) {
            const currentVal = desiredCountyVal !== undefined ? desiredCountyVal : countySelect.value;
            countySelect.innerHTML = '';

            countySelect._allCounties.forEach(c => {
                const isMatch = (c.value === 'all') || (selectedStateId === 'all') || (!selectedStateId) || (c.stateId === selectedStateId);
                if (isMatch) {
                    const opt = document.createElement('option');
                    opt.value = c.value;
                    opt.textContent = c.text;
                    if (c.stateId) opt.setAttribute('data-state-id', c.stateId);
                    if (c.value === currentVal) opt.selected = true;
                    countySelect.appendChild(opt);
                }
            });

            if (!countySelect.querySelector(`option[value="${currentVal}"]`)) {
                countySelect.value = 'all';
            }
        }

        function filterFacilities(selectedStateId, selectedCountyId, desiredFacilityVal) {
            const currentVal = desiredFacilityVal !== undefined ? desiredFacilityVal : facilitySelect.value;
            facilitySelect.innerHTML = '';

            facilitySelect._allFacilities.forEach(f => {
                let isMatch = false;
                if (f.value === 'all') {
                    isMatch = true;
                } else if (selectedCountyId && selectedCountyId !== 'all') {
                    isMatch = (f.countyId === selectedCountyId);
                } else if (selectedStateId && selectedStateId !== 'all') {
                    isMatch = (f.stateId === selectedStateId);
                } else {
                    isMatch = true;
                }

                if (isMatch) {
                    const opt = document.createElement('option');
                    opt.value = f.value;
                    opt.textContent = f.text;
                    if (f.countyId) opt.setAttribute('data-county-id', f.countyId);
                    if (f.stateId) opt.setAttribute('data-state-id', f.stateId);
                    if (f.value === currentVal) opt.selected = true;
                    facilitySelect.appendChild(opt);
                }
            });

            if (!facilitySelect.querySelector(`option[value="${currentVal}"]`)) {
                facilitySelect.value = 'all';
            }
        }

        // Initialize lists according to initial server selections
        filterCounties(stateSelect.value, '{{ $selectedCountyId }}');
        filterFacilities(stateSelect.value, '{{ $selectedCountyId }}', '{{ $selectedFacilityId }}');

        // State change: narrow counties & facilities, reset selections to 'all'
        stateSelect.addEventListener('change', function() {
            filterCounties(this.value, 'all');
            filterFacilities(this.value, 'all', 'all');
        });

        // County change: narrow facilities, reset facility selection to 'all'
        countySelect.addEventListener('change', function() {
            filterFacilities(stateSelect.value, this.value, 'all');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setupCascadingForForm('{{ $formId }}');
        });
    } else {
        setupCascadingForForm('{{ $formId }}');
    }
})();

function toggleFilterCustomDates(formId, val) {
    const container = document.getElementById(formId + '-custom-date-container');
    if (container) {
        container.style.display = val === 'custom' ? 'flex' : 'none';
    }
}
</script>
