# Docker Compose: production

Подготовка **хоста** (ОС, пользователь, Docker Engine, UFW, Nginx) — [setup.md](./setup.md).  
Процесс деплоя (git pull, сценарии) — [deploy.md](./deploy.md).

## Модель развёртывания

Лёгкий runtime-образ `agelto/app:latest` (PHP 8.5, nginx, Node/yarn, Chromium). **Код с хоста**
(`git pull`), зависимости и сборки в именованных volumes. Образ собирается из
`docker/php/Dockerfile`.

Все сервисы (`app`, `horizon`, `scheduler`, `reverb`) используют один образ. Horizon заменяет
`queue:work` — обеспечивает мониторинг и управление очередями через Filament.

## Make-команды production

| Сценарий | Команда |
|----------|---------|
| Обычный деплой после `git pull` | `make prod:deploy:code` |
| Composer / фронт / миграции | `make prod:deploy:runtime` |
| Смена PHP-расширений / Dockerfile | `make prod:deploy:image` |
| Только Composer | `make prod:deploy:php-deps` |
| Только фронт | `make prod:deploy:frontend` |
| Перезапуск всех сервисов | `make prod:restart-services` |
| Перезапуск только воркеров | `make prod:workers:restart` |
| Сброс dev-артефактов | `make prod-pre-clean` |
| Проверка | `make prod:doctor` |

Makefile читает переменные из двух файлов: `.env` (приложение) и `.env.docker.production`
(порты хоста). Если `.env.docker.production` отсутствует — любая `prod:*` цель завершится с
ошибкой и подсказкой по копированию.

## Конфигурационные файлы

| Файл | Git | Назначение |
|------|-----|-----------|
| `.env` | игнор | Все переменные Laravel (DB, API, APP_URL, ключи) |
| `.env.docker.production` | игнор | Порты хоста для prod (APP_PORT, PGSQL_PORT и т.д.) |
| `.env.docker.production.example` | коммит | Шаблон — скопировать и заполнить |

Если `.env.docker.production` отсутствует при запуске любой `prod:*` команды, Makefile выведет:

```
Ошибка: .env.docker.production не найден.
Скопируйте: cp .env.docker.production.example .env.docker.production
```

Различие с dev: `.env.docker` используется только для `docker-compose.yml` (dev-стек),
`.env.docker.production` — только для `docker-compose.prod.yml`. Порты не пересекаются.

## Docker Secrets

Docker Swarm Secrets позволяют хранить чувствительные значения (`APP_KEY`, `DB_PASSWORD`,
`REDIS_PASSWORD`) в зашифрованном виде в overlay-сети, не в plain-text env.

### Как это работает

1. На сервере создаются Swarm secrets командами `docker secret create`.
2. В `docker-compose.prod.yml` secrets объявлены как `external: true` и подключены ко всем
   сервисам (`app`, `horizon`, `scheduler`, `reverb`).
3. Docker монтирует содержимое secrets в `/run/secrets/<name>` внутри контейнера.
4. Entrypoint-скрипт `docker/php/entrypoint.d/10-secrets.sh` читает файлы и экспортирует
   их как переменные окружения (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`) до запуска PHP-FPM.

### Создание secrets на сервере (один раз)

```bash
# Инициализировать Swarm (если не инициализирован)
docker swarm init

# Создать secrets
echo "base64:YOUR_APP_KEY==" | docker secret create app_key -
echo "your_db_password"      | docker secret create db_password -
echo "your_redis_password"   | docker secret create redis_password -

# Проверить
docker secret ls
```

### Обновление secret

```bash
# Удалить старый (сначала убедиться что сервис остановлен)
docker secret rm app_key

# Создать новый
echo "base64:NEW_APP_KEY==" | docker secret create app_key -

# Перезапустить сервисы
make prod:restart-services
```

### Работа без Swarm (обычный docker compose)

При `make up ENV=prod` без `docker stack deploy` secrets не активируются, `external: true`
блок игнорируется. В этом случае `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD` читаются из `.env`
как обычно — entrypoint-скрипт выполняет noop (файлов `/run/secrets/*` нет).

## Первый запуск

```bash
# Под пользователем deploy, в каталоге проекта

# 1. Настроить окружение приложения
cp .env.example .env
nano .env     # APP_URL, DB_*, API-ключи, APP_KEY (если без Swarm secrets)

# 2. Настроить Docker-конфиг production
cp .env.docker.production.example .env.docker.production
nano .env.docker.production    # APP_PORT (порт на хосте) и другие порты

# 3. (Опционально) Docker Swarm secrets для APP_KEY / DB_PASSWORD
docker swarm init
echo "base64:$(php artisan key:generate --show 2>/dev/null || cat .env | grep APP_KEY | cut -d= -f2)" | docker secret create app_key -
echo "your_db_password" | docker secret create db_password -
echo ""                 | docker secret create redis_password -

# 4. Собрать образ и развернуть
make prod:deploy:image

# 5. Проверка
make prod:doctor
curl -fsS "http://127.0.0.1:${APP_PORT:-8080}/up" && echo OK
```

## Volumes на production

| Mount | Сервисы | Назначение |
|-------|---------|-----------|
| `./` → `/var/www/html` | все | Код приложения с хоста (git pull) |
| `vendor_data` | app, horizon, scheduler | Composer-зависимости |
| `frontend_build` | app | `public/build` (Vite) |
| `frontend_filament` | app | `public/filament` |
| `pgsql_data` | pgsql | Данные PostgreSQL |
| `redis_data` | redis | Данные Redis |

## Horizon (очереди)

В Agelto вместо `queue:work` используется **Laravel Horizon** (`horizon`-сервис). Horizon
обеспечивает мониторинг очередей через Filament.

Перезапуск Horizon (graceful — текущие jobs завершатся):

```bash
make prod:workers:restart
```

Просмотр логов:

```bash
make prod:logs SERVICE=horizon
```

## Troubleshooting

### `make prod:*` падает: `.env.docker.production не найден`

```bash
cp .env.docker.production.example .env.docker.production
nano .env.docker.production   # настроить порты
```

### App unhealthy после recreate

Проверить логи: `docker logs agelto_app --tail 50`.  
Убедиться что в `.env` есть `APP_KEY`. Если используются Swarm secrets — проверить что secrets
созданы: `docker secret ls`.

### PostgreSQL не запускается

```bash
make down ENV=prod
docker volume rm agelto_pgsql_data   # ВНИМАНИЕ: удаляет данные БД
make up ENV=prod
```

### Vite manifest missing

```bash
make prod:deploy:frontend
```
