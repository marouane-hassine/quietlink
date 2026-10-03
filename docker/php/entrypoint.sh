#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs the mandatory app:boot checks before PHP-FPM (§9.5); any failure stops the container.
set -eu

if [ "${1:-}" = "php-fpm" ]; then
    php /app/bin/console app:boot --no-interaction
fi

exec "$@"
