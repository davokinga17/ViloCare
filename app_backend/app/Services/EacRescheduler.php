<?php

namespace App\Services;

use App\Models\EacEpisode;
use App\Models\EacSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out which STORED EAC dates would change under the current schedule (EacSchedule), and applies them.
 *
 * Only two kinds of row can change, nothing else is ever touched:
 *   - a PENDING session of an open episode (never completed or cancelled ones);
 *   - repeat_vl_due_date of an episode still in 'sessions_completed' whose repeat VL has no result yet.
 *
 * Past dates are never touched: a pending session or repeat VL whose current date is today or earlier is left
 * as it is (an overdue session stays overdue and never becomes due-today), and a change is skipped when its
 * new date would fall before today. Only dates strictly in the future are re-dated.
 *
 * Chain rule: a pending session counts from the previous session's ACTUAL date when that one is completed,
 * or from the previous session's NEW date when it is still pending. Two cases are left alone on purpose:
 *   - a session whose date the counsellor picked (the completed session before it has next_session_date set);
 *   - the pending session 1 is never moved into the past: its new date is max(day the episode was opened
 *     + first_session_delay_days, today).
 *
 * plan() only reads. apply() uses normal save() so rows turn pending, sync, and the Auditable trait writes one
 * audit entry per changed row.
 */
class EacRescheduler
{
    /** @var array{past: int, would_be_past: int} Counted by the last plan(); nothing is written for these. */
    private array $skipped = ['past' => 0, 'would_be_past' => 0];

    public function __construct(private readonly EacSchedule $schedule)
    {
    }

    /** @return array{past: int, would_be_past: int} */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * True when a stored date may be re-dated to $new: the current date is strictly in the future and the new
     * date is not before today. Otherwise counts why it is left alone.
     */
    private function movable(?string $old, string $new): bool
    {
        if ($old === null || $old <= today()->toDateString()) {
            $this->skipped['past']++;

            return false;
        }
        if ($new < today()->toDateString()) {
            $this->skipped['would_be_past']++;

            return false;
        }

        return true;
    }

    /**
     * @return Collection<int, array{kind: string, art_number: string, label: string, model: EacSession|EacEpisode, old: ?string, new: string}>
     */
    public function plan(): Collection
    {
        $changes = collect();
        $this->skipped = ['past' => 0, 'would_be_past' => 0];

        $episodes = EacEpisode::query()
            ->with(['patient:id,art_number', 'sessions'])
            ->whereIn('status', EacEpisode::OPEN_STATUSES)
            ->orderBy('created_at')
            ->get();

        foreach ($episodes as $episode) {
            $art = (string) ($episode->patient?->art_number ?? '?');
            $sessions = $episode->sessions->sortBy('session_number')->values();

            // Date the next pending session counts from; null = chain broken (leave later ones alone).
            $previousDate = null;
            $previousCounsellorPicked = false;

            foreach ($sessions as $session) {
                if ($session->completion_status === 'completed') {
                    $previousDate = Carbon::parse($session->session_date)->startOfDay();
                    $previousCounsellorPicked = $session->next_session_date !== null;

                    continue;
                }
                if ($session->completion_status !== 'pending') {
                    $previousDate = null; // cancelled: nothing to count from

                    continue;
                }

                if ($session->session_number === 1) {
                    $new = $this->schedule->firstSessionDate(Carbon::parse($episode->created_at))->max(today());
                } elseif ($previousDate === null) {
                    continue;
                } elseif ($previousCounsellorPicked) {
                    // The counsellor chose this date; keep it and continue the chain from it.
                    $previousDate = Carbon::parse($session->session_date)->startOfDay();
                    $previousCounsellorPicked = false;

                    continue;
                } else {
                    $new = $this->schedule->nextSessionDate($previousDate);
                }

                $old = $session->session_date?->toDateString();
                $moves = $old !== $new->toDateString() && $this->movable($old, $new->toDateString());

                // A session that stays put keeps its own date as the base for the next one.
                $previousDate = $moves || $old === null ? $new : Carbon::parse($old)->startOfDay();
                $previousCounsellorPicked = false;

                if ($moves) {
                    $changes->push([
                        'kind' => 'session',
                        'art_number' => $art,
                        'label' => "session {$session->session_number}",
                        'model' => $session,
                        'old' => $old,
                        'new' => $new->toDateString(),
                    ]);
                }
            }

            if ($episode->status === 'sessions_completed' && $episode->repeat_viral_load_result_id === null) {
                $three = $sessions->first(fn (EacSession $s) => $s->session_number === 3 && $s->completion_status === 'completed');
                if ($three && $three->session_date) {
                    $new = $this->schedule->repeatVlDate($three->session_date)->toDateString();
                    $old = $episode->repeat_vl_due_date?->toDateString();
                    if ($old !== $new && $this->movable($old, $new)) {
                        $changes->push([
                            'kind' => 'repeat_vl',
                            'art_number' => $art,
                            'label' => 'repeat VL due',
                            'model' => $episode,
                            'old' => $old,
                            'new' => $new,
                        ]);
                    }
                }
            }
        }

        return $changes;
    }

    /** Applies a plan() result. Returns the number of rows changed. */
    public function apply(Collection $changes): int
    {
        return DB::transaction(function () use ($changes) {
            foreach ($changes as $change) {
                $model = $change['model'];
                if ($change['kind'] === 'session') {
                    $model->session_date = $change['new'];
                } else {
                    $model->repeat_vl_due_date = $change['new'];
                }
                $model->save(); // normal save: pending + new last_modified_at + audit entry
            }

            return $changes->count();
        });
    }
}
