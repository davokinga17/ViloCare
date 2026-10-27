<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\ViralLoadResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ViralLoadEngine
{
    public const SUPPRESSION_THRESHOLD = 1000; // < 1,000 copies/ml is suppressed

    /**
     * Determine if a VL value is suppressed.
     */
    public function isSuppressed(int $resultValue): bool
    {
        return $resultValue < self::SUPPRESSION_THRESHOLD;
    }

    /**
     * Calculate the next due date and clinical rationale based on national algorithm.
     *
     * @return array{next_due_date: Carbon|null, reason: string, requires_eac: bool, requires_clinical_review: bool}
     */
    public function calculateNextDueDate(
        Patient $patient,
        int $resultValue,
        Carbon|string $sampleDate,
        bool $isPostEacRepeat = false,
        ?string $currentResultId = null
    ): array {
        $sampleDate = Carbon::parse($sampleDate);
        $isSuppressed = $this->isSuppressed($resultValue);

        // --------------------------------------------------------------------
        // Branch 1: High Viral Load (>= 1,000 copies/ml)
        // --------------------------------------------------------------------
        if (! $isSuppressed) {
            if ($isPostEacRepeat) {
                return [
                    'next_due_date' => null,
                    'reason' => 'Post-EAC Repeat: Persistent High VL (≥1,000 c/ml). Suspected treatment failure: clinical review.',
                    'requires_eac' => false,
                    'requires_clinical_review' => true,
                ];
            }

            return [
                'next_due_date' => null, // Managed via EAC session schedule and post-EAC repeat test
                'reason' => 'High Viral Load (≥1,000 c/ml). EAC (Enhanced Adherence Counselling) 3-session package triggered.',
                'requires_eac' => true,
                'requires_clinical_review' => false,
            ];
        }

        // --------------------------------------------------------------------
        // Branch 2: Suppressed Result (< 1,000 copies/ml) Post-EAC Repeat
        // --------------------------------------------------------------------
        if ($isPostEacRepeat) {
            return [
                'next_due_date' => $sampleDate->copy()->addMonths(6),
                'reason' => 'Post-EAC Repeat: Re-suppressed (<1,000 c/ml). Returned to routine 6-month monitoring.',
                'requires_eac' => false,
                'requires_clinical_review' => false,
            ];
        }

        // --------------------------------------------------------------------
        // Branch 3: Pediatric Patients (< 15 years old)
        // Rule: Every 6 months regardless of suppression history until turning 15
        // --------------------------------------------------------------------
        if ($patient->age < 15) {
            return [
                'next_due_date' => $sampleDate->copy()->addMonths(6),
                'reason' => "Pediatric Cohort (Age {$patient->age} < 15): Standard 6-month repeat interval.",
                'requires_eac' => false,
                'requires_clinical_review' => false,
            ];
        }

        // --------------------------------------------------------------------
        // Branch 4: Pregnant or Breastfeeding Patients (PMTCT Cohort)
        // Rule: Every 6 months until breastfeeding ceases
        // --------------------------------------------------------------------
        if ($patient->is_pregnant || $patient->is_breastfeeding) {
            $statusLabel = $patient->is_pregnant ? 'Pregnant' : 'Breastfeeding';

            return [
                'next_due_date' => $sampleDate->copy()->addMonths(6),
                'reason' => "PMTCT Cohort ({$statusLabel}): 6-month repeat interval until breastfeeding cessation.",
                'requires_eac' => false,
                'requires_clinical_review' => false,
            ];
        }

        // --------------------------------------------------------------------
        // Branch 5: General Adult Population (Age 15+, non-pregnant, non-breastfeeding)
        // Rule: 6 months; after 2 consecutive suppressed results, switch to 12 months
        // --------------------------------------------------------------------
        $consecutiveSuppressedCount = $this->countConsecutiveSuppressedResults($patient, $isSuppressed, $currentResultId);

        if ($consecutiveSuppressedCount >= 2) {
            return [
                'next_due_date' => $sampleDate->copy()->addMonths(12),
                'reason' => 'General Adult Cohort: 2+ consecutive suppressed results (<1,000 c/ml). Switched to 12-month annual repeat interval.',
                'requires_eac' => false,
                'requires_clinical_review' => false,
            ];
        }

        return [
            'next_due_date' => $sampleDate->copy()->addMonths(6),
            'reason' => 'General Adult Cohort: Initial suppressed result (<1,000 c/ml). 6-month repeat interval.',
            'requires_eac' => false,
            'requires_clinical_review' => false,
        ];
    }

    /**
     * Calculate initial due date for a patient who has no viral load test yet.
     * Returns null when there is no ART start date: nothing can be scheduled from it.
     */
    public function calculateInitialDueDate(Patient $patient, bool $alreadyOnArtWhenPregnant = false): ?array
    {
        if (! $patient->art_start_date) {
            return null;
        }

        $artStartDate = Carbon::parse($patient->art_start_date);

        // 1. If patient was already established on ART and becomes pregnant: immediate VL test indicated
        if ($patient->is_pregnant && $alreadyOnArtWhenPregnant) {
            return [
                'due_date' => now()->toDateString(),
                'reason' => 'Patient already on ART who becomes pregnant: Immediate VL test indicated.',
            ];
        }

        // 2. Newly identified during ANC / newly initiated pregnant woman: first VL at 3 months post ART initiation
        if ($patient->is_pregnant) {
            return [
                'due_date' => $artStartDate->copy()->addMonths(3)->toDateString(),
                'reason' => 'Newly initiated during ANC (Pregnant): First VL test due at 3 months post-initiation.',
            ];
        }

        // 3. Routine baseline for all other cohorts: 6 months post ART initiation
        return [
            'due_date' => $artStartDate->copy()->addMonths(6)->toDateString(),
            'reason' => 'Baseline VL monitoring: First test due at 6 months post-ART initiation.',
        ];
    }

    /**
     * Calculate due date when an established ART patient becomes pregnant.
     * Rule: Immediate VL test regardless of last test date, then every 6 months until breastfeeding stops.
     */
    public function calculateDueDateForPregnancyOnset(Patient $patient): array
    {
        return [
            'due_date' => now()->toDateString(),
            'reason' => 'Patient already on ART who becomes pregnant: Immediate VL test indicated.',
        ];
    }

    /**
     * Count consecutive suppressed results including current test.
     */
    protected function countConsecutiveSuppressedResults(
        Patient $patient,
        bool $currentResultIsSuppressed,
        ?string $currentResultId = null
    ): int {
        if (! $currentResultIsSuppressed) {
            return 0;
        }

        $count = 1; // Current test is suppressed

        $previousResults = ViralLoadResult::where('patient_id', $patient->id)
            ->when($currentResultId, fn ($q) => $q->where('id', '!=', $currentResultId))
            ->orderBy('sample_date', 'desc')
            ->get();

        foreach ($previousResults as $res) {
            if ($res->is_suppressed) {
                $count++;
            } else {
                break; // Sequence of suppression broken by a high VL
            }
        }

        return $count;
    }

    public const OUTCOME_NONE = 'none';                   // no open episode, nothing EAC-related happened
    public const OUTCOME_EPISODE_OPENED = 'episode_opened'; // high VL, no open episode: new EAC episode
    public const OUTCOME_CONTINUING = 'continuing';         // high VL before session 3 is done: counselling goes on
    public const OUTCOME_CLOSED_EARLY = 'closed_early';     // suppressed before session 3 was completed
    public const OUTCOME_RESOLVED = 'resolved';             // suppressed after session 3 (normal post-EAC repeat)
    public const OUTCOME_FLAGGED = 'flagged';               // high after session 3: suspected treatment failure

    /**
     * Process a recorded VL result: computes algorithm fields and applies the EAC rules.
     *
     * The server decides how the result relates to an open EAC episode (status active or
     * sessions_completed). Whatever the form posted for is_post_eac_repeat / eac_episode_id is
     * overwritten here.
     *
     *  - Suppressed, open episode: episode resolved, result linked, pending sessions cancelled;
     *    routine schedule resumes. "Closed early" when fewer than 3 sessions were completed.
     *  - High, open episode, session 3 not completed: ordinary result; episode and sessions untouched.
     *  - High, session 3 completed: flagged for clinical review (suspected treatment failure).
     *  - High, no open episode: new EAC episode.
     *
     * @return array{outcome: string, episode: ?EacEpisode, session_number: ?int}
     */
    public function processResult(ViralLoadResult $result): array
    {
        $patient = $result->patient;
        $isSuppressed = $this->isSuppressed($result->result_value);

        $openEpisodes = EacEpisode::where('patient_id', $patient->id)
            ->whereIn('status', EacEpisode::OPEN_STATUSES)
            ->where('viral_load_result_id', '!=', $result->id)
            ->orderByDesc('created_at')
            ->get();
        $episode = $openEpisodes->first();
        $afterSessionThree = $episode?->sessionThreeCompleted() ?? false;
        $sessionNumber = $episode?->currentSessionNumber();

        $outcome = self::OUTCOME_NONE;
        if ($episode) {
            $outcome = match (true) {
                $isSuppressed && $afterSessionThree => self::OUTCOME_RESOLVED,
                $isSuppressed => self::OUTCOME_CLOSED_EARLY,
                $afterSessionThree => self::OUTCOME_FLAGGED,
                default => self::OUTCOME_CONTINUING,
            };
        }

        // Only a result that follows completed sessions is a post-EAC repeat.
        $isPostEacRepeat = in_array($outcome, [self::OUTCOME_RESOLVED, self::OUTCOME_FLAGGED], true);

        $calculation = $this->calculateNextDueDate(
            patient: $patient,
            resultValue: $result->result_value,
            sampleDate: $result->sample_date,
            isPostEacRepeat: $isPostEacRepeat,
            currentResultId: (string) $result->id
        );

        $reason = $calculation['reason'];
        if ($outcome === self::OUTCOME_CLOSED_EARLY) {
            $reason = "Suppressed (<1,000 c/ml) during EAC (session {$sessionNumber} of 3): EAC closed early, pending sessions cancelled. ".$reason;
        } elseif ($outcome === self::OUTCOME_CONTINUING) {
            $reason = "High Viral Load (≥1,000 c/ml) during EAC (session {$sessionNumber} of 3): result recorded, counselling continues.";
        }

        DB::transaction(function () use (&$outcome, &$episode, $result, $patient, $calculation, $openEpisodes, $isSuppressed, $isPostEacRepeat, $reason) {
            $result->is_suppressed = $isSuppressed;
            $result->is_post_eac_repeat = $isPostEacRepeat;
            $result->eac_episode_id = $isPostEacRepeat || $outcome === self::OUTCOME_CLOSED_EARLY ? $episode->id : null;
            $result->next_due_date = $calculation['next_due_date']?->toDateString();
            $result->algorithm_reason = $reason;
            $result->flagged_for_clinical_review = $calculation['requires_clinical_review'];
            $result->save();

            // High VL with no open episode triggers a new one. With an open episode it never does.
            if ($outcome === self::OUTCOME_NONE && $calculation['requires_eac']) {
                $episode = $this->initiateEacEpisode($patient, $result);
                $outcome = self::OUTCOME_EPISODE_OPENED;
            } elseif (in_array($outcome, [self::OUTCOME_RESOLVED, self::OUTCOME_CLOSED_EARLY], true)) {
                foreach ($openEpisodes as $open) {
                    $this->closeEpisode($open, $result, $outcome === self::OUTCOME_CLOSED_EARLY);
                }
            } elseif ($outcome === self::OUTCOME_FLAGGED) {
                $episode->repeat_viral_load_result_id = $result->id;
                $episode->status = 'flagged_for_regimen_switch'; // key kept; label is "Suspected treatment failure: clinical review"
                $episode->save();
            }
        });

        return [
            'outcome' => $outcome,
            'episode' => $episode,
            'session_number' => $sessionNumber,
        ];
    }

    /**
     * Resolve an episode on a suppressed result and cancel what is still pending. Every change is a
     * normal save() so the rows turn pending, get a new last_modified_at and are audited, and
     * therefore sync in both directions.
     */
    private function closeEpisode(EacEpisode $episode, ViralLoadResult $result, bool $early): void
    {
        $completed = $episode->completedSessionsCount();
        $cancelled = [];

        foreach ($episode->sessions()->where('completion_status', 'pending')->get() as $session) {
            $session->completion_status = 'cancelled';
            $session->notes = trim(($session->notes ? $session->notes.' ' : '')
                .'[Cancelled: suppressed VL on '.Carbon::parse($result->sample_date)->toDateString().' closed the EAC episode.]');
            $session->save();
            $cancelled[] = $session->session_number;
        }

        $episode->repeat_viral_load_result_id = $result->id;
        $episode->status = 'resolved_suppressed';
        $episode->save();

        if ($early) {
            AuditLog::log(
                action: 'eac_closed_early',
                tableName: 'eac_episodes',
                recordId: (string) $episode->id,
                oldValues: ['completed_sessions' => $completed],
                newValues: [
                    'status' => 'resolved_suppressed',
                    'closed_early' => true,
                    'closing_result_id' => (string) $result->id,
                    'cancelled_sessions' => $cancelled,
                ],
            );
        }
    }

    /**
     * Initiate a 3-session EAC episode for a high VL result.
     */
    public function initiateEacEpisode(Patient $patient, ViralLoadResult $result): EacEpisode
    {
        $episode = EacEpisode::create([
            'patient_id' => $patient->id,
            'viral_load_result_id' => $result->id,
            'status' => 'active',
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        // Link episode to result
        $result->eac_episode_id = $episode->id;
        $result->save();

        // Schedule Session 1 (typically 14 days after result)
        $session1Date = Carbon::parse($result->result_date)->addDays(14);
        $episode->sessions()->create([
            'patient_id' => $patient->id,
            'session_number' => 1,
            'session_date' => $session1Date->toDateString(),
            'completion_status' => 'pending',
            'notes' => 'Initial EAC Session: Identification of adherence barriers and root cause analysis.',
            'sync_status' => 'pending',
            'last_modified_at' => now(),
        ]);

        return $episode;
    }
}
