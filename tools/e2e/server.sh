#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Disposable instance for Playwright: temporary config and storage, boot, PHP built-in server.
set -eu
root=$(cd "$(dirname "$0")/../.." && pwd)
dir="$root/var/e2e"
rm -rf "$dir"
mkdir -p "$dir/config/themes"
cat > "$dir/config/config.php" <<PHP
<?php
return [
    'app' => ['public_url' => 'http://localhost:8090'],
    'storage' => [
        'root_dir' => '$dir/data/pastes',
        'idempotency_dir' => '$dir/data/idempotency',
        'ratelimit_dir' => '$dir/data/ratelimit',
        'state_dir' => '$dir/data/state',
        'generated_assets_dir' => '$dir/generated',
        'allow_unsupported_fs' => true,
        'min_free_bytes' => 1,
    ],
    'paste' => ['read_once_reservation_ttl' => 30],
    // The full campaign (5 browser projects, one client address) creates and deletes far more
    // than the production 10-minute budgets allow; per-minute buckets keep their defaults.
    'http' => ['rate_limits' => [
        'create' => ['limit' => 1000, 'interval' => 600],
        'delete' => ['limit' => 1000, 'interval' => 600],
    ]],
    // Local export and printing (Could, off by default) are enabled so local-output.spec.ts can
    // exercise them; they only add buttons to the reading screen.
    'ui' => ['allow_export' => true, 'allow_print' => true],
    'log' => ['level' => 'warning'],
];
PHP
export APP_ENV=dev QUIETLINK_CONFIG_DIR="$dir/config" QUIETLINK_GENERATED_DIR="$dir/generated"
export QUIETLINK_APP_SECRET="ZTJlLW9ubHktZHVtbXktc2VjcmV0LW5vdC1mb3ItcHJvZHVjdGlvbg=="
php "$root/bin/console" app:boot >/dev/null
tools_dir="$root/tools/dev"
"$tools_dir/purge-loop.sh" &
# Several workers so that parallel browser requests never stall a navigation.
export PHP_CLI_SERVER_WORKERS=4
# 127.0.0.1, not "localhost": in the Linux e2e container localhost resolves to ::1 first, while
# Node and the browsers also reach the URLs below (http://localhost:8090) over IPv4.
exec php -S 127.0.0.1:8090 -t "$root/public" "$root/tools/dev/router.php"
