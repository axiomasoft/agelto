---
audience: dev
type: howto
status: living
---

# Docker: production

## Prebuilt image, не build-on-deploy

`docker-compose.prod.yml` требует оба значения: `COMPOSE_PROJECT_NAME` и неизменяемый `IMAGE_TAG`.
`app`, `horizon` и `scheduler` используют ровно `agelto/app:${IMAGE_TAG}`; production host выполняет
только `pull` + `up -d`, а не build. В production нет host bind-mount для source или `vendor` и нет
`container_name`, поэтому два Compose-проекта изолированы. Образ имеет stages `runtime-base` и
`application`, запускается как `www-data`; private Composer closure остаётся gate P2, а production
frontend artifact — gate P1.4/P3. P1 ещё не является доказательством готовности релиза.

## Секреты (Docker Swarm)

Чувствительные переменные (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MINIO_ROOT_PASSWORD`) —
через `secrets:` (Docker Swarm secrets, `/run/secrets/*`, читаются `entrypoint.d/10-secrets.sh`).
Работает ТОЛЬКО с `docker stack deploy` (Swarm mode); без Swarm (`make up ENV=prod` без stack
deploy) — переменные берутся из `.env` как обычно, блок `secrets` неактивен. Создание секрета на
сервере: `echo "value" | docker secret create app_key -`.

## Деплой с живыми очередями (D26)

Полная последовательность и разновидности (`prod:deploy`/`:code`/`:runtime`/`:image`) —
[`../process/deploy.md`](../process/deploy.md), не дублируется здесь. Ключевой prod-специфичный
момент: `stop_grace_period` контейнера `horizon` — `${HORIZON_STOP_GRACE_PERIOD:-600s}` (Docker
default 10s убил бы 300+с job); инвариант `job timeout < supervisor timeout < queue.retry_after` —
`../runbooks/horizon-backlog.md`.

## Redis durability (D34)

`command: ["redis-server", "--appendonly", "yes", "--appendfsync", "everysec", "--maxmemory-policy",
"noeviction"]` — без этого дефолтный `redis:7-alpine` живёт только на периодических RDB-снапшотах,
crash/OOM теряет очередь Horizon (до часа работы пайплайна). `noeviction` — очередь задач, ключи
нельзя вытеснять под memory-pressure как обычный кэш.

## Бэкапы (P7.7, D33)

`make db:backup ENV=prod` — `pg_dump` + ОБЯЗАТЕЛЬНАЯ проверка restore на одноразовую scratch-БД
(`docker/postgres/backup-and-verify.sh`); дамп на bind-mount `./storage/backups` (виден на хосте,
переживает пересоздание контейнера). Off-host копирование/ротация/cron-расписание — инфра-решение
владельца, вне scope этого раздела (открытый вопрос плана EST-AI).

## Смежное

- [`../process/deploy.md`](../process/deploy.md) — команды деплоя одним местом.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои prod-контейнеров.
