@extends('layouts.app')

@section('title', 'Edit Patient: ' . $patient->art_number)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Edit Patient: {{ $patient->full_name }}</h1>
        <p class="page-subtitle">ART Number: <strong>{{ $patient->art_number }}</strong></p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">
            &larr; Back to Patient Profile
        </a>
    </div>
</div>

<form method="POST" action="{{ route('patients.update', $patient) }}">
    @csrf
    @method('PUT')

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Demographic Details</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="art_number" class="form-label">ART Number <span class="required">*</span></label>
                            <input type="text" id="art_number" name="art_number" value="{{ old('art_number', $patient->art_number) }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">Contact Phone</label>
                            <input type="tel" inputmode="tel" id="phone" name="phone" value="{{ old('phone', $patient->phone) }}" class="form-control">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="{{ old('first_name', $patient->first_name) }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="{{ old('last_name', $patient->last_name) }}" required class="form-control">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="sex" class="form-label">Sex <span class="required">*</span></label>
                            <select id="sex" name="sex" required class="form-select" onchange="toggleMaternalFields()">
                                <option value="female" {{ old('sex', $patient->sex) === 'female' ? 'selected' : '' }}>Female</option>
                                <option value="male" {{ old('sex', $patient->sex) === 'male' ? 'selected' : '' }}>Male</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="age" class="form-label">Age in Years <span class="required">*</span></label>
                            <input type="number" id="age" name="age" value="{{ old('age', $patient->age) }}" min="0" max="120" required class="form-control">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" id="address" name="address" value="{{ old('address', $patient->address) }}" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Clinical Flags & Status</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="art_start_date" class="form-label">ART Start Date <span class="required">*</span></label>
                        <input type="date" id="art_start_date" name="art_start_date" value="{{ old('art_start_date', $patient->art_start_date->toDateString()) }}" required class="form-control">
                    </div>

                    <div class="form-group">
                        <label for="facility_id" class="form-label">Facility <span class="required">*</span></label>
                        <select id="facility_id" name="facility_id" required class="form-select">
                            @foreach($facilities as $fac)
                                <option value="{{ $fac->id }}" {{ old('facility_id', $patient->facility_id) == $fac->id ? 'selected' : '' }}>
                                    {{ $fac->name }} ({{ $fac->county->name }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Enrollment Status <span class="required">*</span></label>
                        <select id="status" name="status" required class="form-select">
                            <option value="active" {{ old('status', $patient->status) === 'active' ? 'selected' : '' }}>Active in Care</option>
                            <option value="transferred_out" {{ old('status', $patient->status) === 'transferred_out' ? 'selected' : '' }}>Transferred Out</option>
                            <option value="lost_to_followup" {{ old('status', $patient->status) === 'lost_to_followup' ? 'selected' : '' }}>Lost to Follow-up</option>
                            <option value="deceased" {{ old('status', $patient->status) === 'deceased' ? 'selected' : '' }}>Deceased</option>
                        </select>
                    </div>

                    <div id="maternal-flags" style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border);">
                        <label class="form-label">Maternal Status</label>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; cursor: pointer;">
                                <input type="checkbox" id="is_pregnant" name="is_pregnant" value="1" {{ old('is_pregnant', $patient->is_pregnant) ? 'checked' : '' }}>
                                <span>🤰 Currently Pregnant</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.875rem; cursor: pointer;">
                                <input type="checkbox" id="is_breastfeeding" name="is_breastfeeding" value="1" {{ old('is_breastfeeding', $patient->is_breastfeeding) ? 'checked' : '' }}>
                                <span>🤱 Currently Breastfeeding</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        Save Patient Changes
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
