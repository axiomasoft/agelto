FROM serversideup/php:8.4-fpm-nginx

USER root

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libicu-dev \
    libpq-dev \
    qpdf \
    && docker-php-ext-install intl bcmath pdo_pgsql pgsql \
    && if ! php -m | grep -qi '^redis$'; then pecl install redis && docker-php-ext-enable redis; fi \
    && if ! php -m | grep -qi '^gd$'; then install-php-extensions gd; fi \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

USER root
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/entrypoint.d/10-secrets.sh /etc/entrypoint.d/10-secrets.sh
RUN chmod +x /etc/entrypoint.d/10-secrets.sh
USER www-data
