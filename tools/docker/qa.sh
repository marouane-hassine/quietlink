#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs the validations inside Docker: PHP (cs, PHPStan, PHPUnit including the CLI and
# integration tests), frontend (typecheck, Vitest, build, budget, coverage report) and the CI
# pattern checks. vendor/ and node_modules/ live in container volumes, never on the host.
# Usage: tools/docker/qa.sh [php|frontend|all]   (default: all)
set -euo pipefail
cd "$(dirname "$0")/../.."
target=${1:-all}
image=quietlink/qa:local
docker build -q -f docker/qa/Dockerfile -t "$image" docker/qa > /dev/null
run() {
  docker run --rm -t \
    -v "$PWD:/src" \
    -v quietlink-qa-vendor:/src/vendor \
    -v quietlink-qa-node:/src/node_modules \
    -v quietlink-qa-home:/home/quietlink \
    --tmpfs /src/var:uid=10001,gid=10001 \
    -e HOME=/home/quietlink \
    "$image" bash -c "$1"
}
# Volumes are created root-owned; hand them to the unprivileged user once.
docker run --rm -u 0 -v quietlink-qa-vendor:/v -v quietlink-qa-node:/n -v quietlink-qa-home:/h "$image" chown 10001:10001 /v /n /h
case "$target" in
  php) run 'composer install -q --no-interaction && composer qa' ;;
  frontend) run 'npm ci --no-audit --no-fund --loglevel=error && npm run qa' ;;
  all) run 'composer install -q --no-interaction && composer qa && npm ci --no-audit --no-fund --loglevel=error && npm run qa && tools/ci/forbidden-patterns.sh' ;;
  *) echo "usage: $0 [php|frontend|all]" >&2; exit 64 ;;
esac
