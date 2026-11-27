# syntax=docker/dockerfile:1.19@sha256:b6afd42430b15f2d2a4c5a02b919e98a525b785b1aaff16747d2f623364e39b6

FROM pgsty/silo:RELEASE.2026-11-12T00-00-00Z@sha256:c20d112390b9a9ce8c11b8af95ad35bcaa62f4759663c09c936cd8d2b511f329 AS mc

FROM serversideup/php:8.6-fpm-nginx@sha256:672d413df0ca56e163a35fa7a9a30b5d70e62ea70f5e03bdbd04318d955f3d40 AS app-base

USER root

# mpdf/mpdf 8.3.x still caps its php constraint at ~8.5.0 (upstream's development branch
# already allows ~8.6.0). `php+` ignores only that upper bound; lower bounds and every
# extension requirement stay enforced. Drop it once a tagged mpdf release allows PHP 8.6.
ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_IGNORE_PLATFORM_REQ=php+ \
    PHP_UPLOAD_MAX_FILE_SIZE=200M \
    PHP_POST_MAX_SIZE=200M \
    PHP_MAX_EXECUTION_TIME=300

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    libicu-dev \
    libpq-dev \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pgsql bcmath pcntl \
    && apt-mark manual $(apt-cache depends libicu-dev libpq-dev | awk '/Depends:/{print $2}' | grep -E '^lib(icu|pq)[0-9]') \
    && apt-get purge -y --auto-remove libicu-dev libpq-dev \
    && if ! php -m | grep -qi '^redis$'; then pecl install redis-6.3.0 && docker-php-ext-enable redis; fi \
    && if ! php -m | grep -qi '^gd$'; then install-php-extensions gd; fi \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# S3 client (`mc`) for ILM bucket lifecycle rules (`ai:storage:lifecycle`); the project has no
# S3 SDK. The binary is copied from the pinned object-storage image instead of being downloaded
# from GitHub releases. MinIO's own images were removed from Docker Hub, so the source is the
# maintained MinIO-compatible Silo image; its `mc` is a symlink to `mcli`.
COPY --from=mc /usr/bin/mcli /usr/local/bin/mc

WORKDIR /var/www/html

RUN install -d -m 0755 -o root -g root /var/www/html \
    && install -d -m 0775 -o www-data -g www-data \
        storage \
        storage/app \
        storage/logs \
        storage/framework \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache \
        bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

USER root
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/entrypoint.d/10-secrets.sh /etc/entrypoint.d/10-secrets.sh
RUN chmod +x /etc/entrypoint.d/10-secrets.sh \
    && mv /usr/local/bin/docker-php-serversideup-entrypoint /usr/local/bin/docker-php-serversideup-entrypoint.real \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -eu' \
        'if [ "${AGELTO_SECRETS_LOADED:-0}" != "1" ]; then' \
        '    AGELTO_ENTRYPOINT_PRELOAD=1 exec /etc/entrypoint.d/10-secrets.sh "$@"' \
        'fi' \
        'exec /usr/local/bin/docker-php-serversideup-entrypoint.real "$@"' \
        > /usr/local/bin/docker-php-serversideup-entrypoint \
    && chmod 0755 /usr/local/bin/docker-php-serversideup-entrypoint

# Доверие ЕИС (`config('crawler.tls.bundles')`, `SourceTlsTrust`) — узкое, НЕ системный трест
# (P4.12, D125): бандл несёт Russian Trusted Root CA (SHA-256
# D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31)
# и Russian Trusted Sub CA 2024 (SHA-256
# 21:55:78:50:36:C9:00:DB:B5:F1:BB:2A:15:69:C8:0C:55:59:5B:D6:BF:94:86:7A:29:BB:DD:BC:7D:88:A3:F2).
# `update-ca-certificates` НЕ вызывается — попадание в системный трест расширило бы доверие
# российскому УЦ на весь контейнер, включая исходящий канал к LLM-провайдеру.
COPY docker/php/certs/russian-trusted-ca.pem /etc/ssl/certs/russian-trusted-ca.pem
ENTRYPOINT ["/bin/sh", "-c", "AGELTO_ENTRYPOINT_PRELOAD=1 exec /etc/entrypoint.d/10-secrets.sh \"$@\"", "agelto-entrypoint"]
CMD ["/init"]

# Development keeps its source bind mount. Production copies only the filtered build context;
# Composer/vendor closure and framework readiness remain explicit P2/P3 gates.
FROM app-base AS development
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && if ! php -m | grep -qi '^pcov$'; then pecl install pcov && docker-php-ext-enable pcov; fi \
    # ./:/var/www/html — bind-mount с хоста (uid 1000), процесс www-data — uid 33; git считает
    # это "dubious ownership" и блокирует любые операции, включая движок Tia (требует git).
    # system-level (не global www-data), чтобы пережить --force-recreate без volume на $HOME.
    && git config --system --add safe.directory /var/www/html \
    # Именованный том app_pest_tia:/var/www/.pest (D5) — Docker копирует содержимое этого пути
    # из образа при первом создании ПУСТОГО тома, включая владельца; entrypoint.d здесь не
    # годится — serversideup/php выполняет entrypoint.d уже как www-data, не root.
    && mkdir -p /var/www/.pest && chown www-data:www-data /var/www/.pest \
    && chown -R www-data:www-data /var/www/html \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY docker/php/conf.d/pcov.ini /usr/local/etc/php/conf.d/pcov.ini
