<?php

namespace App\Support;

/**
 * How many SMS segments a message needs.
 *  - GSM-7 text: 160 characters in one segment (153 per part when split). Characters such as ^ { } \ [ ] ~ | € count double.
 *  - Anything outside the GSM alphabet (emoji, curly quotes, letters such as ï or ć, Arabic ...) switches the whole
 *    message to UCS-2: 70 characters in one segment (67 per part).
 */
class SmsSegments
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /**
     * @return array{encoding: string, length: int, segments: int, single_limit: int, multipart: bool}
     */
    public static function analyse(string $message): array
    {
        $chars = mb_str_split($message);
        $gsmLength = 0;
        $isGsm = true;

        foreach ($chars as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $gsmLength++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $gsmLength += 2;
            } else {
                $isGsm = false;
                break;
            }
        }

        if ($isGsm) {
            $length = $gsmLength;
            $limit = 160;
            $perPart = 153;
        } else {
            // UCS-2 counts UTF-16 code units: characters above U+FFFF (emoji) take two.
            $length = intdiv(strlen((string) mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);
            $limit = 70;
            $perPart = 67;
        }

        return [
            'encoding' => $isGsm ? 'gsm7' : 'ucs2',
            'length' => $length,
            'segments' => $length <= $limit ? 1 : (int) ceil($length / $perPart),
            'single_limit' => $limit,
            'multipart' => $length > $limit,
        ];
    }

    /** A plain-language warning when the message does not fit one segment, else null. */
    public static function warning(string $message, ?string $providerName = null): ?string
    {
        $info = self::analyse($message);
        if (! $info['multipart']) {
            return null;
        }

        $text = "This message is {$info['length']} characters and needs {$info['segments']} SMS parts (one part holds {$info['single_limit']} characters"
            .($info['encoding'] === 'gsm7' ? ', or 70 if it contains special characters' : ' because it contains special characters').').';

        if ($providerName === 'twilio') {
            $text .= ' Twilio does not support multi-part messages to South Sudan numbers, so it is likely to be rejected. Shorten it to one part.';
        } else {
            $text .= ' Each part is billed as a separate SMS. Shorten it to one part if you can.';
        }

        return $text;
    }
}
