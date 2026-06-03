FROM serversideup/php:8.5-fpm-nginx

USER root

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    gnupg \
    ca-certificates \
    libicu-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libmagickwand-dev \
    libpq-dev \
    libzip-dev \
    libldap2-dev \
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
    && curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
    && apt-get install -y nodejs \
    && npm install -g yarn@1.22.22 \
    && LIBARCH="$(case "$(uname -m)" in x86_64) echo x86_64-linux-gnu ;; aarch64|arm64) echo aarch64-linux-gnu ;; *) echo "unsupported arch: $(uname -m)" >&2; exit 1 ;; esac)" \
    && docker-php-ext-configure ldap --with-libdir="lib/${LIBARCH}" \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install intl bcmath gd exif sockets pcntl pdo_pgsql pgsql zip ldap \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && if ! php -m | grep -qi '^redis$'; then pecl install redis && docker-php-ext-enable redis; fi \
    && mkdir -p /var/www/html/storage/app/browser/page_debug \
    && chown -R www-data:www-data /var/www/html/storage \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/app/browser/page_debug \
    bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

USER www-data

USER root
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/php/entrypoint.d/10-secrets.sh /etc/entrypoint.d/10-secrets.sh
COPY docker/php/entrypoint.d/20-init-media-permissions.sh /etc/entrypoint.d/20-init-media-permissions.sh
RUN chmod +x /etc/entrypoint.d/10-secrets.sh /etc/entrypoint.d/20-init-media-permissions.sh
USER www-data

ENV CHROME_BIN=/usr/bin/chromium
ENV CHROME_PATH=/usr/lib/chromium/
