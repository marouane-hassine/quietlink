# SPDX-License-Identifier: AGPL-3.0-or-later
# QuietLink application image: PHP-FPM, non-root, read-only root filesystem compatible.
# Base images are pinned by multi-arch index digest (docs/README-developer.md, "Updating pinned dependencies").

FROM node:24-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1 AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY tsconfig.json vite.config.ts ./
COPY frontend ./frontend
COPY translations ./translations
COPY tests/vectors ./tests/vectors
RUN npx vite build

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM php:8.3-fpm-alpine@sha256:454b11c8907e32878ce92e87b13c14e1bbe6e1b10f4f96d4a19c9b62ec675d41 AS app
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
