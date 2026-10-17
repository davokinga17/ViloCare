@extends('layouts.app')

@section('title', 'Record Viral Load Result')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Record Viral Load Result</h1>
        <p class="page-subtitle">Input lab analyzer results for automated national algorithm due-date calculation and EAC triggers.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('viral-load.index') }}" class="btn btn-secondary">
            &larr; Back to Viral Load List
        </a>
    </div>
</div>

@if(isset($preselectedPatient) && $preselectedPatient && $preselectedPatient->isImmediateVlRequired())
    <div class="alert" style="background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; margin-bottom: 20px; padding: 14px 18px; border-radius: var(--radius-md); display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 1.5rem;">🤰</span>
        <div>
            <strong style="display: block; color: #9f1239;">PMTCT Immediate Baseline Viral Load Test</strong>
            <span style="font-size: 0.875rem; color: #be123c;">Recording viral load for <strong>{{ $preselectedPatient->full_name }} ({{ $preselectedPatient->art_number }})</strong> following pregnancy onset. The algorithm will set next repeat to 6 months post-sample.</span>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('viral-load.store') }}">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. Patient Selection & Result Value</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="patient_id" class="form-label">Select Patient <span class="required">*</span></label>
                        <select id="patient_id" name="patient_id" required class="form-select">
                            <option value="">-- Choose Patient by ART # or Name --</option>
                            @foreach($patients as $p)
                                <option value="{{ $p->id }}" {{ (old('patient_id', $preselectedPatient?->id) == $p->id) ? 'selected' : '' }}>
                                    {{ $p->art_number }} — {{ $p->full_name }} ({{ $p->age }} yrs, {{ $p->sex }}{{ $p->is_pregnant ? ', Pregnant' : '' }}{{ $p->is_breastfeeding ? ', Breastfeeding' : '' }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="result_value" class="form-label">Viral Load Result (Copies / mL) <span class="required">*</span></label>
                            <input type="number" id="result_value" name="result_value" value="{{ old('result_value') }}" min="0" max="20000000" required class="form-control" placeholder="e.g. 40, 450, 15000">
                            <div class="form-help">Threshold: &lt; 1,000 = Suppressed; ≥ 1,000 = Flag for EAC Adherence Package.</div>
                        </div>

                        <div class="form-group" style="display: flex; flex-direction: column; justify-content: center;">
                            <label class="form-label" style="margin-bottom: 8px;">Undetectable Result</label>
                            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor: pointer; padding: 8px 12px; background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: var(--radius-md);">
                                <input type="checkbox" id="is_target_not_detected" name="is_target_not_detected" value="1" {{ old('is_target_not_detected') ? 'checked' : '' }} onchange="toggleLdl(this)">
                                <span>🎯 Target Not Detected / LDL (&lt;20 c/ml)</span>
                            </label>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="sample_date" class="form-label">Sample Collection Date <span class="required">*</span></label>
                            <input type="date" id="sample_date" name="sample_date" value="{{ old('sample_date', now()->subDays(3)->toDateString()) }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="result_date" class="form-label">Lab Result Date <span class="required">*</span></label>
                            <input type="date" id="result_date" name="result_date" value="{{ old('result_date', now()->toDateString()) }}" required class="form-control">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lab & Clinical Metadata -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">2. Laboratory Details & Clinical Context</h2>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="sample_type" class="form-label">Sample Specimen Type <span class="required">*</span></label>
                            <select id="sample_type" name="sample_type" required class="form-select">
                                <option value="Plasma" {{ old('sample_type') === 'Plasma' ? 'selected' : '' }}>Plasma (Standard)</option>
                                <option value="DBS" {{ old('sample_type') === 'DBS' ? 'selected' : '' }}>DBS (Dried Blood Spot)</option>
                                <option value="Whole Blood" {{ old('sample_type') === 'Whole Blood' ? 'selected' : '' }}>Whole Blood</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="lab_name" class="form-label">Testing Laboratory <span class="required">*</span></label>
                            <select id="lab_name" name="lab_name" required class="form-select">
                                <option value="">-- Select testing laboratory --</option>
                                @foreach($labs as $lab)
                                    <option value="{{ $lab }}" {{ old('lab_name', $defaultLab) === $lab ? 'selected' : '' }}>{{ $lab }}</option>
                                @endforeach
                            </select>
                            @error('lab_name')
                                <div class="form-help" style="color: #dc2626;">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="requesting_clinician_id" class="form-label">Requesting Clinician</label>
                        <select id="requesting_clinician_id" name="requesting_clinician_id" class="form-select">
                            <option value="">-- Optional Clinician Assignment --</option>
                            @foreach($clinicians as $cl)
                                <option value="{{ $cl->id }}" {{ old('requesting_clinician_id') == $cl->id ? 'selected' : '' }}>
                                    {{ $cl->name }} ({{ $cl->role->label() }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes" class="form-label">Clinical / Lab Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control" placeholder="Any specimen observations or notes...">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Card: Algorithm & Repeat Triggers -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">3. Adherence Follow-up Context</h2>
                </div>
                <div class="card-body">
                    <div id="eac-notice" style="display: none; background-color: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px; margin-bottom: 16px; font-size: 0.85rem; color: #92400e;">
                        <strong>This patient has an open EAC episode.</strong>
                        <p id="eac-notice-text" style="margin-top: 4px; color: #b45309;"></p>
                    </div>

                    <div style="font-size: 0.8rem; color: #64748b; line-height: 1.6;">
                        <strong style="color: #0f172a; display: block; margin-bottom: 4px;">National Algorithm Rules:</strong>
                        &bull; <strong>General Adults:</strong> 6 mo &rarr; 12 mo after 2 consecutive suppressed.<br>
                        &bull; <strong>Children &lt;15:</strong> 6 mo repeat indefinitely until 15 yrs.<br>
                        &bull; <strong>PMTCT:</strong> 6 mo repeat until breastfeeding stops.<br>
                        &bull; <strong>Result ≥1,000:</strong> Auto-triggers 3-session EAC.<br>
                        &bull; <strong>Result &lt;1,000 during EAC:</strong> EAC closed (early if sessions remain), routine schedule resumes.<br>
                        &bull; <strong>Result ≥1,000 before session 3 is done:</strong> Recorded; counselling continues.<br>
                        &bull; <strong>Repeat ≥1,000 after session 3:</strong> Suspected treatment failure: clinical review.
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        💾 Save & Run Algorithm
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Explains up front what the result will do to the patient's open EAC episode (the server decides).
const openEac = @json($openEacByPatient);
function updateEacNotice() {
    const info = openEac[document.getElementById('patient_id').value];
    const box = document.getElementById('eac-notice');
    if (!info) { box.style.display = 'none'; return; }
    document.getElementById('eac-notice-text').textContent = info.after_session_3
        ? 'EAC sessions are complete, so this result is the post-EAC repeat. Below 1,000 resolves the episode; 1,000 or more flags "Suspected treatment failure: clinical review".'
        : 'EAC in progress (session ' + info.session + ' of 3). Below 1,000: EAC closed early, pending sessions cancelled. 1,000 or more: result recorded, counselling continues.';
    box.style.display = 'block';
}
document.getElementById('patient_id').addEventListener('change', updateEacNotice);
updateEacNotice();

function toggleLdl(checkbox) {
    const valInput = document.getElementById('result_value');
    if (checkbox.checked) {
        valInput.value = 0;
        valInput.readOnly = true;
        valInput.style.backgroundColor = '#f1f5f9';
    } else {
        valInput.readOnly = false;
        valInput.style.backgroundColor = '#ffffff';
    }
}
</script>
@endsection
