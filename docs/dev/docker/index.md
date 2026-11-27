---
audience: dev
type: reference
status: living
---

# Docker: стек

## Сервисы (`docker-compose.yml`/`docker-compose.prod.yml`)

| Сервис | Роль |
|:--|:--|
| `app` | PHP-FPM+Nginx (serversideup/php), HTTP + `/up` healthcheck |
| `horizon` | Queue worker (`php artisan horizon`) — `ExecuteWorkJob` в очередях `process-listing`, `process-acquisition`, `process-download`, `process-prepare`, `process-ai`, `process-publication`; отдельная default queue для остальных jobs. Browser automation выполняется в worker-контейнере |
| `scheduler` | `php artisan schedule:work` — acquisition schedule, retention, health report, partition maintenance и scheduler heartbeat |
| `process-relay`, `process-reconciler`, `process-feeder`, `process-projector` | Отдельные `process:daemon` для outbox delivery, recovery, admission и проекций; readiness проверяет их durable heartbeat |
| `postgres` | `agelto_postgres:17-partman` — Postgres 17 + `pg_partman` (партиционирование `llm_calls`/`fetches`/`operation_logs`/`events`) |
| `redis` | Cache/session/queue (`QUEUE_CONNECTION=redis`); prod — AOF durability (D34, см. `production.md`) |
| `minio` | S3-совместимое хранилище (`FILESYSTEM_DISK=s3`) — документы, LLM-блобы |
| `browser` | Задел под будущий Node+Playwright рендер-сервис площадок с клиентским рендерингом (не на критическом пути сегодня — ЕИС HTTP-first) |

## Runtime-образ

Dev-сервисы собирают именованную цель `development`. Исходники — bind mount с хоста;
bootstrap/cache, runtime фреймворка и Pest Tia — именованные volumes в рамках проекта.
`COMPOSE_PROJECT_NAME` по умолчанию `agelto_dev`; к сервисам обращаются через Compose, фиксированный
`container_name` не используется.

Production использует стадию `application` без root и требует явных `COMPOSE_PROJECT_NAME` и
неизменяемого `IMAGE_TAG`; исходники и `vendor` никогда не монтируются с хоста. Приватные
зависимости Composer и итоговая готовность ассетов фреймворка проверяются отдельными gates, а не
считаются свойствами самого образа. Команды релиза production выбирают явный класс инвалидации
(`code`, `php-deps`, `frontend`, `runtime`, `image-promotion`), но все пути используют готовый тег и
`--no-build`.

## Здоровье

`healthcheck` на `app`/`horizon`/`scheduler` — `curl /up`, `horizon:status | grep running`,
`pgrep schedule:work` соответственно; `postgres`/`redis`/`minio` — штатные `pg_isready`/`redis-cli
ping`/MinIO `/minio/health/live`.

## Смежное

- [`development.md`](development.md) — dev-режим, bind-mount, override.
- [`production.md`](production.md) — prebuilt image, Swarm secrets, деплой с живыми очередями.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои контейнеров.
- `../getting-started.md` — первый запуск.
- [текущий inventory команд](../../operators/production/commands.md#current-command-inventory)
  — сгенерированный реестр всех публичных Make targets и P12 script modes.
