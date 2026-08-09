---
audience: dev
type: reference
status: living
---

# Docker: стек

## Сервисы (`docker-compose.yml`/`docker-compose.prod.yml`, project `agelto_v2`)

| Сервис | Роль |
|:--|:--|
| `app` | PHP-FPM+Nginx (serversideup/php), HTTP + `/up` healthcheck |
| `horizon` | Queue worker (`php artisan horizon`) — тяжёлые jobs пайплайна (`doc_prepare`/`ai_screening`/`ai_extraction`), в prod несёт `shm_size: 2gb` под будущий Chromium (browser automation — канон CLAUDE.md §Docker/Runtime: только worker-контейнер) |
| `scheduler` | `php artisan schedule:work` — крон-задачи (`pipeline:*`, `ai:*` по расписанию) |
| `postgres` | `agelto_postgres:17-partman` — Postgres 17 + `pg_partman` (партиционирование `llm_calls`/`fetches`/`operation_logs`/`events`) |
| `redis` | Cache/session/queue (`QUEUE_CONNECTION=redis`); prod — AOF durability (D34, см. `production.md`) |
| `minio` | S3-совместимое хранилище (`FILESYSTEM_DISK=s3`) — документы, LLM-блобы |
| `browser` | Задел под будущий Node+Playwright рендер-сервис площадок с клиентским рендерингом (не на критическом пути сегодня — ЕИС HTTP-first) |

## Runtime-образ

`docker/php/Dockerfile` — лёгкий: PHP-расширения + Node/yarn, **БЕЗ** `composer install` внутри
(канон CLAUDE.md §Docker/Runtime). `vendor` и `public/build` — через volumes:
`app_bootstrap_cache`/`app_storage_framework` (dev, именованные) или bind-mount `./storage/framework`
(prod); `vendor` — bind-mount в dev, именованный volume `vendor_data` в prod, наполняется
`make prod:composer-install`/`make prod:deploy:frontend`-эквивалентом.

## Здоровье

`healthcheck` на `app`/`horizon`/`scheduler` — `curl /up`, `horizon:status | grep running`,
`pgrep schedule:work` соответственно; `postgres`/`redis`/`minio` — штатные `pg_isready`/`redis-cli
ping`/MinIO `/minio/health/live`.

## Смежное

- [`development.md`](development.md) — dev-режим, bind-mount, override.
- [`production.md`](production.md) — prebuilt image, Swarm secrets, деплой с живыми очередями.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои контейнеров.
- `../getting-started.md` — первый запуск.
