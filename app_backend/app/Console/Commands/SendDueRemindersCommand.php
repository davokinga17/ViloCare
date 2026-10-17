<?php

namespace App\Console\Commands;

use App\Services\DueReminderService;
use Illuminate\Console\Command;

class SendDueRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:send-due-reminders
        {--days=7 : Number of days ahead to scan for due viral load tests}
        {--dry-run : List who would be messaged and send nothing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically send discreet SMS reminders to patients due for viral load testing within N days';

    /**
     * Execute the console command.
     */
    public function handle(DueReminderService $reminders): int
    {
        $days = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');
        $today = today()->toDateString();
        $targetDate = now()->addDays($days)->toDateString();

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Scanning for patients due between {$today} and {$targetDate}...");

        $candidates = $reminders->candidates($days);

        if ($candidates->isEmpty()) {
            $this->info('No patients due in this window.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} due patients.");

        $dispatched = 0;
        $skipped = 0;

        foreach ($candidates as $item) {
            $patient = $item->patient;
            $due = $item->next_due_date->format('Y-m-d');

            if ($reason = $reminders->skipReason($item)) {
                $this->line("  [Skip] {$patient->art_number}: {$reason}.");
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  [Would send] {$patient->art_number} {$patient->first_name} ({$this->maskPhone($patient->phone)}) due {$due} [{$item->groupLabel()}]");
            } else {
                $reminders->send($item);
                $this->info("  [✓] Dispatched discreet reminder to {$patient->first_name} ({$patient->phone}) for {$due}");
            }
            $dispatched++;
        }

        $this->newLine();
        $verb = $dryRun ? 'would be dispatched (nothing sent)' : 'dispatched';
        $this->info("Due Reminders Summary: {$dispatched} reminders {$verb}, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /** Dry-run output is often pasted into chats and tickets, so keep phone numbers partial. */
    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? str_repeat('*', strlen($phone) - 4).substr($phone, -4) : $phone;
    }
}
