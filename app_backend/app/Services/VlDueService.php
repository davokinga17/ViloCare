<?php

namespace App\Services;

use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\ViralLoadResult;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The one definition of "patient currently due for a viral load test".
 *
 * Every number and list that shows VL-due patients (dashboard card and list, reports summary,
 * due-list report and CSV, AI insights) comes from here, so they always agree.
 *
 * Rules
 *  - One row per PATIENT, never per result.
 *  - A patient qualifies under up to four groups; they are listed once, in the highest priority
 *    group: immediate PMTCT, then post-EAC repeat VL, then routine, then baseline.
 *  - Baseline: patient with no VL result at all and an ART start date; due date from
 *    ViralLoadEngine::calculateInitialDueDate() (ART start + 3 months if newly initiated pregnant,
 *    else + 6 months). A past date stays the original date (overdue).
 *  - Routine: only the patient's LATEST result counts (latest sample_date, then created_at); its
 *    next_due_date (set by ViralLoadEngine) must be <= today + look-ahead. Older results are ignored,
 *    and a missed due date stays the original date (the patient simply shows as overdue).
 *  - Post-EAC: completed-sessions episode whose repeat_vl_due_date is within the window (earliest wins).
 *  - Immediate PMTCT: pregnant, onset recorded, no VL since onset. Due today.
 *  - Samples collected but not yet resulted do not change due status.
 */
class VlDueService
{
    public const DEFAULT_DAYS_AHEAD = 30;

    /**
     * @param  array{state_id?: ?string, county_id?: ?string, facility_id?: ?string}  $location  'all'/empty = no filter;
     *                                                                                           the most specific of facility, county, state is applied.
     * @return Collection<int, VlDueItem> one item per patient, ordered by due date then group priority
     */
    public function due(array $location = [], int $daysAhead = self::DEFAULT_DAYS_AHEAD): Collection
    {
        // Exclusive upper bound = the day AFTER the last included day. The 'date' cast stores
        // "Y-m-d 00:00:00", so "<= 'Y-m-d'" would silently drop patients due on the boundary day
        // (today, when $daysAhead is 0). "< next day" works for both stored formats and PostgreSQL.
        $before = now()->addDays($daysAhead + 1)->toDateString();
        $items = [];

        foreach ($this->immediatePmtctPatients($location) as $patient) {
            $items[$patient->id] = new VlDueItem(
                $patient,
                VlDueItem::GROUP_PMTCT,
                today(),
                $patient->currentVlDueReason(),
                null,
                $patient->latestViralLoadResult,
            );
        }

        $episodes = EacEpisode::with(['patient.facility', 'patient.activeRegimen', 'viralLoadResult'])
            ->where('status', 'sessions_completed')
            ->whereNotNull('repeat_vl_due_date')
            ->where('repeat_vl_due_date', '<', $before)
            ->orderBy('repeat_vl_due_date')
            ->orderBy('created_at');
        $this->filterByPatientLocation($episodes, $location);

        foreach ($episodes->get() as $episode) {
            // Ordered earliest first, so the first episode seen carries the original due date.
            $items[$episode->patient_id] ??= new VlDueItem(
                $episode->patient,
                VlDueItem::GROUP_POST_EAC,
                $episode->repeat_vl_due_date,
                'Completed 3 EAC adherence counseling sessions following high VL episode.',
                $episode,
            );
        }

        $results = ViralLoadResult::query()
            ->latestPerPatient()
            ->with(['patient.facility', 'patient.activeRegimen'])
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $before)
            ->orderBy('next_due_date');
        $this->filterByPatientLocation($results, $location);

        foreach ($results->get() as $result) {
            $items[$result->patient_id] ??= new VlDueItem(
                $result->patient,
                VlDueItem::GROUP_ROUTINE,
                $result->next_due_date,
                (string) $result->algorithm_reason,
                null,
                $result,
            );
        }

        // Baseline: no viral load result at all. Computed on the fly from the ART start date
        // (never stored), so it follows edits to the date, sync and imports.
        $engine = app(ViralLoadEngine::class);
        $baseline = Patient::with(['facility', 'activeRegimen'])
            ->whereDoesntHave('viralLoadResults')
            ->whereNotNull('art_start_date');
        $this->filterPatientLocation($baseline, $location);

        foreach ($baseline->get() as $patient) {
            if (isset($items[$patient->id])) {
                continue;
            }

            $initial = $engine->calculateInitialDueDate($patient);
            if (! $initial) {
                continue;
            }

            $dueDate = Carbon::parse($initial['due_date'])->startOfDay();
            if ($dueDate->toDateString() >= $before) {
                continue;
            }

            $items[$patient->id] = new VlDueItem(
                $patient,
                VlDueItem::GROUP_BASELINE,
                $dueDate,
                $initial['reason'],
            );
        }

        return collect($items)
            ->sortBy([
                fn (VlDueItem $a, VlDueItem $b) => $a->next_due_date <=> $b->next_due_date,
                fn (VlDueItem $a, VlDueItem $b) => VlDueItem::PRIORITY[$a->group] <=> VlDueItem::PRIORITY[$b->group],
            ])
            ->values();
    }

    /**
     * Patients whose due date is exactly today: immediate PMTCT (always today), plus post-EAC repeat
     * and routine whose due date is today. Overdue patients are excluded (they are in due()).
     * Uses due() so each patient's group and date are decided once, in one place.
     *
     * @return Collection<int, VlDueItem>
     */
    public function dueToday(array $location = []): Collection
    {
        return $this->due($location, 0)
            ->filter(fn (VlDueItem $item) => $item->next_due_date->isSameDay(today()))
            ->values();
    }

    public function countToday(array $location = []): int
    {
        return $this->dueToday($location)->count();
    }

    /** Patients who must have a VL now because they became pregnant after their last test. */
    public function immediatePmtctPatients(array $location = []): Collection
    {
        $query = Patient::with(['facility', 'activeRegimen', 'latestViralLoadResult'])
            ->where('is_pregnant', true)
            ->whereNotNull('pregnancy_onset_at');
        $this->filterPatientLocation($query, $location);

        return $query->get()->filter(fn (Patient $p) => $p->isImmediateVlRequired())->values();
    }

    public function count(array $location = [], int $daysAhead = self::DEFAULT_DAYS_AHEAD): int
    {
        return $this->due($location, $daysAhead)->count();
    }

    /** Due patients whose due date has already passed. */
    public function overdueCount(array $location = [], int $daysAhead = self::DEFAULT_DAYS_AHEAD): int
    {
        return $this->due($location, $daysAhead)->filter(fn (VlDueItem $i) => $i->isOverdue())->count();
    }

    private function filterByPatientLocation($query, array $location): void
    {
        if ($column = $this->locationColumn($location)) {
            [$name, $value] = $column;
            $query->whereHas('patient', fn ($q) => $q->where($name, $value));
        }
    }

    private function filterPatientLocation($query, array $location): void
    {
        if ($column = $this->locationColumn($location)) {
            [$name, $value] = $column;
            $query->where($name, $value);
        }
    }

    /** @return array{0: string, 1: string}|null */
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
