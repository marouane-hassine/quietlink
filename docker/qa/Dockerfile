# SPDX-License-Identifier: AGPL-3.0-or-later
# Local validation image (tools/docker/qa.sh): PHP 8.3 with the production extensions, Composer
# and Node, to run composer qa, npm run qa and the CLI tests inside Docker. Never deployed.
# Base images are the digests pinned by the production images (docs/README-developer.md).

FROM node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS node

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.3-fpm-alpine@sha256:454b11c8907e32878ce92e87b13c14e1bbe6e1b10f4f96d4a19c9b62ec675d41
RUN apk add --no-cache icu-libs libstdc++ git bash coreutils \
 && apk add --no-cache --virtual .build icu-dev \
 && docker-php-ext-install -j"$(nproc)" intl \
 && apk del .build \
 && addgroup -g 10001 -S quietlink && adduser -u 10001 -S -G quietlink -h /home/quietlink -s /bin/sh quietlink
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
 && echo 'memory_limit=1G' > /usr/local/etc/php/conf.d/qa.ini
USER 10001:10001
WORKDIR /src
