# SPDX-License-Identifier: AGPL-3.0-or-later
# QuietLink application image: PHP-FPM, non-root, read-only root filesystem compatible.
# Base images should be pinned by digest in release builds (see docs/README-admin.md).

FROM node:24-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY tsconfig.json vite.config.ts ./
COPY frontend ./frontend
COPY translations ./translations
COPY tests/vectors ./tests/vectors
RUN npx vite build

FROM composer:2 AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM php:8.3-fpm-alpine AS app
RUN apk add --no-cache icu-libs \
 && apk add --no-cache --virtual .build icu-dev \
 && docker-php-ext-install -j"$(nproc)" intl opcache \
 && apk del .build \
 && addgroup -g 10001 -S quietlink && adduser -u 10001 -S -G quietlink -H -s /sbin/nologin quietlink \
 && rm -f /usr/local/etc/php-fpm.d/*.conf

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-quietlink.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/quietlink.conf
COPY docker/php/entrypoint.sh /usr/local/bin/quietlink-entrypoint

WORKDIR /app
COPY --from=vendor /src/vendor ./vendor
COPY bin ./bin
COPY config ./config
COPY public/index.php ./public/index.php
COPY src ./src
COPY templates ./templates
COPY translations ./translations
COPY LICENSE composer.json ./
COPY --from=assets /src/public/build ./public/build

# Prewarm the Symfony container at build time; it is read-only at runtime (§9.2).
RUN APP_ENV=prod php bin/console cache:warmup --no-debug \
 && rm -f config/reference.php \
 && chown -R root:root /app && chmod -R a+rX,go-w /app \
 && chmod 0755 /usr/local/bin/quietlink-entrypoint \
 && install -d -o 10001 -g 10001 -m 0700 /var/lib/quietlink \
 && install -d -o 10001 -g 10001 -m 0755 /var/lib/quietlink-generated

ENV APP_ENV=prod \
    QUIETLINK_CONFIG_DIR=/app/config \
    QUIETLINK_FPM_POOL_FILE=/usr/local/etc/php-fpm.d/quietlink.conf

USER 10001:10001
EXPOSE 9000
STOPSIGNAL SIGQUIT
ENTRYPOINT ["/usr/local/bin/quietlink-entrypoint"]
CMD ["php-fpm", "--nodaemonize"]
