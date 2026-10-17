<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\RegimenHistory;
use App\Models\SampleCollection;
use App\Models\SampleRejection;
use App\Models\ViralLoadResult;
use App\Services\Ai\AiProviderInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AiInsightService
{
    protected AiProviderInterface $provider;

    public function __construct(?AiProviderInterface $provider = null)
    {
        $this->provider = $provider ?? app(AiProviderInterface::class);
    }

    /**
     * Cache key for daily clinical dashboard summary.
     */
    public const CACHE_KEY_DAILY_SUMMARY = 'vilocare_clinical_ai_summary';

    public const CACHE_KEY_DAILY_SUMMARY_TIME = 'vilocare_clinical_ai_summary_time';

    /**
     * Retrieve or regenerate the daily/weekly clinical summary.
     */
    public function getClinicalSummary(bool $forceRefresh = false): array
    {
        if (! $forceRefresh && Cache::has(self::CACHE_KEY_DAILY_SUMMARY)) {
            return [
                'summary' => Cache::get(self::CACHE_KEY_DAILY_SUMMARY),
                'generated_at' => Cache::get(self::CACHE_KEY_DAILY_SUMMARY_TIME, now()->toIso8601String()),
                'from_cache' => true,
                'data_snapshot' => $this->getAggregatedData(),
            ];
        }

        $aggregatedData = $this->getAggregatedData();
        $summary = $this->provider->generateClinicalSummary($aggregatedData);
        $generatedAt = now()->toIso8601String();

        // Cache summary until end of the current day to control API costs.
        // Warnings (missing key, rate limit, outage) are not cached, so fixing the cause takes effect at once.
        if (! str_starts_with($summary, '⚠️')) {
            Cache::put(self::CACHE_KEY_DAILY_SUMMARY, $summary, now()->endOfDay());
            Cache::put(self::CACHE_KEY_DAILY_SUMMARY_TIME, $generatedAt, now()->endOfDay());
        }

        return [
            'summary' => $summary,
            'generated_at' => $generatedAt,
            'from_cache' => false,
            'data_snapshot' => $aggregatedData,
        ];
    }

    /**
     * Answer a staff member's question strictly using aggregated data.
     */
    public function askQuestion(string $question): array
    {
        $aggregatedData = $this->getAggregatedData();
        $answer = $this->provider->answerStaffQuestion(trim($question), $aggregatedData);

        return [
            'question' => $question,
            'answer' => $answer,
            'answered_at' => now()->toIso8601String(),
            'data_snapshot' => $aggregatedData,
        ];
    }

    /**
     * Compile 100% Anonymized & Aggregated Clinic Statistics (Zero PII).
     *
     * @return array<string, mixed>
     */
    public function getAggregatedData(): array
    {
        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // 1. Patient Cohort Breakdown (Aggregated counts only)
        $totalPatients = Patient::count();
        $pediatricCount = Patient::where('age', '<', 15)->count();
        $youthCount = Patient::whereBetween('age', [15, 24])->count();
        $adultCount = Patient::where('age', '>=', 25)->count();
        $femaleCount = Patient::where('sex', 'female')->count();
        $maleCount = Patient::where('sex', 'male')->count();
        $pregnantCount = Patient::where('is_pregnant', true)->count();
        $breastfeedingCount = Patient::where('is_breastfeeding', true)->count();

        // Active Regimen lines distribution
        $activeRegimens = RegimenHistory::whereNull('end_date')->get();
        $firstLineCount = $activeRegimens->filter(fn ($r) => str_contains(strtolower($r->regimen_line ?? ''), '1') || str_contains(strtolower($r->regimen_name ?? ''), '1st') || str_contains(strtolower($r->regimen_line ?? ''), 'first'))->count();
        $secondLineCount = $activeRegimens->filter(fn ($r) => str_contains(strtolower($r->regimen_line ?? ''), '2') || str_contains(strtolower($r->regimen_name ?? ''), '2nd') || str_contains(strtolower($r->regimen_line ?? ''), 'second'))->count();
        $thirdLineCount = $activeRegimens->filter(fn ($r) => str_contains(strtolower($r->regimen_line ?? ''), '3') || str_contains(strtolower($r->regimen_name ?? ''), '3rd') || str_contains(strtolower($r->regimen_line ?? ''), 'third'))->count();
        $unclassifiedRegimenCount = max(0, $totalPatients - ($firstLineCount + $secondLineCount + $thirdLineCount));

        // 2. Viral Load Suppression Metrics
        // Per patient (latest result of each active patient), single shared definition (see SuppressionService).
        $suppressionService = app(SuppressionService::class);
        $suppression = $suppressionService->summary();
        $cohortStats = $suppressionService->cohorts()['cohorts'];
        $pregnantStats = $suppressionService->summary([], null, null, 'pregnant');
        $totalVl = $suppression['total_vl'];
        $suppressedCount = $suppression['suppressed_count'];
        $unsuppressedCount = $suppression['unsuppressed_count'];
        $flaggedForSwitch = ViralLoadResult::where('flagged_for_clinical_review', true)->count();
        $overallSuppressionRate = $suppression['suppression_rate'];
        $pediatricRate = $cohortStats['pediatric']['suppression_rate'];
        $adultRate = $cohortStats['adult']['suppression_rate'];
        $pmtctRate = $cohortStats['pmtct']['suppression_rate'];
        $pregnantRate = $pregnantStats['suppression_rate'];
        // 3. EAC Cascade (Enhanced Adherence Counselling)
        $activeEac = EacEpisode::where('status', 'active')->count();
        $eacCompletedSessions = EacEpisode::where('status', 'sessions_completed')->count();
        $eacRepeatVlDone = EacEpisode::where('status', 'repeat_vl_done')->count();
        $eacResolvedSuppressed = EacEpisode::where('status', 'resolved_suppressed')->count();
        $eacFlaggedSwitch = EacEpisode::where('status', 'flagged_for_regimen_switch')->count();
        $totalEacEpisodes = EacEpisode::count();
        $eacCompletionRate = $totalEacEpisodes > 0 ? round((($eacCompletedSessions + $eacRepeatVlDone + $eacResolvedSuppressed) / $totalEacEpisodes) * 100, 1) : 0;

        // 4. Sample Collection & Laboratory Metrics
        $samplesMonth = SampleCollection::whereDate('collection_date', '>=', $startOfMonth)->count();
        $samplesInTransit = SampleCollection::where('status', 'in_transit')->count();
        $samplesCollectedAtFacility = SampleCollection::where('status', 'collected')->count();
        $samplesReceivedAtLab = SampleCollection::whereIn('status', ['received_at_lab', 'processed'])->count();
        $samplesRejectedTotal = SampleCollection::where('status', 'rejected')->count();
        $totalSamplesEver = SampleCollection::count();
        $rejectionRate = $totalSamplesEver > 0 ? round(($samplesRejectedTotal / $totalSamplesEver) * 100, 1) : 0;

        // Rejection reasons breakdown
        $rejectionReasons = SampleRejection::selectRaw('reason, count(*) as total')
            ->groupBy('reason')
            ->pluck('total', 'reason')
            ->toArray();

        // 5. Appointments & Due Testing Tracking
        $todayAppointmentsCount = Appointment::whereDate('appointment_date', $today)->where('status', 'scheduled')->count();
        $weekAppointmentsCount = Appointment::whereBetween('appointment_date', [$startOfWeek, $endOfWeek])->count();
        $overdueAppointmentsCount = Appointment::where('appointment_date', '<', $today)->where('status', 'scheduled')->count();

        // Patients (not result rows) due for VL, from the single shared definition. Each patient is
        // counted once: overdue + due in the next 30 days = total due.
        $vlDueService = app(VlDueService::class);
        $immediatePmtctDueCount = $vlDueService->immediatePmtctPatients()->count();
        $dueItems = $vlDueService->due();
        $totalDueForVl = $dueItems->count();
        $overdueVlCount = $dueItems->filter(fn ($item) => $item->isOverdue())->count();
        $dueNext30Days = $totalDueForVl - $overdueVlCount;

        return [
            'clinic_metadata' => [
                'reporting_period' => $today->toDateString(),
                'facility_type' => 'ART Comprehensive Clinic Pilot',
            ],
            'cohort_demographics' => [
                'total_active_patients' => $totalPatients,
                'age_breakdown' => [
                    'under_15_pediatric' => $pediatricCount,
                    'age_15_to_24_youth' => $youthCount,
                    'age_25_plus_adults' => $adultCount,
                ],
                'sex_breakdown' => [
                    'female' => $femaleCount,
                    'male' => $maleCount,
                ],
                'special_populations' => [
                    'pregnant_women' => $pregnantCount,
                    'breastfeeding_mothers' => $breastfeedingCount,
                ],
                'regimen_lines' => [
                    'first_line_count' => $firstLineCount,
                    'second_line_count' => $secondLineCount,
                    'third_line_count' => $thirdLineCount,
                    'other_or_unassigned' => $unclassifiedRegimenCount,
                ],
            ],
            'viral_load_metrics' => [
                'total_tests_recorded' => $totalVl,
                'suppressed_count' => $suppressedCount,
                'unsuppressed_count' => $unsuppressedCount,
                'overall_suppression_rate_percent' => $overallSuppressionRate,
                'pediatric_suppression_rate_percent' => $pediatricRate,
                'pediatric_tested_count' => $cohortStats['pediatric']['patients_tested'],
                'adult_suppression_rate_percent' => $adultRate,
                'adult_tested_count' => $cohortStats['adult']['patients_tested'],
                'pmtct_suppression_rate_percent' => $pmtctRate,
                'pmtct_tested_count' => $cohortStats['pmtct']['patients_tested'],
                'pregnant_suppression_rate_percent' => $pregnantRate,
                'pregnant_tested_count' => $pregnantStats['patients_tested'],
                'patients_tested' => $suppression['patients_tested'],
                'patients_suppressed' => $suppression['patients_suppressed'],
                'patients_unsuppressed' => $suppression['patients_unsuppressed'],
                'active_patients' => $suppression['active_patients'],
                'vl_coverage_percent' => $suppression['vl_coverage_percent'],
                'flagged_for_regimen_switch_review' => $flaggedForSwitch,
            ],
            'eac_cascade' => [
                'total_episodes_initiated' => $totalEacEpisodes,
                'active_counselling_episodes' => $activeEac,
                'completed_sessions_awaiting_repeat_vl' => $eacCompletedSessions,
                'repeat_vl_done' => $eacRepeatVlDone,
                'resolved_suppressed' => $eacResolvedSuppressed,
                'flagged_regimen_switch' => $eacFlaggedSwitch,
                'sessions_completion_rate_percent' => $eacCompletionRate,
            ],
            'specimen_and_laboratory' => [
                'samples_collected_this_month' => $samplesMonth,
                'samples_collected_at_facility' => $samplesCollectedAtFacility,
                'samples_in_transit_to_lab' => $samplesInTransit,
                'samples_received_or_analyzed' => $samplesReceivedAtLab,
                'samples_rejected_total' => $samplesRejectedTotal,
                'overall_rejection_rate_percent' => $rejectionRate,
                'rejection_reasons_breakdown' => $rejectionReasons,
            ],
            'appointments_and_due_monitoring' => [
                'scheduled_visits_today' => $todayAppointmentsCount,
                'scheduled_visits_this_week' => $weekAppointmentsCount,
                'overdue_appointments_count' => $overdueAppointmentsCount,
                'total_patients_due_for_vl' => $totalDueForVl,
                'patients_due_for_routine_vl_next_30_days' => $dueNext30Days,
                'patients_overdue_for_vl_testing' => $overdueVlCount,
                'immediate_pmtct_vl_required_count' => $immediatePmtctDueCount,
            ],
        ];
    }
}
