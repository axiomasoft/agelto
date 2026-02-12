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
    curl \
    gnupg \
    ca-certificates \
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
    libgbm1 \
    libdrm2 \
    fonts-liberation \
    libappindicator3-1 \
    xdg-utils \
    # 1. Установка Node.js (необходим для npm)
    && curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs \
    # 2. Установка pnpm
    && npm install -g pnpm \
    # 3. Настройка расширений PHP (удален redis из pecl, так как он обычно есть)
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl bcmath gd exif sockets pcntl \
    # Установка только Imagick (Redis обычно встроен, если будет ругаться - вернем)
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    # 4. Настройка прав
    && mkdir -p /var/www/html/storage/app/browser/page_debug \
    && chown -R www-data:www-data /var/www/html/storage \
    # 5. Очистка
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Переменные для Chromium
ENV CHROME_BIN=/usr/bin/chromium
ENV CHROME_PATH=/usr/lib/chromium/

# Копируем файлы зависимостей (для кэширования слоев)
COPY composer.json composer.lock ./

# Устанавливаем PHP зависимости (без dev-пакетов)
RUN composer install --no-dev --no-interaction --no-autoloader --no-scripts

# Копируем весь остальной код проекта
COPY . .

# Финальная донастройка Composer (оптимизация автозагрузки)
RUN composer install --no-dev --optimize-autoloader

# Выставляем права для пользователя www-data (чтобы Laravel мог писать логи и кэш)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Возвращаемся под обычного пользователя, если это требует базовый образ
USER www-data
