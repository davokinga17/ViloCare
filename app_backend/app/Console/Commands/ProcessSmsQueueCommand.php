<?php

namespace App\Console\Commands;

use App\Models\NotificationLog;
use App\Services\SmsService;
use Illuminate\Console\Command;

class ProcessSmsQueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:process-queue {--limit=50 : Maximum number of queued messages to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch pending and offline-queued SMS notifications via Africa\'s Talking API';

    /**
     * Execute the console command.
     */
    public function handle(SmsService $smsService): int
    {
        $limit = (int) $this->option('limit');
        
        $queuedLogs = NotificationLog::where('status', 'queued')
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        if ($queuedLogs->isEmpty()) {
            $this->info('No queued SMS messages waiting for dispatch.');
            return self::SUCCESS;
        }

        $this->info("Processing {$queuedLogs->count()} queued SMS message(s)...");

        $sentCount = 0;
        $failedCount = 0;

        foreach ($queuedLogs as $log) {
            $this->line("Dispatching message #{$log->id} to {$log->recipient_phone}...");
            
            $result = $smsService->dispatchToProvider($log);

            if ($result->isSent()) {
                $this->info("  [✓] Sent successfully (Provider ID: {$result->provider_message_id})");
                $sentCount++;
            } else {
                $this->warn("  [✗] Failed: {$result->error_message}");
                $failedCount++;
            }
        }

        $this->newLine();
        $this->info("SMS Queue Process Summary: {$sentCount} sent, {$failedCount} deferred/failed.");

        return self::SUCCESS;
    }
}
