<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\SampleCollection;
use App\Models\ViralLoadResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The numbers behind the dashboard chart section, one method per chart.
 *
 * Nothing here is placeholder data: every figure comes from the database, filtered by the same
 * state / county / facility rule as the rest of the dashboard (the most specific one wins).
 *
 * Shared definitions, so cards and charts can never disagree
 *  - Suppression donut: SuppressionService::summary() (latest result per patient).
 *  - VL due (current month): VlDueService::due() items, one date per patient.
 *  - Active EAC: eacProgress() is the only query for EAC counts; the workload bar reads its result.
 *  - Cohorts: one classification query on patientQuery(), the same base as the Total Patients card.
 *
 * Month grouping is done in PHP on a bounded date range (never strftime / to_char), so the same code
 * runs on SQLite (clinics) and PostgreSQL (central server).
 */
class DashboardChartsService
{
    public const MONTHS = 6;

    /** Colour-blind-safe (Okabe-Ito) colours; one colour per concept, reused across cards. */
    public const COLORS = [
        'vl_due' => '#E69F00',
        'samples' => '#0072B2',
        'tested' => '#009E73',
        'suppressed' => '#009E73',
        'unsuppressed' => '#D55E00',
        'appointments' => '#CC79A7',
        'eac_active' => '#56B4E9',
        'eac_repeat_due' => '#E69F00',
        'eac_resolved' => '#009E73',
        'eac_flagged' => '#D55E00',
        'eac_other' => '#94A3B8',
        'children' => '#0072B2',
        'adults' => '#4B5563',
        'pmtct' => '#CC79A7',
    ];

    public function __construct(
        private SuppressionService $suppressionService,
        private VlDueService $vlDueService,
    ) {}

    /**
     * Everything the dashboard needs, computed once.
     *
     * @param  array{state_id?: ?string, county_id?: ?string, facility_id?: ?string}  $location
     * @param  Collection|null  $dueItems  VlDueService::due($location) if the caller already has it
     * @param  array|null  $suppression  SuppressionService::summary() if the caller already has it
     */
    public function build(array $location, ?string $from = null, ?string $to = null, ?Collection $dueItems = null, ?array $suppression = null): array
    {
        $eac = $this->eacProgress($location);

        return [
            'line' => $this->monthlyVlActivity($location, $dueItems),
            'suppression' => $this->suppression($location, $from, $to, $suppression),
            'workload' => $this->todaysWorkload($location, $eac),
            'eac' => $eac,
            'cohorts' => $this->patientsByCohort($location, $from, $to),
        ];
    }

