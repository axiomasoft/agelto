FROM serversideup/php:8.4-fpm-nginx

USER root

RUN apt-get update && apt-get install -y \
    libicu-dev \
    nodejs \
    npm \
    && docker-php-ext-install intl \
    && npm install -g pnpm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
