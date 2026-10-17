<?php

namespace App\Console\Commands;

use App\Services\LocationReconciler;
use App\Services\SyncService;
use Illuminate\Console\Command;

class LocationsCompareCommand extends Command
{
    protected $signature = 'vilocare:locations-compare {--url= : Override central sync API endpoint URL} {--all : Also list rows whose ids already match}';

    protected $description = 'Read-only: compare states, counties and facilities with the central server by business key and report affected patients and samples';

    public function handle(SyncService $syncService, LocationReconciler $reconciler): int
    {
        $this->info('Comparing locations with the central server (read-only, nothing is changed)...');

        try {
            $remote = $syncService->fetchRemoteLocations($this->option('url') ?: null);
            $refs = $syncService->fetchRemoteReferences([
                'patients' => ['state_id', 'county_id', 'facility_id'],
                'sample_collections' => ['facility_id'],
            ], $this->option('url') ?: null);
        } catch (\Throwable $e) {
            $friendly = SyncService::formatConnectionError($e->getMessage());
            $this->error("{$friendly['title']}: {$friendly['friendly_message']}");

            return self::FAILURE;
        }

        $rows = $reconciler->compare($remote);
        $show = $this->option('all') ? $rows : array_values(array_filter($rows, fn ($r) => $r['status'] !== 'match'));

        $this->table(
            ['Table', 'Code / key', 'Name', 'Local id', 'Cloud id', 'Status'],
            array_map(fn ($r) => [$r['table'], $r['key'], $r['name'], $r['local_id'] ?? '-', $r['remote_id'] ?? '-', $r['status']], $show)
        );

        $summary = array_count_values(array_column($rows, 'status'));
        $this->line('Rows: '.collect(['match', 'id_differs', 'local_only', 'remote_only'])
            ->map(fn ($s) => "{$s}=".($summary[$s] ?? 0))->implode('  '));

        $impact = $reconciler->impact($remote, $refs);
        $this->newLine();
        $this->table(['Measure', 'Count'], collect($impact['counts'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all());

        if ($impact['moved_patients']) {
            $this->warn(count($impact['moved_patients']).' patient(s) sit in a different location than on the cloud (silent reassignment). The cloud copy wins on reconcile:');
            $this->table(
                ['Patient id', 'Local facility', 'Cloud facility', 'Local county', 'Cloud county'],
                array_map(fn ($m) => [
                    substr($m['id'], 0, 8).'…', $m['from']['facility_id'] ?? '?', $m['to']['facility_id'],
                    $m['from']['county_id'] ?? '?', $m['to']['county_id'],
                ], $impact['moved_patients'])
            );
        }

        $this->info('Fix: php artisan vilocare:reconcile-locations --dry-run');

        return self::SUCCESS;
    }
}
