# Инструкция по деплою на сервер (Debian/Ubuntu)

Эта инструкция описывает процесс развертывания Laravel приложения с Docker на сервере Debian/Ubuntu.

## Режимы работы

Проект поддерживает два режима работы:

- **DEV (разработка)** - использует `docker-compose.yml`
    - Код монтируется через volumes
    - Node.js и Vite доступны для разработки
    - Горячая перезагрузка (HMR)
- **PROD (production)** - использует `docker-compose.prod.yml`
    - Immutable образ (`agelto/app:latest`)
    - Нет volumes с кодом (код вшит в образ)
    - Минимальный runtime (без Node.js)
    - Secrets для безопасного хранения паролей

## Главные отличия и типичные ошибки (PROD)

### Порты: почему 8081 снаружи, а 8080 внутри?

В PROD используется образ `serversideup`, который работает от пользователя `www-data`. Обычный пользователь в Linux не может слушать порт 80.

- **Внутри контейнера** приложение всегда слушает порт **8080**.
- В `docker-compose.prod.yml` проброс задаётся как `"${APP_PORT:-8081}:8080"`.
- Nginx на хосте проксирует на `127.0.0.1:8081` (или ваш `APP_PORT`).

### Обновление кода (immutable)

В PROD изменения файлов в папке проекта на сервере **не попадают** в работающее приложение. Чтобы обновить код, нужно заново выполнить **сборку образа** и перезапуск контейнеров (см. раздел [6. PROD: Production деплой](#6-prod-production-деплой), подраздел «Обновление приложения»).

### HTTPS и Mixed Content

Если сайт открыт по HTTP (например, порт 8081), а CSS/JS не загружаются:

1. В `.env` укажите: `APP_URL=http://127.0.0.1:8081` (или ваш реальный URL до настройки SSL).
2. Очистите кэш:  
   `docker compose -f docker-compose.prod.yml exec app php artisan config:clear`

После настройки SSL через Nginx/Certbot задайте `APP_URL=https://yourdomain.com`.

## Содержание

1. [Подготовка сервера](#1-подготовка-сервера)
2. [Создание пользователя](#2-создание-пользователя)
3. [Установка Docker и Docker Compose](#3-установка-docker-и-docker-compose)
4. [Настройка проекта](#4-настройка-проекта)
5. [DEV: Локальная разработка](#5-dev-локальная-разработка)
6. [PROD: Production деплой](#6-prod-production-деплой)
7. [Установка и настройка Nginx (Reverse Proxy)](#7-установка-и-настройка-nginx-reverse-proxy)
8. [Настройка SSL (Let's Encrypt)](#8-настройка-ssl-lets-encrypt)
9. [Запуск приложения](#9-запуск-приложения)
10. [Полезные команды](#10-полезные-команды)
11. [Решение проблем](#11-решение-проблем)
12. [Полная очистка (начать с нуля)](#12-полная-очистка-начать-с-нуля)
13. [Советы по безопасности](#советы-по-безопасности)

---

## 1. Подготовка сервера

### Обновление системы

```bash
apt update && apt upgrade -y
```

**Объяснение:** Обновляем список пакетов и устанавливаем последние обновления безопасности.

### Установка необходимых пакетов

```bash
apt install -y curl wget git ufw
```

**Объяснение:**

- `curl`, `wget` - для загрузки файлов
- `git` - для клонирования репозитория
- `ufw` - для настройки файрвола

---

## 2. Создание пользователя

### Зачем нужен отдельный пользователь?

**Важно:** Работать от root небезопасно. Создаем отдельного пользователя для работы с приложением.

### Создание пользователя

```bash
# Создаем пользователя с домашней директорией
adduser --disabled-password --gecos "" deploy

# Добавляем пользователя в группу docker (будет создана после установки Docker)
# Пока просто создаем пользователя
```

**Объяснение:**

- `--disabled-password` - пользователь не сможет входить по паролю (только по SSH ключу)
- `--gecos ""` - без дополнительной информации
- `deploy` - имя пользователя (можно изменить)

### Настройка SSH доступа

```bash
# Переключаемся на нового пользователя
su - deploy

# Создаем директорию для SSH ключей
mkdir -p ~/.ssh
chmod 700 ~/.ssh

# Добавляем ваш публичный SSH ключ
nano ~/.ssh/authorized_keys
# Вставьте ваш публичный ключ и сохраните (Ctrl+O, Enter, Ctrl+X)

# Устанавливаем правильные права
chmod 600 ~/.ssh/authorized_keys

# Возвращаемся к root
exit
```

**Объяснение:** Настраиваем доступ по SSH ключу для безопасного входа без пароля.

---

## 3. Установка Docker и Docker Compose

### Установка Docker

```bash
# Удаляем старые версии (если есть)
apt remove -y docker docker-engine docker.io containerd runc

# Устанавливаем зависимости
apt install -y ca-certificates curl gnupg lsb-release

# Добавляем официальный GPG ключ Docker
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

# Добавляем репозиторий Docker
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

# Обновляем список пакетов
apt update

# Устанавливаем Docker
apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Запускаем Docker
systemctl start docker
systemctl enable docker

# Проверяем установку
docker --version
```

**Объяснение:**

- Устанавливаем Docker из официального репозитория
- `docker-compose-plugin` - это Docker Compose V2 (используется как `docker compose`)

### Добавление пользователя в группу docker

```bash
# Добавляем пользователя deploy в группу docker
usermod -aG docker deploy

# Проверяем (нужно будет перелогиниться)
groups deploy
```

**Объяснение:** Позволяет пользователю `deploy` запускать Docker команды без sudo.

### Установка Docker Compose (если нужна отдельная версия)

```bash
# Docker Compose V2 уже установлен как плагин
# Проверяем
docker compose version
```

**Объяснение:** В современных версиях Docker Compose встроен как плагин.

---

## 4. Настройка проекта

### Выбор директории для проекта

**Рекомендация:** Используем `/var/www/agelto/agelto` или `/opt/agelto/agelto`

```bash
# Создаем директорию
mkdir -p /var/www/agelto/agelto

# Устанавливаем владельца
chown deploy:deploy /var/www/agelto/agelto

# Переключаемся на пользователя deploy
su - deploy
cd /var/www/agelto/agelto
```

**Объяснение:**

- `/var/www/` - стандартная директория для веб-приложений
- Устанавливаем владельца, чтобы пользователь `deploy` мог работать с файлами

### Клонирование репозитория

```bash
# Клонируем проект
git clone <URL_ВАШЕГО_РЕПОЗИТОРИЯ> .

# Или если репозиторий приватный, используйте SSH:
# git clone git@github.com:username/repo.git .
```

**Объяснение:** Клонируем код проекта из Git репозитория.

---

## 5. DEV: Локальная разработка

**Используется:** `docker-compose.yml`

**Особенности:**

- Код монтируется через volumes (изменения видны сразу)
- Node.js и Vite доступны для разработки
- Горячая перезагрузка (HMR)
- Удобно для разработки и отладки

### Настройка .env для DEV

```bash
# Копируем пример конфигурации
cp .env.example .env

# Редактируем конфигурацию
nano .env
```

**PUID и PGID (владелец файлов в контейнере):**  
В `docker-compose.yml` контейнеру передаются `PUID` и `PGID` из `.env` (или значения по умолчанию 1000). Они задают UID/GID пользователя, от которого внутри контейнера создаются файлы (логи, кэш, загрузки). Чтобы файлы на хосте не принадлежали root, укажите в `.env` свой пользовательский ID:

```bash
# Узнать свой UID и GID на хосте
id -u   # подставьте в PUID
id -g   # подставьте в PGID
```

Если не заданы, используются 1000:1000.

**Важные настройки для production:**

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080

# База данных
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=agelto
DB_USERNAME=agelto
DB_PASSWORD=agelto_password

# Пароль root для MySQL (обязательно!)
DB_ROOT_PASSWORD=root_password

# Настройки портов
APP_PORT=8080
MYSQL_PORT=3307
VITE_PORT=5174

# Владелец файлов в контейнере (UID/GID пользователя на хосте).
# Нужно, чтобы созданные в контейнере файлы принадлежали вашему пользователю.
# Узнать: id -u (PUID), id -g (PGID). По умолчанию 1000.
# PUID=1000
# PGID=1000

# Vite для разработки
VITE_HOST=0.0.0.0
VITE_HMR_HOST=localhost
VITE_USE_POLLING=true
```

### Запуск DEV окружения

```bash
# 1. Запускаем контейнеры
docker compose up -d

# 2. Ждем, пока MySQL станет готов
sleep 30

# 3. Проверяем статус
docker compose ps
```

### Установка зависимостей (DEV)

```bash
# Устанавливаем PHP зависимости
docker compose exec app composer install

# Устанавливаем Node.js зависимости
docker compose exec app pnpm install

# Генерируем ключ приложения (если первый запуск)
docker compose exec app php artisan key:generate
```

### Запуск Vite Dev Server (DEV)

```bash
# Запускаем Vite dev server внутри контейнера
docker compose exec app pnpm run dev

# Или локально на хосте (если Node.js установлен)
pnpm install
pnpm run dev
```

**Объяснение:** В DEV режиме код монтируется через volumes, поэтому изменения видны сразу. Vite обеспечивает горячую перезагрузку.

### Выполнение миграций (DEV)

```bash
# Создаем таблицы для очередей (если используете database драйвер)
docker compose exec app php artisan queue:table
docker compose exec app php artisan migrate

# Или с seeding
docker compose exec app php artisan migrate --seed
```

### Настройка прав доступа (DEV)

```bash
# Устанавливаем права на storage и bootstrap/cache
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

---

## 6. PROD: Production деплой

**Используется:** `docker-compose.prod.yml`

**Особенности:**

- Immutable образ (`agelto/app:latest`)
- Нет volumes с кодом (код встроен в образ)
- Минимальный runtime (без Node.js)
- Secrets для безопасного хранения паролей
- Готово для CI/CD

### Подготовка секретов (PROD)

**Важно:** В PROD используем secrets вместо переменных в `.env` для безопасности.

```bash
# Создаем директорию для secrets
mkdir -p secrets

# Генерируем пароли и сохраняем в файлы
echo "agelto_password" > secrets/db_password.txt
echo "root_password_here" > secrets/db_root_password.txt

# Генерируем APP_KEY (можно использовать временный контейнер)
docker run --rm -v $(pwd):/app -w /app serversideup/php:8.4-cli php artisan key:generate --show
# Скопируйте вывод и сохраните:
echo "base64:your_generated_key_here" > secrets/app_key.txt

# Устанавливаем безопасные права
chmod 600 secrets/*.txt
chown deploy:deploy secrets/*.txt
```

**Объяснение:**

- Secrets хранятся в отдельных файлах, не в `.env`
- Файлы secrets должны иметь права 600 (только владелец может читать)
- Secrets автоматически монтируются в контейнеры через Docker secrets

### Создание .env для PROD

```bash
# Создаем .env для PROD (без секретов)
nano .env
```

```env
APP_NAME=agelto
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

# База данных (имена переменных, значения из secrets)
DB_DATABASE=agelto
DB_USERNAME=agelto

# Пароли будут браться из secrets/
# DB_PASSWORD - из secrets/db_password.txt
# DB_ROOT_PASSWORD - из secrets/db_root_password.txt
# APP_KEY - из secrets/app_key.txt

# Настройки портов
APP_PORT=80
MYSQL_PORT=3306

# Cache / Session / Queue (для PROD рекомендуется Redis)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

**Примечание:** В `docker-compose.prod.yml` переменные из `.env` используются, но пароли должны быть в secrets. Нужно обновить `docker-compose.prod.yml` для использования secrets, или использовать переменные окружения напрямую.

### Сборка PROD образа (первый запуск)

```bash
# Переходим в директорию проекта
cd /var/www/agelto/agelto

# Собираем образ для production (контекст — корень проекта, код копируется в образ)
docker build -t agelto/app:latest -f docker/php/Dockerfile .

# Проверяем, что образ создан
docker images | grep agelto/app
```

**Важно:** Текущий `docker/php/Dockerfile` содержит только базовую настройку PHP. Для PROD нужно добавить копирование кода и установку зависимостей. Пример обновленного Dockerfile:

```dockerfile
FROM serversideup/php:8.4-fpm-nginx

USER root

RUN apt-get update && apt-get install -y \
    libicu-dev \
    && docker-php-ext-install intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Копируем код приложения
COPY ../../ /var/www/html/

# Устанавливаем зависимости (опционально, можно при первом запуске)
# RUN composer install --optimize-autoloader --no-dev

# Устанавливаем права
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
```

**Объяснение:**

- Образ собирается из `docker/php/Dockerfile`
- В образ должен быть встроен код приложения
- Node.js не нужен в PROD, поэтому образ минимальный
- Зависимости можно установить при сборке образа или при первом запуске контейнера

### Запуск PROD окружения

```bash
# 1. Запускаем контейнеры PROD
docker compose -f docker-compose.prod.yml up -d

# 2. Ждем, пока MySQL станет готов
sleep 40

# 3. Проверяем статус
docker compose -f docker-compose.prod.yml ps
```

### Обновление приложения (при каждом обновлении кода)

При каждом обновлении кода выполняйте по порядку:

**Шаг 1. Получение кода**

```bash
cd /var/www/agelto/agelto
git pull origin main
```

**Шаг 2. Сборка образа (обязательно)**

Текущее состояние кода копируется в образ. Без пересборки работающий сайт не изменится.

```bash
docker build -t agelto/app:latest -f docker/php/Dockerfile .
```

**Шаг 3. Перезапуск контейнеров**

`--force-recreate` заставляет применить новый образ.

```bash
docker compose -f docker-compose.prod.yml up -d --force-recreate
```

**Шаг 4. Настройка внутри контейнера**

```bash
# Миграции БД
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Очистка и оптимизация кэша
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear

# Права на storage и bootstrap/cache (логи и кэш)
docker compose -f docker-compose.prod.yml exec app chown -R www-data:www-data storage bootstrap/cache
```

### Выполнение миграций (PROD)

```bash
# Выполняем миграции (обязательно с --force для production)
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Если нужно seeding (обычно не рекомендуется в PROD)
# docker compose -f docker-compose.prod.yml exec app php artisan migrate --seed --force
```

**Объяснение:**

- В PROD обязательно использовать `--force`, чтобы Laravel не блокировал миграции
- Seeding обычно не выполняется в production

### Оптимизация Laravel (PROD)

```bash
# Кэширование конфигурации
docker compose -f docker-compose.prod.yml exec app php artisan config:cache

# Кэширование маршрутов
docker compose -f docker-compose.prod.yml exec app php artisan route:cache

# Кэширование представлений
docker compose -f docker-compose.prod.yml exec app php artisan view:cache

# Оптимизация автозагрузчика
docker compose -f docker-compose.prod.yml exec app composer dump-autoload --optimize --no-dev
```

---

## 7. Установка и настройка Nginx (Reverse Proxy)

На хосте (вне Docker) Nginx принимает трафик на портах 80 и 443 и перенаправляет его в контейнеры. После настройки SSL (п. 8) Certbot сам изменит конфиг, добавив HTTPS.

**1. Установка Nginx**

```bash
sudo apt update
sudo apt install nginx -y
```

**2. Создание конфигурации сайта**

```bash
sudo nano /etc/nginx/sites-available/agelto
```

**3. Содержимое конфигурации**

Вставьте блок ниже, заменив `yourdomain.com` на ваш домен. Порт в `proxy_pass` для `location /` должен совпадать с `APP_PORT` в .env (в примере — 8081):

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name yourdomain.com; # Укажите ваш домен

    # Основное приложение (Laravel)
    location / {
        proxy_pass http://127.0.0.1:8081; # APP_PORT из .env
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    # Laravel Reverb (WebSockets)
    location /app {
        proxy_pass http://127.0.0.1:8000; # Порт Reverb
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

**4. Активация сайта**

```bash
sudo ln -s /etc/nginx/sites-available/agelto /etc/nginx/sites-enabled/
sudo nginx -t   # Проверка синтаксиса
sudo systemctl restart nginx
```

При необходимости отключите конфиг по умолчанию: `sudo rm -f /etc/nginx/sites-enabled/default`.

---

## 8. Настройка SSL (Let's Encrypt)

Чтобы сайт работал по HTTPS:

**1. Установка Certbot**

```bash
sudo apt install certbot python3-certbot-nginx -y
```

**2. Получение сертификата**

```bash
sudo certbot --nginx -d yourdomain.com
```

Следуйте подсказкам: введите email и выберите **Redirect** (автоматическое перенаправление с HTTP на HTTPS).

**Важно:** После установки SSL Certbot сам изменит файл `/etc/nginx/sites-available/agelto`, добавив пути к сертификатам и настройки HTTPS.

Для нескольких доменов: `sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com`

### Автоматическое обновление сертификата

```bash
# Проверяем, что автообновление настроено
sudo systemctl status certbot.timer

# Тестируем обновление (dry-run)
sudo certbot renew --dry-run
```

**Объяснение:** Certbot автоматически обновляет сертификаты перед истечением.

---

## 9. Запуск приложения

### Запуск контейнеров

```bash
# Переключаемся на пользователя deploy
su - deploy
cd /var/www/agelto/agelto

# Убедитесь, что .env файл настроен правильно
# Особенно важно: DB_ROOT_PASSWORD должен быть установлен

# Запускаем контейнеры
docker compose up -d --build

# Ждем, пока MySQL станет готов (около 30 секунд)
echo "Ожидание готовности MySQL..."
sleep 30

# Проверяем статус
docker compose ps

# Все контейнеры должны быть в статусе "Up"
# MySQL должен быть "healthy"
```

**Объяснение:**

- `-d` - запуск в фоновом режиме
- `--build` - пересборка образов
- Ждем готовности MySQL перед выполнением миграций

### Выполнение миграций

```bash
# Если AUTORUN_ENABLED=false, выполняем вручную
docker compose exec app php artisan migrate --force

# Если используется seeding
docker compose exec app php artisan db:seed --force
```

**Объяснение:**

- `--force` - обязательно для production
- Миграции создают структуру базы данных

### Оптимизация Laravel

```bash
# Кэширование конфигурации
docker compose exec app php artisan config:cache

# Кэширование маршрутов
docker compose exec app php artisan route:cache

# Кэширование представлений
docker compose exec app php artisan view:cache

# Оптимизация автозагрузчика
docker compose exec app composer dump-autoload --optimize
```

**Объяснение:** Кэширование значительно ускоряет работу приложения в production.

### Проверка работы

```bash
# Проверяем логи
docker compose logs app

# Проверяем статус контейнеров
docker compose ps

# Проверяем доступность
curl -I http://localhost:8080
```

---

## 10. Полезные команды

### Сравнение команд DEV vs PROD

| Действие           | DEV                                           | PROD                                                                             |
| ------------------ | --------------------------------------------- | -------------------------------------------------------------------------------- |
| Поднять контейнеры | `docker compose up -d`                        | `docker compose -f docker-compose.prod.yml up -d`                                |
| Проверка статуса   | `docker compose ps`                           | `docker compose -f docker-compose.prod.yml ps`                                   |
| Миграции           | `docker compose exec app php artisan migrate` | `docker compose -f docker-compose.prod.yml exec app php artisan migrate --force` |
| Vite dev server    | `docker compose exec app pnpm run dev`        | ❌ не нужен                                                                      |
| Логи               | `docker compose logs -f`                      | `docker compose -f docker-compose.prod.yml logs -f`                              |
| Остановка          | `docker compose down`                         | `docker compose -f docker-compose.prod.yml down`                                 |
| Перезапуск         | `docker compose restart`                      | `docker compose -f docker-compose.prod.yml restart`                              |
| Сборка образа      | `docker compose build`                        | `docker build -t agelto/app:latest -f docker/php/Dockerfile .`                   |

### Управление контейнерами

**DEV:**

```bash
# Остановка
docker compose down

# Запуск
docker compose up -d

# Перезапуск
docker compose restart

# Просмотр логов
docker compose logs -f app
docker compose logs -f mysql
docker compose logs -f queue
docker compose logs -f scheduler
docker compose logs -f reverb
```

**PROD:**

```bash
# Остановка
docker compose -f docker-compose.prod.yml down

# Запуск
docker compose -f docker-compose.prod.yml up -d

# Перезапуск
docker compose -f docker-compose.prod.yml restart

# Просмотр логов
docker compose -f docker-compose.prod.yml logs -f app
docker compose -f docker-compose.prod.yml logs -f mysql
docker compose -f docker-compose.prod.yml logs -f queue
docker compose -f docker-compose.prod.yml logs -f scheduler
docker compose -f docker-compose.prod.yml logs -f reverb
```

### Отладка PROD

```bash
# Логи приложения в реальном времени
docker compose -f docker-compose.prod.yml logs -f app

# Проверка доступности внутри контейнера
docker compose -f docker-compose.prod.yml exec app curl -I http://localhost:8080

# Проверка прав и файлов в контейнере
docker compose -f docker-compose.prod.yml exec app ls -la storage bootstrap/cache
```

### Работа с базой данных

```bash
# Подключение к MySQL
docker compose exec mysql mysql -u agelto -p agelto

# Создание бэкапа
docker compose exec mysql mysqldump -u agelto -p agelto > backup_$(date +%Y%m%d_%H%M%S).sql

# Восстановление из бэкапа
docker compose exec -T mysql mysql -u agelto -p agelto < backup.sql
```

### Обновление приложения

**DEV:**

```bash
# Переходим в директорию проекта
cd /var/www/agelto/agelto

# Получаем последние изменения
git pull

# Обновляем зависимости
docker compose exec app composer install
docker compose exec app pnpm install
docker compose exec app pnpm run build

# Выполняем миграции
docker compose exec app php artisan migrate

# Очищаем кэш
docker compose exec app php artisan optimize:clear

# Перезапускаем контейнеры
docker compose restart
```

**PROD:**

```bash
# Переходим в директорию проекта
cd /var/www/agelto/agelto

# Получаем последние изменения
git pull

# Пересобираем образ
docker build -t agelto/app:latest ./docker/php

# Перезапускаем контейнеры (образ обновится автоматически)
docker compose -f docker-compose.prod.yml up -d --force-recreate

# Выполняем миграции
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# Очищаем и пересоздаем кэш
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan config:cache
docker compose -f docker-compose.prod.yml exec app php artisan route:cache
docker compose -f docker-compose.prod.yml exec app php artisan view:cache
```

### Мониторинг

```bash
# Использование ресурсов
docker stats

# Проверка дискового пространства
df -h
docker system df

# Очистка неиспользуемых ресурсов
docker system prune -a
```

### Настройка файрвола

```bash
# Разрешаем SSH
ufw allow 22/tcp

# Разрешаем HTTP и HTTPS
ufw allow 80/tcp
ufw allow 443/tcp

# Включаем файрвол
ufw enable

# Проверяем статус
ufw status
```

**Объяснение:** Файрвол защищает сервер от нежелательных подключений.

---

## Дополнительные рекомендации

### Резервное копирование

Настройте автоматическое резервное копирование базы данных:

```bash
# Создаем скрипт бэкапа
nano /usr/local/bin/backup-agelto.sh
```

```bash
#!/bin/bash
BACKUP_DIR="/var/backups/agelto"
DATE=$(date +%Y%m%d_%H%M%S)
mkdir -p $BACKUP_DIR

# Бэкап базы данных
docker compose -f /var/www/agelto/agelto/docker-compose.yml exec -T mysql mysqldump -u agelto -p'PASSWORD' agelto > $BACKUP_DIR/db_$DATE.sql

# Бэкап файлов (опционально)
tar -czf $BACKUP_DIR/files_$DATE.tar.gz /var/www/agelto/agelto/storage

# Удаляем старые бэкапы (старше 30 дней)
find $BACKUP_DIR -type f -mtime +30 -delete
```

```bash
# Делаем скрипт исполняемым
chmod +x /usr/local/bin/backup-agelto.sh

# Добавляем в cron (ежедневно в 2:00)
crontab -e
# Добавьте строку:
# 0 2 * * * /usr/local/bin/backup-agelto.sh
```

### Мониторинг логов

Настройте ротацию логов:

```bash
# Создаем конфигурацию logrotate
nano /etc/logrotate.d/agelto/cms
```

```
/var/www/agelto/agelto/storage/logs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

### Обновление системы

Настройте автоматические обновления безопасности:

```bash
apt install -y unattended-upgrades
dpkg-reconfigure -plow unattended-upgrades
```

---

## 11. Решение проблем

> **Важно:** Если вы столкнулись с проблемой, сначала проверьте раздел ниже - возможно, решение уже описано.

### ⚡ Быстрое решение: Пересоздание MySQL с правильным паролем

**Симптомы:**

- Предупреждение `WARN[0000] The "MYSQL_ROOT_PASSWORD" variable is not set`
- Ошибка `service "app" is not running` при выполнении команд
- MySQL контейнер был создан без `DB_ROOT_PASSWORD` в `.env`

**Быстрая инструкция (выполните на вашем сервере):**

```bash
# Переходим в директорию проекта
cd /var/www/agelto/agelto

# 1. Бэкап базы данных (если есть данные)
docker compose exec mysql mysqldump -u root -p'root_password' agelto > /tmp/mysql_backup_$(date +%Y%m%d_%H%M%S).sql 2>/dev/null

# 2. Добавляем DB_ROOT_PASSWORD в .env
echo "" >> .env
echo "DB_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d '=+/' | cut -c1-25)" >> .env

# 3. Останавливаем контейнеры
docker compose down

# 4. Удаляем volume MySQL (удалит все данные!)
docker volume rm cms_mysql_data

# 5. Запускаем контейнеры заново
docker compose up -d

# 6. Ждем готовности MySQL (30-40 секунд)
sleep 40

# 7. Восстанавливаем данные (если был бэкап)
DB_ROOT_PASSWORD=$(grep "^DB_ROOT_PASSWORD=" .env | cut -d '=' -f2)
docker compose exec -T mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto < /tmp/mysql_backup_*.sql 2>&1 | grep -v "Warning"

# 8. Проверяем, что все работает
docker compose exec app composer install --optimize-autoloader --no-dev
```

**Готово!** Теперь MySQL использует пароль из `.env` и команды должны работать.

---

### Подробная инструкция: Пересоздание MySQL контейнера с правильным паролем

**Когда это нужно:** Если при выполнении команд вы видите предупреждение `WARN[0000] The "MYSQL_ROOT_PASSWORD" variable is not set` и MySQL контейнер был создан без указания пароля в `.env`.

**Ситуация:** MySQL контейнер был создан без указания `DB_ROOT_PASSWORD` в `.env`, и теперь нужно пересоздать его с правильным паролем.

**Важно:** Если в базе данных уже есть данные, обязательно сделайте бэкап перед пересозданием!

#### Пошаговая инструкция:

```bash
# 1. Переходим в директорию проекта
cd /var/www/agelto/agelto

# 2. Проверяем, есть ли данные в базе (если есть таблицы - делаем бэкап)
docker compose exec mysql mysql -u root -p'root_password' agelto -e "SHOW TABLES;" 2>&1 | grep -v "Warning"

# 3. Если есть таблицы, создаем бэкап базы данных
docker compose exec mysql mysqldump -u root -p'root_password' agelto > /tmp/mysql_backup_$(date +%Y%m%d_%H%M%S).sql 2>/dev/null

# Проверяем, что бэкап создан
ls -lh /tmp/mysql_backup_*.sql

# 4. Добавляем DB_ROOT_PASSWORD в .env файл
# Генерируем случайный пароль и добавляем в .env
echo "" >> .env
echo "# MySQL Root Password" >> .env
echo "DB_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -d '=+/' | cut -c1-25)" >> .env

# Или вручную отредактируйте .env и добавьте:
# DB_ROOT_PASSWORD=your_strong_password_here

# Проверяем, что пароль добавлен
grep DB_ROOT_PASSWORD .env

# 5. Останавливаем все контейнеры
docker compose down

# 6. Удаляем volume MySQL (ВНИМАНИЕ: это удалит все данные!)
# Сначала узнаем имя volume
docker volume ls | grep mysql

# Удаляем volume (обычно называется cms_mysql_data или agelto_mysql_data)
docker volume rm cms_mysql_data
# Или если называется по-другому:
# docker volume rm $(docker volume ls | grep mysql | awk '{print $2}')

# 7. Запускаем контейнеры заново
docker compose up -d

# 8. Ждем, пока MySQL станет готов (около 30-40 секунд)
echo "Ожидание готовности MySQL..."
sleep 40

# 9. Проверяем статус MySQL (должен быть "healthy")
docker compose ps mysql

# 10. Восстанавливаем данные из бэкапа (если делали бэкап)
# Сначала узнаем пароль из .env
DB_ROOT_PASSWORD=$(grep "^DB_ROOT_PASSWORD=" .env | cut -d '=' -f2)

# Восстанавливаем базу данных
docker compose exec -T mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto < /tmp/mysql_backup_*.sql 2>&1 | grep -v "Warning"

# 11. Проверяем, что данные восстановлены
docker compose exec mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto -e "SHOW TABLES;" 2>&1 | grep -v "Warning"

# 12. Теперь можно выполнять команды
docker compose exec app composer install --optimize-autoloader --no-dev
```

**Объяснение:**

- MySQL инициализируется только при первом запуске контейнера
- Если контейнер уже создан без пароля, нужно удалить volume и пересоздать контейнер
- Volume содержит все данные MySQL, поэтому его удаление удаляет базу данных
- После пересоздания MySQL будет использовать новый пароль из `.env`
- Бэкап позволяет восстановить данные после пересоздания

**Если бэкап содержит предупреждения Docker:**

Если при восстановлении вы видите ошибки из-за предупреждений Docker в бэкапе, очистите бэкап:

```bash
# Очищаем бэкап от предупреждений Docker
grep -v "^time=" /tmp/mysql_backup_*.sql | \
  grep -v "^WARN" | \
  grep -v "level=warning" | \
  grep -v "docker-compose.yml" > /tmp/mysql_backup_clean.sql

# Восстанавливаем из очищенного бэкапа
DB_ROOT_PASSWORD=$(grep "^DB_ROOT_PASSWORD=" .env | cut -d '=' -f2)
docker compose exec -T mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto < /tmp/mysql_backup_clean.sql 2>&1 | grep -v "Warning"
```

### Ошибка "Container is restarting" или "service 'app' is not running"

**Проблема:** Контейнер постоянно перезапускается или не запускается.

**Диагностика:**

```bash
# Проверяем логи контейнера
docker compose logs --tail=100 app

# Или для PROD
docker compose -f docker-compose.prod.yml logs --tail=100 app

# Проверяем статус контейнеров
docker compose ps
# Или для PROD
docker compose -f docker-compose.prod.yml ps
```

**Частые причины:**

1. **DEV:** Образ `serversideup/php` проверяет наличие Laravel при старте. Если зависимости не установлены (`vendor/` отсутствует), контейнер падает.
2. **PROD:** Образ не собран или код не встроен в образ.
3. Ошибки в коде приложения.
4. Проблемы с правами доступа.
5. Проблемы с подключением к базе данных.
6. Неправильная конфигурация `.env`.

**Решение для DEV:**

```bash
# 1. Останавливаем контейнер app
docker compose stop app

# 2. Запускаем только MySQL (если не запущен)
docker compose up -d mysql
sleep 30

# 3. Запускаем app
docker compose up -d app
sleep 5

# 4. Устанавливаем зависимости
docker compose exec app composer install
docker compose exec app pnpm install
docker compose exec app pnpm run build

# 5. Перезапускаем
docker compose restart app
```

**Решение для PROD:**

```bash
# 1. Проверяем, что образ собран
docker images | grep agelto/app

# 2. Если образа нет, собираем его
docker build -t agelto/app:latest ./docker/php

# 3. Проверяем логи
docker compose -f docker-compose.prod.yml logs --tail=100 app

# 4. Пересоздаем контейнеры
docker compose -f docker-compose.prod.yml up -d --force-recreate

# 5. Проверяем статус
docker compose -f docker-compose.prod.yml ps
```

**Диагностика:**

```bash
# 1. Проверяем статус контейнеров
docker compose ps

# 2. Смотрим логи контейнера app (последние 50 строк)
docker compose logs --tail=50 app

# 3. Смотрим логи в реальном времени
docker compose logs -f app

# 4. Проверяем, не заняты ли порты
netstat -tulpn | grep -E '8080|3307|8000|5174'
```

**Частые причины и решения:**

#### Причина 1: Контейнер падает из-за ошибки в коде/конфигурации

```bash
# Смотрим детальные логи
docker compose logs app

# Останавливаем контейнеры
docker compose down

# Запускаем заново с просмотром логов
docker compose up app
# Нажмите Ctrl+C после просмотра ошибки
```

#### Причина 2: Проблемы с правами доступа

```bash
# Проверяем права на директории
ls -la storage bootstrap/cache

# Исправляем права
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

#### Причина 3: Проблемы с базой данных

```bash
# Проверяем, что MySQL запущен и здоров
docker compose ps mysql

# Проверяем подключение к базе
docker compose exec app php artisan db:show

# Если MySQL не запущен, запускаем его отдельно
docker compose up -d mysql
sleep 30
docker compose ps mysql
```

#### Причина 4: Неправильная конфигурация .env

```bash
# Проверяем основные переменные
grep -E "^APP_|^DB_|^DB_ROOT_PASSWORD" .env

# Убедитесь, что DB_HOST=mysql (не 127.0.0.1!)
# Убедитесь, что DB_ROOT_PASSWORD установлен
```

**Решение:**

```bash
# 1. Останавливаем все контейнеры
docker compose down

# 2. Проверяем конфигурацию
docker compose config

# 3. Запускаем контейнеры заново
docker compose up -d

# 4. Ждем, пока контейнеры запустятся
sleep 10

# 5. Проверяем статус
docker compose ps

# 6. Если контейнер все еще перезапускается, смотрим логи
docker compose logs --tail=100 app
```

**Объяснение:**

- Контейнер перезапускается, если процесс внутри него завершается с ошибкой
- Логи покажут причину ошибки
- Часто проблема в конфигурации, правах доступа или подключении к базе данных

### Предупреждение "MYSQL_ROOT_PASSWORD variable is not set"

**Проблема:** В `.env` файле не установлена переменная `DB_ROOT_PASSWORD`, и MySQL контейнер уже создан с дефолтным паролем.

**Решение:**

**Важно:** Если в базе данных уже есть данные, сначала сделайте бэкап!

#### Шаг 1: Бэкап базы данных (если есть данные)

```bash
# Проверяем, есть ли данные в базе
docker compose exec mysql mysql -u root -p'root_password' agelto -e "SHOW TABLES;" 2>&1 | grep -v "Warning"

# Если есть таблицы, делаем бэкап
docker compose exec mysql mysqldump -u root -p'root_password' agelto > backup_before_password_change_$(date +%Y%m%d_%H%M%S).sql

# Проверяем, что бэкап создан
ls -lh backup_before_password_change_*.sql
```

#### Шаг 2: Добавление DB_ROOT_PASSWORD в .env

```bash
# Добавляем в .env файл (используйте сильный пароль!)
echo "DB_ROOT_PASSWORD=your_strong_root_password_here" >> .env

# Или редактируем вручную
nano .env
# Добавьте строку: DB_ROOT_PASSWORD=your_strong_root_password_here
```

#### Шаг 3: Пересоздание MySQL контейнера

```bash
# Останавливаем все контейнеры
docker compose down

# Удаляем volume MySQL (ВНИМАНИЕ: это удалит все данные!)
docker volume rm cms_mysql_data
# Или если volume называется по-другому:
# docker volume ls | grep mysql
# docker volume rm <имя_volume>

# Запускаем контейнеры заново
docker compose up -d

# Ждем, пока MySQL станет готов
sleep 30

# Проверяем статус
docker compose ps
```

#### Шаг 4: Восстановление данных (если делали бэкап)

```bash
# Восстанавливаем базу данных из бэкапа
docker compose exec -T mysql mysql -u root -p'your_strong_root_password_here' agelto < backup_before_password_change_*.sql

# Или если используете переменную из .env:
# Сначала узнаем пароль из .env
DB_ROOT_PASSWORD=$(grep "^DB_ROOT_PASSWORD=" .env | cut -d '=' -f2)
docker compose exec -T mysql mysql -u root -p"${DB_ROOT_PASSWORD}" agelto < backup_before_password_change_*.sql
```

**Объяснение:**

- MySQL инициализируется только при первом запуске контейнера
- Если контейнер уже создан без пароля, нужно пересоздать его
- Удаление volume удаляет все данные, поэтому важен бэкап
- После пересоздания MySQL будет использовать новый пароль из `.env`

### Контейнеры не запускаются

```bash
# Проверяем логи
docker compose logs

# Проверяем статус
docker compose ps

# Проверяем использование ресурсов
docker stats

# Проверяем, не заняты ли порты
netstat -tulpn | grep -E '8080|3307|8000|5174'
```

### Проблемы с правами доступа

```bash
# Исправляем права
docker compose exec app chown -R www-data:www-data storage bootstrap/cache
docker compose exec app chmod -R 775 storage bootstrap/cache
```

### Проблемы с базой данных

```bash
# Проверяем подключение
docker compose exec app php artisan db:show

# Проверяем логи MySQL
docker compose logs mysql
```

### Очистка кэша

```bash
# Очистка всех кэшей Laravel
docker compose exec app php artisan optimize:clear

# Очистка кэша конфигурации
docker compose exec app php artisan config:clear
```

---

## 12. Полная очистка (начать с нуля)

**Когда использовать:** Если нужно полностью удалить все контейнеры, volumes, образы и начать установку заново на пустом сервере или после неудачной установки.

**Внимание:** Это удалит все контейнеры, volumes, образы и данные базы данных!

### Очистка проекта (если проект уже установлен)

**DEV:**

```bash
# Переходим в директорию проекта (если она существует)
cd /var/www/agelto/agelto

# 1. Останавливаем и удаляем все контейнеры проекта
docker compose down

# 2. Удаляем все volumes (включая базу данных)
docker compose down -v
```

**PROD:**

```bash
# Переходим в директорию проекта
cd /var/www/agelto/agelto

# 1. Останавливаем и удаляем все контейнеры проекта
docker compose -f docker-compose.prod.yml down

# 2. Удаляем все volumes (включая базу данных)
docker compose -f docker-compose.prod.yml down -v

# 3. Удаляем образ проекта
docker rmi agelto/app:latest

# 4. Проверяем, что все удалено
docker compose -f docker-compose.prod.yml ps
docker volume ls | grep -E 'cms|agelto'
docker images | grep -E 'cms|agelto'
```

### Полная очистка Docker (Hard Reset)

Если нужно удалить всё и начать с нуля (внимание: удалит данные БД проекта и неиспользуемые ресурсы Docker):

```bash
# 1. Остановка проекта и удаление томов
docker compose -f docker-compose.prod.yml down -v

# 2. Удаление образа проекта
docker rmi agelto/app:latest

# 3. Полная очистка неиспользуемых ресурсов Docker
docker system prune -a --volumes -f
```

### Полная очистка Docker (для пустого сервера или полного сброса)

```bash
# 1. Останавливаем все контейнеры Docker
docker stop $(docker ps -aq) 2>/dev/null || true

# 2. Удаляем все контейнеры
docker rm $(docker ps -aq) 2>/dev/null || true

# 3. Удаляем все volumes
docker volume rm $(docker volume ls -q) 2>/dev/null || true

# 4. Удаляем все образы
docker rmi $(docker images -q) 2>/dev/null || true

# 5. Полная очистка неиспользуемых ресурсов Docker
docker system prune -a --volumes -f

# 6. Проверяем, что все очищено
docker ps -a
docker volume ls
docker images
```

**Объяснение:**

- `docker compose down` - останавливает и удаляет контейнеры проекта
- `docker compose down -v` - также удаляет volumes проекта (база данных)
- `docker compose down --rmi all` - также удаляет образы проекта
- `docker system prune -a --volumes -f` - полная очистка всех неиспользуемых ресурсов Docker
- Команды с `|| true` не вызовут ошибку, если ресурсов нет

**После очистки:**

- Если проект уже был установлен, можно начать с раздела "Настройка .env файла"
- Если это пустой сервер, начните с раздела "1. Подготовка сервера"

---

## Советы по безопасности

- **APP_DEBUG:** В production всегда указывайте `APP_DEBUG=false` в `.env`.
- **База данных:** Не открывайте порт MySQL (3306) наружу. Доступ к БД только внутри Docker-сети.
- **SSL:** Для работы по HTTPS на реальном домене используйте Nginx + Let's Encrypt (см. раздел 8), Nginx Proxy Manager или Cloudflare.
- Храните `.env` и файлы в `secrets/` в безопасности; используйте сильные пароли; регулярно обновляйте зависимости и систему.

---

## Заключение

После выполнения всех шагов ваше приложение должно быть доступно по адресу `https://yourdomain.com`.

**Важные моменты:**

- ✅ Используйте отдельного пользователя (не root)
- ✅ Настройте SSL сертификаты
- ✅ Включите файрвол
- ✅ Настройте резервное копирование
- ✅ Регулярно обновляйте систему
- ✅ В PROD после каждого обновления кода пересобирайте образ и перезапускайте контейнеры
