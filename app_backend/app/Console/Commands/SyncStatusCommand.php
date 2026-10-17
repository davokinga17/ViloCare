<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vilocare:sync-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display real-time synchronization metrics and pending database records count';

    /**
     * Execute the console command.
     */
    public function handle(SyncService $syncService): int
    {
        $stats = $syncService->getSyncStats();

        $this->info("=== ViLoCare Synchronization Status ===");
        $this->line("Device ID:       <comment>{$stats['device_id']}</comment>");
        $this->line("Remote Endpoint: <comment>{$stats['remote_url']}</comment>");
        $this->line("Last Sync Time:  <comment>" . ($stats['last_synced_at'] ? $stats['last_synced_at']->toDateTimeString() : 'Never') . "</comment>");
        $this->newLine();

        $rows = [];
        foreach ($stats['tables'] as $tableName => $data) {
            $rows[] = [
                $data['label'],
                $tableName,
                $data['pending'] > 0 ? "<error>{$data['pending']}</error>" : "0",
                $data['synced'],
                $data['total'],
            ];
        }

        $this->table(
            ['Clinical Domain', 'Table', 'Pending Push', 'Synced', 'Total'],
            $rows
        );

        $this->newLine();
        $this->info("Total Pending Records:    {$stats['total_pending']}");
        $this->info("Total Synced Records:     {$stats['total_synced']}");
        $this->info("Total Recorded Conflicts: {$stats['total_conflicts']} ({$stats['unresolved_conflicts']} open)");

        return self::SUCCESS;
    }
}
