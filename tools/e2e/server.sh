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
    'log' => ['level' => 'warning'],
];
PHP
export APP_ENV=dev QUIETLINK_CONFIG_DIR="$dir/config" QUIETLINK_GENERATED_DIR="$dir/generated"
export QUIETLINK_APP_SECRET="ZTJlLW9ubHktZHVtbXktc2VjcmV0LW5vdC1mb3ItcHJvZHVjdGlvbg=="
php "$root/bin/console" app:boot >/dev/null
exec php -S localhost:8090 -t "$root/public" "$root/tools/dev/router.php"
