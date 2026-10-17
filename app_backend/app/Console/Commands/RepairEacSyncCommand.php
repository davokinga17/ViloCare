<?php

namespace App\Console\Commands;

use App\Models\EacEpisode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for EAC episodes whose status change was saved silently (before ViralLoadEngine
 * used a normal save) and therefore never reached the other side.
 *
 * A resolved/flagged episode with a linked repeat result keeps its OLD last_modified_at while the
 * repeat result is newer, so the other side treats the copy as "unchanged" and keeps the stale
 * status. --apply re-saves exactly those rows WITHOUT changing any value: last_modified_at moves
 * forward, the row becomes pending, and the next normal sync delivers it. Default mode is a dry run.
 */
class RepairEacSyncCommand extends Command
{
    protected $signature = 'vilocare:repair-eac-sync
        {--apply : Re-save the listed episodes (asks for confirmation, writes a row backup first)}';

    protected $description = 'Find EAC episodes whose resolved/flagged status never synced and (with --apply) re-save them so they sync';

    public function handle(): int
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->info('No stale episodes found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(
            ['Episode id', 'ART #', 'Status', 'Expected from repeat result', 'Last modified', 'Repeat result recorded', 'Action'],
            $candidates->map(fn (array $c) => [
                $c['episode']->id,
                $c['episode']->patient?->art_number ?? '?',
                $c['episode']->status,
                $c['expected'],
                (string) ($c['episode']->last_modified_at ?? 'never'),
                (string) $c['result']->created_at,
                $c['consistent'] ? 'will re-save' : 'SKIPPED - status disagrees with its repeat result, review by hand',
            ])->all()
        );

        if (! $this->option('apply')) {
            $this->info('DRY RUN: nothing was written. Run with --apply to re-save the rows marked "will re-save".');

            return self::SUCCESS;
        }

        $selected = $candidates->filter(fn (array $c) => $c['consistent'])->values();
        if ($selected->isEmpty()) {
            $this->warn('Nothing safe to re-save.');

            return self::FAILURE;
        }

        $this->warn('On PostgreSQL (Render) also take a database snapshot/export from the Render dashboard before continuing.');
        if (! $this->confirm("Re-save {$selected->count()} episode(s)? No values change; only last_modified_at moves forward.", false)) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $backup = $this->backupRows($selected);
        $this->info("Row backup written: {$backup}");

        DB::transaction(function () use ($selected) {
            foreach ($selected as $c) {
                // Same values, newer last_modified_at; the model hook also marks it pending and audits it.
                $c['episode']->forceFill(['last_modified_at' => now()])->save();
                $this->line("Re-saved {$c['episode']->id} ({$c['episode']->status}).");
            }
        });

        $this->info('Done. The other side receives the corrected status on the next normal sync.');

        return self::SUCCESS;
    }

    /**
     * Writes the untouched rows as JSON and checks it can be read back, before anything is changed.
     * Works the same on SQLite and PostgreSQL because it only reads through the query builder.
     */
    private function backupRows(Collection $selected): string
    {
        $rows = DB::table('eac_episodes')->whereIn('id', $selected->map(fn (array $c) => $c['episode']->id)->all())->get();

        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        $path = $dir.DIRECTORY_SEPARATOR.'repair-eac-sync-'.now()->format('Ymd-His').'.json';
        file_put_contents($path, $rows->toJson(JSON_PRETTY_PRINT));

        $written = json_decode((string) file_get_contents($path), true);
        if (! is_array($written) || count($written) !== $rows->count()) {
            @unlink($path);
            throw new \RuntimeException('The backup file could not be verified. Nothing was changed.');
        }

        return $path;
    }

    /** @return Collection<int, array{episode: EacEpisode, result: \App\Models\ViralLoadResult, expected: string, consistent: bool}> */
    private function candidates(): Collection
    {
        return EacEpisode::with(['patient', 'repeatViralLoadResult'])
            ->whereIn('status', ['resolved_suppressed', 'flagged_for_regimen_switch'])
            ->whereNotNull('repeat_viral_load_result_id')
            ->get()
            ->filter(fn (EacEpisode $e) => $e->repeatViralLoadResult
                && ($e->last_modified_at === null || $e->last_modified_at->lt($e->repeatViralLoadResult->created_at)))
            ->map(function (EacEpisode $e) {
                $expected = $e->repeatViralLoadResult->is_suppressed ? 'resolved_suppressed' : 'flagged_for_regimen_switch';

                return ['episode' => $e, 'result' => $e->repeatViralLoadResult, 'expected' => $expected, 'consistent' => $e->status === $expected];
            })
            ->sortBy(fn (array $c) => $c['result']->created_at)
            ->values();
    }
}
