@extends('layouts.app')

@section('title', 'Schedule Clinic Appointment')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Schedule Clinic Appointment</h1>
        <p class="page-subtitle">Schedule a clinical follow-up, viral load sample collection, EAC session, or medication refill.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('appointments.index') }}" class="btn btn-secondary">
            &larr; Back to Appointments List
        </a>
    </div>
</div>

<form method="POST" action="{{ route('appointments.store') }}">
    @csrf

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">1. Patient & Visit Purpose</h2>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label for="patient_id" class="form-label">Select Patient <span class="required">*</span></label>
                        <select id="patient_id" name="patient_id" required class="form-select">
                            <option value="">-- Choose Patient by ART # or Name --</option>
                            @foreach($patients as $p)
                                <option value="{{ $p->id }}" {{ (old('patient_id', $preselectedPatient?->id) == $p->id) ? 'selected' : '' }}>
                                    {{ $p->art_number }} — {{ $p->full_name }} ({{ $p->age }} yrs, {{ $p->sex }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="appointment_date" class="form-label">Appointment Date <span class="required">*</span></label>
                            <input type="date" id="appointment_date" name="appointment_date" value="{{ old('appointment_date', $suggestedDate) }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="appointment_time" class="form-label">Preferred Time / Session</label>
                            <select id="appointment_time" name="appointment_time" class="form-select">
                                <option value="Morning (08:00 AM - 12:00 PM)" {{ old('appointment_time') === 'Morning (08:00 AM - 12:00 PM)' ? 'selected' : '' }}>Morning (08:00 AM - 12:00 PM)</option>
                                <option value="Afternoon (01:00 PM - 04:30 PM)" {{ old('appointment_time') === 'Afternoon (01:00 PM - 04:30 PM)' ? 'selected' : '' }}>Afternoon (01:00 PM - 04:30 PM)</option>
                                <option value="Fast Track Pick-up" {{ old('appointment_time') === 'Fast Track Pick-up' ? 'selected' : '' }}>Fast Track Pick-up (Pharmacy only)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="reason" class="form-label">Visit Purpose / Clinical Reason <span class="required">*</span></label>
                        <select id="reason" name="reason" required class="form-select">
                            <option value="Routine Clinical Review" {{ old('reason', $suggestedReason) === 'Routine Clinical Review' ? 'selected' : '' }}>Routine Clinical Review</option>
                            <option value="Viral Load Sample Collection" {{ old('reason', $suggestedReason) === 'Viral Load Sample Collection' ? 'selected' : '' }}>Viral Load Sample Collection (Routine Bleed)</option>
                            <option value="EAC Counselling Session" {{ old('reason', $suggestedReason) === 'EAC Counselling Session' ? 'selected' : '' }}>Enhanced Adherence Counselling (EAC) Session</option>
                            <option value="ARV Drug Refill" {{ old('reason', $suggestedReason) === 'ARV Drug Refill' ? 'selected' : '' }}>ARV Medication Refill (Multi-month dispensing)</option>
                            <option value="Lab Result Discussion" {{ old('reason', $suggestedReason) === 'Lab Result Discussion' ? 'selected' : '' }}>Lab Result Discussion & Regimen Switch Review</option>
                            <option value="PMTCT Antenatal Follow-up" {{ old('reason', $suggestedReason) === 'PMTCT Antenatal Follow-up' ? 'selected' : '' }}>PMTCT Antenatal / Postnatal Follow-up</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes" class="form-label">Appointment Notes</label>
                        <textarea id="notes" name="notes" rows="3" class="form-control" placeholder="Special patient instructions, fast-track refill notes, or clinician referral details...">{{ old('notes') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side: Scheduling Policy & Actions -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">📅 Retention Policy</h2>
                </div>
                <div class="card-body" style="font-size: 0.85rem; color: #475569; line-height: 1.6;">
                    <div style="padding: 12px; background: #f0fdfa; border-radius: var(--radius-md); border: 1px solid #ccfbf1; margin-bottom: 14px;">
                        <strong style="color: #0f766e; display: block; margin-bottom: 4px;">Viral Load Due Alignment:</strong>
                        Align appointment dates with calculated 6-month or 12-month Viral Load due dates to minimize clinic visits.
                    </div>

                    <div style="padding: 12px; background: #fffbeb; border-radius: var(--radius-md); border: 1px solid #fde68a; margin-bottom: 14px;">
                        <strong style="color: #b45309; display: block; margin-bottom: 4px;">Defaulter Tracking:</strong>
                        Missed appointments trigger staff follow-up within 72 hours.
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
                        💾 Save & Schedule Appointment
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
