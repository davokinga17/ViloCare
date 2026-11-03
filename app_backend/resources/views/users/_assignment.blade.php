{{-- Facility / laboratory assignment. Shown to administrators; a clinician sees their own facility, fixed. --}}
@php
    $currentFacilityId = old('facility_id', $user->facility_id ?? null);
    $currentLabId = old('testing_laboratory_id', $user->testing_laboratory_id ?? null);
@endphp

@if(auth()->user()->isAdmin())
    <div class="form-group" id="assignment-facility-group">
        <label for="facility_id" class="form-label">Facility <span class="required" id="facility-required">*</span></label>
        <select id="facility_id" name="facility_id" class="form-select">
            <option value="">-- No facility (administrators, or a laboratory below) --</option>
            @foreach($facilitiesByCounty as $countyName => $countyFacilities)
                <optgroup label="{{ $countyName }}">
                    @foreach($countyFacilities as $facility)
                        <option value="{{ $facility->id }}" {{ $currentFacilityId === $facility->id ? 'selected' : '' }}>{{ $facility->name }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
        @error('facility_id') <div class="form-help" style="color:#b91c1c;">{{ $message }}</div> @enderror
        <div class="form-help">Staff see only this facility's patients and data. Administrators see everything and need no facility.</div>
    </div>

    <div class="form-group" id="assignment-lab-group" style="display: none;">
        <label for="testing_laboratory_id" class="form-label">Testing laboratory (instead of a facility)</label>
        <select id="testing_laboratory_id" name="testing_laboratory_id" class="form-select">
            <option value="">-- None --</option>
            @foreach($standaloneLabs as $lab)
                <option value="{{ $lab->id }}" {{ $currentLabId === $lab->id ? 'selected' : '' }}>{{ $lab->name }}</option>
            @endforeach
        </select>
        @error('testing_laboratory_id') <div class="form-help" style="color:#b91c1c;">{{ $message }}</div> @enderror
        <div class="form-help">A lab technician at a stand-alone laboratory (such as the National Public Health Laboratory) sees results and samples from all facilities. Choose a facility OR a laboratory, not both.</div>
    </div>

    <script>
        (function () {
            var role = document.getElementById('role');
            var facilityGroup = document.getElementById('assignment-facility-group');
            var labGroup = document.getElementById('assignment-lab-group');
            var required = document.getElementById('facility-required');
            function sync() {
                var value = role ? role.value : '';
                facilityGroup.style.display = value === 'administrator' ? 'none' : '';
                labGroup.style.display = value === 'lab_technician' ? '' : 'none';
                if (required) { required.style.display = value === 'lab_technician' ? 'none' : ''; }
            }
            if (role) { role.addEventListener('change', sync); }
            sync();
        })();
    </script>
@else
    <div class="form-group">
        <label class="form-label">Facility</label>
        <input type="text" class="form-control" value="{{ $ownFacility?->name ?? 'No facility assigned. Ask an administrator.' }}" disabled>
        <div class="form-help">New staff are added to your facility.</div>
    </div>
@endif
