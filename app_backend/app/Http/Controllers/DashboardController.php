<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\County;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\State;
use App\Models\User;
use App\Models\ViralLoadResult;
use App\Services\DashboardChartsService;
use App\Services\SuppressionService;
use App\Services\VlDueService;
use App\Support\ClinicDefaults;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardChartsService $chartsService, VlDueService $vlDueService, SuppressionService $suppressionService): View
    {
        $user = auth()->user();

        // 1. Location hierarchy lists (South Sudan: State -> County -> Facility)
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $defaultFacility = ClinicDefaults::facility($facilities);

        // Determine selection: defaults to the configured facility (config/vilocare.php) on first clean visit without filter params
        if (! $request->hasAny(['state_id', 'county_id', 'facility_id'])) {
            $selectedFacilityId = $defaultFacility?->id ?? 'all';
            $selectedCountyId = $defaultFacility?->county_id ?? 'all';
            $selectedStateId = $defaultFacility?->county?->state_id ?? 'all';
        } else {
            $selectedStateId = $request->input('state_id') ?: 'all';
            $selectedCountyId = $request->input('county_id') ?: 'all';
            $selectedFacilityId = $request->input('facility_id') ?: 'all';
        }

        // Validate hierarchy integrity
        if ($selectedFacilityId !== 'all') {
            $matchedFacility = $facilities->firstWhere('id', $selectedFacilityId);
            if ($matchedFacility) {
                if ($selectedCountyId !== 'all' && $matchedFacility->county_id !== $selectedCountyId) {
                    $selectedFacilityId = 'all';
                } elseif ($selectedStateId !== 'all' && $matchedFacility->county?->state_id !== $selectedStateId) {
                    $selectedFacilityId = 'all';
                }
            }
        }
        if ($selectedCountyId !== 'all') {
            $matchedCounty = $counties->firstWhere('id', $selectedCountyId);
            if ($matchedCounty && $selectedStateId !== 'all' && $matchedCounty->state_id !== $selectedStateId) {
                $selectedCountyId = 'all';
            }
        }

        $dateRange = $request->input('date_range', 'all');

        $startDate = null;
        $endDate = null;

        if ($dateRange === 'today') {
            $startDate = Carbon::today()->startOfDay();
            $endDate = Carbon::today()->endOfDay();
        } elseif ($dateRange === 'this_month') {
            $startDate = Carbon::now()->startOfMonth();
            $endDate = Carbon::now()->endOfMonth();
        } elseif ($dateRange === '30_days') {
            $startDate = Carbon::now()->subDays(30)->startOfDay();
            $endDate = Carbon::now()->endOfDay();
        } elseif ($dateRange === 'custom') {
            if ($request->filled('start_date')) {
                $startDate = Carbon::parse($request->input('start_date'))->startOfDay();
            }
            if ($request->filled('end_date')) {
                $endDate = Carbon::parse($request->input('end_date'))->endOfDay();
            }
        }

        // Reusable location filter helper
        $applyLocation = function ($query, bool $isDirectPatient = false) use ($selectedStateId, $selectedCountyId, $selectedFacilityId) {
            if ($selectedFacilityId !== 'all' && ! empty($selectedFacilityId)) {
                return $isDirectPatient
                    ? $query->where('facility_id', $selectedFacilityId)
                    : $query->whereHas('patient', fn ($q) => $q->where('facility_id', $selectedFacilityId));
            }

            if ($selectedCountyId !== 'all' && ! empty($selectedCountyId)) {
                return $isDirectPatient
                    ? $query->where('county_id', $selectedCountyId)
                    : $query->whereHas('patient', fn ($q) => $q->where('county_id', $selectedCountyId));
            }

            if ($selectedStateId !== 'all' && ! empty($selectedStateId)) {
                return $isDirectPatient
                    ? $query->where('state_id', $selectedStateId)
                    : $query->whereHas('patient', fn ($q) => $q->where('state_id', $selectedStateId));
            }

            return $query;
        };

        $location = ['state_id' => $selectedStateId, 'county_id' => $selectedCountyId, 'facility_id' => $selectedFacilityId];
        $from = $startDate && $endDate ? $startDate->toDateString() : null;
        $to = $startDate && $endDate ? $endDate->toDateString() : null;

        // 2. Viral Load Suppression Metrics: per patient, single shared definition (see SuppressionService).
        $suppression = $suppressionService->summary($location, $from, $to);

        // Patients due for VL: one per patient, single shared definition (see VlDueService).
        $immediatePmtctPatients = $vlDueService->immediatePmtctPatients($location);
        $dueItems = $vlDueService->due($location);
        $dueForVlCount = $dueItems->count();

        // 3. Chart section. The cards below read EAC, today's workload and cohort totals from the same
        // result, so a card and its chart can never disagree (see DashboardChartsService).
        $charts = $chartsService->build($location, $from, $to, $dueItems, $suppression);
        $totalPatients = $charts['cohorts']['total'];

        $vlQuery = ViralLoadResult::query();
        $applyLocation($vlQuery, false);
        if ($from) {
            $vlQuery->whereBetween('result_date', [$from, $to]);
        }
        $flaggedCount = (clone $vlQuery)->where('flagged_for_clinical_review', true)->count();
        $totalVl = $suppression['total_vl'];
        $suppressedCount = $suppression['suppressed_count'];
        $unsuppressedCount = $suppression['unsuppressed_count'];
        $suppressionRate = $suppression['suppression_rate'];

        $metrics = [
            'total_patients' => $totalPatients,
            'total_vl' => $totalVl,
            'suppressed_count' => $suppressedCount,
            'unsuppressed_count' => $unsuppressedCount,
            'flagged_count' => $flaggedCount,
            'suppression_rate' => $suppressionRate,
            'patients_tested' => $suppression['patients_tested'],
            'patients_suppressed' => $suppression['patients_suppressed'],
            'patients_unsuppressed' => $suppression['patients_unsuppressed'],
            'active_patients' => $suppression['active_patients'],
            'vl_coverage_percent' => $suppression['vl_coverage_percent'],
            'due_for_vl' => $dueForVlCount,
            'due_today' => $vlDueService->countToday($location),
            'immediate_pmtct_due' => $immediatePmtctPatients->count(),
            'active_eac' => $charts['eac']['counts']['active'],
            'eac_sessions_complete' => $charts['eac']['counts']['sessions_completed'],
            'today_appointments' => $charts['workload']['bars'][0]['count'],
            'pending_samples' => $charts['workload']['bars'][1]['count'],
            'total_staff' => User::where('is_active', true)->count(),
            'pending_sync_count' => Patient::where('sync_status', 'pending')->count()
                + ViralLoadResult::where('sync_status', 'pending')->count()
                + SampleCollection::where('sync_status', 'pending')->count()
                + Appointment::where('sync_status', 'pending')->count(),
        ];

        // 5. Actionable Lists for Dashboard
        $duePatientsList = $dueItems->take(8);

        $activeEacQueueQuery = EacEpisode::with(['patient.facility', 'viralLoadResult', 'sessions'])
            ->whereIn('status', ['active', 'sessions_completed'])
            ->latest();
        $applyLocation($activeEacQueueQuery, false);
        $activeEacQueue = $activeEacQueueQuery->take(6)->get();

        $todayAppointmentsQuery = Appointment::with(['patient.facility', 'scheduler'])
            ->whereDate('appointment_date', today())
            ->orderBy('appointment_time', 'asc');
        $applyLocation($todayAppointmentsQuery, false);
        $todayAppointments = $todayAppointmentsQuery->take(8)->get();

        $recentActivities = AuditLog::with('user')->latest()->take(6)->get();

        return view('dashboard.index', [
            'user' => $user,
            'metrics' => $metrics,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'selectedDateRange' => $dateRange,
            'startDate' => $request->input('start_date'),
            'endDate' => $request->input('end_date'),
            'currentFacility' => $facilities->firstWhere('id', $selectedFacilityId) ?? $defaultFacility,
            'immediatePmtctPatients' => $immediatePmtctPatients,
            'duePatientsList' => $duePatientsList,
            'activeEacQueue' => $activeEacQueue,
            'todayAppointments' => $todayAppointments,
            'recentActivities' => $recentActivities,
            'charts' => $charts,
        ]);
    }
}
