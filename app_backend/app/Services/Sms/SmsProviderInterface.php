<?php

namespace App\Services\Sms;

/**
 * One SMS gateway. SmsService owns every rule about WHAT may be sent (privacy words, queueing, logging);
 * a provider only knows HOW to hand one message to its gateway.
 */
interface SmsProviderInterface
{
    /** Stable key stored on every NotificationLog row: "africastalking" or "twilio". */
    public function name(): string;

    /** Human name for screens and messages. */
    public function label(): string;

    /** True when every credential needed to send is present. */
    public function isConfigured(): bool;

    /** Why nothing can be sent, for the log row when isConfigured() is false. */
    public function notConfiguredReason(): string;

    /**
     * @param  string  $to  recipient in E.164 format (+211...)
     */
    public function send(string $to, string $message): SmsResult;
}
