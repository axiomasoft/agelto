# syntax=docker/dockerfile:1.19

FROM minio/mc:latest AS mc

FROM serversideup/php:8.5-fpm-nginx AS runtime-base

USER root

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libicu-dev \
    libpq-dev \
    qpdf \
    libreoffice-calc \
    libreoffice-writer \
    fonts-dejavu-core \
    fonts-liberation \
    7zip \
    unar \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-rus \
    tesseract-ocr-eng \
    ocrmypdf \
    ghostscript \
    && docker-php-ext-install intl bcmath pdo_pgsql pgsql \
    && if ! php -m | grep -qi '^redis$'; then pecl install redis && docker-php-ext-enable redis; fi \
    && if ! php -m | grep -qi '^gd$'; then install-php-extensions gd; fi \
    && if ! php -m | grep -qi '^pcov$'; then install-php-extensions pcov; fi \
    # ./:/var/www/html — bind-mount с хоста (uid 1000), процесс www-data — uid 33; git считает
    # это "dubious ownership" и блокирует любые операции, включая движок Tia (требует git).
    # system-level (не global www-data), чтобы пережить --force-recreate без volume на $HOME.
    && git config --system --add safe.directory /var/www/html \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# MinIO Client (mc) — S3/MinIO ILM bucket lifecycle rules (P7.7, `ai:storage:lifecycle`);
# нет composer-пакета в проекте под S3 API (aws-sdk-php не установлен) — Code Guidance
# item'а прямо допускает "mc в отдельном init-контейнере" как альтернативу S3 SDK.
# Копируем бинарник из официального образа вместо `curl`-скачивания релиза с GitHub —
# в некоторых сетевых окружениях (в т.ч. sandboxed CI) `objects.githubusercontent.com`
# недоступен, тогда как обычный `docker pull minio/mc` проходит через реестр Docker Hub.
COPY --from=mc /usr/bin/mc /usr/local/bin/mc

WORKDIR /var/www/html

RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

# Именованный том app_pest_tia:/var/www/.pest (D5) — Docker копирует содержимое этого пути
# из образа при первом создании ПУСТОГО тома, включая владельца; entrypoint.d здесь не
# годится — serversideup/php выполняет entrypoint.d уже как www-data, не root (см. чуть выше
# про storage/vendor — тот же вывод).
RUN mkdir -p /var/www/.pest && chown www-data:www-data /var/www/.pest

USER root
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/conf.d/pcov.ini /usr/local/etc/php/conf.d/pcov.ini
COPY docker/php/entrypoint.d/10-secrets.sh /etc/entrypoint.d/10-secrets.sh
RUN chmod +x /etc/entrypoint.d/10-secrets.sh

# Доверие ЕИС (`config('crawler.tls.bundles')`, `SourceTlsTrust`) — узкое, НЕ системный трест
# (P4.12, D125): бандл несёт Russian Trusted Root CA (SHA-256
# D2:6D:2D:02:31:B7:C3:9F:92:CC:73:85:12:BA:54:10:35:19:E4:40:5D:68:B5:BD:70:3E:97:88:CA:8E:CF:31)
# и Russian Trusted Sub CA 2024 (SHA-256
# 21:55:78:50:36:C9:00:DB:B5:F1:BB:2A:15:69:C8:0C:55:59:5B:D6:BF:94:86:7A:29:BB:DD:BC:7D:88:A3:F2).
# `update-ca-certificates` НЕ вызывается — попадание в системный трест расширило бы доверие
# российскому УЦ на весь контейнер, включая исходящий канал к LLM-провайдеру.
COPY docker/php/certs/russian-trusted-ca.pem /etc/ssl/certs/russian-trusted-ca.pem
USER www-data

# Development keeps its source bind mount. Production copies only the filtered build context;
# Composer/vendor closure and framework readiness remain explicit P2/P3 gates.
FROM runtime-base AS development

FROM node:24-bookworm AS frontend
WORKDIR /build
COPY package.json pnpm-lock.yaml ./
RUN corepack enable && pnpm install --frozen-lockfile
COPY resources ./resources
COPY vite.config.js ./
RUN pnpm build

FROM runtime-base AS dependencies
WORKDIR /var/www/html
COPY --exclude=local/vendors/azguard --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data local/vendors/azguard/packages/core /var/www/html/local/vendors/azguard/packages/core
COPY --chown=www-data:www-data local/vendors/azguard/packages/filament /var/www/html/local/vendors/azguard/packages/filament
RUN composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --classmap-authoritative

FROM runtime-base AS application
COPY --exclude=local/vendors/azguard --chown=www-data:www-data . /var/www/html
COPY --from=dependencies --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=frontend --chown=www-data:www-data /build/public/build /var/www/html/public/build
RUN rm -f .env \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
