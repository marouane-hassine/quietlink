#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs the validations inside Docker: PHP (cs, PHPStan, PHPUnit including the CLI and
# integration tests), frontend (typecheck, Vitest, build, budget, coverage report) and the CI
# pattern checks. vendor/ and node_modules/ live in container volumes, never on the host.
# Commands run with the host user's uid/gid, so files written to the working copy (the
# frontend build in public/build) keep their usual owner on Linux hosts.
# Usage: tools/docker/qa.sh [php|frontend|all]   (default: all)
set -euo pipefail
cd "$(dirname "$0")/../.."
target=${1:-all}
image=quietlink/qa:local
docker build -q -f docker/qa/Dockerfile -t "$image" docker/qa > /dev/null
user="$(id -u):$(id -g)"
run() {
  docker run --rm -t -u "$user" \
    -v "$PWD:/src" \
    -v quietlink-qa-vendor:/src/vendor \
    -v quietlink-qa-node:/src/node_modules \
    -v quietlink-qa-home:/home/quietlink \
    --tmpfs "/src/var:uid=$(id -u),gid=$(id -g)" \
    -e HOME=/home/quietlink \
    "$image" bash -c "$1"
}
# Volumes are created root-owned; hand them to the invoking user.
docker run --rm -u 0 -v quietlink-qa-vendor:/v -v quietlink-qa-node:/n -v quietlink-qa-home:/h "$image" chown -R "$user" /v /n /h
case "$target" in
  php) run 'composer install -q --no-interaction && composer qa' ;;
  frontend) run 'npm ci --no-audit --no-fund --loglevel=error && npm run qa' ;;
  all) run 'composer install -q --no-interaction && composer qa && npm ci --no-audit --no-fund --loglevel=error && npm run qa && tools/ci/forbidden-patterns.sh' ;;
  *) echo "usage: $0 [php|frontend|all]" >&2; exit 64 ;;
esac
