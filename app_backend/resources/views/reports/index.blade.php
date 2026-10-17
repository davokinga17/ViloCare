@extends('layouts.app')

@section('title', 'Clinical Reports & MoH Data Hub')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Clinical Reports & MoH Registry</h1>
        <p class="page-subtitle">Generate, filter, and export patient cohort lists, viral load suppression statistics, EAC adherence summaries, and due service lists for clinical management and Ministry of Health submissions.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('reports.verify') }}" class="btn btn-secondary">
            🔍 Verify Report Reference Code
        </a>
    </div>
</div>

<!-- Cascading Location & Period Filter Bar -->
@include('partials.location-filter-bar', [
    'action' => route('reports.index'),
    'formId' => 'reports-filter-form',
    'states' => $states,
    'counties' => $counties,
    'facilities' => $facilities,
    'selectedStateId' => $selectedStateId,
    'selectedCountyId' => $selectedCountyId,
    'selectedFacilityId' => $selectedFacilityId,
    'selectedDateRange' => $selectedDateRange,
    'startDate' => $startDate,
    'endDate' => $endDate,
    'resetUrl' => route('reports.index'),
])

<!-- KPI Metric Cards -->
<div class="stats-grid" style="margin-bottom: 24px;">
    <div class="stat-card" style="padding: 16px;">
        <div class="stat-header">
            <span class="stat-label">Total Cohort Size</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">👥</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem;">{{ number_format($stats['total_patients']) }}</div>
        <div class="stat-desc">Active in ART care</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Viral Suppression Rate</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">🛡️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #059669;">{{ $stats['suppression_rate'] }}%</div>
        <div class="stat-desc">{{ $stats['patients_suppressed'] }} of {{ $stats['patients_tested'] }} patients with a result (latest) &bull; VL coverage {{ $stats['vl_coverage_percent'] }}%</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #f59e0b;">
        <div class="stat-header">
            <span class="stat-label">Active in EAC</span>
            <div class="stat-icon-wrapper" style="background-color: #fffbeb; color: #d97706;">📋</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #d97706;">{{ number_format($stats['active_eac']) }}</div>
        <div class="stat-desc">High VL counseling</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #3b82f6;">
        <div class="stat-header">
            <span class="stat-label">Due for VL Testing</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb;">⏰</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #2563eb;">{{ number_format($stats['due_vl']) }}</div>
        <div class="stat-desc">Next 30 days</div>
    </div>
</div>

<!-- Visual Trends: Monthly Suppression Trend & Age Cohort Breakdown -->
@include('partials.analytics-charts', [
    'chartPrefix' => 'reports',
    'monthlyTrend' => $monthlyTrend,
    'cohortBreakdown' => $cohortBreakdown,
])

@php
    $locationParams = array_filter([
        'state_id' => $selectedStateId !== 'all' ? $selectedStateId : null,
        'county_id' => $selectedCountyId !== 'all' ? $selectedCountyId : null,
        'facility_id' => $selectedFacilityId !== 'all' ? $selectedFacilityId : null,
    ]);
@endphp

<!-- 4 Core Report Cards Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
    <!-- 1. Patient Cohort Report -->
    <div class="card" style="border-top: 4px solid #0d9488;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">👥</span>
                <div>
                    <h2 class="card-title" style="font-size: 1.1rem;">Patient Cohort & Demographics</h2>
                    <div style="font-size: 0.75rem; color: #64748b;">Complete registry with ART dates, regimens, and pregnancy/pediatric flags</div>
                </div>
            </div>
        </div>
        <div class="card-body" style="font-size: 0.85rem; color: #475569;">
            <p>Comprehensive register of all enrolled ART patients, active regimen history, months on treatment, and facility breakdowns.</p>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('reports.patients', $locationParams) }}" class="btn btn-primary btn-sm" style="flex: 1; text-align: center;">
                👁️ View Report
            </a>
            <a href="{{ route('reports.patients', array_merge($locationParams, ['export' => 'csv'])) }}" class="btn btn-secondary btn-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    <!-- 2. Viral Load Summary Report -->
    <div class="card" style="border-top: 4px solid #10b981;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">🔬</span>
                <div>
                    <h2 class="card-title" style="font-size: 1.1rem;">Viral Load Suppression Summary</h2>
                    <div style="font-size: 0.75rem; color: #64748b;">Suppression cascade, age/sex cohorts, and high-VL unsuppressed roster</div>
                </div>
            </div>
        </div>
        <div class="card-body" style="font-size: 0.85rem; color: #475569;">
            <p>Aggregated suppression cascade analysis across pediatric, adult, and PMTCT cohorts, plus list of patients flagged for clinical review.</p>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('reports.viral-load', $locationParams) }}" class="btn btn-primary btn-sm" style="flex: 1; text-align: center; background-color: #059669;">
                👁️ View Report
            </a>
            <a href="{{ route('reports.viral-load', array_merge($locationParams, ['export' => 'csv'])) }}" class="btn btn-secondary btn-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    <!-- 3. EAC Adherence & Regimen Switch Report -->
    <div class="card" style="border-top: 4px solid #f59e0b;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">📋</span>
                <div>
                    <h2 class="card-title" style="font-size: 1.1rem;">EAC Adherence & Switch Roster</h2>
                    <div style="font-size: 0.75rem; color: #64748b;">3-session counselling progression and post-EAC repeat test outcomes</div>
                </div>
            </div>
        </div>
        <div class="card-body" style="font-size: 0.85rem; color: #475569;">
            <p>Track all unsuppressed high-VL episodes, 3-session adherence completion rates, repeat test scheduling, and regimen switch recommendations.</p>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('reports.eac', $locationParams) }}" class="btn btn-primary btn-sm" style="flex: 1; text-align: center; background-color: #d97706;">
                👁️ View Report
            </a>
            <a href="{{ route('reports.eac', array_merge($locationParams, ['export' => 'csv'])) }}" class="btn btn-secondary btn-sm">
                📥 Export CSV
            </a>
        </div>
    </div>

    <!-- 4. Due for Service & Defaulter List -->
    <div class="card" style="border-top: 4px solid #3b82f6;">
        <div class="card-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">⏰</span>
                <div>
                    <h2 class="card-title" style="font-size: 1.1rem;">Due Services & Call-up Roster</h2>
                    <div style="font-size: 0.75rem; color: #64748b;">Upcoming routine VL tests, post-EAC repeats, and scheduled visits</div>
                </div>
            </div>
        </div>
        <div class="card-body" style="font-size: 0.85rem; color: #475569;">
            <p>Actionable operational list for clinic phlebotomists and data clerks to contact patients due for viral load bleeds and appointments.</p>
        </div>
        <div class="card-footer" style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('reports.due-list', $locationParams) }}" class="btn btn-primary btn-sm" style="flex: 1; text-align: center; background-color: #2563eb;">
                👁️ View Due List
            </a>
            <a href="{{ route('reports.due-list', array_merge($locationParams, ['export' => 'csv'])) }}" class="btn btn-secondary btn-sm">
                📥 Export CSV
            </a>
        </div>
    </div>
</div>
@endsection
