@extends('layouts.app')

@section('title', 'Appointment Details')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Appointment: {{ $patient->full_name }} ({{ $patient->art_number }})</h1>
        <p class="page-subtitle">Scheduled Date: <strong>{{ $appointment->appointment_date->format('Y-m-d') }}</strong> &bull; Reason: {{ $appointment->reason }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">
            👥 Patient Profile
        </a>
        <a href="{{ route('appointments.index') }}" class="btn btn-secondary">
            &larr; Back to Appointments
        </a>
    </div>
</div>

<!-- Header Card -->
<div class="card" style="border-left: 6px solid {{ $appointment->isCompleted() ? '#10b981' : ($appointment->isMissed() ? '#ef4444' : '#3b82f6') }}; margin-bottom: 24px;">
    <div class="card-body" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span class="badge {{ $appointment->statusBadgeClass() }}" style="font-size: 0.85rem; padding: 4px 10px; margin-bottom: 6px;">
                    {{ $appointment->statusLabel() }}
                </span>
                <div style="font-size: 1.4rem; font-weight: 700; color: #0f172a;">
                    {{ $appointment->reason }}
                </div>
                <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                    Scheduled for: <strong>{{ $appointment->appointment_date->format('l, F j, Y') }}</strong> ({{ $appointment->appointment_time ?? 'Clinic Hours' }}) &bull; Scheduled by: {{ $appointment->scheduler?->name ?? 'Staff' }}
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                @if($appointment->status === 'scheduled')
                    <form method="POST" action="{{ route('appointments.status', $appointment) }}" style="display: inline;">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="btn btn-primary" style="background-color: #059669; padding: 10px 18px;">
                            ✅ Mark Attended / Completed
                        </button>
                    </form>

                    <form method="POST" action="{{ route('appointments.status', $appointment) }}" style="display: inline;">
                        @csrf
                        <input type="hidden" name="status" value="missed">
                        <button type="submit" class="btn btn-danger" style="padding: 10px 18px;">
                            ❌ Mark Missed / Defaulted
                        </button>
                    </form>

                    <a href="#reschedule-card" class="btn btn-secondary" style="padding: 10px 18px;">
                        🔄 Reschedule Visit
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div>
        <!-- Appointment Info Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">ℹ️ Visit Details & Clinical Notes</h2>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Scheduled Date</div>
                        <div style="font-size: 1.1rem; font-weight: 700; color: #0f172a;">{{ $appointment->appointment_date->format('Y-m-d') }}</div>
                    </div>

                    @if($appointment->attended_at)
                        <div>
                            <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Attendance Timestamp</div>
                            <div style="color: #059669; font-weight: 600;">{{ $appointment->attended_at->format('Y-m-d H:i:s') }} (Attended by: {{ $appointment->attender?->name ?? 'Staff' }})</div>
                        </div>
                    @endif

                    @if($appointment->notes)
                        <div>
                            <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Staff / Visit Notes</div>
                            <p style="color: #334155; margin-top: 4px; white-space: pre-line; background: #f8fafc; padding: 12px; border-radius: var(--radius-md); border: 1px solid var(--border);">{{ $appointment->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Reschedule Form Card -->
        @if($appointment->status === 'scheduled')
            <div class="card" id="reschedule-card">
                <div class="card-header">
                    <h2 class="card-title">🔄 Reschedule Appointment Date</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('appointments.reschedule', $appointment) }}">
                        @csrf

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="new_date" class="form-label">New Appointment Date <span class="required">*</span></label>
                                <input type="date" id="new_date" name="new_date" value="{{ now()->addDays(7)->toDateString() }}" required class="form-control">
                            </div>

                            <div class="form-group">
                                <label for="new_time" class="form-label">Preferred Session</label>
                                <select id="new_time" name="new_time" class="form-select">
                                    <option value="Morning (08:00 AM - 12:00 PM)">Morning (08:00 AM - 12:00 PM)</option>
                                    <option value="Afternoon (01:00 PM - 04:30 PM)">Afternoon (01:00 PM - 04:30 PM)</option>
                                    <option value="Fast Track Pick-up">Fast Track Pick-up</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="reschedule_reason" class="form-label">Reason for Rescheduling</label>
                            <input type="text" id="reschedule_reason" name="reschedule_reason" class="form-control" placeholder="e.g. Patient travel, clinic stock timing, clinician request...">
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 8px;">
                            Confirm Reschedule & Update Queue
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <!-- Right Side: Patient Context & Sync -->
    <div>
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">👤 Patient Context</h2>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Full Name & ART #</div>
                        <a href="{{ route('patients.show', $patient) }}" style="font-weight: 700; color: var(--primary); font-size: 1rem;">
                            {{ $patient->full_name }} ({{ $patient->art_number }})
                        </a>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Cohort & Status</div>
                        <div>{{ $patient->age }} yrs ({{ $patient->ageCohort() }}), {{ ucfirst($patient->sex) }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Active Regimen</div>
                        <span class="badge badge-info">{{ $patient->activeRegimen?->regimen_name ?? 'Not assigned' }}</span>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Contact Phone</div>
                        <div>{{ $patient->phone ?? 'No phone recorded' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync Metadata -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">ℹ️ System Sync Metadata</h2>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Appointment UUID</div>
                        <div style="font-family: monospace; font-size: 0.75rem; word-break: break-all; color: #0f172a;">{{ $appointment->id }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Sync Status</div>
                        <div>
                            @if($appointment->sync_status === 'synced')
                                <span class="badge badge-success">Cloud Synced</span>
                            @else
                                <span class="badge badge-warning">Local Pending Sync</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
