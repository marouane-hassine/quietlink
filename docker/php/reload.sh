#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Reload after a configuration change (§9.5), the container equivalent of the systemd
# ExecReload: app:boot, then USR2 to the PHP-FPM master (PID 1) only if boot succeeded. On
# failure the running workers keep the previous configuration and the error is printed.
# Usage: docker compose exec app quietlink-reload
set -eu
php /app/bin/console app:boot --no-interaction || exit 1
kill -USR2 1
echo 'reload: PHP-FPM reloaded with the new configuration'
