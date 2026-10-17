@extends('layouts.app')

@section('title', 'Patient Profile: ' . $patient->art_number)

@section('content')
<!-- Patient Profile Header -->
<div class="card" style="margin-bottom: 24px; background: linear-gradient(to right, #ffffff, #f8fafc); border-left: 5px solid var(--primary);">
    <div class="card-body" style="padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
            <div style="display: flex; gap: 18px; align-items: center;">
                <div class="user-avatar" style="width: 64px; height: 64px; font-size: 1.5rem; background: linear-gradient(135deg, #0d9488, #0f766e);">
                    {{ strtoupper(substr($patient->first_name, 0, 1) . substr($patient->last_name, 0, 1)) }}
                </div>
                <div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <h1 style="font-size: 1.6rem; font-weight: 700; color: #0f172a; margin: 0;">{{ $patient->full_name }}</h1>
                        <span class="badge badge-info" style="font-size: 0.85rem; font-weight: 700; padding: 4px 10px;">
                            ART: {{ $patient->art_number }}
                        </span>
                        @if($patient->status === 'active')
                            <span class="badge badge-success">Active in Care</span>
                        @else
                            <span class="badge badge-danger">{{ ucfirst(str_replace('_', ' ', $patient->status)) }}</span>
                        @endif
                    </div>
                    <div style="color: #475569; font-size: 0.9rem; margin-top: 6px; display: flex; gap: 16px; flex-wrap: wrap;">
                        <span>👤 <strong>{{ $patient->age }} years</strong> ({{ ucfirst($patient->sex) }})</span>
                        <span>🏥 <strong>{{ $patient->facility?->name }}</strong></span>
                        <span>📍 {{ $patient->county?->name }}, {{ $patient->state?->name }}</span>
                        @if($patient->phone)
                            <span>📞 {{ $patient->phone }}</span>
                        @endif
                    </div>
                    <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
                        @if($patient->isPediatric())
                            <span class="badge" style="background: #fdf4ff; color: #a855f7; border: 1px solid #f0abfc;">🧒 Child Cohort (&lt;15 yrs)</span>
                        @else
                            <span class="badge badge-secondary">Adult Cohort (15+ yrs)</span>
                        @endif

                        @if($patient->is_pregnant)
                            <span class="badge" style="background: #fff1f2; color: #e11d48; border: 1px solid #fecdd3; font-weight: 700;">🤰 Pregnant (PMTCT Cohort)</span>
                        @endif

                        @if($patient->is_breastfeeding)
                            <span class="badge" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">🤱 Breastfeeding</span>
                        @endif

                        <span class="badge badge-secondary">⏳ {{ $patient->monthsOnArt() }} Months on ART (Started: {{ $patient->art_start_date->format('Y-m-d') }})</span>
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('appointments.create', ['patient_id' => $patient->id]) }}" class="btn btn-secondary">
                    📅 Schedule Visit
                </a>
                <a href="{{ route('samples.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="background-color: #3b82f6;">
                    🧪 Collect Sample
                </a>
                @if(in_array(auth()->user()->role->value, ['administrator', 'clinician', 'lab_technician']))
                    <a href="{{ route('viral-load.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="background-color: #0d9488;">
                        🔬 Record Viral Load
                    </a>
                @endif
                @if(in_array(auth()->user()->role->value, ['administrator', 'clinician']))
                    <a href="#switch-regimen-card" class="btn btn-primary">
                        💊 Switch Regimen
                    </a>
                @endif
                <a href="{{ route('patients.edit', $patient) }}" class="btn btn-secondary">
                    ✏️ Edit Demographics
                </a>
                @if(auth()->user()->isAdmin())
                    <button type="button" onclick="openDeleteModal()" class="btn btn-secondary" style="color: #dc2626; border-color: #fecaca; background: #fff5f5;" title="Administrator Only">
                        🗑️ Delete Patient
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>

@if($patient->isImmediateVlRequired())
    <div class="alert" style="background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; padding: 16px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 14px;">
            <span style="font-size: 1.75rem;">🚨</span>
            <div>
                <strong style="font-size: 1.05rem; color: #9f1239; display: block;">Immediate Viral Load Test Indicated (PMTCT Pregnancy Onset)</strong>
                <div style="font-size: 0.875rem; color: #be123c; margin-top: 3px;">
                    This patient was established on ART and recently confirmed pregnant. National PMTCT guidelines require an immediate viral load test to assess suppression and prevent mother-to-child transmission.
                </div>
            </div>
        </div>
        <a href="{{ route('viral-load.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="background-color: #e11d48; border-color: #e11d48; color: #ffffff; font-weight: 700;">
            🔬 Record Immediate Viral Load
        </a>
    </div>