    /**
     * The Total Patients card base: location filter plus the optional ART-start date window
     * (applied only when both bounds are given, as the dashboard has always done).
     */
    public function patientQuery(array $location, ?string $from = null, ?string $to = null): Builder
    {
        $query = Patient::query();
        $this->filterPatients($query, $location);

        if ($from && $to) {
            $query->whereBetween('art_start_date', [$from, $to]);
        }

        return $query;
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Monthly VL due vs samples collected and tested (fixed 6-month window, ignores date range)
    // ---------------------------------------------------------------------------------------------

    public function monthlyVlActivity(array $location, ?Collection $dueItems = null, ?Carbon $now = null): array
    {
        $now ??= now();
        $currentStart = $now->copy()->startOfMonth();
        $firstStart = $currentStart->copy()->subMonths(self::MONTHS - 1);
        $endExclusive = $currentStart->copy()->addMonth();

        $months = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $start = $firstStart->copy()->addMonths($i);
            $isCurrent = $start->equalTo($currentStart);
            $months[$start->format('Y-m')] = [
                'key' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'is_current' => $isCurrent,
                'axis_label' => $isCurrent ? [$start->format('M Y'), 'month to date'] : $start->format('M Y'),
            ];
        }

        $first = $firstStart->toDateString();
        $pastEnd = $currentStart->toDateString();
        $upper = $endExclusive->toDateString();

        // (a) VL due: one patient counted once per month.
        $due = array_fill_keys(array_keys($months), []);

        // Past months: the due dates recorded at the time - every result's next_due_date,
        // every EAC episode's repeat_vl_due_date, and the immediate PMTCT date (pregnancy onset).
        $pastResults = ViralLoadResult::query()->toBase()
            ->where('next_due_date', '>=', $first)->where('next_due_date', '<', $pastEnd);
        $this->filterByPatient($pastResults, $location);
        foreach ($pastResults->get(['patient_id', 'next_due_date']) as $row) {
            $due[substr((string) $row->next_due_date, 0, 7)][$row->patient_id] = true;
        }

        $pastEpisodes = EacEpisode::query()->toBase()
            ->where('repeat_vl_due_date', '>=', $first)->where('repeat_vl_due_date', '<', $pastEnd);
        $this->filterByPatient($pastEpisodes, $location);
        foreach ($pastEpisodes->get(['patient_id', 'repeat_vl_due_date']) as $row) {
            $due[substr((string) $row->repeat_vl_due_date, 0, 7)][$row->patient_id] = true;
        }

        $pastPmtct = Patient::query()->toBase()
            ->where('pregnancy_onset_at', '>=', $first)->where('pregnancy_onset_at', '<', $pastEnd);
        $this->filterPatients($pastPmtct, $location);
        foreach ($pastPmtct->get(['id', 'pregnancy_onset_at']) as $row) {
            $due[substr((string) $row->pregnancy_onset_at, 0, 7)][$row->id] = true;
        }

        // Current month: exactly VlDueService's per-patient rule (latest result, post-EAC, immediate PMTCT).
        // Its 30-day look-ahead always reaches the end of the current month.
        $dueItems ??= $this->vlDueService->due($location);
        $currentKey = $currentStart->format('Y-m');
        foreach ($dueItems as $item) {
            if ($item->next_due_date->format('Y-m') === $currentKey) {
                $due[$currentKey][$item->patient->id] = true;
            }
        }

        // (b) Samples collected and (c) results tested: grouped in PHP over the same range.
        $collected = array_fill_keys(array_keys($months), 0);
        $samples = SampleCollection::query()->toBase()
            ->where('collection_date', '>=', $first)->where('collection_date', '<', $upper);
        $this->filterByPatient($samples, $location);
        foreach ($samples->pluck('collection_date') as $date) {
            $collected[substr((string) $date, 0, 7)]++;
        }

        $tested = array_fill_keys(array_keys($months), 0);
        $results = ViralLoadResult::query()->toBase()
            ->where('result_date', '>=', $first)->where('result_date', '<', $upper);
        $this->filterByPatient($results, $location);
        foreach ($results->pluck('result_date') as $date) {
            $tested[substr((string) $date, 0, 7)]++;
        }

        $series = [
            ['key' => 'vl_due', 'label' => 'VL due', 'color' => self::COLORS['vl_due'], 'values' => array_values(array_map('count', $due))],
            ['key' => 'samples_collected', 'label' => 'Samples collected', 'color' => self::COLORS['samples'], 'values' => array_values($collected)],
            ['key' => 'results_tested', 'label' => 'Results tested', 'color' => self::COLORS['tested'], 'values' => array_values($tested)],
        ];

        return [
            'title' => 'Monthly VL Due vs Samples Collected and Tested',
            'subtitle' => 'Last 6 months',
            'months' => array_values($months),
            'labels' => array_column(array_values($months), 'axis_label'),
            'series' => $series,
            'has_data' => collect($series)->contains(fn ($s) => array_sum($s['values']) > 0),
            'caption' => 'VL due: patients whose due date falls in the month. Samples collected: by collection date. '
                .'Results tested: by result date. Fixed 6-month window that ignores the date-range filter; '
                .'the current month is month to date.',
            'empty' => 'No VL due dates, samples or results in the last 6 months for this filter.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Viral load suppression donut
    // ---------------------------------------------------------------------------------------------

    /**
     * @param  array|null  $summary  a SuppressionService::summary() result, so the dashboard computes it once
     */
    public function suppression(array $location, ?string $from = null, ?string $to = null, ?array $summary = null): array
    {
        $s = $summary ?? $this->suppressionService->summary($location, $from, $to);

        $tested = (int) $s['patients_tested'];
        $suppressed = (int) $s['patients_suppressed'];
        $unsuppressed = (int) $s['patients_unsuppressed'];
        $rate = $s['suppression_rate'];

        return [
            'title' => 'Viral Load Suppression',
            'rate' => $rate,
            'tested' => $tested,
            'coverage_percent' => $s['vl_coverage_percent'],
            'active_patients' => (int) $s['active_patients'],
            'has_data' => $tested > 0,
            'slices' => [
                ['key' => 'suppressed', 'label' => 'Suppressed (<1,000)', 'count' => $suppressed,
                    'percent' => $tested > 0 ? $rate : 0, 'color' => self::COLORS['suppressed']],
                ['key' => 'unsuppressed', 'label' => 'Unsuppressed (≥1,000)', 'count' => $unsuppressed,
                    'percent' => $tested > 0 ? round(100 - $rate, 1) : 0, 'color' => self::COLORS['unsuppressed']],
            ],
            'caption' => 'Latest result per patient. VL coverage '.$s['vl_coverage_percent'].'% ('
                .number_format($tested).' of '.number_format((int) $s['active_patients']).' active patients).',
            'empty' => 'No viral load results yet for this filter.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 3. Today's workload
    // ---------------------------------------------------------------------------------------------

    /** @param  array|null  $eac  eacProgress() result, so the Active EAC bar and the EAC card share one query */
    public function todaysWorkload(array $location, ?array $eac = null): array
    {
        $eac ??= $this->eacProgress($location);

        $appointments = Appointment::query()
            ->whereDate('appointment_date', today())
            ->where('status', 'scheduled');
        $this->filterByPatient($appointments, $location);

        $samples = SampleCollection::query()->whereIn('status', ['collected', 'in_transit']);
        $this->filterByPatient($samples, $location);

        $bars = [
            ['key' => 'appointments', 'label' => 'Appointments scheduled today', 'unit' => 'appointments',
                'count' => $appointments->count(), 'color' => self::COLORS['appointments']],
            ['key' => 'samples_pending', 'label' => 'Samples pending', 'unit' => 'samples',
                'count' => $samples->count(), 'color' => self::COLORS['samples']],
            ['key' => 'active_eac', 'label' => 'Active EAC episodes', 'unit' => 'episodes',
                'count' => $eac['counts']['active'], 'color' => self::COLORS['eac_active']],
        ];

        return [
            'title' => "Today's Workload",
            'subtitle' => today()->format('d M Y'),
            'bars' => $bars,
            'has_data' => collect($bars)->sum('count') > 0,
            'empty' => 'Nothing scheduled, pending or active today.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 4. EAC episode progress
    // ---------------------------------------------------------------------------------------------

    public function eacProgress(array $location): array
    {
        $query = EacEpisode::query()->toBase()->select('status', DB::raw('COUNT(*) as total'))->groupBy('status');
        $this->filterByPatient($query, $location);
        $byStatus = $query->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

        $known = ['active', 'sessions_completed', 'resolved_suppressed', 'flagged_for_regimen_switch'];
        $total = array_sum($byStatus);
        $other = $total - array_sum(array_intersect_key($byStatus, array_flip($known)));

        $rows = [
            ['key' => 'active', 'label' => 'Active', 'count' => $byStatus['active'] ?? 0, 'color' => self::COLORS['eac_active']],
            ['key' => 'sessions_completed', 'label' => 'Repeat VL due (sessions completed)', 'count' => $byStatus['sessions_completed'] ?? 0, 'color' => self::COLORS['eac_repeat_due']],
            ['key' => 'resolved_suppressed', 'label' => 'Resolved', 'count' => $byStatus['resolved_suppressed'] ?? 0, 'color' => self::COLORS['eac_resolved']],
            ['key' => 'flagged_for_regimen_switch', 'label' => 'Flagged for clinical review', 'count' => $byStatus['flagged_for_regimen_switch'] ?? 0, 'color' => self::COLORS['eac_flagged']],
        ];
        // Statuses outside the four above (e.g. a repeat VL recorded but not yet closed) get their own row, so the slices always add up.
        if ($other > 0) {
            $rows[] = ['key' => 'other', 'label' => 'Other', 'count' => $other, 'color' => self::COLORS['eac_other']];
        }

        $percents = $this->sharePercents(array_column($rows, 'count'));
        foreach ($rows as $i => &$row) {
            $row['percent'] = $percents[$i];
        }
        unset($row);

        return [
            'title' => 'EAC Episode Progress',
            'subtitle' => 'All episodes',
            'center_label' => 'Total EAC episodes',
            'total' => $total,
            'counts' => array_column($rows, 'count', 'key') + ['active' => 0, 'sessions_completed' => 0],
            'slices' => $rows,
            'has_data' => $total > 0,
            'empty' => 'No EAC episodes for this filter.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // 5. Patients by age & cohort (non-overlapping)
    // ---------------------------------------------------------------------------------------------

    public function patientsByCohort(array $location, ?string $from = null, ?string $to = null): array
    {
        // One query classifies every patient exactly once: pregnant/breastfeeding first, then age.
        $rows = $this->patientQuery($location, $from, $to)->toBase()
            ->selectRaw("CASE WHEN is_pregnant = true OR is_breastfeeding = true THEN 'pmtct' WHEN age < 15 THEN 'children' ELSE 'adults' END AS grp, COUNT(*) AS total")
            ->groupBy('grp')
            ->pluck('total', 'grp');

        $counts = [
            'children' => (int) ($rows['children'] ?? 0),
            'adults' => (int) ($rows['adults'] ?? 0),
            'pmtct' => (int) ($rows['pmtct'] ?? 0),
        ];
        $total = array_sum($counts);
        $percents = $this->sharePercents(array_values($counts));

        $bars = [
            ['key' => 'children', 'label' => 'Children under 15', 'count' => $counts['children'], 'percent' => $percents[0], 'color' => self::COLORS['children']],
            ['key' => 'adults', 'label' => 'Adults 15+ (not pregnant or breastfeeding)', 'count' => $counts['adults'], 'percent' => $percents[1], 'color' => self::COLORS['adults']],
            ['key' => 'pmtct', 'label' => 'Pregnant and breastfeeding women', 'count' => $counts['pmtct'], 'percent' => $percents[2], 'color' => self::COLORS['pmtct']],
        ];

        return [
            'title' => 'Patients by Age & Cohort',
            'subtitle' => number_format($total).' patients',
            'total' => $total,
            'bars' => $bars,
            'has_data' => $total > 0,
            'empty' => 'No patients for this filter.',
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Percentages with one decimal that always add up to exactly 100.0 (largest-remainder method).
     *
     * @param  int[]  $counts
     * @return float[]
     */
    public function sharePercents(array $counts): array
    {
        $total = array_sum($counts);
        if ($total <= 0) {
            return array_fill(0, count($counts), 0.0);
        }

        $tenths = [];
        $remainders = [];
        foreach ($counts as $i => $count) {
            $tenths[$i] = intdiv($count * 1000, $total);
            $remainders[$i] = ($count * 1000) % $total;
        }

        $left = 1000 - array_sum($tenths);
        arsort($remainders); // stable for ties in PHP 8: earlier rows win
        foreach (array_keys($remainders) as $i) {
            if ($left-- <= 0) {
                break;
            }
            $tenths[$i]++;
        }

        ksort($tenths);

        return array_map(fn ($t) => $t / 10, array_values($tenths));
    }

    /** Restrict a query on a table that has patient_id to the filtered location. */
    private function filterByPatient($query, array $location): void
    {
        if ($column = $this->locationColumn($location)) {
            $query->whereIn('patient_id', Patient::query()->toBase()->select('id')->where($column[0], $column[1]));
        }
    }

    /** Restrict a patients query (Eloquent or base) to the filtered location. */
    private function filterPatients($query, array $location): void
    {
        if ($column = $this->locationColumn($location)) {
            $query->where($column[0], $column[1]);
        }
    }

    /** @return array{0: string, 1: string}|null the most specific of facility, county, state */
    private function locationColumn(array $location): ?array
    {
        foreach (['facility_id', 'county_id', 'state_id'] as $key) {
            $value = $location[$key] ?? null;
            if ($value && $value !== 'all') {
                return [$key, (string) $value];
            }
        }

        return null;
    }
}
