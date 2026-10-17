<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

/**
 * Used by the desktop shell around an app update (never exposed over the web).
 *   hold     stop new syncs from starting; exit 20 while a sync is still running, 0 once none is
 *   release  allow syncs again (the install was skipped)
 *   status   one line of JSON: sync_running, hold, update_required
 */
class UpdateGateCommand extends Command
{
    public const EXIT_SYNC_RUNNING = 20;

    protected $signature = 'vilocare:update-gate {action : hold, release or status}';

    protected $description = 'Desktop shell: hold or release syncs around an app update, or print update-related sync state';

    public function handle(SyncService $sync): int
    {
        switch ($this->argument('action')) {
            case 'hold':
                $sync->setUpdateHold(true);

                return $sync->isSyncRunning() ? self::EXIT_SYNC_RUNNING : self::SUCCESS;
            case 'release':
                $sync->setUpdateHold(false);

                return self::SUCCESS;
            case 'status':
                $this->line(json_encode([
                    'sync_running' => $sync->isSyncRunning(),
                    'hold' => $sync->updateHoldActive(),
                    'update_required' => $sync->updateRequired(),
                ]));

                return self::SUCCESS;
        }

        $this->error('Unknown action. Use hold, release or status.');

        return self::INVALID;
    }
}
