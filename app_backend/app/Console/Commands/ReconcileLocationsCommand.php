<?php

namespace App\Console\Commands;

use App\Services\LocationReconciler;
use App\Services\SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileLocationsCommand extends Command
{
    protected $signature = 'vilocare:reconcile-locations
        {--dry-run : Print what would change and write nothing}
        {--url= : Override central sync API endpoint URL}';

    protected $description = 'Make states, counties and facilities identical to the central server (adopting cloud ids, moving child records with them), restore cloud patient locations and close the related sync conflicts';

    public function handle(SyncService $syncService, LocationReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $url = $this->option('url') ?: null;

        // 1. Read everything needed from the cloud first: nothing local is touched if this fails.
        try {
            $remote = $syncService->fetchRemoteLocations($url);
            $refs = $syncService->fetchRemoteReferences([
                'patients' => ['state_id', 'county_id', 'facility_id'],
            ], $url);
        } catch (\Throwable $e) {
            $friendly = SyncService::formatConnectionError($e->getMessage());
            $this->error("{$friendly['title']}: {$friendly['friendly_message']}");
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        // 2. Plan (read-only).
        $mappings = [];
        $planRows = [];
        $counts = ['rekeyed' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (LocationReconciler::TABLES as $table) {
            foreach ($remote[$table] ?? [] as $row) {
                $result = $reconciler->apply($table, $row, $mappings, true);
                $counts[$result['action']]++;
                if ($result['action'] !== 'unchanged') {
                    $planRows[] = [
                        $table, $row['code'] ?? $row['name'], $result['action'], $result['old_id'] ?? '-', $row['id'],
                        collect($result['children'])->filter()->map(fn ($n, $k) => "{$k}={$n}")->implode(', ') ?: '-',
                    ];
                }
            }
        }
        $restorePlan = $reconciler->patientRestorePlan($remote, $refs['patients'] ?? []);
        $localOnly = array_filter($reconciler->compare($remote), fn ($r) => $r['status'] === 'local_only');

        $this->info($dryRun ? 'DRY RUN - nothing will be written.' : 'Reconciliation plan:');
        if ($planRows) {
            $this->table(['Table', 'Code', 'Action', 'Local id (old)', 'Cloud id (new)', 'Child rows that follow'], $planRows);
        }
        $this->line(sprintf(
            'Locations: %d id(s) to adopt, %d to add, %d to update, %d already identical. Patients whose location is restored from the cloud: %d.',
            $counts['rekeyed'], $counts['inserted'], $counts['updated'], $counts['unchanged'], count($restorePlan)
        ));
        foreach ($localOnly as $row) {
            $this->warn("Local-only {$row['table']} '{$row['key']}' ({$row['name']}) is not on the cloud: left untouched and never pushed. Add it on the cloud if it is real.");
        }

        $nothingToDo = $counts['rekeyed'] + $counts['inserted'] + $counts['updated'] === 0 && ! $restorePlan;

        if ($dryRun) {
            $this->info($nothingToDo ? 'Already reconciled; a real run would change nothing.' : 'Review the plan above, then run without --dry-run.');

            return self::SUCCESS;
        }

        if ($nothingToDo) {
            $this->info('Already reconciled. Nothing to change.');
            $this->retryConflicts($syncService);

            return self::SUCCESS;
        }

        if (! $this->confirm('Write these changes to the local database? A backup is taken first.', false)) {
            $this->warn('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        // 3. Backup must succeed before anything is written.
        try {
            $backup = $this->backupDatabase();
        } catch (\Throwable $e) {
            $this->error('Backup failed - refusing to continue. Nothing was changed. '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info($backup ? "Backup written: {$backup}" : 'No database file to back up (in-memory or non-SQLite database). Make sure you have a snapshot.');

        // 4. Apply atomically; verify foreign keys before committing.
        try {
            $restored = DB::transaction(function () use ($reconciler, $remote, $restorePlan) {
                $mappings = [];
                foreach (LocationReconciler::TABLES as $table) {
                    foreach ($remote[$table] ?? [] as $row) {
                        $reconciler->apply($table, $row, $mappings);
                    }
                }

                $restored = $reconciler->restorePatientLocations($restorePlan);
                $this->assertForeignKeysIntact();

                return $restored;
            });
        } catch (\Throwable $e) {
            $this->error('Reconciliation failed and was rolled back: '.$e->getMessage());
            $this->line($backup ? "Backup is at: {$backup}" : '');

            return self::FAILURE;
        }

        $this->info("Locations reconciled. Patients restored to the cloud location: {$restored['restored']}"
            .($restored['unresolved'] ? " ({$restored['unresolved']} could not be restored - location missing)" : '').'.');
        $this->info('PRAGMA foreign_key_check: no violations.');

        $this->retryConflicts($syncService);
        $this->line('Next: php artisan vilocare:sync --full   then   php artisan vilocare:sync-compare');

        return self::SUCCESS;
    }

    private function retryConflicts(SyncService $syncService): void
    {
        $result = $syncService->retryFlaggedConflicts();
        $this->info("Flagged sync conflicts retried: {$result['retried']}, resolved automatically: {$result['resolved']}, still failing: {$result['still_failing']}.");
    }

    /** Throws when any foreign key is violated (SQLite; other drivers enforce immediately). */
    private function assertForeignKeysIntact(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        $violations = DB::select('PRAGMA foreign_key_check');
        if ($violations) {
            $first = (array) $violations[0];
            throw new \RuntimeException(count($violations).' foreign key violation(s) after reconciliation, e.g. table '.($first['table'] ?? '?').' -> '.($first['parent'] ?? '?').'.');
        }
    }

    /** Copies the SQLite file to database/backups/. Returns null when there is no file to copy. */
    private function backupDatabase(): ?string
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;
        }

        $source = DB::connection()->getDatabaseName();
        if ($source === '' || $source === ':memory:' || ! is_file($source)) {
            return null;
        }

        $dir = database_path('backups');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        // Fold any write-ahead log into the main file so the copy is complete.
        DB::statement('PRAGMA wal_checkpoint(TRUNCATE)');

        $target = $dir.DIRECTORY_SEPARATOR.'database-'.now()->format('Ymd-His').'.sqlite';
        if (! copy($source, $target) || ! is_file($target) || filesize($target) !== filesize($source)) {
            @unlink($target);
            throw new \RuntimeException('The backup copy could not be verified.');
        }

        return $target;
    }
}
