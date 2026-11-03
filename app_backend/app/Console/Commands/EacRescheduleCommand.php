<?php

namespace App\Console\Commands;

use App\Services\EacRescheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves ALREADY STORED EAC dates to the current schedule. Default is a dry run (same output as
 * vilocare:eac-reschedule-preview). With --apply it changes ONLY pending sessions of open episodes and the
 * repeat_vl_due_date of episodes still waiting for their repeat VL, through normal save() so the rows sync
 * and each change is audited. Completed and cancelled sessions are never touched.
 */
class EacRescheduleCommand extends Command
{
    protected $signature = 'vilocare:eac-reschedule
        {--apply : Actually change the dates (asks for confirmation, writes a row backup first). Without it nothing is written}';

    protected $description = 'Move stored pending EAC session dates and repeat VL due dates to the new schedule (dry run unless --apply)';

    public function handle(EacRescheduler $rescheduler): int
    {
        $changes = $rescheduler->plan();
        EacReschedulePreviewCommand::printPlan($this, $changes, $rescheduler->skipped());

        if ($changes->isEmpty()) {
            $this->info('Nothing to change.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info('DRY RUN: nothing was written. Run with --apply to change these dates.');

            return self::SUCCESS;
        }

        $this->warn('On PostgreSQL (Render) also take a database snapshot/export from the Render dashboard before continuing.');
        if (! $this->confirm("Change {$changes->count()} stored date(s)?", false)) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $backup = $this->backupRows($changes);
        $this->info("Row backup written: {$backup}");

        $count = $rescheduler->apply($changes);
        $this->info("Done. {$count} date(s) changed; they reach the other side on the next normal sync.");

        return self::SUCCESS;
    }

    /** Writes the untouched rows as JSON and checks it can be read back, before anything is changed. */
    private function backupRows(Collection $changes): string
    {
        $sessionIds = $changes->where('kind', 'session')->map(fn ($c) => $c['model']->id)->all();
        $episodeIds = $changes->where('kind', 'repeat_vl')->map(fn ($c) => $c['model']->id)->all();

        $payload = [
            'eac_sessions' => DB::table('eac_sessions')->whereIn('id', $sessionIds)->get()->all(),
            'eac_episodes' => DB::table('eac_episodes')->whereIn('id', $episodeIds)->get()->all(),
        ];

        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        $path = $dir.DIRECTORY_SEPARATOR.'eac-reschedule-'.now()->format('Ymd-His').'.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));

        $written = json_decode((string) file_get_contents($path), true);
        if (! is_array($written) || count($written['eac_sessions'] ?? []) !== count($sessionIds) || count($written['eac_episodes'] ?? []) !== count($episodeIds)) {
            @unlink($path);
            throw new \RuntimeException('The backup file could not be verified. Nothing was changed.');
        }

        return $path;
    }
}
