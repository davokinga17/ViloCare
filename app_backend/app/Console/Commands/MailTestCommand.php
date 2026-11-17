<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MailTestCommand extends Command
{
    protected $signature = 'vilocare:mail-test {address : Where to send one plain test email}';

    protected $description = 'Administrator tool: send one plain test email with the current MAIL_* settings (prints only success or the error class)';

    public function handle(): int
    {
        $address = (string) $this->argument('address');

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::INVALID;
        }

        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            $this->warn('MAIL_MAILER is "'.config('mail.default').'": nothing is really sent. Set MAIL_MAILER=smtp first (see docs/HANDOVER.md).');

            return self::FAILURE;
        }

        try {
            Mail::raw('This is a test email from ViLoCare. Email sending works.', function ($message) use ($address) {
                $message->to($address)->subject('ViLoCare test email');
            });
        } catch (\Throwable $e) {
            $this->error('Failed: '.get_class($e)); // class only: the message can contain the host or the user name

            return self::FAILURE;
        }

        $this->info('Success: the test email was handed to the mail server.');

        return self::SUCCESS;
    }
}
