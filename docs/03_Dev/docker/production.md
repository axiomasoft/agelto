---
audience: dev
type: howto
status: draft
---

# Docker: production

## Prebuilt image, не build-on-deploy

`docker-compose.prod.yml`: `image: agelto/app:latest` — образ собирается заранее (`make prod-image`/
`make prod:deploy:image`), деплой кода — `pull` + `up -d`, не пересборка на сервере при каждом
деплое. `vendor` — именованный volume `vendor_data` (не bind-mount, в отличие от dev), наполняется
`make prod:composer-install` (`composer install --no-dev --no-interaction --prefer-dist
--optimize-autoloader --no-scripts` + `package:discover`). `public/build` (фронтенд-ассеты) — тоже
через volume, собирается вне runtime-образа отдельным шагом сборки фронтенда (см. `getting-started.md`
про yarn — тот же принцип: сборка не внутри `docker/php/Dockerfile`).

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
