# SPDX-License-Identifier: AGPL-3.0-or-later
# Ephemeral CLI image: docker run --rm -i quietlink/cli decrypt --url-stdin
# Use `docker run -it` for interactive passphrase prompts (/dev/tty).

FROM composer:2 AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM php:8.3-cli-alpine
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
USER 10002
ENTRYPOINT ["php", "/app/bin/quietlink"]
