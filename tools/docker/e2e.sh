#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Runs the Playwright end-to-end campaign inside Docker (five browser projects, the disposable
# PHP test server of tools/e2e/server.sh). node_modules and vendor/ live in container volumes.
# Usage: tools/docker/e2e.sh [playwright arguments]   e.g. tools/docker/e2e.sh tests/e2e/ux.spec.ts
set -euo pipefail
cd "$(dirname "$0")/../.."
image=quietlink/e2e:local
docker build -q -f docker/e2e/Dockerfile -t "$image" docker/e2e > /dev/null
user="$(id -u):$(id -g)"
# Mount points created by dockerd would belong to root in the working copy (Linux hosts).
mkdir -p var vendor node_modules
docker run --rm -u 0 -v quietlink-e2e-vendor:/v -v quietlink-e2e-node:/n -v quietlink-e2e-home:/h "$image" chown -R "$user" /v /n /h
args=$(printf '%q ' "$@")
docker run --rm -t --init --ipc=host -u "$user" \
  -v "$PWD:/src" \
  -v quietlink-e2e-vendor:/src/vendor \
  -v quietlink-e2e-node:/src/node_modules \
  -v quietlink-e2e-home:/home/e2e \
  --tmpfs "/src/var:uid=$(id -u),gid=$(id -g),exec" \
  -e HOME=/home/e2e -e CI=1 \
  "$image" bash -c "composer install -q --no-interaction && npm ci --no-audit --no-fund --loglevel=error && npx playwright test --reporter=line $args"
