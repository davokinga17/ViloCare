<?php

namespace App\Console\Commands;

use App\Services\EacRescheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Read-only. Lists what vilocare:eac-reschedule --apply WOULD change under the current EAC schedule config.
 * Prints counts and ART numbers only (no names, no phone numbers) and writes nothing.
 */
class EacReschedulePreviewCommand extends Command
{
    protected $signature = 'vilocare:eac-reschedule-preview';

    protected $description = 'Read-only: list open EAC episodes whose pending session dates or repeat VL due date would change under the new schedule';

    public function handle(EacRescheduler $rescheduler): int
    {
        self::printPlan($this, $rescheduler->plan(), $rescheduler->skipped());
        $this->info('Read-only: nothing was written.');

        return self::SUCCESS;
    }

    /** @param array{past: int, would_be_past: int} $skipped */
    public static function printPlan(Command $command, Collection $changes, array $skipped): void
    {
        $sessions = $changes->where('kind', 'session');
        $repeat = $changes->where('kind', 'repeat_vl');

        $command->line(sprintf(
            'Schedule: session 1 = day entered + %d, sessions 2/3 = previous actual + %d, repeat VL = session 3 actual + %d (days).',
            (int) config('vilocare.eac.first_session_delay_days'),
            (int) config('vilocare.eac.session_gap_days'),
            (int) config('vilocare.eac.repeat_vl_days'),
        ));
        $command->line(sprintf(
            'Would change: %d pending session(s) in %d episode(s), %d repeat VL due date(s).',
            $sessions->count(),
            $sessions->pluck('model.eac_episode_id')->unique()->count(),
            $repeat->count(),
        ));

        $command->line(sprintf(
            'Left alone (never changed): %d date(s) already today or in the past, %d whose new date would fall in the past.',
            $skipped['past'],
            $skipped['would_be_past'],
        ));

        if ($changes->isEmpty()) {
            return;
        }

        $command->table(
            ['ART #', 'What', 'Current date', 'New date'],
            $changes->map(fn (array $c) => [$c['art_number'], $c['label'], $c['old'] ?? '(none)', $c['new']])->all()
        );
    }
}
