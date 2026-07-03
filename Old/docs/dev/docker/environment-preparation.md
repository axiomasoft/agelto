# Подготовка окружения

## Файлы окружения

| Файл | Назначение |
|------|------------|
| `.env` | Переменные приложения (DB, Redis, APP_URL, ключи API) |
| `.env.docker` | Порты хоста, PUID/PGID (скопировать из `.env.docker.example`) |

Makefile автоматически подхватывает оба файла, если `.env.docker` существует.

## Первый запуск

```bash
cp .env.example .env
cp .env.docker.example .env.docker
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
make frontend-dev
```

## Reverb

```bash
docker compose exec app php artisan reverb:install
make reverb-restart
```

## Тестовая БД (PostgreSQL)

При первом создании тома `pgsql_data` создаётся `agelto_test`. Для существующего тома:

```bash
make db:create-test
```
