@extends('layouts.app')

@section('title', 'Dashboard')
@section('page_title', 'Dashboard')

@push('styles')
<link href="{{ \App\Support\AssetVersion::url('css/dashboard.css') }}" rel="stylesheet" />
@endpush

@section('content')
<div class="dashboard-shell">
    {{-- 1. Top Surveillance Header Bar --}}
    <section class="surveillance-bar">
        <div>
            <div class="eyebrow">HIV Viral Load Platform</div>
            <h2 class="dashboard-title">ViLoCare Performance Dashboard</h2>
        </div>
        <div class="as-of">
            <span>Period</span>
            <strong>{{ isset($selectedDateRange) && $selectedDateRange !== 'all' ? ucfirst(str_replace('_', ' ', $selectedDateRange)) : 'All time' }}</strong>
        </div>
    </section>

    {{-- 2. Compact Filter Panel --}}
    <section class="filter-panel">
        <div class="filter-panel-head">
            <div>
                <h3>Filters</h3>
                <p>Location &amp; Cohort Criteria</p>
            </div>
            @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
            <a href="{{ route('insights.qa.index') }}" class="dashboard-chatbot-button">
                <span class="dashboard-chatbot-icon">AI</span>
                <span>ViLoCare ChatBot</span>
                <svg viewBox="0 0 20 20" aria-hidden="true">
                    <path d="M7 4l6 6-6 6" />
                </svg>
            </a>
            @endif
        </div>

        <form method="GET" action="{{ route('dashboard') }}" class="dashboard-filters" id="dashboard-filter-form">
            <div class="filter-field">
                <label for="state_id">State:</label>
                <select id="state_id" name="state_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all">All States</option>
                    @foreach($states as $state)
                        <option value="{{ $state->id }}" @selected($selectedStateId == $state->id)>{{ $state->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-field">
                <label for="county_id">County:</label>
                <select id="county_id" name="county_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all">All Counties</option>
                    @foreach($counties as $county)
                        <option value="{{ $county->id }}" @selected($selectedCountyId == $county->id)>{{ $county->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-field">
                <label for="facility_id">Facility:</label>
                <select id="facility_id" name="facility_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all">All Facilities</option>
                    @foreach($facilities as $fac)
                        <option value="{{ $fac->id }}" @selected($selectedFacilityId == $fac->id)>{{ $fac->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-field">
                <label for="due_date">Date</label>
                <input id="due_date" type="date" name="start_date" value="{{ $startDate ?? '' }}" class="form-control form-control-sm">
            </div>

            <div class="filter-field">
                <label for="date_range">Period</label>
                <select id="date_range" name="date_range" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all" @selected(($selectedDateRange ?? 'all') == 'all')>All Time</option>
                    <option value="today" @selected(($selectedDateRange ?? '') == 'today')>Today</option>
                    <option value="this_month" @selected(($selectedDateRange ?? '') == 'this_month')>This Month</option>
                    <option value="30_days" @selected(($selectedDateRange ?? '') == '30_days')>Last 30 Days</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary btn-sm" style="background: #0d9488; border-color: #0d9488;">Apply</button>
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm" style="border-color: #cdd8e3; color: #526276;">Reset</a>
                <a href="{{ route('reports.due-list', ['state_id' => $selectedStateId, 'county_id' => $selectedCountyId, 'facility_id' => $selectedFacilityId]) }}" class="btn btn-outline-success btn-sm" style="border-color: #0d9488; color: #0d9488;">VL Due Line List</a>
            </div>
        </form>
    </section>

    @php
        // Same location filters as the cards, so each card's link opens a list with the same patients.
        $dueListQuery = [
            'state_id' => $selectedStateId,
            'county_id' => $selectedCountyId,
            'facility_id' => $selectedFacilityId,
        ];
    @endphp

    {{-- 3. 5-Column Summary KPI Grid --}}
    <section class="summary-grid" aria-label="Critical summaries">
        <a href="{{ route('patients.index') }}" class="summary-card summary-patient">
            <span class="summary-icon">PT</span>
            <div>
                <p>Total Patients Enrolled</p>
                <strong>{{ number_format($metrics['total_patients'] ?? 0) }}</strong>
                <span>Filtered enrolment cohort</span>
            </div>
        </a>

        <a href="{{ route('reports.due-list', $dueListQuery + ['period' => 'today']) }}" class="summary-card summary-today">
            <span class="summary-icon">VL</span>
            <div>
                <p>Total Due for VL Today</p>
                <strong>{{ number_format($metrics['due_today'] ?? 0) }}</strong>
                <span>Due on {{ now()->format('d M Y') }}</span>
            </div>
        </a>

        <a href="{{ route('reports.due-list', $dueListQuery) }}" class="summary-card summary-vl">
            <span class="summary-icon">VL</span>
            <div>
                <p>Total Due for VL Cumulative</p>
                <strong>{{ number_format($metrics['due_for_vl'] ?? 0) }}</strong>
                <span>Open repeat VL workload</span>
            </div>
        </a>

        <a href="{{ route('eac.index') }}" class="summary-card summary-eac">
            <span class="summary-icon">EC</span>
            <div>
                <p>Total EAC Due</p>
                <strong>{{ number_format($metrics['active_eac'] ?? 0) }}</strong>
                <span>High VL clients needing EAC</span>
            </div>
        </a>

        <a href="{{ route('eac.index', ['status' => 'sessions_completed']) }}" class="summary-card summary-repeat">
            <span class="summary-icon">RV</span>
            <div>
                <p>Total Due for Repeat VL</p>
                <strong>{{ number_format($metrics['eac_sessions_complete'] ?? 0) }}</strong>
                <span>Session 3 completed</span>
            </div>
        </a>
    </section>

    {{-- 4. Mini Metrics Bar --}}
    <section class="mini-metrics">
        <div>
            <span>Appointments Today</span>
            <strong>{{ number_format($metrics['today_appointments'] ?? 0) }}</strong>
        </div>
        <div>
            <span>Specimens Pending</span>
            <strong>{{ number_format($metrics['pending_samples'] ?? 0) }}</strong>
        </div>
        <div>
            <span>Samples Rejected</span>
            <strong>{{ number_format($metrics['flagged_count'] ?? 0) }}</strong>
        </div>
        <div>
            <span>High Viral Load Results</span>
            <strong>{{ number_format($metrics['unsuppressed_count'] ?? 0) }}</strong>
        </div>
    </section>

    {{-- 5. Analytics Visual Trends (Charts) Grid --}}
    <section class="chart-section" aria-label="Charts">
        @php
            $lineChart = $charts['line'];
            $donut = $charts['suppression'];
            $workload = $charts['workload'];
            $eacChart = $charts['eac'];
            $cohortChart = $charts['cohorts'];
        @endphp

        {{-- Top row: VL activity (2/3) beside suppression donut (1/3) --}}
        <div class="chart-row chart-row-top">
            <article class="insight-card chart-card" aria-labelledby="chart-line-title">
                <div class="card-head">
                    <h3 id="chart-line-title">{{ $lineChart['title'] }}</h3>
                    <span>{{ $lineChart['subtitle'] }}</span>
                </div>
                @if($lineChart['has_data'])
                    <div class="chart-body">
                        <canvas id="vlActivityChart" class="dashboard-trend-chart" role="img"
                            aria-label="Monthly VL due, samples collected and results tested for the last 6 months"></canvas>
                    </div>
                @else
                    <div class="chart-body chart-empty">{{ $lineChart['empty'] }}</div>
                @endif
                <p class="chart-caption">{{ $lineChart['caption'] }}</p>
            </article>

            <article class="insight-card chart-card" aria-labelledby="chart-suppression-title">
                <div class="card-head">
                    <h3 id="chart-suppression-title">{{ $donut['title'] }}</h3>
                    <span>{{ number_format($donut['tested']) }} patients tested</span>
                </div>
                @if($donut['has_data'])
                    <div class="chart-body donut-layout">
                        <div class="donut-wrap">
                            <canvas id="suppressionChart" class="dashboard-cohort-pie-chart" role="img"
                                aria-label="{{ $donut['rate'] }}% of tested patients suppressed"></canvas>
                        </div>
                        <ul class="chart-legend">
                            @foreach($donut['slices'] as $slice)
                                <li>
                                    <span class="legend-dot" style="background: {{ $slice['color'] }};"></span>
                                    <span class="legend-text">{{ $slice['label'] }}<strong>{{ $slice['percent'] }}%</strong><em>{{ number_format($slice['count']) }} patients</em></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="chart-body chart-empty">{{ $donut['empty'] }}</div>
                @endif
                <p class="chart-caption">{{ $donut['caption'] }}</p>
            </article>
        </div>

        {{-- Bottom row: three equal cards --}}
        <div class="chart-row chart-row-bottom">
            <article class="insight-card chart-card" aria-labelledby="chart-workload-title">
                <div class="card-head">
                    <h3 id="chart-workload-title">{{ $workload['title'] }}</h3>
                    <span>{{ $workload['subtitle'] }}</span>
                </div>
                @if($workload['has_data'])
                    <div class="chart-body">
                        <canvas id="workloadChart" role="img"
                            aria-label="@foreach($workload['bars'] as $bar){{ $bar['label'] }}: {{ $bar['count'] }} {{ $bar['unit'] }}. @endforeach"></canvas>
                    </div>
                @else
                    <div class="chart-body chart-empty">{{ $workload['empty'] }}</div>
                @endif
            </article>

            <article class="insight-card chart-card" aria-labelledby="chart-eac-title">
                <div class="card-head">
                    <h3 id="chart-eac-title">{{ $eacChart['title'] }}</h3>
                    <span>{{ $eacChart['subtitle'] }}</span>
                </div>
                @if($eacChart['has_data'])
                    <div class="chart-body donut-layout">
                        <div class="donut-wrap">
                            <canvas id="eacChart" role="img" aria-label="{{ $eacChart['total'] }} EAC episodes by status"></canvas>
                        </div>
                        <ul class="chart-legend">
                            @foreach($eacChart['slices'] as $slice)
                                <li>
                                    <span class="legend-dot" style="background: {{ $slice['color'] }};"></span>
                                    <span class="legend-text">{{ $slice['label'] }}<strong>{{ number_format($slice['count']) }}</strong><em>{{ $slice['percent'] }}%</em></span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="chart-body chart-empty">{{ $eacChart['empty'] }}</div>
                @endif
            </article>

            <article class="insight-card chart-card" aria-labelledby="chart-cohort-title">
                <div class="card-head">
                    <h3 id="chart-cohort-title">{{ $cohortChart['title'] }}</h3>
                    <span>{{ $cohortChart['subtitle'] }}</span>
                </div>
                @if($cohortChart['has_data'])
                    <div class="chart-body">
                        <canvas id="cohortChart" class="dashboard-cohort-bar-chart" role="img"
                            aria-label="@foreach($cohortChart['bars'] as $bar){{ $bar['label'] }}: {{ $bar['count'] }} ({{ $bar['percent'] }}%). @endforeach"></canvas>
                    </div>
                @else
                    <div class="chart-body chart-empty">{{ $cohortChart['empty'] }}</div>
                @endif
            </article>
        </div>
    </section>

    {{-- 6. Operational Data Tables --}}
    <section style="display: grid; grid-template-columns: 1fr; gap: 0.8rem; margin-top: 0.5rem;">
        {{-- Patients Due Table --}}
        <div class="filter-panel" style="padding: 1rem;">
            <div class="filter-panel-head" style="margin-bottom: 0.8rem;">
                <div>
                    <h3>Patients Due for Viral Load Testing (Next 30 Days)</h3>
                    <p>Each patient listed once (immediate PMTCT, post-EAC repeat or routine)</p>
                </div>
                <a href="{{ route('reports.due-list', $dueListQuery) }}" class="btn btn-sm btn-secondary" style="font-size: 0.72rem; border-color: #cdd8e3;">View All &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table" style="width: 100%; font-size: 0.82rem; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e5edf3; text-align: left; color: #526276;">
                            <th style="padding: 8px;">Due Date</th>
                            <th style="padding: 8px;">ART Number / Patient</th>
                            <th style="padding: 8px;">Cohort</th>
                            <th style="padding: 8px;">Regimen</th>
                            <th style="padding: 8px;">Reason</th>
                            <th style="padding: 8px; text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($duePatientsList as $due)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 8px;">
                                    <strong style="color: #0f766e;">{{ $due->next_due_date->format('Y-m-d') }}</strong>
                                    <div style="font-size: 0.72rem; margin-top: 2px; {{ $due->isOverdue() ? 'color: #b91c1c; font-weight: 700;' : 'color: #64748b;' }}">{{ $due->dueLabel() }}</div>
                                </td>
                                <td style="padding: 8px;">
                                    <a href="{{ route('patients.show', $due->patient) }}" style="font-weight: 700; color: #0d9488; text-decoration: none;">
                                        {{ $due->patient->art_number }}
                                    </a>
                                    <div style="font-size: 0.75rem; color: #64748b;">{{ $due->patient->full_name }}</div>
                                </td>
                                <td style="padding: 8px;">{{ $due->patient->ageCohort() }}</td>
                                <td style="padding: 8px;"><span style="background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 0.72rem;">{{ $due->patient->activeRegimen?->regimen_name ?? 'N/A' }}</span></td>
                                <td style="padding: 8px; color: #64748b; font-size: 0.75rem;"><span style="font-weight: 700; color: #475569;">{{ $due->groupLabel() }}</span><div>{{ Str::limit($due->algorithm_reason, 30) }}</div></td>
                                <td style="padding: 8px; text-align: right;">
                                    <a href="{{ route('samples.create', ['patient_id' => $due->patient->id]) }}" class="btn btn-sm btn-primary" style="background: #0d9488; border-color: #0d9488; font-size: 0.72rem; padding: 3px 8px;">
                                        🧪 Draw
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 18px; color: #94a3b8;">
                                    No patients due for viral load testing in the next 30 days.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- 7. AI Clinical Insights Box (moved to bottom of page) --}}
    @if(auth()->user()->hasRole(\App\Enums\UserRole::ADMINISTRATOR, \App\Enums\UserRole::CLINICIAN))
    <div class="insight-card" id="ai-insights-panel" style="border: 1px solid #99f6e4; background: linear-gradient(180deg, #f0fdfa 0%, #ffffff 100%);">
        <div class="card-head">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.2rem;">🤖</span>
                <h3 style="color: #115e59; font-size: 0.95rem;">AI Clinical Insights &amp; Decision Support</h3>
            </div>
            <div style="display: flex; gap: 6px;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="generateAiSummary(true)" id="btn-refresh-ai" style="display: none; font-size: 0.7rem;">🔄 Refresh</button>
                <button type="button" class="btn btn-sm btn-secondary" id="btn-dismiss-ai" style="display: none; font-size: 0.7rem;">Dismiss</button>
                <a href="{{ route('insights.qa.index') }}" class="btn btn-sm btn-primary" style="background: #0d9488; border-color: #0d9488; font-size: 0.7rem;">💬 Q&amp;A Assistant</a>
            </div>
        </div>
        @include('partials.integration-notice', ['kind' => 'gemini'])
        <div id="ai-summary-empty" style="padding: 12px; text-align: center;">
            <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 8px;">Generate an executive plain-language summary of clinic performance and unsuppressed cohort trends.</p>
            <button type="button" class="btn btn-primary btn-sm" onclick="generateAiSummary(false)" style="background: #0d9488; border-color: #0d9488; font-size: 0.75rem; font-weight: 700;">
                ✨ Generate Clinical Summary
            </button>
        </div>
        <div id="ai-summary-loading" style="display: none; text-align: center; padding: 16px;">
            <div style="font-size: 1.5rem;">✨</div>
            <div style="font-size: 0.85rem; color: #0f766e; font-weight: 600;">Analyzing anonymized clinic metrics...</div>
        </div>
        <div id="ai-summary-content" style="display: none; padding: 12px; background: #ffffff; border-radius: 8px; border: 1px solid #ccfbf1; font-size: 0.85rem; line-height: 1.6;">
            <div id="ai-summary-text"></div>
        </div>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\AssetVersion::url('js/chart.umd.min.js') }}"></script>
<script>
    window.vilocareDashboardCharts = @json($charts);
</script>
<script src="{{ \App\Support\AssetVersion::url('js/dashboard-charts.js') }}"></script>
<script>
    // AI Insights Generator JS helper
    async function generateAiSummary(force = false) {
        const loadingEl = document.getElementById('ai-summary-loading');
        const emptyEl = document.getElementById('ai-summary-empty');
        const contentEl = document.getElementById('ai-summary-content');
        const textEl = document.getElementById('ai-summary-text');
        const refreshBtn = document.getElementById('btn-refresh-ai');

        if (loadingEl) loadingEl.style.display = 'block';
        if (emptyEl) emptyEl.style.display = 'none';
        if (contentEl) contentEl.style.display = 'none';

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const url = force ? "{{ route('insights.summary.generate') }}" : "{{ route('insights.summary.get') }}";
            const options = force ? {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ force_refresh: true })
            } : {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            };

            const resp = await fetch(url, options);
            const data = await resp.json();

            if (data.summary) {
                textEl.innerHTML = data.summary.replace(/\n/g, '<br>');
                if (refreshBtn) refreshBtn.style.display = 'inline-flex';
                if (loadingEl) loadingEl.style.display = 'none';
                if (contentEl) contentEl.style.display = 'block';
            } else {
                if (loadingEl) loadingEl.style.display = 'none';
                if (emptyEl) emptyEl.style.display = 'block';
            }
        } catch (err) {
            console.error('AI Insights error:', err);
            if (loadingEl) loadingEl.style.display = 'none';
            if (emptyEl) emptyEl.style.display = 'block';
        }
    }
</script>
@endpush
