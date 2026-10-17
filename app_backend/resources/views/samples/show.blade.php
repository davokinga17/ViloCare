@extends('layouts.app')

@section('title', 'Specimen: ' . $sample->sample_code)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Specimen Details: {{ $sample->sample_code }}</h1>
        <p class="page-subtitle">Patient: <strong>{{ $patient->full_name }} ({{ $patient->art_number }})</strong> &bull; Collected: {{ $sample->collection_date->format('Y-m-d') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">
            👥 Patient Profile
        </a>
        <a href="{{ route('samples.index') }}" class="btn btn-secondary">
            &larr; Back to Samples
        </a>
    </div>
</div>

<!-- Status Header Banner -->
<div class="card" style="border-left: 6px solid {{ $sample->isRejected() ? '#ef4444' : ($sample->isReceived() ? '#10b981' : '#3b82f6') }}; margin-bottom: 24px;">
    <div class="card-body" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span class="badge {{ $sample->statusBadgeClass() }}" style="font-size: 0.85rem; padding: 4px 10px; margin-bottom: 6px;">
                    {{ $sample->statusLabel() }}
                </span>
                <div style="font-size: 1.4rem; font-weight: 700; color: #0f172a;">
                    {{ $sample->sample_type }} Specimen ({{ $sample->sample_code }})
                </div>
                <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                    Collected at: <strong>{{ $sample->facility?->name ?? 'Facility' }}</strong> (Code: {{ $sample->health_facility_code ?? 'N/A' }}) &bull; Phlebotomist: {{ $sample->collector?->name ?? ($sample->collector_name ?? 'Staff') }}
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                @if(in_array(auth()->user()->role->value, ['administrator', 'lab_technician']) && !$sample->isReceived() && !$sample->isRejected())
                    <form method="POST" action="{{ route('samples.receive', $sample) }}" style="display: inline;">
                        @csrf
                        <input type="hidden" name="sample_reception_date" value="{{ now()->toDateString() }}">
                        <button type="submit" class="btn btn-primary" style="background-color: #059669; padding: 10px 18px;">
                            📥 Receive Sample at Lab
                        </button>
                    </form>
                @endif

                @if(in_array(auth()->user()->role->value, ['administrator', 'lab_technician']) && $sample->isReceived() && !$sample->isRejected())
                    <a href="{{ route('viral-load.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="background-color: #0d9488; padding: 10px 18px;">
                        🔬 Run & Record Viral Load &rarr;
                    </a>
                @endif

                @if(!$sample->isRejected() && in_array(auth()->user()->role->value, ['administrator', 'lab_technician', 'clinician']))
                    <a href="#rejection-form-card" class="btn btn-danger" style="padding: 10px 18px;">
                        ❌ Log Rejection
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- If Sample is Rejected: Detailed Root Cause & Action Box -->
@if($sample->isRejected() && $sample->rejection)
    <div class="card" style="border-left: 6px solid #dc2626; background: #fff5f5; margin-bottom: 24px;">
        <div class="card-header" style="background: #fee2e2;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.3rem;">🚨</span>
                <h2 class="card-title" style="color: #991b1b;">Laboratory Specimen Rejection Report</h2>
            </div>
            <span class="badge badge-danger">Rejected on {{ $sample->rejection->rejection_date->format('Y-m-d') }}</span>
        </div>
        <div class="card-body">
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                <div>
                    <div>
                        <div style="font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase;">Primary Rejection Reason</div>
                        <p style="font-size: 1.05rem; font-weight: 700; color: #7f1d1d; margin-top: 4px;">{{ $sample->rejection->reason }}</p>
                    </div>

                    <div style="margin-top: 14px;">
                        <div style="font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase;">Immediate Action Taken</div>
                        <p style="color: #334155; margin-top: 4px; font-size: 0.9rem;">{{ $sample->rejection->action_taken }}</p>
                    </div>

                    @if($sample->rejection->corrective_action)
                        <div style="margin-top: 14px;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: #991b1b; text-transform: uppercase;">Corrective & Preventive Action (CAPA)</div>
                            <p style="color: #334155; margin-top: 4px; font-size: 0.9rem;">{{ $sample->rejection->corrective_action }}</p>
                        </div>
                    @endif
                </div>

                <div style="border-left: 1px solid #fecaca; padding-left: 18px; font-size: 0.85rem;">
                    <div style="color: #991b1b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Rejected By Lab Staff</div>
                    <strong style="color: #0f172a;">{{ $sample->rejection->rejectedBy?->name ?? 'Lab Technician' }}</strong>

                    <div style="margin-top: 16px;">
                        <a href="{{ route('samples.create', ['patient_id' => $patient->id]) }}" class="btn btn-primary" style="width: 100%; text-align: center;">
                            🔄 Schedule Immediate Redraw
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <!-- Left Column: Custody Tracking & Log Rejection Form -->
    <div>
        <!-- Custody Timeline Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <h2 class="card-title">📦 Specimen Custody & Processing Timeline</h2>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-direction: column; gap: 16px;">
                    <!-- Step 1: Collection -->
                    <div style="display: flex; gap: 14px; align-items: flex-start;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            1
                        </div>
                        <div>
                            <strong style="color: #0f172a;">Specimen Collected</strong>
                            <div style="color: #64748b; font-size: 0.85rem;">Date: {{ $sample->collection_date->format('Y-m-d') }} &bull; Phlebotomist: {{ $sample->collector?->name ?? 'Staff' }}</div>
                            <div style="color: #334155; font-size: 0.85rem; margin-top: 4px;">Type: {{ $sample->sample_type }} &bull; Facility: {{ $sample->facility?->name ?? 'Facility' }} ({{ $sample->health_facility_code }})</div>
                        </div>
                    </div>

                    <!-- Step 2: Lab Reception -->
                    <div style="display: flex; gap: 14px; align-items: flex-start;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: {{ $sample->sample_reception_date ? '#ecfdf5' : '#f1f5f9' }}; color: {{ $sample->sample_reception_date ? '#059669' : '#94a3b8' }}; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            2
                        </div>
                        <div>
                            <strong style="color: {{ $sample->sample_reception_date ? '#0f172a' : '#94a3b8' }};">Testing Laboratory Reception</strong>
                            @if($sample->sample_reception_date)
                                <div style="color: #059669; font-size: 0.85rem;">Received on: {{ $sample->sample_reception_date->format('Y-m-d') }} &bull; Receiver: {{ $sample->receiver?->name ?? 'Lab Staff' }}</div>
                            @else
                                <div style="color: #94a3b8; font-size: 0.85rem;">Awaiting laboratory receipt confirmation</div>
                            @endif
                        </div>
                    </div>

                    <!-- Step 3: Analysis Status -->
                    <div style="display: flex; gap: 14px; align-items: flex-start;">
                        <div style="width: 32px; height: 32px; border-radius: 50%; background: {{ $sample->isRejected() ? '#fee2e2' : ($sample->isProcessed() ? '#ecfdf5' : '#f1f5f9') }}; color: {{ $sample->isRejected() ? '#dc2626' : ($sample->isProcessed() ? '#059669' : '#94a3b8') }}; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">
                            3
                        </div>
                        <div>
                            <strong style="color: {{ $sample->isRejected() ? '#dc2626' : ($sample->isProcessed() ? '#059669' : '#94a3b8') }};">Viral Load Analysis & Reporting</strong>
                            @if($sample->isRejected())
                                <div style="color: #dc2626; font-size: 0.85rem;">Specimen Rejected by Laboratory. Analysis cancelled.</div>
                            @elseif($sample->isProcessed())
                                <div style="color: #059669; font-size: 0.85rem;">Analysis complete and results entered into Viral Load Engine.</div>
                            @else
                                <div style="color: #94a3b8; font-size: 0.85rem;">Pending analyzer run</div>
                            @endif
                        </div>
                    </div>
                </div>

                @if($sample->notes)
                    <div style="margin-top: 20px; padding: 14px; background: #f8fafc; border-radius: var(--radius-md); border: 1px solid var(--border);">
                        <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Specimen Notes</div>
                        <p style="color: #334155; font-size: 0.875rem; margin-top: 4px; white-space: pre-line;">{{ $sample->notes }}</p>
                    </div>
                @endif
            </div>
        </div>

        <!-- Log Sample Rejection Form (If not already rejected) -->
        @if(!$sample->isRejected() && in_array(auth()->user()->role->value, ['administrator', 'lab_technician', 'clinician']))
            <div class="card" id="rejection-form-card" style="border: 1px solid #fca5a5;">
                <div class="card-header" style="background-color: #fef2f2;">
                    <h2 class="card-title" style="color: #991b1b;">❌ Log Specimen Rejection</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('samples.reject', $sample) }}">
                        @csrf

                        <div class="form-grid">
                            <div class="form-group">
                                <label for="rejection_date" class="form-label">Rejection Date <span class="required">*</span></label>
                                <input type="date" id="rejection_date" name="rejection_date" value="{{ now()->toDateString() }}" required class="form-control">
                            </div>

                            <div class="form-group">
                                <label for="reason" class="form-label">Rejection Reason <span class="required">*</span></label>
                                <select id="reason" name="reason" required class="form-select">
                                    <option value="">-- Select Laboratory Rejection Reason --</option>
                                    <option value="Hemolyzed specimen">Hemolyzed specimen</option>
                                    <option value="Insufficient volume (<2ml plasma or <3 DBS spots)">Insufficient volume (&lt;2ml plasma or &lt;3 DBS spots)</option>
                                    <option value="Clotted blood">Clotted blood</option>
                                    <option value="Improper packaging / temperature excursion">Improper packaging / temperature excursion</option>
                                    <option value="Mislabeled specimen / missing requisition form">Mislabeled specimen / missing requisition form</option>
                                    <option value="Delayed transit (>24h without cold chain)">Delayed transit (&gt;24h without cold chain)</option>
                                    <option value="Leaked / broken specimen container">Leaked / broken specimen container</option>
                                    <option value="Expired collection tube">Expired collection tube</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="action_taken" class="form-label">Immediate Action Taken <span class="required">*</span></label>
                            <input type="text" id="action_taken" name="action_taken" value="Discarded specimen; requested immediate patient redraw" required class="form-control" placeholder="e.g. Sample discarded, clinician notified for immediate redraw">
                        </div>

                        <div class="form-group">
                            <label for="corrective_action" class="form-label">Corrective & Preventive Action (CAPA)</label>
                            <textarea id="corrective_action" name="corrective_action" rows="2" class="form-control" placeholder="e.g. Phlebotomy refresher training on proper inversion and centrifuge timing...">{{ old('corrective_action') }}</textarea>
                        </div>

                        <button type="submit" class="btn btn-danger" style="margin-top: 8px;">
                            Confirm Sample Rejection (Link Rejection Record)
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </div>

    <!-- Right Column: Patient & Offline Sync Details -->
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
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Age Cohort & Sex</div>
                        <div style="color: #0f172a;">{{ $patient->age }} years ({{ $patient->ageCohort() }}), {{ ucfirst($patient->sex) }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Current Regimen</div>
                        <span class="badge badge-info">{{ $patient->activeRegimen?->regimen_name ?? 'Not assigned' }}</span>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Phone Number</div>
                        <div style="color: #0f172a;">{{ $patient->phone ?? 'Not provided' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync & Offline Metadata -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">ℹ️ System & Sync Metadata</h2>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Specimen UUID</div>
                        <div style="font-family: monospace; font-size: 0.75rem; word-break: break-all; color: #0f172a;">{{ $sample->id }}</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Sync Status</div>
                        <div>
                            @if($sample->sync_status === 'synced')
                                <span class="badge badge-success">Cloud Synced</span>
                            @else
                                <span class="badge badge-warning">Local Pending Sync</span>
                            @endif
                        </div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; text-transform: uppercase; font-weight: 700;">Last Modified Timestamp</div>
                        <div style="color: #334155;">{{ $sample->last_modified_at?->format('Y-m-d H:i:s') ?? $sample->updated_at->format('Y-m-d H:i:s') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
