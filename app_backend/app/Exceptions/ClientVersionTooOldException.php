<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The central server answered HTTP 426: this app version is below VILOCARE_MIN_CLIENT_VERSION.
 * Sync pauses; records stay pending until the app is updated.
 */
class ClientVersionTooOldException extends RuntimeException
{
    public function __construct(public readonly ?string $minimumVersion = null)
    {
        parent::__construct('This version of ViLoCare is out of date. Sync is paused until you update.');
    }
}
