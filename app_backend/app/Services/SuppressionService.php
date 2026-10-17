<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\ViralLoadResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of "viral load suppression rate".
 *
 * Every suppression number (dashboard, reports, VL page, analytics, AI insights) comes from here.
 *
 * Rules
 *  - Counts PATIENTS, never test rows. Each active patient contributes only their LATEST result
 *    (same rule as VlDueService: ViralLoadResult::latestPerPatient - sample_date, created_at, id).
 *  - Only active patients (status = 'active') are counted.
 *  - Suppressed = latest result is_suppressed (< 1,000 copies/ml).
 *  - Rate = suppressed patients / patients with at least one result.
 *  - Coverage = patients with at least one result / all active patients.
 *  - Optional result_date window: only results inside it compete to be the patient's latest.
 *  - Monthly trend: latest result as of each month end.
 *
 * Queries use only portable constructs (NOT EXISTS, IN, plain comparisons) for SQLite and PostgreSQL.
 */
class SuppressionService
{
    public const COHORTS = ['pediatric', 'adult', 'pmtct'];

    /**
     * @param  array{state_id?: ?string, county_id?: ?string, facility_id?: ?string}  $location
     * @return array<string, int|float>
     */
    public function summary(array $location = [], ?string $from = null, ?string $to = null, ?string $cohort = null): array
    {
        $activePatients = $this->patients($location, $cohort)->count();

        $latest = ViralLoadResult::query()
            ->latestPerPatient($from, $to)
            ->whereIn('patient_id', $this->patients($location, $cohort)->select('id'));

        $tested = (clone $latest)->count();
        $suppressed = (clone $latest)->where('is_suppressed', true)->count();
        $unsuppressed = $tested - $suppressed;

        return [
            'total_vl' => $tested,
            'suppressed_count' => $suppressed,
            'unsuppressed_count' => $unsuppressed,
            'suppression_rate' => $this->percent($suppressed, $tested),
            'patients_tested' => $tested,
            'patients_suppressed' => $suppressed,
            'patients_unsuppressed' => $unsuppressed,
            'active_patients' => $activePatients,
            'vl_coverage_percent' => $this->percent($tested, $activePatients),
        ];
    }

    /**
     * Pediatric (<15), adult (15+) and PMTCT (pregnant or breastfeeding) - same per-patient rule.
     * The shape matches AnalyticsService::getCohortSuppressionBreakdown().
     */
    public function cohorts(array $location = [], ?string $from = null, ?string $to = null): array
    {
        $parts = [];
        foreach (self::COHORTS as $cohort) {
            $parts[$cohort] = $this->summary($location, $from, $to, $cohort);
        }

        $all = $this->summary($location, $from, $to);

        return [
            'categories' => ['Pediatric (<15 yrs)', 'Adult (15+ yrs)', 'PMTCT (Maternal)'],
            'suppressed' => array_values(array_map(fn ($p) => $p['patients_suppressed'], $parts)),
            'unsuppressed' => array_values(array_map(fn ($p) => $p['patients_unsuppressed'], $parts)),
            'totals' => array_values(array_map(fn ($p) => $p['patients_tested'], $parts)),
            'rates' => array_values(array_map(fn ($p) => $p['suppression_rate'], $parts)),
            'coverage' => array_values(array_map(fn ($p) => $p['vl_coverage_percent'], $parts)),
            'cohorts' => $parts,
            'overall' => [
                'suppressed' => $all['patients_suppressed'],
                'unsuppressed' => $all['patients_unsuppressed'],
                'total' => $all['patients_tested'],
                'rate' => $all['suppression_rate'],
                'coverage' => $all['vl_coverage_percent'],
            ],
        ];
    }

    /**
     * For each of the last $months months, every patient counted once using their latest result
     * as of the end of that month.
     */
    public function monthlyTrend(array $location = [], int $months = 6): array
    {
        $anchor = Carbon::now();
        $latest = ViralLoadResult::orderByDesc('result_date')->first();
        if ($latest && $latest->result_date && $latest->result_date->greaterThan($anchor)) {
            $anchor = $latest->result_date->copy();
        }

        $out = ['labels' => [], 'rates' => [], 'totals' => [], 'suppressed' => [], 'unsuppressed' => [], 'target' => 95];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $anchor->copy()->subMonths($i);
            $s = $this->summary($location, null, $month->copy()->endOfMonth()->toDateString());

            $out['labels'][] = $month->format('M Y');
            $out['rates'][] = $s['suppression_rate'];
            $out['totals'][] = $s['patients_tested'];
            $out['suppressed'][] = $s['patients_suppressed'];
            $out['unsuppressed'][] = $s['patients_unsuppressed'];
        }

        return $out;
    }

    /** Active patients in the location, optionally within one cohort. */
    private function patients(array $location, ?string $cohort = null): Builder
    {
        $query = Patient::query()->where('status', 'active');

        foreach (['facility_id', 'county_id', 'state_id'] as $key) {
            $value = $location[$key] ?? null;
            if ($value && $value !== 'all') {
                $query->where($key, $value);
                break;
            }
        }

        match ($cohort) {
            'pediatric' => $query->where('age', '<', 15),
            'adult' => $query->where('age', '>=', 15),
            'pregnant' => $query->where('is_pregnant', true),
            'pmtct' => $query->where(fn ($q) => $q->where('is_pregnant', true)->orWhere('is_breastfeeding', true)),
            default => null,
        };

        return $query;
    }

    private function percent(int $part, int $whole): float|int
    {
        return $whole > 0 ? round(($part / $whole) * 100, 1) : 0;
    }
}
