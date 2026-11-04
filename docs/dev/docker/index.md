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

Dev services build the named `development` target. Source is a host bind-mount; bootstrap/cache,
framework runtime and Pest Tia are project-scoped named volumes. `COMPOSE_PROJECT_NAME` defaults to
`agelto_dev`; services are addressed through Compose and never use fixed `container_name`.

Production uses the non-root `application` stage and requires an explicit `COMPOSE_PROJECT_NAME` plus
immutable `IMAGE_TAG`; source and `vendor` are never host-mounted. Private Composer dependencies and
final framework asset readiness remain P2/P3 gates, not properties claimed by the P1 image.
Production release commands select an explicit invalidation class (`code`, `php-deps`, `frontend`,
`runtime`, `image-promotion`) but all paths consume a ready tag and use `--no-build`.

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
