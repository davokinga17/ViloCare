<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal reader/writer for the application's .env file (the one Laravel actually loaded).
 * Values are written double-quoted; callers must reject quotes, backslashes and line breaks first.
 */
class EnvFile
{
    public static function path(): string
    {
        return app()->environmentFilePath();
    }

    public static function get(string $key): ?string
    {
        $path = self::path();
        if (! is_file($path)) {
            return null;
        }

        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($path), $m)) {
            return null;
        }

        $value = trim($m[1]);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function set(array $values): void
    {
        $path = self::path();
        $text = is_file($path) ? (string) file_get_contents($path) : '';

        foreach ($values as $key => $value) {
            if (preg_match('/["\\\\\r\n]/', $value)) {
                throw new RuntimeException("Invalid characters in value for {$key}.");
            }

            $line = "{$key}=\"{$value}\"";
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $text = preg_match($pattern, $text)
                ? preg_replace_callback($pattern, fn () => $line, $text)
                : rtrim($text)."\n".$line."\n";
        }

        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $text, LOCK_EX) === false || ! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('The settings file could not be written.');
        }
    }
}
