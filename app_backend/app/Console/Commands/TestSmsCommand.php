<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;

class TestSmsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:test {phone=+211910000001 : Recipient phone number} {--message= : Optional custom discrete message}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test discrete SMS notification through the selected SMS provider (Africa\'s Talking or Twilio)';

    /**
     * Execute the console command.
     */
    public function handle(SmsService $smsService): int
    {
        $phone = (string) $this->argument('phone');
        $message = $this->option('message') ?? "Hello from Kapoeta Civil Hospital! Your scheduled routine wellness check-up reminder is active.";

        $this->info("Sending test discrete SMS notification...");
        $this->line("Recipient: <comment>{$phone}</comment>");
        $this->line("Message:   <comment>{$message}</comment>");
        $this->newLine();

        try {
            $notification = $smsService->send(
                phoneNumber: $phone,
                message: $message,
                category: 'custom'
            );

            if ($notification->isSent()) {
                $this->info("✅ SMS Dispatched Successfully!");
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['Notification ID', $notification->id],
                        ['Recipient', $notification->recipient_phone],
                        ['Provider', $notification->provider],
                        ['Status', $notification->status],
                        ['Provider Message ID', $notification->provider_message_id ?? 'N/A'],
                        ['Sent At', $notification->sent_at?->toDateTimeString() ?? 'Now'],
                    ]
                );
                if ($notification->provider === 'africastalking') {
                    $this->info("📱 Sandbox: check the Africa's Talking Simulator at https://simulator.africastalking.com:1517/simulator/receive-sms");
                }
            } else {
                $this->warn("⚠️ SMS Queued or Failed:");
                $this->line("Status: " . $notification->status);
                $this->line("Error:  " . ($notification->error_message ?? 'None'));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
