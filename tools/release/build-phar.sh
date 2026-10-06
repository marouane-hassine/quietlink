#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Reproducible build of the CLI PHAR (quietlink.phar) inside a pinned container: the same
# Composer image (by digest), the same Box release (checked by SHA-256), the same options and
# the commit timestamp of HEAD. CI, the release workflow and anyone verifying a release run
# this script. Box adds files in directory listing order, which depends on the filesystem: the
# entries are then rewritten sorted by path, with fixed timestamps and signature.
# Usage: tools/release/build-phar.sh   (in a clean checkout of the tag; writes quietlink.phar)
set -euo pipefail
cd "$(dirname "$0")/../.."
image='composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac'
box_url='https://github.com/box-project/box/releases/download/4.7.0/box.phar'
box_sha256='3d390eeaec33288098fe83f8a54c60cc575cb6be295f38ff4482b4b4f26f8d52'
# seld/phar-utils 1.2.3 (the library Box uses), the one file needed, pinned by commit and hash.
timestamps_url='https://raw.githubusercontent.com/Seldaek/phar-utils/65fb381613fd31745d39a740a112a233f978d2d9/src/Timestamps.php'
timestamps_sha256='56d764d75f987cd2c5cedb097b9d7fa0598f247af534c95886ee511c59c852cd'
timestamp=${TIMESTAMP:-$(git log -1 --format=%cI)}
# PHAR_BUILD_TMPFS=1 builds on a tmpfs instead of the container filesystem: CI compares both.
extra=()
if [ "${PHAR_BUILD_TMPFS:-0}" = 1 ]; then extra=(--tmpfs /tmp:exec,mode=1777); fi   # TIMESTAMP overrides it outside a git checkout
docker run --rm --platform linux/amd64 ${extra[@]+"${extra[@]}"} -u "$(id -u):$(id -g)" -e HOME=/tmp -e COMPOSER_HOME=/tmp/composer \
  -e BOX_URL="$box_url" -e BOX_SHA256="$box_sha256" -e TIMESTAMP="$timestamp" \
  -e TIMESTAMPS_URL="$timestamps_url" -e TIMESTAMPS_SHA256="$timestamps_sha256" \
  -v "$PWD:/src" -w /src --entrypoint sh "$image" -euc '
    curl -fsSL -o /tmp/box.phar "$BOX_URL"
    echo "$BOX_SHA256  /tmp/box.phar" | sha256sum -c - > /dev/null
    rm -rf /tmp/build && mkdir /tmp/build
    # Built from a copy holding only what the PHAR contains, never the working copy vendor/.
    cp -R composer.json composer.lock box.json LICENSE bin src /tmp/build/
    cd /tmp/build
    composer install --quiet --no-dev --classmap-authoritative --no-interaction --no-scripts --ignore-platform-reqs
    php -r "\$c = json_decode(file_get_contents(\"box.json\"), true); \$c[\"timestamp\"] = getenv(\"TIMESTAMP\"); file_put_contents(\"box.release.json\", json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));"
    php -d phar.readonly=0 /tmp/box.phar compile --config=box.release.json --no-interaction --no-parallel > /dev/null
    # Entries sorted by path: Box keeps the directory listing order of the build filesystem.
    curl -fsSL -o /tmp/Timestamps.php "$TIMESTAMPS_URL"
    echo "$TIMESTAMPS_SHA256  /tmp/Timestamps.php" | sha256sum -c - > /dev/null
    php -d phar.readonly=0 /src/tools/release/normalize-phar.php quietlink.phar /src/quietlink.phar "$TIMESTAMP" /tmp/Timestamps.php'
sha256sum quietlink.phar 2>/dev/null || shasum -a 256 quietlink.phar