@endif

<!-- Tabs Grid -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div>
        <!-- Viral Load Test History (Module 4) -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title">🔬 Viral Load Monitoring History (National Algorithm)</h2>
                @if($patient->latestViralLoadResult)
                    @if($patient->latestViralLoadResult->is_suppressed)
                        <span class="badge badge-success">Latest: Suppressed ({{ $patient->latestViralLoadResult->formatted_result }})</span>
                    @else
                        <span class="badge badge-danger">Latest: High VL ({{ $patient->latestViralLoadResult->formatted_result }})</span>
                    @endif
                @else
                    <span class="badge badge-secondary">No Tests Yet</span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Result Date</th>
                            <th>Viral Load Value</th>
                            <th>Status</th>
                            <th>Algorithm Next Due</th>
                            <th>Lab / Clinician</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($patient->viralLoadResults as $vl)
                            <tr style="{{ !$vl->is_suppressed ? 'background-color: #fffdfa;' : '' }}">
                                <td>
                                    <strong style="color: #0f172a;">{{ $vl->result_date->format('Y-m-d') }}</strong>
                                    <div style="font-size: 0.75rem; color: #64748b;">Sample: {{ $vl->sample_date->format('Y-m-d') }}</div>
                                </td>
                                <td>
                                    <strong style="color: {{ $vl->is_suppressed ? '#059669' : '#dc2626' }}; font-size: 1rem;">
                                        {{ $vl->formatted_result }}
                                    </strong>
                                    @if($vl->is_post_eac_repeat)
                                        <div><span class="badge badge-secondary" style="font-size: 0.7rem;">Post-EAC Repeat</span></div>
                                    @endif
                                </td>
                                <td>
                                    @if($vl->is_suppressed)
                                        <span class="badge badge-success">🛡️ Suppressed</span>
                                    @else
                                        <span class="badge badge-warning">⚠️ High VL (EAC)</span>
                                    @endif

                                    @if($vl->flagged_for_clinical_review)
                                        <div style="margin-top: 3px;">
                                            <span class="badge badge-danger">🚨 Review</span>
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($vl->next_due_date)
                                        <strong style="color: #0f766e;">{{ $vl->next_due_date->format('Y-m-d') }}</strong>
                                        <div style="font-size: 0.75rem; color: #64748b;">{{ Str::limit($vl->algorithm_reason, 35) }}</div>
                                    @else
                                        <span style="font-size: 0.8rem; color: #d97706; font-weight: 600;">EAC Active</span>
                                    @endif
                                </td>
                                <td>
                                    <div style="font-size: 0.8rem; color: #334155;">{{ $vl->lab_name }}</div>
                                    <div style="font-size: 0.75rem; color: #64748b;">{{ $vl->recorder?->name ?? 'Staff' }}</div>
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('viral-load.show', $vl) }}" class="btn btn-secondary btn-sm">
                                        👁️ View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
                                    No viral load tests recorded yet.
                                    @if(in_array(auth()->user()->role->value, ['administrator', 'clinician', 'lab_technician']))
                                        <div style="margin-top: 8px;">
                                            <a href="{{ route('viral-load.create', ['patient_id' => $patient->id]) }}" class="btn btn-sm btn-primary">
                                                Record First Viral Load
                                            </a>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Specimen Collections (Module 6) -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title">🧪 Specimen Collections & Lab Tracking</h2>
                <a href="{{ route('samples.create', ['patient_id' => $patient->id]) }}" class="btn btn-sm btn-primary" style="background-color: #3b82f6;">
                    ➕ New Specimen
                </a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Sample Code</th>
                            <th>Specimen Type</th>
                            <th>Collection Date</th>
                            <th>Status</th>
                            <th>Lab Reception</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($patient->sampleCollections as $smp)
                            <tr style="{{ $smp->isRejected() ? 'background-color: #fef2f2;' : '' }}">
                                <td>
                                    <strong style="font-family: monospace; color: #0f172a;">{{ $smp->sample_code }}</strong>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">{{ $smp->sample_type }}</span>
                                </td>
                                <td>
                                    <strong>{{ $smp->collection_date->format('Y-m-d') }}</strong>
                                    <div style="font-size: 0.75rem; color: #64748b;">By: {{ $smp->collector?->name ?? 'Staff' }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $smp->statusBadgeClass() }}">
                                        {{ $smp->statusLabel() }}
                                    </span>
                                    @if($smp->isRejected() && $smp->rejection)
                                        <div style="font-size: 0.75rem; color: #b91c1c; margin-top: 3px;">
                                            {{ Str::limit($smp->rejection->reason, 25) }}
                                        </div>
                                    @endif
                                </td>
                                <td>
                                    @if($smp->sample_reception_date)
                                        <span style="color: #059669; font-weight: 600;">{{ $smp->sample_reception_date->format('Y-m-d') }}</span>
                                    @else
                                        <span style="color: #94a3b8; font-size: 0.85rem;">Pending</span>
                                    @endif
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('samples.show', $smp) }}" class="btn btn-secondary btn-sm">
                                        👁️ View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 24px; color: #94a3b8;">
                                    No blood or plasma specimens logged for this patient yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- EAC Episodes (Module 5 integration) -->
        @if($patient->eacEpisodes->isNotEmpty())
            <div class="card" style="margin-bottom: 24px; border-left: 4px solid #f59e0b;">
                <div class="card-header">
                    <h2 class="card-title">📋 Enhanced Adherence Counselling (EAC) Episodes</h2>
                </div>
                <div class="card-body">
                    @foreach($patient->eacEpisodes as $episode)
                        <div style="padding: 14px; background: #fffbeb; border-radius: var(--radius-md); margin-bottom: 12px; border: 1px solid #fde68a;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                                <div>
                                    <span class="badge {{ $episode->status === 'resolved_suppressed' ? 'badge-success' : ($episode->status === 'flagged_for_regimen_switch' ? 'badge-danger' : 'badge-warning') }}">
                                        Status: {{ ucfirst(str_replace('_', ' ', $episode->status)) }}
                                    </span>
                                    <strong style="margin-left: 8px; color: #78350f;">
                                        Initiated: {{ $episode->created_at->format('Y-m-d') }}
                                    </strong>
                                </div>
                                <a href="{{ route('eac.show', $episode) }}" class="btn btn-sm btn-primary" style="background-color: #d97706;">
                                    Manage EAC Sessions &rarr;
                                </a>
                            </div>
                            <div style="margin-top: 10px; font-size: 0.85rem; color: #92400e;">
                                Completed Sessions: <strong>{{ $episode->sessions->where('completion_status', 'completed')->count() }} / 3</strong>
                                @if($episode->repeat_vl_due_date)
                                    &bull; Repeat VL Due Date: <strong>{{ $episode->repeat_vl_due_date->format('Y-m-d') }}</strong>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- Treatment History (Module 3) -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">💊 Treatment Regimen History (Immutable Audit Log)</h2>
                @if($patient->activeRegimen)
                    <span class="badge badge-success">Active: {{ $patient->activeRegimen->regimen_name }}</span>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Regimen Name</th>
                            <th>Line</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Reason for Change</th>
                            <th>Prescribed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($patient->regimenHistories as $history)
                            <tr style="{{ $history->isActive() ? 'background-color: #f0fdfa;' : '' }}">
                                <td>
                                    @if($history->isActive())
                                        <span class="badge badge-success">🟢 Current Active</span>
                                    @else
                                        <span class="badge badge-secondary">Past Regimen</span>
                                    @endif
                                </td>
                                <td>
                                    <strong style="color: #0f172a;">{{ $history->regimen_name }}</strong>
                                </td>
                                <td><span class="badge badge-secondary">{{ $history->regimen_line }}</span></td>
                                <td>{{ $history->start_date->format('Y-m-d') }}</td>
                                <td>
                                    @if($history->end_date)
                                        {{ $history->end_date->format('Y-m-d') }}
                                    @else
                                        <span style="color: #059669; font-weight: 600;">Present</span>
                                    @endif
                                </td>
                                <td style="font-size: 0.8rem; color: #475569;">
                                    {{ $history->reason_for_change ?? 'Initial Baseline' }}
                                </td>
                                <td style="font-size: 0.75rem; color: #64748b;">
                                    {{ $history->recorder?->name ?? 'System' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 24px; color: #94a3b8;">
                                    No regimen history recorded for this patient.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Appointments History (Module 7) -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                <h2 class="card-title">📅 Appointments & Clinic Visits</h2>
                <a href="{{ route('appointments.create', ['patient_id' => $patient->id]) }}" class="btn btn-sm btn-primary" style="background-color: #2563eb;">
                    ➕ Schedule Visit
                </a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Visit Purpose</th>
                            <th>Status</th>
                            <th>Scheduled By</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($patient->appointments as $apt)
                            <tr style="{{ $apt->isToday() ? 'background-color: #f0fdf4;' : ($apt->isMissed() ? 'background-color: #fef2f2;' : '') }}">
                                <td>
                                    <strong style="color: #0f172a;">{{ $apt->appointment_date->format('Y-m-d') }}</strong>
                                    @if($apt->isToday())
                                        <span class="badge badge-success" style="font-size: 0.65rem;">TODAY</span>
                                    @endif
                                    <div style="font-size: 0.75rem; color: #64748b;">{{ $apt->appointment_time ?? 'Clinic Hours' }}</div>
                                </td>
                                <td>
                                    <span class="badge badge-secondary">{{ $apt->reason }}</span>
                                    @if($apt->notes)
                                        <div style="font-size: 0.75rem; color: #475569; margin-top: 2px;">{{ Str::limit($apt->notes, 30) }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $apt->statusBadgeClass() }}">
                                        {{ $apt->statusLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <div style="font-size: 0.8rem; color: #334155;">{{ $apt->scheduler?->name ?? 'Staff' }}</div>
                                </td>
                                <td style="text-align: right;">
                                    <a href="{{ route('appointments.show', $apt) }}" class="btn btn-secondary btn-sm">
                                        👁️ View
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 24px; color: #94a3b8;">
                                    No appointments scheduled for this patient yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Switch Regimen Form Card -->
        <div class="card" id="switch-regimen-card">
            <div class="card-header">
                <h2 class="card-title">🔄 Prescribe Regimen Switch / Change</h2>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('patients.regimen.store', $patient) }}">
                    @csrf

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="regimen_name" class="form-label">New Regimen <span class="required">*</span></label>
                            <select id="regimen_name" name="regimen_name" required class="form-select">
                                <option value="">-- Select Regimen --</option>
                                <optgroup label="Adult Regimens">
                                    <option value="TDF + 3TC + DTG (TLD)">TDF + 3TC + DTG (TLD)</option>
                                    <option value="TAF + 3TC + DTG">TAF + 3TC + DTG</option>
                                    <option value="AZT + 3TC + EFV">AZT + 3TC + EFV</option>
                                    <option value="ABC + 3TC + DTG">ABC + 3TC + DTG</option>
                                    <option value="AZT + 3TC + ATV/r">AZT + 3TC + ATV/r (2nd Line)</option>
                                    <option value="TDF + 3TC + ATV/r">TDF + 3TC + ATV/r (2nd Line)</option>
                                    <option value="AZT + 3TC + DRV/r">AZT + 3TC + DRV/r (3rd Line)</option>
                                </optgroup>
                                <optgroup label="Pediatric Regimens (<15 yrs)">
                                    <option value="ABC + 3TC + DTG (Pediatric)">ABC + 3TC + DTG (Pediatric)</option>
                                    <option value="ABC + 3TC + LPV/r">ABC + 3TC + LPV/r</option>
                                    <option value="AZT + 3TC + LPV/r">AZT + 3TC + LPV/r</option>
                                </optgroup>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="regimen_line" class="form-label">Regimen Line <span class="required">*</span></label>
                            <select id="regimen_line" name="regimen_line" required class="form-select">
                                <option value="1st Line">1st Line</option>
                                <option value="2nd Line">2nd Line</option>
                                <option value="3rd Line">3rd Line</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="start_date" class="form-label">Effective Switch Date <span class="required">*</span></label>
                            <input type="date" id="start_date" name="start_date" value="{{ now()->toDateString() }}" required class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="reason_for_change" class="form-label">Reason for Regimen Change <span class="required">*</span></label>
                            <select id="reason_for_change" name="reason_for_change" required class="form-select">
                                <option value="">-- Select Clinical Rationale --</option>
                                <option value="Treatment Failure / Unsuppressed VL (Post-EAC)">Treatment Failure / Unsuppressed VL (Post-EAC)</option>
                                <option value="Adverse Drug Reaction / Toxicity">Adverse Drug Reaction / Toxicity</option>
                                <option value="Pregnancy / PMTCT Optimization">Pregnancy / PMTCT Optimization</option>
                                <option value="National Guidelines Optimization / Transition">National Guidelines Optimization / Transition</option>
                                <option value="Drug Stockout / Formulation Change">Drug Stockout / Formulation Change</option>
                                <option value="Patient Preference / Pill Burden Reduction">Patient Preference / Pill Burden Reduction</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 8px;">
                        Record Regimen Switch (Preserve History)
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right: Clinical Summary & Location Metadata -->
    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">ℹ️ Patient Metadata</h2>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Unique System UUID</div>
                        <div style="font-family: monospace; font-size: 0.8rem; word-break: break-all; color: #0f172a;">{{ $patient->id }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Sync Status</div>
                        <div>
                            @if($patient->sync_status === 'synced')
                                <span class="badge badge-success">Cloud Synced</span>
                            @else
                                <span class="badge badge-warning">Local Pending Sync</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Registered In System By</div>
                        <div style="font-weight: 600; color: #0f172a;">{{ $patient->creator?->name ?? 'System Intake' }}</div>
                        <div style="color: #94a3b8; font-size: 0.75rem;">{{ $patient->created_at->format('Y-m-d H:i:s') }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Last Modified At</div>
                        <div style="color: #334155;">{{ $patient->last_modified_at?->format('Y-m-d H:i:s') ?? $patient->updated_at->format('Y-m-d H:i:s') }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Physical Residential Address</div>
                        <div style="color: #334155;">{{ $patient->address ?? 'Not recorded' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if(auth()->user()->isAdmin())
<!-- Administrator Delete Confirmation Modal -->
<div id="delete-patient-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 99999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(2px);">
    <div style="background: white; border-radius: var(--radius-lg); max-width: 520px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2); overflow: hidden; border: 1px solid var(--border);">
        <div style="padding: 18px 24px; border-bottom: 1px solid #fee2e2; background: #fef2f2; display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 1.6rem;">🗑️</span>
            <div>
                <h3 style="color: #991b1b; margin: 0; font-size: 1.15rem; font-weight: 700;">Delete Patient Record (Admin)</h3>
                <div style="font-size: 0.8rem; color: #b91c1c;">Administrator privilege required</div>
            </div>
        </div>
        <div style="padding: 24px;">
            <p style="color: #334155; margin-bottom: 16px; font-size: 0.95rem; line-height: 1.5;">
                Are you sure you want to permanently delete patient <strong>{{ $patient->full_name }}</strong> (<code style="color: var(--primary);">{{ $patient->art_number }}</code>)?
            </p>
            @php
                $vlCount = $patient->viralLoadResults->count();
                $eacCount = $patient->eacEpisodes->count();
                $sampleCount = $patient->sampleCollections->count();
                $aptCount = $patient->appointments->count();
                $regimenCount = $patient->regimenHistories->count();
                $hasHistory = ($vlCount + $eacCount + $sampleCount + $aptCount) > 0;
            @endphp
            @if($hasHistory)
                <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-md); padding: 14px; margin-bottom: 18px; font-size: 0.875rem; color: #92400e;">
                    <strong>⚠️ Caution: Associated Clinical History Detected:</strong>
                    <ul style="margin: 8px 0 4px 20px; line-height: 1.5;">
                        @if($vlCount > 0)
                            <li><strong>{{ $vlCount }}</strong> Viral Load Results</li>
                        @endif
                        @if($eacCount > 0)
                            <li><strong>{{ $eacCount }}</strong> EAC Episodes & Adherence Sessions</li>
                        @endif
                        @if($sampleCount > 0)
                            <li><strong>{{ $sampleCount }}</strong> Specimen Collections</li>
                        @endif
                        @if($aptCount > 0)
                            <li><strong>{{ $aptCount }}</strong> Clinic Appointments</li>
                        @endif
                        @if($regimenCount > 0)
                            <li><strong>{{ $regimenCount }}</strong> Regimen History Records</li>
                        @endif
                    </ul>
                    <div style="margin-top: 8px; font-size: 0.8rem; color: #b45309; font-weight: 600;">
                        Proceeding will permanently delete this patient and all associated clinical history from the system.
                    </div>
                </div>
            @else
                <div style="background: #f0fdfa; border: 1px solid #ccfbf1; border-radius: var(--radius-md); padding: 12px 14px; margin-bottom: 18px; font-size: 0.875rem; color: #0f766e;">
                    ℹ️ This patient has no clinical history (empty registration or test entry). Safe to delete.
                </div>
            @endif
            <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 0;">
                📜 This action will be permanently recorded in the system audit log.
            </p>
        </div>
        <div style="padding: 16px 24px; background: #f8fafc; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
            <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary">
                Cancel
            </button>
            <form method="POST" action="{{ route('patients.destroy', $patient) }}" style="display: inline;">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-primary" style="background-color: #dc2626; border-color: #dc2626; color: white; font-weight: 600;">
                    Confirm & Delete Patient
                </button>
            </form>
        </div>
    </div>
</div>
<script>
    function openDeleteModal() {
        const modal = document.getElementById('delete-patient-modal');
        if (modal) modal.style.display = 'flex';
    }
    function closeDeleteModal() {
        const modal = document.getElementById('delete-patient-modal');
        if (modal) modal.style.display = 'none';
    }
    window.addEventListener('click', function(e) {
        const modal = document.getElementById('delete-patient-modal');
        if (modal && e.target === modal) closeDeleteModal();
    });
</script>
@endif
@endsection
