<?php

namespace App\Services\Sms;

/**
 * What a provider says happened to one message.
 *  - sent:     the provider accepted it (message id may be given)
 *  - failed:   the provider refused it; $error is the provider's own text
 *  - deferred: the provider could not be reached (offline); the message stays queued for the next retry
 */
final class SmsResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $messageId,
        public readonly ?string $error,
        public readonly bool $deferred = false,
    ) {}

    public static function sent(?string $messageId): self
    {
        return new self(true, $messageId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }

    public static function deferred(string $error): self
    {
        return new self(false, null, $error, true);
    }
}
