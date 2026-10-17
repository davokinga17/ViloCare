@extends('layouts.app')

@section('title', 'Appointments & Clinical Visits')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Appointments & Clinical Visits</h1>
        <p class="page-subtitle">Schedule and track routine clinical follow-ups, viral load bleeds, EAC sessions, and drug pick-ups.</p>
    </div>
    <div class="page-actions">
        <a href="{{ route('appointments.create') }}" class="btn btn-primary">
            <span>➕</span> Schedule Appointment
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="stats-grid">
    <div class="stat-card" style="padding: 16px; border-left: 4px solid #3b82f6;">
        <div class="stat-header">
            <span class="stat-label">Today's Clinic Queue</span>
            <div class="stat-icon-wrapper" style="background-color: #eff6ff; color: #2563eb;">📅</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #2563eb;">{{ $stats['today'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #0d9488;">
        <div class="stat-header">
            <span class="stat-label">Upcoming Scheduled</span>
            <div class="stat-icon-wrapper" style="background-color: #f0fdfa; color: #0d9488;">⏰</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #0d9488;">{{ $stats['upcoming'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #ef4444;">
        <div class="stat-header">
            <span class="stat-label">Overdue / Defaulters</span>
            <div class="stat-icon-wrapper" style="background-color: #fef2f2; color: #dc2626;">⚠️</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #dc2626;">{{ $stats['overdue'] }}</div>
    </div>

    <div class="stat-card" style="padding: 16px; border-left: 4px solid #10b981;">
        <div class="stat-header">
            <span class="stat-label">Attended / Completed</span>
            <div class="stat-icon-wrapper" style="background-color: #ecfdf5; color: #059669;">✅</div>
        </div>
        <div class="stat-val" style="font-size: 1.6rem; color: #059669;">{{ $stats['completed'] }}</div>
    </div>
</div>

<!-- Filter Tabs & Search Bar -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="padding: 16px 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--border);">
            <!-- Period Tabs -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="{{ route('appointments.index', ['period' => 'today']) }}" class="btn btn-sm {{ $currentPeriod === 'today' ? 'btn-primary' : 'btn-secondary' }}">
                    Today ({{ $stats['today'] }})
                </a>
                <a href="{{ route('appointments.index', ['period' => 'this_week']) }}" class="btn btn-sm {{ $currentPeriod === 'this_week' ? 'btn-primary' : 'btn-secondary' }}">
                    This Week
                </a>
                <a href="{{ route('appointments.index', ['period' => 'this_month']) }}" class="btn btn-sm {{ $currentPeriod === 'this_month' ? 'btn-primary' : 'btn-secondary' }}">
                    This Month
                </a>
                <a href="{{ route('appointments.index', ['period' => 'upcoming']) }}" class="btn btn-sm {{ $currentPeriod === 'upcoming' ? 'btn-primary' : 'btn-secondary' }}">
                    All Upcoming
                </a>
                <a href="{{ route('appointments.index', ['period' => 'overdue']) }}" class="btn btn-sm {{ $currentPeriod === 'overdue' ? 'btn-primary' : 'btn-secondary' }}" style="{{ $currentPeriod === 'overdue' ? 'background: #dc2626;' : 'color: #dc2626;' }}">
                    Overdue Missed ({{ $stats['overdue'] }})
                </a>
                <a href="{{ route('appointments.index', ['period' => 'all']) }}" class="btn btn-sm {{ $currentPeriod === 'all' ? 'btn-primary' : 'btn-secondary' }}">
                    All Records
                </a>
            </div>
        </div>

        <form method="GET" action="{{ route('appointments.index') }}" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
            <input type="hidden" name="period" value="{{ $currentPeriod }}">

            <div style="flex: 2; min-width: 220px;">
                <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="Search by Patient ART #, Name, Phone...">
            </div>

            <div style="width: 200px;">
                <select name="reason" class="form-select">
                    <option value="">All Visit Reasons</option>
                    <option value="Routine Clinical Review" {{ request('reason') === 'Routine Clinical Review' ? 'selected' : '' }}>Routine Clinical Review</option>
                    <option value="Viral Load Sample Collection" {{ request('reason') === 'Viral Load Sample Collection' ? 'selected' : '' }}>Viral Load Bleed</option>
                    <option value="EAC Counselling Session" {{ request('reason') === 'EAC Counselling Session' ? 'selected' : '' }}>EAC Session</option>
                    <option value="ARV Drug Refill" {{ request('reason') === 'ARV Drug Refill' ? 'selected' : '' }}>Drug Refill</option>
                    <option value="Lab Result Discussion" {{ request('reason') === 'Lab Result Discussion' ? 'selected' : '' }}>Result Discussion</option>
                </select>
            </div>

            <button type="submit" class="btn btn-secondary">Filter</button>
            @if(request()->hasAny(['search', 'reason', 'status']))
                <a href="{{ route('appointments.index', ['period' => $currentPeriod]) }}" class="btn btn-secondary" style="color: #64748b;">Reset</a>
            @endif
        </form>
    </div>
