@extends('layouts.app')

@section('title', 'Register New Patient')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">New Patient Enrollment</h1>
        <p class="page-subtitle">Register an ART patient, assign baseline treatment regimen, and record clinic location.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.index') }}" class="btn btn-secondary">
            &larr; Back to Patients Directory
        </a>
    </div>
</div>

<form method="POST" action="{{ route('patients.store') }}">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <!-- Left: Demographics & Clinical Information -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. Patient Demographics</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="art_number" class="form-label">ART Number <span class="required">*</span></label>
                            <input type="text" id="art_number" name="art_number" value="{{ old('art_number') }}" required class="form-control" placeholder="e.g. ART-10045">
                            <div class="form-help">Unique national clinic ART tracking number.</div>
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">Contact Phone (for Reminders)</label>
                            <input type="tel" inputmode="tel" id="phone" name="phone" value="{{ old('phone') }}" class="form-control" placeholder="+211910000000">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="{{ old('first_name') }}" required class="form-control" placeholder="e.g. Grace">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="{{ old('last_name') }}" required class="form-control" placeholder="e.g. Namubiru">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="sex" class="form-label">Biological Sex <span class="required">*</span></label>
                            <select id="sex" name="sex" required class="form-select" onchange="toggleMaternalFields()">
                                <option value="">-- Select Sex --</option>
                                <option value="female" {{ old('sex') === 'female' ? 'selected' : '' }}>Female</option>
                                <option value="male" {{ old('sex') === 'male' ? 'selected' : '' }}>Male</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="age" class="form-label">Age in Years <span class="required">*</span></label>
                            <input type="number" id="age" name="age" value="{{ old('age') }}" min="0" max="120" required class="form-control" placeholder="e.g. 28">
                            <div class="form-help">Single numeric age field (used for pediatric &lt;15 algorithm logic).</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address" class="form-label">Physical Residential Address</label>
                        <input type="text" id="address" name="address" value="{{ old('address') }}" class="form-control" placeholder="e.g. Village/Sub-location, Estate, House #">
                    </div>
                </div>
            </div>

            <!-- Treatment Regimen -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">2. Initial Treatment Regimen (Immutable Regimen History)</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="regimen_name" class="form-label">Baseline ART Regimen <span class="required">*</span></label>
                            <select id="regimen_name" name="regimen_name" required class="form-select">
                                <option value="">-- Select Standard Regimen --</option>
                                <optgroup label="Adult 1st Line">
                                    <option value="TDF + 3TC + DTG (TLD)" {{ old('regimen_name') === 'TDF + 3TC + DTG (TLD)' ? 'selected' : '' }}>TDF + 3TC + DTG (TLD) — Preferred 1st Line</option>
                                    <option value="TAF + 3TC + DTG" {{ old('regimen_name') === 'TAF + 3TC + DTG' ? 'selected' : '' }}>TAF + 3TC + DTG</option>
                                    <option value="AZT + 3TC + EFV" {{ old('regimen_name') === 'AZT + 3TC + EFV' ? 'selected' : '' }}>AZT + 3TC + EFV</option>
                                    <option value="ABC + 3TC + DTG" {{ old('regimen_name') === 'ABC + 3TC + DTG' ? 'selected' : '' }}>ABC + 3TC + DTG</option>
                                </optgroup>
                                <optgroup label="Pediatric 1st Line (<15 yrs)">
                                    <option value="ABC + 3TC + DTG (Pediatric)" {{ old('regimen_name') === 'ABC + 3TC + DTG (Pediatric)' ? 'selected' : '' }}>ABC + 3TC + DTG (Pediatric)</option>
                                    <option value="ABC + 3TC + LPV/r" {{ old('regimen_name') === 'ABC + 3TC + LPV/r' ? 'selected' : '' }}>ABC + 3TC + LPV/r</option>
                                    <option value="AZT + 3TC + LPV/r" {{ old('regimen_name') === 'AZT + 3TC + LPV/r' ? 'selected' : '' }}>AZT + 3TC + LPV/r</option>
                                </optgroup>
                                <optgroup label="2nd Line / Alternative">
                                    <option value="AZT + 3TC + ATV/r" {{ old('regimen_name') === 'AZT + 3TC + ATV/r' ? 'selected' : '' }}>AZT + 3TC + ATV/r</option>
                                    <option value="TDF + 3TC + ATV/r" {{ old('regimen_name') === 'TDF + 3TC + ATV/r' ? 'selected' : '' }}>TDF + 3TC + ATV/r</option>
                                    <option value="AZT + 3TC + DRV/r" {{ old('regimen_name') === 'AZT + 3TC + DRV/r' ? 'selected' : '' }}>AZT + 3TC + DRV/r</option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="regimen_line" class="form-label">Regimen Line <span class="required">*</span></label>
                            <select id="regimen_line" name="regimen_line" required class="form-select">
                                <option value="1st Line" {{ old('regimen_line') === '1st Line' ? 'selected' : '' }}>1st Line</option>
                                <option value="2nd Line" {{ old('regimen_line') === '2nd Line' ? 'selected' : '' }}>2nd Line</option>
                                <option value="3rd Line" {{ old('regimen_line') === '3rd Line' ? 'selected' : '' }}>3rd Line</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Clinic Location & Maternal Status -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">3. Clinical Flags & Location</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="art_start_date" class="form-label">ART Start Date <span class="required">*</span></label>
                        <input type="date" id="art_start_date" name="art_start_date" value="{{ old('art_start_date', now()->toDateString()) }}" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="facility_id" class="form-label">Care Facility (Location FK) <span class="required">*</span></label>
                        <select id="facility_id" name="facility_id" required class="form-select">
                            <option value="">-- Select Facility --</option>
                            @foreach($facilities as $fac)
                                <option value="{{ $fac->id }}" {{ old('facility_id') == $fac->id ? 'selected' : '' }}>
                                    {{ $fac->name }} ({{ $fac->county->name }}, {{ $fac->county->state->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div id="maternal-flags" style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);">
                        <label class="form-label" style="margin-bottom: 8px;">Maternal Status (Viral Load Algorithm Triggers)</label>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; cursor: pointer;">
                                <input type="checkbox" id="is_pregnant" name="is_pregnant" value="1" {{ old('is_pregnant') ? 'checked' : '' }}>
                                <span>🤰 Currently Pregnant</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; cursor: pointer;">
                                <input type="checkbox" id="is_breastfeeding" name="is_breastfeeding" value="1" {{ old('is_breastfeeding') ? 'checked' : '' }}>
                                <span>🤱 Currently Breastfeeding</span>
                            </label>
                        </div>
                        <div class="form-help" style="margin-top: 6px;">
                            Trigger 6-month testing interval until breastfeeding cessation.
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        💾 Complete Registration
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function toggleMaternalFields() {
    const sex = document.getElementById('sex').value;
    const maternalBox = document.getElementById('maternal-flags');
    const isPregnant = document.getElementById('is_pregnant');
    const isBreastfeeding = document.getElementById('is_breastfeeding');

    if (sex === 'male') {
        isPregnant.checked = false;
        isBreastfeeding.checked = false;
        maternalBox.style.opacity = '0.4';
        isPregnant.disabled = true;
        isBreastfeeding.disabled = true;
    } else {
        maternalBox.style.opacity = '1';
        isPregnant.disabled = false;
        isBreastfeeding.disabled = false;
    }
}
document.addEventListener('DOMContentLoaded', toggleMaternalFields);
</script>
@endsection
