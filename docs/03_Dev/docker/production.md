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

## Private Composer packages

Before a build, run `make vendor:sync`. It clones the immutable AzGuard commit declared in
`docker/private-vendors.lock.json` to ignored `local/vendors/azguard`; the checkout is neither a
submodule nor an image runtime artifact. The Dockerfile copies only the allowlisted package paths into
the dependency stage and excludes the raw checkout from the final `application` stage.
Both final images carry the exact manifest SHA as an OCI label and a non-secret build attestation;
the production Doctor validates that attestation and the installed allowlisted packages without
requiring the checkout or `.git` in the image.

## Секреты (file-backed Compose)

Sensitive values (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MINIO_ROOT_PASSWORD`) live only in
ignored `local/secrets/` files and are mounted as explicit Compose `secrets:` grants under
`/run/secrets/*`; `entrypoint.d/10-secrets.sh` reads the declared `*_FILE` paths. `.env.production`
contains non-secret configuration only. Generate missing files with `make secrets:init`; reruns
preserve their bytes. Do not use Swarm secret commands or environment-value fallbacks.

Local Compose preserves the owner and `0600` mode of a mounted file. Image startup therefore uses
a short root-only preload only for the fixed secret paths, then drops to `www-data` before the
ServersideUp entrypoint and application command; the application process never runs as root.

`make bootstrap MODE=prod-build ENV_FILE=.env.production IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=agelto_build`
is safe on a build machine: it validates vendor/secrets/preflight, builds the immutable image and
renders the production contract. It does not provision a host, pull/recreate services, migrate,
rotate credentials, rollback or delete volumes.

## Release classes

Полная матрица команд — [`../process/deploy.md`](../process/deploy.md). Каждый путь требует
`IMAGE_TAG` (не `latest`) и `COMPOSE_PROJECT_NAME`: `code`, `php-deps`, `frontend` и `runtime`
делают `pull` + `up -d --no-build`, а `image-promotion` только получает готовый tag. Ни один из
них не запускает build, не монтирует source/vendor/frontend artifacts и не удаляет volumes.
Ключевой prod-специфичный момент: `stop_grace_period` контейнера `horizon` —
`${HORIZON_STOP_GRACE_PERIOD:-600s}` (Docker default 10s убил бы 300+с job); инвариант
`job timeout < supervisor timeout < queue.retry_after` — `../runbooks/horizon-backlog.md`.

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
# File-backed secrets

Production Compose uses the same ignored `local/secrets/` files, generated with
`make secrets:init`. `.env.docker.production` is intentionally non-secret; services receive
only their declared Docker secret mounts.
