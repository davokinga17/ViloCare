@extends('layouts.app')

@section('title', 'EAC Episode: ' . $patient->art_number)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Enhanced Adherence Counselling (EAC) Episode</h1>
        <p class="page-subtitle">Patient: <strong>{{ $patient->full_name }} ({{ $patient->art_number }})</strong> &bull; Triggered by High VL: <strong>{{ number_format($episode->viralLoadResult?->result_value ?? 0) }} c/ml</strong></p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">
            👥 Patient Profile
        </a>
        <a href="{{ route('eac.index') }}" class="btn btn-secondary">
            &larr; Back to EAC List
        </a>
    </div>
</div>

<!-- Episode Summary Header -->
<div class="card" style="border-left: 6px solid {{ $episode->status === 'flagged_for_regimen_switch' ? '#ef4444' : ($episode->status === 'resolved_suppressed' ? '#10b981' : '#f59e0b') }};">
    <div class="card-body" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span class="badge {{ $episode->statusBadgeClass() }}" style="font-size: 0.85rem; padding: 4px 10px; margin-bottom: 6px;">
                    {{ $episode->statusLabel() }}
                </span>
                <div style="font-size: 1.25rem; font-weight: 700; color: #0f172a;">
                    Completed {{ $episode->completedSessionsCount() }} of 3 Adherence Counselling Sessions
                </div>
                <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                    Current Active Regimen: <strong>{{ $patient->activeRegimen?->regimen_name ?? 'N/A' }}</strong> &bull; Baseline Test Date: {{ $episode->viralLoadResult?->result_date->format('Y-m-d') }}
                </div>
            </div>

            <div>
                @if($episode->status === 'sessions_completed')
                    <a href="{{ route('viral-load.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="background-color: #2563eb; padding: 10px 18px;">
                        🧪 Record Post-EAC Repeat VL Test &rarr;
                    </a>
                @elseif($episode->status === 'flagged_for_regimen_switch')
                    <a href="{{ route('patients.show', $patient) }}#switch-regimen-card" class="btn btn-danger" style="padding: 10px 18px;">
                        🚨 Suspected treatment failure: clinical review &rarr;
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- 3 Sessions Timeline / Accordion Cards -->
<div style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 24px;">
    @for($i = 1; $i <= 3; $i++)
        @php
            $session = $episode->sessions->firstWhere('session_number', $i);
            $isComplete = $session && $session->completion_status === 'completed';
            $isCancelled = $session && $session->completion_status === 'cancelled';
            $isNextPending = ($episode->isOpen() && !$isComplete && !$isCancelled && ($i === 1 || ($episode->sessions->firstWhere('session_number', $i - 1)?->completion_status === 'completed')));
        @endphp

        <div class="card" style="border: 1px solid {{ $isComplete ? '#a7f3d0' : ($isNextPending ? '#fde68a' : '#e2e8f0') }}; background: {{ $isComplete ? '#f0fdfa' : ($isNextPending ? '#ffffff' : '#f8fafc') }};">
            <div class="card-header" style="background-color: {{ $isComplete ? '#ecfdf5' : ($isNextPending ? '#fffbeb' : '#f1f5f9') }};">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 1.25rem;">
                        {{ $isComplete ? '✅' : ($isNextPending ? '⏳' : '🔒') }}
                    </span>
                    <h2 class="card-title" style="font-size: 1.05rem;">
                        Session #{{ $i }}: {{ $i === 1 ? 'Adherence Barriers & Pill Count' : ($i === 2 ? 'Action Plan Review & Support Network' : 'Final Adherence Assessment') }}
                    </h2>
                </div>
                <div>
                    @if($isComplete)
                        <span class="badge badge-success">Completed on {{ $session->session_date->format('Y-m-d') }}</span>
                    @elseif($isNextPending)
                        <span class="badge badge-warning">Ready for Counselling</span>
                    @elseif($isCancelled)
                        <span class="badge badge-secondary">Cancelled</span>
                    @else
                        <span class="badge badge-secondary">Pending Prior Session</span>
                    @endif
                </div>
            </div>

            <div class="card-body">
                @if($isComplete)
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                        <div>
                            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Identified Adherence Barriers</div>
                            <p style="color: #0f172a; font-weight: 600; margin-top: 4px; font-size: 0.95rem;">{{ $session->barriers }}</p>

                            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-top: 14px;">Action Plan Formulated</div>
                            <p style="color: #334155; margin-top: 4px; font-size: 0.9rem;">{{ $session->action_plan }}</p>

                            @if($session->notes)
                                <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-top: 14px;">Counselor Session Notes</div>
                                <p style="color: #475569; font-size: 0.85rem; margin-top: 4px;">{{ $session->notes }}</p>
                            @endif
                        </div>

                        <div style="font-size: 0.85rem; border-left: 1px solid var(--border); padding-left: 18px;">
                            <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Conducted By</div>
                            <strong style="color: #0f172a;">{{ $session->counselor?->name ?? 'Clinician / Counselor' }}</strong>

                            <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-top: 10px;">Session Date</div>
                            <div>{{ $session->session_date->format('Y-m-d') }}</div>

                            @if($session->next_session_date)
                                <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700; margin-top: 10px;">Next Scheduled Date</div>
                                <strong style="color: #0d9488;">{{ $session->next_session_date->format('Y-m-d') }}</strong>
                            @endif
                        </div>
                    </div>
                @elseif($isNextPending)
                    <!-- Form to conduct / complete session -->
                    <form method="POST" action="{{ route('eac.sessions.update', $session ?? $episode->sessions()->create(['patient_id' => $patient->id, 'session_number' => $i, 'session_date' => now()->toDateString(), 'completion_status' => 'pending'])) }}">
                        @csrf
                        @method('PUT')

                        <div class="form-grid" style="margin-bottom: 16px;">
                            <div class="form-group">
                                <label for="session_date" class="form-label">Session Date <span class="required">*</span></label>
                                <input type="date" id="session_date" name="session_date" value="{{ old('session_date', now()->toDateString()) }}" required class="form-control">
                            </div>

                            @if($i < 3)
                                <div class="form-group">
                                    <label for="next_session_date" class="form-label">Next Session Scheduled Date</label>
                                    <input type="date" id="next_session_date" name="next_session_date" value="{{ old('next_session_date', now()->addDays(14)->toDateString()) }}" class="form-control">
                                    <div class="form-help">Typically 2 weeks following this session.</div>
                                </div>
                            @endif
                        </div>

                        <div class="form-group">
                            <label class="form-label">Adherence Barriers Identified (Select all that apply)</label>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px; margin-top: 6px;">
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Forgetfulness / Busy Routine"> Forgetfulness / Routine
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Adverse Side Effects / Toxicity"> Drug Side Effects
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Stigma / Non-Disclosure"> Stigma / Non-Disclosure
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Pill Fatigue / High Pill Burden"> Pill Fatigue
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Travel / Distance to Clinic"> Travel / Distance
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; cursor: pointer;">
                                    <input type="checkbox" name="barriers[]" value="Mental Health / Depression"> Mental Health / Stress
                                </label>
                            </div>
                            <input type="text" name="barriers_other" class="form-control" style="margin-top: 8px;" placeholder="Other specific adherence barrier (optional)...">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Adherence Action Plan Agreed With Patient <span class="required">*</span></label>
                            <textarea name="action_plan" rows="2" required class="form-control" placeholder="e.g. Set daily 8 PM alarm, treatment buddy assigned, pill organizer provided...">{{ old('action_plan') }}</textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Counselor Observations & Notes</label>
                            <textarea name="notes" rows="2" class="form-control" placeholder="Key insights, patient demeanor, readiness...">{{ old('notes') }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-primary" style="margin-top: 6px;">
                            💾 Save & Complete Session #{{ $i }}
                        </button>
                    </form>
                @elseif($isCancelled)
                    <p style="color: #64748b; font-size: 0.875rem; font-style: italic;">
                        Cancelled: a suppressed viral load result closed this EAC episode early.
                    </p>
                @elseif(!$episode->isOpen())
                    <p style="color: #94a3b8; font-size: 0.875rem; font-style: italic;">
                        This EAC episode is closed.
                    </p>
                @else
                    <p style="color: #94a3b8; font-size: 0.875rem; font-style: italic;">
                        Session #{{ $i }} will be unlocked after Session #{{ $i - 1 }} is completed.
                    </p>
                @endif
            </div>
        </div>
    @endfor
</div>
@endsection
