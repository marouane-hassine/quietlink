# SPDX-License-Identifier: AGPL-3.0-or-later
# Ephemeral CLI image: docker run --rm -i quietlink/cli decrypt --url-stdin
# Use `docker run -it` for interactive passphrase prompts (/dev/tty).

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM php:8.3-cli-alpine@sha256:8dbeb51c352cf1f70d5d50f3642c9fbed311e914475ea2382f5bc1525bef7bb3
RUN apk add --no-cache icu-libs \
 && apk add --no-cache --virtual .build icu-dev \
 && docker-php-ext-install -j"$(nproc)" intl \
 && apk del .build \
 && adduser -u 10002 -S -H -s /sbin/nologin quietlink
WORKDIR /app
COPY --from=vendor /src/vendor ./vendor
COPY src ./src
COPY bin/quietlink ./bin/quietlink
COPY LICENSE ./
# Release metadata last: a new version or revision rebuilds no other layer.
ARG VERSION=dev
ARG REVISION=unknown
LABEL org.opencontainers.image.title="quietlink-cli" \
      org.opencontainers.image.description="QuietLink command line client" \
      org.opencontainers.image.source="https://github.com/marouane-hassine/quietlink" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.revision="${REVISION}"
USER 10002
ENTRYPOINT ["php", "/app/bin/quietlink"]
