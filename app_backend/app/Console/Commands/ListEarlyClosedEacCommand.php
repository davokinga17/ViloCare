<?php

namespace App\Console\Commands;

use App\Models\EacEpisode;
use Illuminate\Console\Command;

/**
 * Read-only: lists episodes that were closed (resolved or flagged) before all 3 sessions were
 * completed and still have pending sessions. Typically data from before the "close early" rule.
 * Nothing is written; clean-up is left to the user.
 */
class ListEarlyClosedEacCommand extends Command
{
    protected $signature = 'vilocare:list-early-closed-eac';

    protected $description = 'Read-only: list closed EAC episodes that still have pending sessions';

    public function handle(): int
    {
        $rows = EacEpisode::with(['patient', 'sessions'])
            ->whereIn('status', ['resolved_suppressed', 'flagged_for_regimen_switch'])
            ->whereHas('sessions', fn ($q) => $q->where('completion_status', 'pending'))
            ->get()
            ->filter(fn (EacEpisode $e) => $e->sessions->where('completion_status', 'completed')->count() < 3)
            ->map(fn (EacEpisode $e) => [
                $e->id,
                $e->patient?->art_number ?? '?',
                $e->status,
                $e->sessions->where('completion_status', 'completed')->count().' / 3',
                $e->sessions->where('completion_status', 'pending')->pluck('session_number')->sort()->implode(', '),
                (string) $e->last_modified_at,
            ])
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No closed episodes with pending sessions.');

            return self::SUCCESS;
        }

        $this->table(['Episode id', 'ART #', 'Status', 'Completed', 'Pending session #', 'Last modified'], $rows->all());
        $this->info($rows->count().' episode(s). Read-only: nothing was changed.');

        return self::SUCCESS;
    }
}
