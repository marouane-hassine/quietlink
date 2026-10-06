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
        header("Content-Security-Policy: default-src 'none'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'; form-action 'none'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'; require-trusted-types-for 'script'; trusted-types dompurify quietlink-worker");
    }
    return false;
}
if (preg_match('#^/themes/generated/(tokens\.[0-9a-f]{16}\.css)$#', $path, $match) === 1) {
    $dir = getenv('QUIETLINK_GENERATED_DIR') ?: '/tmp/ql-dev/generated';
    if (!is_file($dir . '/' . $match[1])) {
        // As nginx would: a plain 404, never a PHP warning revealing a path.
        http_response_code(404);
        return true;
    }
    header('Content-Type: text/css');
    header('X-Content-Type-Options: nosniff');
    readfile($dir . '/' . $match[1]);
    return true;
}
require __DIR__ . '/../../public/index.php';
