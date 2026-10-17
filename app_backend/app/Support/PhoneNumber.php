<?php

namespace App\Support;

/**
 * Phone numbers in E.164 form. Local South Sudan numbers become +211 numbers; numbers that already carry
 * another country code (+254, +256, ...) are left alone.
 */
class PhoneNumber
{
    public static function normalise(string $phone): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        if ($cleaned === '' || $cleaned === null) {
            return '';
        }

        // 00211... is the international prefix written the old way.
        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+'.substr($cleaned, 2);
        }

        if (str_starts_with($cleaned, '+')) {
            // +2110912345678: the national "0" is not part of the number.
            return preg_replace('/^\+2110(\d{9})$/', '+211$1', $cleaned);
        }

        // 0912345678 -> +211912345678
        if (str_starts_with($cleaned, '0')) {
            return '+211'.substr($cleaned, 1);
        }

        // 9-digit national number without the 0 (mobile numbers start with 9 or 1).
        if (preg_match('/^[19]\d{8}$/', $cleaned)) {
            return '+211'.$cleaned;
        }

        return '+'.$cleaned;
    }

    public static function isValid(string $e164): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{7,14}$/', $e164);
    }
}
