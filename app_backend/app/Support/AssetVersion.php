<?php

namespace App\Support;

/**
 * URL for a file in public/ with a version taken from the file's content, so a browser (or any cache in front of the
 * server) can never keep using an old copy after a deploy: when the file changes, its URL changes.
 *
 * Plain asset() URLs are served with no Cache-Control, so browsers reuse a stored copy for a while without asking.
 */
class AssetVersion
{
    /** @var array<string, string> */
    private static array $hashes = [];

    public static function url(string $path): string
    {
        return asset($path).'?v='.self::hash($path);
    }

    public static function hash(string $path): string
    {
        return self::$hashes[$path] ??= (is_file($full = public_path($path)) ? substr((string) md5_file($full), 0, 10) : '0');
    }
}
