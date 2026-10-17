@extends('layouts.app')

@section('title', 'Viral Load Report: ' . $result->formatted_result)

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Viral Load Clinical Test Report</h1>
        <p class="page-subtitle">Patient: <strong>{{ $patient->full_name }} ({{ $patient->art_number }})</strong> &bull; Result Date: {{ $result->result_date->format('Y-m-d') }}</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('patients.show', $patient) }}" class="btn btn-secondary">
            👥 View Patient Profile
        </a>
        <a href="{{ route('viral-load.index') }}" class="btn btn-secondary">
            &larr; Back to Results List
        </a>
    </div>
</div>

<!-- Main Outcome Card -->
<div class="card" style="border-left: 6px solid {{ $result->is_suppressed ? '#10b981' : '#ef4444' }};">
    <div class="card-body" style="padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
            <div>
                <span class="badge {{ $result->is_suppressed ? 'badge-success' : 'badge-danger' }}" style="font-size: 0.9rem; padding: 5px 12px; margin-bottom: 8px;">
                    {{ $result->suppressionStatusLabel() }}
                </span>
                <div style="font-size: 2.25rem; font-weight: 800; color: {{ $result->is_suppressed ? '#059669' : '#dc2626' }}; line-height: 1.2;">
                    {{ $result->formatted_result }}
                </div>
                <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                    Sample Date: <strong>{{ $result->sample_date->format('Y-m-d') }}</strong> &bull; Specimen: {{ $result->sample_type }} &bull; Lab: {{ $result->lab_name }}
                </div>
            </div>

            <div style="text-align: right;">
                @if(!$result->is_suppressed && $result->eac_episode_id)
                    <a href="{{ route('eac.show', $result->eac_episode_id) }}" class="btn btn-primary" style="background-color: #d97706; padding: 12px 20px;">
                        📋 Open EAC Adherence Episode &rarr;
                    </a>
                @elseif($result->flagged_for_clinical_review)
                    <a href="{{ route('patients.show', $patient) }}#switch-regimen-card" class="btn btn-danger" style="padding: 12px 20px;">
                        🚨 Suspected treatment failure: clinical review &rarr;
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<!-- Algorithm Reasoning Card -->
<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px;">
    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">🤖 National Algorithm Schedule & Rationale</h2>
            </div>
            <div class="card-body">
                <div style="padding: 16px; background-color: #f8fafc; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 18px;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; font-weight: 700; color: #64748b;">Clinical Rule Applied</div>
                    <div style="font-size: 1.05rem; font-weight: 600; color: #0f172a; margin-top: 4px;">
                        {{ $result->algorithm_reason }}
                    </div>
                </div>

                <div class="form-grid">
                    <div>
                        <div style="color: #64748b; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Next Routine Due Date</div>
                        <div style="font-size: 1.4rem; font-weight: 700; color: #0f766e; margin-top: 2px;">
                            {{ $result->next_due_date ? $result->next_due_date->format('Y-m-d') : 'Follow-up via EAC Workflow' }}
                        </div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Clinical Review Required?</div>
                        <div style="margin-top: 4px;">
                            @if($result->flagged_for_clinical_review)
                                <span class="badge badge-danger">🚨 Yes — High VL After EAC</span>
                            @else
                                <span class="badge badge-success">No — Routine Adherence</span>
                            @endif
                        </div>
                    </div>
                </div>

                @if($result->notes)
                    <div style="margin-top: 20px; padding-top: 14px; border-top: 1px solid var(--border);">
                        <div style="color: #64748b; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Lab & Specimen Notes</div>
                        <p style="color: #334155; font-size: 0.875rem; margin-top: 4px;">{{ $result->notes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Patient Context Card -->
    <div>
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Patient Context</h2>
            </div>
            <div class="card-body" style="font-size: 0.875rem;">
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">ART Number</div>
                        <strong style="color: #0f172a;">{{ $patient->art_number }}</strong>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Age & Cohort</div>
                        <div>{{ $patient->age }} years ({{ $patient->ageCohort() }})</div>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Current Active Regimen</div>
                        <span class="badge badge-info">{{ $patient->activeRegimen?->regimen_name ?? 'Not assigned' }}</span>
                    </div>

                    <div>
                        <div style="color: #64748b; font-size: 0.75rem; font-weight: 700; text-transform: uppercase;">Recorded in System By</div>
                        <div>{{ $result->recorder?->name ?? 'System Intake' }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
