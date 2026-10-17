<?php

// Router for PHP's built-in server (php -S ... router.php).
// - Serves static files from public/ as-is.
// - Serves uploaded files at /storage/* straight from the data folder
//   (replaces the public/storage symlink, which needs admin rights and a writable install dir).
// - Sends everything else to Laravel's public/index.php.

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$publicRoot = $_SERVER['DOCUMENT_ROOT'];

// Startup self-check used by the desktop shell (src/php.js): is opcache really active in THIS server process?
// Answers only to this computer and reveals nothing but PHP runtime settings.
if ($uri === '/__vilocare/opcache') {
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        http_response_code(404);
        return true;
    }

    $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode([
        'php' => PHP_VERSION,
        'loaded' => extension_loaded('Zend OPcache'),
        'enabled' => is_array($status) && !empty($status['opcache_enabled']),
        'cached_scripts' => is_array($status) ? ($status['opcache_statistics']['num_cached_scripts'] ?? 0) : 0,
        'memory_used_mb' => is_array($status) ? round(($status['memory_usage']['used_memory'] ?? 0) / 1048576, 1) : 0,
        'jit_enabled' => is_array($status) && !empty($status['jit']['enabled']),
        'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
    ]);

    return true;
}

if (str_starts_with($uri, '/storage/')) {
    $base = realpath((getenv('LARAVEL_STORAGE_PATH') ?: '') . '/app/public');
    $file = $base ? realpath($base . '/' . substr($uri, 9)) : false;

    if ($file && is_file($file) && str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
        header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        return true;
    }

    http_response_code(404);
    return true;
}

if ($uri !== '/' && is_file($publicRoot . $uri) && !str_ends_with($uri, '.php')) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicRoot . '/index.php';
require $publicRoot . '/index.php';
