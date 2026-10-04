#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Post-deployment smoke test of the Docker stack, run locally against a throwaway Compose
# project (its own config, secret and volumes; the working copy's config/ is never touched):
# images build and run unprivileged, no database service exists, app:boot --dry-run and
# app:config:check succeed, the healthchecks pass, and the CLI image creates, reads and deletes
# a paste of dummy text through the web container. Everything is removed at the end.
# Usage: tools/docker/smoke.sh   (QUIETLINK_SMOKE_PORT, default 18096)
set -euo pipefail
cd "$(dirname "$0")/../.."
project=quietlink-smoke
port=${QUIETLINK_SMOKE_PORT:-18096}
work=$(mktemp -d)
compose() { docker compose -p "$project" -f compose.yaml -f "$work/override.yaml" "$@"; }
cleanup() {
  status=$?
  if [ "$status" -ne 0 ]; then compose logs --no-color --tail 50 >&2 || true; fi
  compose down -v --remove-orphans > /dev/null 2>&1 || true
  rm -rf "$work"
  exit "$status"
}
trap cleanup EXIT
step() { printf '== %s\n' "$*"; }

step "configuration and secret (throwaway)"
sed "s#'public_url' => '[^']*'#'public_url' => 'http://localhost:$port'#" config/config.php.example > "$work/config.php"
grep -q "'public_url' => 'http://localhost:$port'" "$work/config.php"
cat > "$work/override.yaml" <<YAML
services:
  app:
    volumes:
      - $work/config.php:/app/config/config.php:ro
  purge:
    volumes:
      - $work/config.php:/app/config/config.php:ro
secrets:
  app_secret:
    file: $work/app_secret
YAML

step "build images"
compose build -q
docker build -q -f docker/cli/Dockerfile -t quietlink/cli:smoke . > /dev/null
compose run --rm --no-deps -T app php bin/console app:secret:generate > "$work/app_secret"
chmod 644 "$work/app_secret" "$work/config.php"   # readable by uid 10001 inside the containers

step "no database service, unprivileged images"
! compose config | grep -Eiq 'image: *(postgres|mysql|mariadb|redis|valkey|mongo)'
test "$(docker image inspect -f '{{.Config.User}}' quietlink/app:dev)" = "10001:10001"
test "$(docker image inspect -f '{{.Config.User}}' quietlink/web:dev)" = "101"
test "$(docker image inspect -f '{{.Config.User}}' quietlink/cli:smoke)" = "10002"

step "app:boot --dry-run before the first start"
compose run --rm --no-deps -T app php bin/console app:boot --dry-run --format=json | grep -q '"status": "ok"'

step "start and wait for the healthchecks"
QUIETLINK_HTTP_PORT=$port compose up -d --wait --wait-timeout 180
compose exec -T app php bin/console app:config:check --format=json | grep -q '"ready": true'
test "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/healthz")" = "200"
curl -sI "http://127.0.0.1:$port/" | grep -qi '^content-security-policy:.*script-src'
test -z "$(compose exec -T app sh -c 'touch /app/probe 2>/dev/null && echo writable')"

step "create, read and delete dummy text with the CLI image"
cli() { docker run --rm -i --network "container:$(compose ps -q web)" quietlink/cli:smoke "$@"; }
# The share link goes to stdout, the management link to stderr (never mixed in pipes).
created=$(printf 'smoke test dummy text' | cli create --server "http://localhost:8080" --expires 5m 2>&1)
share=$(printf '%s\n' "$created" | grep -Eo 'http://localhost:[0-9]+/p/[^[:space:]]+' | head -1)
manage=$(printf '%s\n' "$created" | grep -Eo 'http://localhost:[0-9]+/manage/[^[:space:]]+' | head -1)
test -n "$share" && test -n "$manage"
printf '%s' "$share" | cli decrypt --url-stdin | grep -qx 'smoke test dummy text'
printf '%s' "$manage" | cli delete --url-stdin --yes > /dev/null
! printf '%s' "$share" | cli metadata --url-stdin > /dev/null 2>&1

step "purge runs"
compose exec -T app php bin/console app:purge-expired --no-interaction | grep -q '"failed":0'

step "smoke test passed"
