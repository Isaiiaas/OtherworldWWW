<?php
/**
 * Restrict the including endpoint to local (loopback) requests only.
 *
 * The map-annotator UI and its write endpoints (save-pins, reset-pins,
 * run-parser, delete-map) are not meant to be public. Locking the UI
 * alone is half-baked — anyone could still POST pin changes directly.
 *
 * CLI invocations are allowed so cron / shell flows keep working.
 *
 * To override on a specific environment, set the env var
 *   OTHERWORLD_ALLOW_REMOTE_ADMIN=1
 * before including this file (e.g., via Apache SetEnv or php-fpm pool
 * config). Don't do this on a public host.
 */

if (PHP_SAPI === 'cli') return;

if (($_SERVER['OTHERWORLD_ALLOW_REMOTE_ADMIN'] ?? getenv('OTHERWORLD_ALLOW_REMOTE_ADMIN')) === '1') {
    return;
}

$addr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($addr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 Forbidden — this endpoint is restricted to local requests.\n";
    exit;
}
