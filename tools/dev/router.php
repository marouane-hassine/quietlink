<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Router for `php -S` in development only: serves built assets and generated theme files
// as a web server would, everything else goes to the front controller.

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
if (str_starts_with($path, '/build/') && is_file(__DIR__ . '/../../public' . $path)) {
    if (str_contains($path, 'argon2.worker')) {
        header("Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'");
    }
    return false;
}
if (preg_match('#^/themes/generated/(tokens\.[0-9a-f]{16}\.css)$#', $path, $match) === 1) {
    $dir = getenv('QUIETLINK_GENERATED_DIR') ?: '/tmp/ql-dev/generated';
    header('Content-Type: text/css');
    readfile($dir . '/' . $match[1]);
    return true;
}
require __DIR__ . '/../../public/index.php';