</div>

<!-- Appointments Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Patient</th>
                    <th>Visit Purpose</th>
                    <th>Status</th>
                    <th>Scheduled By</th>
                    <th style="text-align: right;">Quick Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($appointments as $apt)
                    <tr style="{{ $apt->isToday() ? 'background-color: #f0fdf4;' : ($apt->isMissed() ? 'background-color: #fef2f2;' : '') }}">
                        <td>
                            <strong style="color: #0f172a; font-size: 0.95rem;">
                                {{ $apt->appointment_date->format('Y-m-d') }}
                            </strong>
                            @if($apt->isToday())
                                <span class="badge badge-success" style="font-size: 0.7rem; padding: 2px 6px;">TODAY</span>
                            @endif
                            <div style="font-size: 0.75rem; color: #64748b;">{{ $apt->appointment_time ?? 'Clinic Hours' }}</div>
                        </td>
                        <td>
                            <a href="{{ route('patients.show', $apt->patient) }}" style="font-weight: 700; color: var(--primary);">
                                {{ $apt->patient->art_number }}
                            </a>
                            <div style="font-size: 0.8rem; color: #0f172a;">{{ $apt->patient->full_name }} ({{ $apt->patient->ageCohort() }})</div>
                            @if($apt->patient->phone)
                                <div style="font-size: 0.75rem; color: #64748b;">📞 {{ $apt->patient->phone }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge badge-secondary" style="font-size: 0.8rem;">{{ $apt->reason }}</span>
                            @if($apt->notes)
                                <div style="font-size: 0.75rem; color: #475569; margin-top: 2px;">{{ Str::limit($apt->notes, 35) }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge {{ $apt->statusBadgeClass() }}">
                                {{ $apt->statusLabel() }}
                            </span>
                            @if($apt->attended_at)
                                <div style="font-size: 0.75rem; color: #059669; margin-top: 2px;">
                                    Attended: {{ $apt->attended_at->format('H:i') }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div style="font-size: 0.8rem; color: #334155;">{{ $apt->scheduler?->name ?? 'System' }}</div>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                @if($apt->status === 'scheduled')
                                    <!-- Mark Completed Action -->
                                    <form method="POST" action="{{ route('appointments.status', $apt) }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="status" value="completed">
                                        <button type="submit" class="btn btn-primary btn-sm" style="background-color: #059669;" title="Mark Patient Attended">
                                            ✅ Attended
                                        </button>
                                    </form>

                                    <!-- Mark Missed Action -->
                                    <form method="POST" action="{{ route('appointments.status', $apt) }}" style="display: inline;">
                                        @csrf
                                        <input type="hidden" name="status" value="missed">
                                        <button type="submit" class="btn btn-secondary btn-sm" style="color: #dc2626;" title="Mark Missed">
                                            ❌ Missed
                                        </button>
                                    </form>
                                @endif

                                <a href="{{ route('appointments.show', $apt) }}" class="btn btn-secondary btn-sm">
                                    👁️ Details
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 36px; color: #64748b;">
                            No appointments found for the selected period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($appointments->hasPages())
        <div class="card-footer">
            {{ $appointments->links() }}
        </div>
    @endif
</div>
@endsection
