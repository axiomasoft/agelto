# Production (Docker Compose)

Выкладка production через `docker-compose.prod.yml`.

## Типовой сценарий

```bash
make image
docker compose -f docker-compose.prod.yml up -d --force-recreate
make prod:release
make prod:db-migrate
```

## После `git pull`

- Если менялся код/зависимости: `make prod:deploy:image` (или `make prod:release`).
- Если только фронт: `make prod:deploy:frontend`.
- Если только Composer: `make prod:deploy:php-deps`.
- Если только код без deps/assets: `make prod:deploy:code`.

Проверка состояния: `make prod:doctor`.

## Порты

- Внутри контейнера приложение слушает **8080**.
- Снаружи проброс: `"${APP_PORT:-8080}:8080"`.
- Reverb: `"${REVERB_SERVER_PORT:-8000}:${REVERB_SERVER_PORT:-8000}"`.

## Horizon

В Agelto вместо `queue:work` используется **Laravel Horizon** (`horizon`-сервис). Перезапуск воркеров:

```bash
make prod:workers:restart
```
