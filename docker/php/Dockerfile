FROM serversideup/php:8.4-fpm-nginx

USER root

# Устанавливаем переменную, чтобы apt не задавал вопросов
ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    # Добавляем curl и gnupg для корректной установки node если понадобится
    curl \
    gnupg \
    # Зависимости для Chromium (добавлены недостающие: libgbm1, libdrm2, fonts)
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
    libgbm1 \
    libdrm2 \
    fonts-liberation \
    libappindicator3-1 \
    xdg-utils \
    # Установка расширений PHP
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl bcmath gd exif sockets pcntl \
    # Установка Imagick и Redis через PECL
    && pecl install imagick redis \
    && docker-php-ext-enable imagick redis \
    # Настройка Node.js и pnpm (Node уже есть в базовом образе или ставится из apt)
    && npm install -g pnpm \
    # Настройка прав для Chromium и путей дебага
    && mkdir -p /var/www/html/storage/app/browser/page_debug \
    && chown -R www-data:www-data /var/www/html/storage \
    # Очистка
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Указываем переменную окружения для Puppeteer/Chrome если нужно (опционально)
ENV CHROME_BIN=/usr/bin/chromium
ENV CHROME_PATH=/usr/lib/chromium/

WORKDIR /var/www/html

# Возвращаемся к пользователю www-data, чтобы не плодить файлы от root
USER www-data
