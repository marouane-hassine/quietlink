#!/bin/sh
# SPDX-License-Identifier: AGPL-3.0-or-later
# Development equivalent of the Compose purge service: app:purge-expired every 60 s.
# Without it health.json becomes stale after 10 minutes and creation is refused (§7.5).
set -u
root=$(cd "$(dirname "$0")/../.." && pwd)
while true; do
    php "$root/bin/console" app:purge-expired --no-interaction >/dev/null || true
    sleep 60
done
