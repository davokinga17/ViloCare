<?php

namespace App\Services;

/**
 * Starts `php artisan vilocare:sync` as a detached process so the "Sync now" button never ties up the
 * (single-threaded) web server. The child inherits this process's environment, so it uses the same data
 * folder, .env and PHP settings. Progress is read back from the sync state (SyncService::runStatus()).
 */
class BackgroundSyncLauncher
{
    public function start(): bool
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $log = storage_path('logs/manual-sync.log');

        if (! is_file($php) || ! is_file($artisan)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            // "start /B" returns at once and leaves the child running after this request ends.
            $command = 'start "" /B "'.$php.'" "'.$artisan.'" vilocare:sync > "'.$log.'" 2>&1';
            $handle = @popen($command, 'r');

            return $handle !== false && pclose($handle) !== -1;
        }

        $command = 'nohup '.escapeshellarg($php).' '.escapeshellarg($artisan).' vilocare:sync > '.escapeshellarg($log).' 2>&1 &';
        @exec($command);

        return true;
    }
}
