<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vilocare:sync {--url= : Override central sync API endpoint URL} {--full : Ignore the stored pull cursor and re-pull everything (safe and idempotent)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute full two-way synchronization between local SQLite and central PostgreSQL cloud server';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        $overrideUrl = $this->option('url');

        if ($syncService->isCentral()) {
            $this->info('This is the central server (VILOCARE_ROLE=central). It does not run an outbound sync; clinics sync to it.');

            return self::SUCCESS;
        }

        $this->info('Starting ViLoCare full synchronization cycle...');

        $result = $syncService->runFullSync($overrideUrl, (bool) $this->option('full'));

        if (($result['mode'] ?? null) === 'not_configured') {
            $this->warn('Sync is not set up (no sync URL/token). Running offline; records stay pending. An administrator can add the connection in Settings.');

            return self::SUCCESS;
        }

        if (!$result['success']) {
            $friendly = $result['friendly_error'] ?? SyncService::formatConnectionError($result['error'] ?? 'Unknown network error');
            $this->error("Sync Notice [{$friendly['title']}]: " . $friendly['friendly_message']);
            $this->warn("Data Safety: " . $friendly['action_hint']);
            if (!empty($result['error'])) {
                $this->line("<fg=gray>Raw Diagnostic: {$result['error']}</>");
            }
            return self::FAILURE;
        }

        $this->info('Synchronization completed successfully!');
        $this->table(
            ['Metric', 'Count / Value'],
            [
                ['Local Changes Pushed', $result['pushed']],
                ['Remote Changes Pulled', $result['pulled']],
                ['LWW Conflicts Reconciled', $result['conflicts']],
                ['Records Needing Review', $result['failed'] ?? 0],
                ['Queued SMS Processed', $result['sms_processed']],
                ['Sync Timestamp', $result['timestamp']],
                ['Sync Mode', $result['mode'] ?? 'standard'],
            ]
        );

        return self::SUCCESS;
    }
}
