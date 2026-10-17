<?php

namespace App\Http\Controllers;

use App\Models\County;
use App\Models\EacEpisode;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\State;
use App\Models\ViralLoadResult;
use App\Services\AnalyticsService;
use App\Services\SuppressionService;
use App\Services\VlDueItem;
use App\Services\VlDueService;
use App\Support\ClinicDefaults;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, AnalyticsService $analyticsService, VlDueService $vlDueService, SuppressionService $suppressionService): View
    {
        // 1. Location hierarchy lists
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $selectedStateId = $request->input('state_id', 'all');
        $selectedCountyId = $request->input('county_id', 'all');
        $selectedFacilityId = $request->input('facility_id', 'all');

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

        // 2. Query Builder with location filters
        $patientQuery = Patient::query();
        $vlQuery = ViralLoadResult::query();
        $eacQuery = EacEpisode::query();

        if ($selectedFacilityId !== 'all' && ! empty($selectedFacilityId)) {
            $patientQuery->where('facility_id', $selectedFacilityId);
            $vlQuery->whereHas('patient', fn ($q) => $q->where('facility_id', $selectedFacilityId));
            $eacQuery->whereHas('patient', fn ($q) => $q->where('facility_id', $selectedFacilityId));
        } elseif ($selectedCountyId !== 'all' && ! empty($selectedCountyId)) {
            $patientQuery->where('county_id', $selectedCountyId);
            $vlQuery->whereHas('patient', fn ($q) => $q->where('county_id', $selectedCountyId));
            $eacQuery->whereHas('patient', fn ($q) => $q->where('county_id', $selectedCountyId));
        } elseif ($selectedStateId !== 'all' && ! empty($selectedStateId)) {
            $patientQuery->where('state_id', $selectedStateId);
            $vlQuery->whereHas('patient', fn ($q) => $q->where('state_id', $selectedStateId));
            $eacQuery->whereHas('patient', fn ($q) => $q->where('state_id', $selectedStateId));
        }

        if ($startDate && $endDate) {
            $patientQuery->whereBetween('art_start_date', [$startDate->toDateString(), $endDate->toDateString()]);
            $vlQuery->whereBetween('result_date', [$startDate->toDateString(), $endDate->toDateString()]);
        }

        $totalPatients = (clone $patientQuery)->count();
        $suppression = $suppressionService->summary(
            ['state_id' => $selectedStateId, 'county_id' => $selectedCountyId, 'facility_id' => $selectedFacilityId],
            $startDate && $endDate ? $startDate->toDateString() : null,
            $startDate && $endDate ? $endDate->toDateString() : null,
        );
        $totalVl = $suppression['total_vl'];
        $suppressionRate = $suppression['suppression_rate'];
        $activeEac = (clone $eacQuery)->where('status', 'active')->count();

        $dueForVl = $vlDueService->count([
            'state_id' => $selectedStateId,
            'county_id' => $selectedCountyId,
            'facility_id' => $selectedFacilityId,
        ]);

        $stats = [
            'total_patients' => $totalPatients,
            'total_vl' => $totalVl,
            'suppression_rate' => $suppressionRate,
            'patients_tested' => $suppression['patients_tested'],
            'patients_suppressed' => $suppression['patients_suppressed'],
            'patients_unsuppressed' => $suppression['patients_unsuppressed'],
            'active_patients' => $suppression['active_patients'],
            'vl_coverage_percent' => $suppression['vl_coverage_percent'],
            'active_eac' => $activeEac,
            'due_vl' => $dueForVl,
        ];

        // 3. Analytics Visual Trends (Monthly Suppression Trend & Cohort Breakdown)
        $monthlyTrend = $analyticsService->getMonthlySuppressionTrend($selectedStateId, $selectedCountyId, $selectedFacilityId, 6);
        $cohortBreakdown = $analyticsService->getCohortSuppressionBreakdown($selectedStateId, $selectedCountyId, $selectedFacilityId, $startDate?->toDateString(), $endDate?->toDateString());

        return view('reports.index', [
            'stats' => $stats,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'selectedDateRange' => $dateRange,
            'startDate' => $request->input('start_date'),
            'endDate' => $request->input('end_date'),
            'monthlyTrend' => $monthlyTrend,
            'cohortBreakdown' => $cohortBreakdown,
        ]);
    }

    public function patientList(Request $request): View|StreamedResponse
    {
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $selectedStateId = $request->input('state_id', 'all');
        $selectedCountyId = $request->input('county_id', 'all');
        $selectedFacilityId = $request->input('facility_id', 'all');

        $query = Patient::with(['facility', 'county.state', 'state', 'activeRegimen', 'latestViralLoadResult'])->latest('art_start_date');

        if ($cohort = $request->input('cohort')) {
            if ($cohort === 'pediatric') {
                $query->where('age', '<', 15);
            } elseif ($cohort === 'adult') {
                $query->where('age', '>=', 15);
            }
        }

        if ($sex = $request->input('sex')) {
            $query->where('sex', $sex);
        }

        if ($condition = $request->input('condition')) {
            if ($condition === 'pregnant') {
                $query->where('is_pregnant', true);
            } elseif ($condition === 'breastfeeding') {
                $query->where('is_breastfeeding', true);
            }
        }

        if ($selectedFacilityId !== 'all' && ! empty($selectedFacilityId)) {
            $query->where('facility_id', $selectedFacilityId);
        } elseif ($selectedCountyId !== 'all' && ! empty($selectedCountyId)) {
            $query->where('county_id', $selectedCountyId);
        } elseif ($selectedStateId !== 'all' && ! empty($selectedStateId)) {
            $query->where('state_id', $selectedStateId);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->input('export') === 'csv') {
            $filename = 'vilocare_patient_cohort_report_'.date('Ymd_His').'.csv';
            $patients = $query->get();

            return response()->streamDownload(function () use ($patients) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'ART Number',
                    'Full Name',
                    'Sex',
                    'Age',
                    'Age Cohort',
                    'ART Start Date',
                    'Months on ART',
                    'Pregnant',
                    'Breastfeeding',
                    'Current Regimen',
                    'Regimen Line',
                    'Latest VL (copies/ml)',
                    'Latest VL Date',
                    'Suppression Status',
                    'Facility',
                    'County',
                    'State',
                    'Status',
                ]);

                foreach ($patients as $p) {
                    $latestVl = $p->latestViralLoadResult;
                    fputcsv($handle, [
                        $p->art_number,
                        $p->full_name,
                        ucfirst($p->sex),
                        $p->age,
                        $p->ageCohort(),
                        $p->art_start_date->format('Y-m-d'),
                        $p->monthsOnArt(),
                        $p->is_pregnant ? 'Yes' : 'No',
                        $p->is_breastfeeding ? 'Yes' : 'No',
                        $p->activeRegimen?->regimen_name ?? 'None',
                        $p->activeRegimen?->regimen_line ?? 'None',
                        $latestVl ? ($latestVl->is_target_not_detected ? 'LDL (<20)' : $latestVl->result_value) : 'Not Tested',
                        $latestVl?->result_date?->format('Y-m-d') ?? 'N/A',
                        $latestVl ? ($latestVl->is_suppressed ? 'Suppressed' : 'Unsuppressed') : 'N/A',
                        $p->facility?->name ?? 'N/A',
                        $p->county?->name ?? 'N/A',
                        $p->state?->name ?? 'N/A',
                        ucfirst(str_replace('_', ' ', $p->status)),
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        }

        $patients = $query->paginate(20)->withQueryString();
        $reportRef = 'VLC-PAT-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid()), 0, 6));

        return view('reports.patients', [
            'patients' => $patients,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'reportRef' => $reportRef,
            'isPrint' => (bool) $request->input('print'),
        ]);
    }

    public function viralLoadSummary(Request $request, AnalyticsService $analyticsService, SuppressionService $suppressionService): View|StreamedResponse
    {
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $selectedStateId = $request->input('state_id', 'all');
        $selectedCountyId = $request->input('county_id', 'all');
        $selectedFacilityId = $request->input('facility_id', 'all');

        $query = ViralLoadResult::with(['patient.facility', 'patient.activeRegimen', 'requestingClinician'])->latest('result_date');

        // Location filtering helper
        $applyLocation = function ($q) use ($selectedStateId, $selectedCountyId, $selectedFacilityId) {
            if ($selectedFacilityId !== 'all' && ! empty($selectedFacilityId)) {
                return $q->whereHas('patient', fn ($sub) => $sub->where('facility_id', $selectedFacilityId));
            }
            if ($selectedCountyId !== 'all' && ! empty($selectedCountyId)) {
                return $q->whereHas('patient', fn ($sub) => $sub->where('county_id', $selectedCountyId));
            }
            if ($selectedStateId !== 'all' && ! empty($selectedStateId)) {
                return $q->whereHas('patient', fn ($sub) => $sub->where('state_id', $selectedStateId));
            }

            return $q;
        };

        $applyLocation($query);

        if ($status = $request->input('status')) {
            if ($status === 'suppressed') {
                $query->where('is_suppressed', true);
            } elseif ($status === 'unsuppressed') {
                $query->where('is_suppressed', false);
            }
        }

        if ($request->filled('flagged')) {
            $query->where('flagged_for_clinical_review', true);
        }

        if ($startDate = $request->input('start_date')) {
            $query->whereDate('result_date', '>=', $startDate);
        }
        if ($endDate = $request->input('end_date')) {
            $query->whereDate('result_date', '<=', $endDate);
        }

        // Filtered base metrics: per patient, single shared definition (see SuppressionService).
        $location = ['state_id' => $selectedStateId, 'county_id' => $selectedCountyId, 'facility_id' => $selectedFacilityId];
        $from = $request->input('start_date') ?: null;
        $to = $request->input('end_date') ?: null;
        $suppression = $suppressionService->summary($location, $from, $to);
        $cohortRates = $suppressionService->cohorts($location, $from, $to)['cohorts'];

        $baseMetricsQuery = ViralLoadResult::query();
        $applyLocation($baseMetricsQuery);
        $flaggedCount = (clone $baseMetricsQuery)->where('flagged_for_clinical_review', true)->count();

        if ($request->input('export') === 'csv') {
            $filename = 'vilocare_viral_load_summary_'.date('Ymd_His').'.csv';
            $results = $query->get();

            return response()->streamDownload(function () use ($results) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'Patient ART #',
                    'Patient Name',
                    'Age',
                    'Sex',
                    'Sample Date',
                    'Result Date',
                    'Result Value (c/ml)',
                    'Suppression Status',
                    'Algorithm Next Due Date',
                    'Clinical Rationale',
                    'Suspected treatment failure: clinical review',
                    'Post-EAC Repeat',
                    'Lab Name',
                    'Sample Type',
                ]);

                foreach ($results as $r) {
                    fputcsv($handle, [
                        $r->patient->art_number,
                        $r->patient->full_name,
                        $r->patient->age,
                        ucfirst($r->patient->sex),
                        $r->sample_date->format('Y-m-d'),
                        $r->result_date->format('Y-m-d'),
                        $r->formatted_result,
                        $r->is_suppressed ? 'Suppressed' : 'High VL',
                        $r->next_due_date?->format('Y-m-d') ?? 'Active in EAC',
                        $r->algorithm_reason,
                        $r->flagged_for_clinical_review ? 'YES (Clinical review)' : 'No',
                        $r->is_post_eac_repeat ? 'Yes' : 'No',
                        $r->lab_name,
                        $r->sample_type,
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        }

        $results = $query->paginate(20)->withQueryString();
        $reportRef = 'VLC-VL-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid()), 0, 6));

        // Visual charts for VL Report
        $monthlyTrend = $analyticsService->getMonthlySuppressionTrend($selectedStateId, $selectedCountyId, $selectedFacilityId, 6);
        $cohortBreakdown = $analyticsService->getCohortSuppressionBreakdown($selectedStateId, $selectedCountyId, $selectedFacilityId, $from, $to);

        return view('reports.viral-load', [
            'results' => $results,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'monthlyTrend' => $monthlyTrend,
            'cohortBreakdown' => $cohortBreakdown,
            'stats' => [
                'total' => $suppression['total_vl'],
                'suppressed' => $suppression['suppressed_count'],
                'unsuppressed' => $suppression['unsuppressed_count'],
                'flagged' => $flaggedCount,
                'suppression_rate' => $suppression['suppression_rate'],
                'pediatric_rate' => $cohortRates['pediatric']['suppression_rate'],
                'adult_rate' => $cohortRates['adult']['suppression_rate'],
                'pmtct_rate' => $cohortRates['pmtct']['suppression_rate'],
                'patients_tested' => $suppression['patients_tested'],
                'patients_suppressed' => $suppression['patients_suppressed'],
                'patients_unsuppressed' => $suppression['patients_unsuppressed'],
                'active_patients' => $suppression['active_patients'],
                'vl_coverage_percent' => $suppression['vl_coverage_percent'],
            ],
            'reportRef' => $reportRef,
            'isPrint' => (bool) $request->input('print'),
        ]);
    }

    public function eacStatus(Request $request): View|StreamedResponse
    {
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $selectedStateId = $request->input('state_id', 'all');
        $selectedCountyId = $request->input('county_id', 'all');
        $selectedFacilityId = $request->input('facility_id', 'all');

        $query = EacEpisode::with(['patient.facility', 'viralLoadResult', 'repeatViralLoadResult', 'sessions.counselor'])->latest();

        if ($selectedFacilityId !== 'all' && ! empty($selectedFacilityId)) {
            $query->whereHas('patient', fn ($q) => $q->where('facility_id', $selectedFacilityId));
        } elseif ($selectedCountyId !== 'all' && ! empty($selectedCountyId)) {
            $query->whereHas('patient', fn ($q) => $q->where('county_id', $selectedCountyId));
        } elseif ($selectedStateId !== 'all' && ! empty($selectedStateId)) {
            $query->whereHas('patient', fn ($q) => $q->where('state_id', $selectedStateId));
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($request->input('export') === 'csv') {
            $filename = 'vilocare_eac_adherence_report_'.date('Ymd_His').'.csv';
            $episodes = $query->get();

            return response()->streamDownload(function () use ($episodes) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'Patient ART #',
                    'Patient Name',
                    'Age / Sex',
                    'Initial High VL (c/ml)',
                    'Initial Test Date',
                    'EAC Status',
                    'Completed Sessions',
                    'Repeat VL Due Date',
                    'Repeat VL Result (c/ml)',
                    'Outcome',
                ]);

                foreach ($episodes as $e) {
                    fputcsv($handle, [
                        $e->patient->art_number,
                        $e->patient->full_name,
                        "{$e->patient->age} / ".ucfirst($e->patient->sex),
                        $e->viralLoadResult?->result_value ?? 'N/A',
                        $e->viralLoadResult?->result_date?->format('Y-m-d') ?? 'N/A',
                        $e->statusLabel(),
                        $e->completedSessionsCount().' / 3',
                        $e->repeat_vl_due_date?->format('Y-m-d') ?? 'Pending Sessions',
                        $e->repeatViralLoadResult?->formatted_result ?? 'Awaiting Repeat',
                        $e->status === 'flagged_for_regimen_switch' ? 'SUSPECTED TREATMENT FAILURE: CLINICAL REVIEW' : ($e->status === 'resolved_suppressed' ? 'Resolved Suppressed' : 'In Progress'),
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        }

        $episodes = $query->paginate(20)->withQueryString();
        $reportRef = 'VLC-EAC-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid()), 0, 6));

        return view('reports.eac', [
            'episodes' => $episodes,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'reportRef' => $reportRef,
            'isPrint' => (bool) $request->input('print'),
        ]);
    }

    public function dueList(Request $request, VlDueService $vlDueService): View|StreamedResponse
    {
        $states = State::orderBy('name')->get();
        $counties = County::with('state')->orderBy('name')->get();
        $facilities = Facility::with('county.state')->orderBy('name')->get();

        $selectedStateId = $request->input('state_id', 'all');
        $selectedCountyId = $request->input('county_id', 'all');
        $selectedFacilityId = $request->input('facility_id', 'all');

        $daysAhead = max(0, (int) $request->input('days', 30));

        // One row per patient, in their highest-priority group (see VlDueService).
        // period=today is the dashboard "Due for VL Today" list: due date exactly today, no overdue.
        $location = [
            'state_id' => $selectedStateId,
            'county_id' => $selectedCountyId,
            'facility_id' => $selectedFacilityId,
        ];
        $isToday = $request->input('period') === 'today';
        $due = $isToday ? $vlDueService->dueToday($location) : $vlDueService->due($location, $daysAhead);
        $duePmtct = $due->where('group', VlDueItem::GROUP_PMTCT)->values();
        $dueEac = $due->where('group', VlDueItem::GROUP_POST_EAC)->values();
        $dueResults = $due->where('group', VlDueItem::GROUP_ROUTINE)->values();

        if ($request->input('export') === 'csv') {
            $filename = 'vilocare_due_services_report_'.date('Ymd_His').'.csv';

            return response()->streamDownload(function () use ($duePmtct, $dueEac, $dueResults) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'Type',
                    'Due Date',
                    'Patient ART #',
                    'Patient Name',
                    'Age / Sex',
                    'Phone',
                    'Facility',
                    'Current Regimen',
                    'Reason / Context',
                    'Due Status',
                ]);

                $types = [
                    VlDueItem::GROUP_PMTCT => 'Immediate Viral Load Test (PMTCT)',
                    VlDueItem::GROUP_POST_EAC => 'Post-EAC Repeat Viral Load Test',
                    VlDueItem::GROUP_ROUTINE => 'Routine Viral Load Test',
                ];

                // Same order as on screen: PMTCT, post-EAC, routine. Each patient appears once.
                foreach ([$duePmtct, $dueEac, $dueResults] as $group) {
                    foreach ($group as $item) {
                        fputcsv($handle, [
                            $types[$item->group],
                            $item->next_due_date->format('Y-m-d'),
                            $item->patient->art_number,
                            $item->patient->full_name,
                            "{$item->patient->age} / ".ucfirst($item->patient->sex),
                            $item->patient->phone ?? 'N/A',
                            $item->patient->facility?->name ?? 'N/A',
                            $item->patient->activeRegimen?->regimen_name ?? 'N/A',
                            $item->algorithm_reason,
                            $item->dueLabel(),
                        ]);
                    }
                }
                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store, no-cache',
            ]);
        }

        $reportRef = 'VLC-DUE-'.date('Ymd').'-'.strtoupper(substr(md5(uniqid()), 0, 6));

        return view('reports.due-list', [
            'duePmtct' => $duePmtct,
            'dueResults' => $dueResults,
            'dueEac' => $dueEac,
            'states' => $states,
            'counties' => $counties,
            'facilities' => $facilities,
            'selectedStateId' => $selectedStateId,
            'selectedCountyId' => $selectedCountyId,
            'selectedFacilityId' => $selectedFacilityId,
            'daysAhead' => $daysAhead,
            'isToday' => $isToday,
            'reportRef' => $reportRef,
            'isPrint' => (bool) $request->input('print'),
        ]);
    }

    public function verify(Request $request): View
    {
        $refCode = $request->input('ref');
        $verificationResult = null;

        if ($refCode) {
            $isValid = preg_match('/^VLC-(PAT|VL|EAC|DUE)-\d{8}-[A-Z0-9]{6}$/', $refCode);
            $verificationResult = [
                'code' => $refCode,
                'is_valid' => (bool) $isValid,
                'verified_at' => now(),
                'clinic_name' => ClinicDefaults::name(),
                'issuer' => 'ViLoCare South Sudan National Viral Load Health Registry',
            ];
        }

        return view('reports.verify', [
            'refCode' => $refCode,
            'result' => $verificationResult,
        ]);
    }
}
