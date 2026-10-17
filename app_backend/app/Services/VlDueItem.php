<?php

namespace App\Services;

use App\Models\EacEpisode;
use App\Models\Patient;
use App\Models\ViralLoadResult;
use Carbon\Carbon;

/**
 * One patient who is due for a viral load test, in the single group that applies to them.
 */
class VlDueItem
{
    public const GROUP_PMTCT = 'pmtct';

    public const GROUP_POST_EAC = 'post_eac';

    public const GROUP_ROUTINE = 'routine';

    /** Lower number wins when a patient qualifies under several groups. */
    public const PRIORITY = [
        self::GROUP_PMTCT => 1,
        self::GROUP_POST_EAC => 2,
        self::GROUP_ROUTINE => 3,
    ];

    public function __construct(
        public Patient $patient,
        public string $group,
        public Carbon $next_due_date,
        public string $algorithm_reason,
        public ?EacEpisode $eacEpisode = null,
        public ?ViralLoadResult $result = null,
    ) {}

    /** Whole days past the due date (negative while still in the future, 0 = today). */
    public function daysOverdue(): int
    {
        return (int) round($this->next_due_date->copy()->startOfDay()->diffInDays(today(), false));
    }

    public function isOverdue(): bool
    {
        return $this->daysOverdue() > 0;
    }

    public function dueLabel(): string
    {
        $days = $this->daysOverdue();

        return match (true) {
            $days > 0 => 'Overdue by '.$days.' '.($days === 1 ? 'day' : 'days'),
            $days === 0 => 'Due today',
            default => 'Due in '.abs($days).' '.(abs($days) === 1 ? 'day' : 'days'),
        };
    }

    public function groupLabel(): string
    {
        return match ($this->group) {
            self::GROUP_PMTCT => 'Immediate (PMTCT)',
            self::GROUP_POST_EAC => 'Post-EAC repeat',
            default => 'Routine',
        };
    }
}
