#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Disposable instance for npm run bench:load: temporary storage, every rate limit bucket lifted
# (all load comes from one address), PHP built-in server. Never use this configuration in production.
set -eu
root=$(cd "$(dirname "$0")/../.." && pwd)
dir="$root/var/bench-instance"
rm -rf "$dir"
mkdir -p "$dir/config/themes"
cat > "$dir/config/config.php" <<PHP
<?php
return [
    'app' => ['public_url' => 'http://localhost:8095'],
    'storage' => [
        'root_dir' => '$dir/data/pastes',
        'idempotency_dir' => '$dir/data/idempotency',
        'ratelimit_dir' => '$dir/data/ratelimit',
        'state_dir' => '$dir/data/state',
        'generated_assets_dir' => '$dir/generated',
        'allow_unsupported_fs' => true,
        'min_free_bytes' => 1,
    ],
    'http' => ['rate_limits' => [
        'create' => ['limit' => 1000000, 'interval' => 60],
        'create_replay' => ['limit' => 1000000, 'interval' => 60],
        'challenge' => ['limit' => 1000000, 'interval' => 60],
        'open' => ['limit' => 1000000, 'interval' => 60],
        'status' => ['limit' => 1000000, 'interval' => 60],
        'consume' => ['limit' => 1000000, 'interval' => 60],
        'delete' => ['limit' => 1000000, 'interval' => 60],
        'health' => ['limit' => 1000000, 'interval' => 60],
        'open_per_paste' => ['limit' => 1000000, 'interval' => 60],
        'status_per_paste' => ['limit' => 1000000, 'interval' => 60],
    ]],
    'log' => ['level' => 'warning', 'file' => null],
];
PHP
export APP_ENV=prod QUIETLINK_CONFIG_DIR="$dir/config" QUIETLINK_GENERATED_DIR="$dir/generated"
export QUIETLINK_APP_SECRET="YmVuY2gtb25seS1kdW1teS1zZWNyZXQtbm90LWZvci1wcm9k"
php "$root/bin/console" app:boot >/dev/null
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
exec php -S localhost:8095 -t "$root/public" "$root/tools/dev/router.php"
