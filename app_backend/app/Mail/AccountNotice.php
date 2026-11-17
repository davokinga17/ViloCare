<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Short plain-text account email (verification, reset link, "password changed"). Never carries patient data,
 * a password, or another person's login email.
 */
class AccountNotice extends Mailable
{
    /** @param array<int, string> $lines */
    public function __construct(public string $kind, public string $noticeSubject, public array $lines, public ?string $link = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->noticeSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.account-notice', with: ['lines' => $this->lines, 'link' => $this->link]);
    }
}