USER root

FROM development AS development-prepare
RUN --mount=type=bind,source=docker/php/install-prepare-tools.sh,target=/tmp/install-prepare-tools.sh \
    sh /tmp/install-prepare-tools.sh

FROM node:26-trixie@sha256:f8e5d4bb28fa55c74f1345166a082a10b7c68f22304350c1ce0d18358ae94996 AS frontend
WORKDIR /build
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
# Node 25+ no longer bundles Corepack; install the pinned release, which then honours
# package.json#packageManager.
RUN npm install --global corepack@0.36.0 && corepack enable && pnpm install --frozen-lockfile --ignore-scripts
COPY resources ./resources
COPY vite.config.js ./
COPY docs ./docs
COPY scripts/documentation ./scripts/documentation
RUN pnpm build && pnpm documentation:build

FROM app-base AS dependencies
WORKDIR /var/www/html
COPY --exclude=local/vendors/azguard --exclude=local/vendors/proxyma . /var/www/html
COPY local/vendors/azguard/packages/core /var/www/html/local/vendors/azguard/packages/core
COPY local/vendors/azguard/packages/filament /var/www/html/local/vendors/azguard/packages/filament
COPY local/vendors/proxyma/packages/core /var/www/html/local/vendors/proxyma/packages/core
COPY local/vendors/proxyma/packages/filament /var/www/html/local/vendors/proxyma/packages/filament
RUN --mount=type=cache,id=agelto-composer-cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM app-base AS application
ARG SOURCE_SHA
ARG VENDOR_LOCK_SHA
COPY --exclude=local/vendors/azguard --exclude=local/vendors/proxyma . /var/www/html
COPY --from=dependencies /var/www/html/vendor /var/www/html/vendor
COPY --from=frontend /build/public/build /var/www/html/public/build
COPY --from=frontend /build/var/private-documentation /var/private-documentation
RUN rm -f .env \
    && install -m 0600 /dev/null database/database.sqlite \
    && DB_CONNECTION=sqlite DB_DATABASE=/var/www/html/database/database.sqlite php artisan filament:assets --no-interaction \
    && DB_CONNECTION=sqlite DB_DATABASE=/var/www/html/database/database.sqlite php artisan livewire:publish --assets --no-interaction \
    && rm -f database/database.sqlite \
    && mkdir -p storage/app storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && install -d -m 0775 -o www-data -g www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && install -d -m 0755 /usr/share/agelto \
    && printf '%s\n' "$VENDOR_LOCK_SHA" > /usr/share/agelto/vendor-lock.sha \
    && chown -R root:root app bootstrap/app.php bootstrap/providers.php config database public resources routes vendor artisan \
    && chmod -R a-w app bootstrap/app.php bootstrap/providers.php config database public resources routes vendor artisan /var/private-documentation \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
LABEL org.opencontainers.image.revision="${SOURCE_SHA}" \
      io.agelto.vendor-lock-sha="${VENDOR_LOCK_SHA}"
USER root

FROM application AS worker-prepare
RUN --mount=type=bind,source=docker/php/install-prepare-tools.sh,target=/tmp/install-prepare-tools.sh \
    sh /tmp/install-prepare-tools.sh

FROM app-base AS doctor-dependencies
WORKDIR /var/www/html
COPY --exclude=local/vendors/azguard --exclude=local/vendors/proxyma . /var/www/html
COPY local/vendors/azguard/packages/core /var/www/html/local/vendors/azguard/packages/core
COPY local/vendors/azguard/packages/filament /var/www/html/local/vendors/azguard/packages/filament
COPY local/vendors/proxyma/packages/core /var/www/html/local/vendors/proxyma/packages/core
COPY local/vendors/proxyma/packages/filament /var/www/html/local/vendors/proxyma/packages/filament
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM app-base AS doctor
ARG SOURCE_SHA
ARG VENDOR_LOCK_SHA
COPY --exclude=local/vendors/azguard --exclude=local/vendors/proxyma . /var/www/html
COPY --from=doctor-dependencies /var/www/html/vendor /var/www/html/vendor
RUN install -d -m 0755 /usr/share/agelto \
    && printf '%s\n' "$VENDOR_LOCK_SHA" > /usr/share/agelto/vendor-lock.sha \
    && install -d -m 0775 -o www-data -g www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R root:root app bootstrap/app.php bootstrap/providers.php config database public resources routes vendor artisan \
    && chmod -R a-w app bootstrap/app.php bootstrap/providers.php config database public resources routes vendor artisan \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
LABEL org.opencontainers.image.title="agelto-doctor" \
      org.opencontainers.image.revision="${SOURCE_SHA}" \
      io.agelto.vendor-lock-sha="${VENDOR_LOCK_SHA}"
USER root
