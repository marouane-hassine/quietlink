# SPDX-License-Identifier: AGPL-3.0-or-later
# Local end-to-end image (tools/docker/e2e.sh): the official Playwright image of the version in
# package.json (Chromium, Firefox, WebKit included) plus PHP 8.3 for the disposable test server.
# Never deployed. Base images pinned by digest (docs/README-developer.md).

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM mcr.microsoft.com/playwright:v1.63.0-noble@sha256:eff16c30e6f3f4af0a03fa4b706120d5e9b0891c344a27d64559aff5900a4a27
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends php8.3-cli php8.3-intl php8.3-mbstring php8.3-xml unzip \
 && rm -rf /var/lib/apt/lists/* \
 && php -r 'foreach (["sodium", "intl", "openssl", "mbstring"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing $e\n"); exit(1); } }'
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /src
