<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class MarkCentralSyncedCommand extends Command
{
    protected $signature = 'vilocare:mark-central-synced {--force : Do not ask for confirmation}';

    protected $description = 'One-time, central server only: mark every row still flagged "pending" as synced (the central server has nowhere to upload to)';

    public function handle(SyncService $syncService): int
    {
        if (! $syncService->isCentral()) {
            $this->error('This is not the central server (VILOCARE_ROLE=central). Refusing to touch clinic pending rows: they have not been uploaded yet.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Mark all pending rows on this central server as synced?')) {
            $this->info('Nothing changed.');

            return self::SUCCESS;
        }

        $updated = $syncService->markAllPendingAsSynced();
        $this->info("Marked {$updated} pending row(s) as synced.");

        return self::SUCCESS;
    }
}
