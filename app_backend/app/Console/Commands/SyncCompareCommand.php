<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncCompareCommand extends Command
{
    protected $signature = 'vilocare:sync-compare {--url= : Override central sync API endpoint URL}';

    protected $description = 'Read-only: compare local and central record counts per table and count remote ids missing locally';

    public function handle(SyncService $syncService): int
    {
        $this->info('Comparing this clinic with the central server (read-only, nothing is changed)...');

        try {
            $rows = $syncService->compareWithRemote($this->option('url') ?: null);
        } catch (\Throwable $e) {
            $friendly = SyncService::formatConnectionError($e->getMessage());
            $this->error("{$friendly['title']}: {$friendly['friendly_message']}");

            return self::FAILURE;
        }

        $table = [];
        $totalMissing = 0;
        foreach ($rows as $tableName => $row) {
            $totalMissing += $row['missing_locally'];
            $table[] = [
                $tableName,
                $row['local'],
                $row['remote'] ?? 'n/a',
                $row['missing_locally'] > 0 ? "<error>{$row['missing_locally']}</error>" : '0',
            ];
        }

        $this->table(['Table', 'Local rows', 'Remote rows', 'Remote ids missing locally'], $table);

        if ($totalMissing > 0) {
            $this->warn("{$totalMissing} remote record(s) are not on this computer. Run: php artisan vilocare:sync --full");
        } else {
            $this->info('Every remote record id is present locally.');
        }

        return self::SUCCESS;
    }
}
