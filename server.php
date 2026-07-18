<?php

/**
 * Laravel router script for PHP built-in server.
 * Used when `php artisan serve` rewrite misbehaves.
 *
 * Usage: php -S 127.0.0.1:8001 -t public server.php
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)
);

if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false; // serve static file as-is
}

require_once __DIR__ . '/public/index.php';
