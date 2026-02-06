FROM serversideup/php:8.4-fpm-nginx

USER root

RUN apt-get update && apt-get install -y \
    libicu-dev \
    nodejs \
    npm \
    # Добавляем bcmath для работы с ценами
    && docker-php-ext-install intl bcmath \
    # Установка Redis через pecl (уже может быть в образе)
    && (pecl install redis || true) && docker-php-ext-enable redis \
    && npm install -g pnpm \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
