FROM serversideup/php:8.4-fpm-nginx

USER root

# Устанавливаем переменную, чтобы apt не задавал вопросов
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    # Зависимости для GD
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    # Зависимости для Imagick
    libmagickwand-dev \
    nodejs \
    npm \
    # Зависимости для Chromium
    chromium \
    libnss3 \
    libx11-6 \
    libxcomposite1 \
    libxcursor1 \
    libxdamage1 \
    libxext6 \
    libxfixes3 \
    libxi6 \
    libxrender1 \
    libxtst6 \
    libcups2 \
    libxss1 \
    libasound2 \
    libpangocairo-1.0-0 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libgtk-3-0 \
    # Установка расширений PHP
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl bcmath gd exif sockets \
    # Установка Imagick и Redis через PECL
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis \
    # Установка pnpm
    && npm install -g pnpm \
    # Настройка прав для Chrome
    && mkdir -p /var/www/.local/share/Google/Chrome && chown -R www-data:www-data /var/www/.local \
    # Очистка
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
