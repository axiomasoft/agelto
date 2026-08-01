FROM minio/mc:latest AS mc

FROM serversideup/php:8.5-fpm-nginx

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

USER root
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/conf.d/pcov.ini /usr/local/etc/php/conf.d/pcov.ini
COPY docker/php/entrypoint.d/10-secrets.sh /etc/entrypoint.d/10-secrets.sh
RUN chmod +x /etc/entrypoint.d/10-secrets.sh
USER www-data
