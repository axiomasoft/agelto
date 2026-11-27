---
audience: dev
type: howto
status: living
---

# Docker: production

## Prebuilt image, не build-on-deploy

`docker-compose.prod.yml` требует оба значения: `COMPOSE_PROJECT_NAME` и неизменяемый `IMAGE_TAG`.
Веб, scheduler и четыре демона используют `agelto/app:${IMAGE_TAG}` на основе `app-base`.
Horizon использует `agelto/worker-prepare:${IMAGE_TAG}` с утилитами подготовки документов.
`IMAGE_REGISTRY` задаёт необязательный префикс реестра в обеих командах promotion и deploy.
Production host загружает образы и ждёт их готовности с `up -d --wait --no-build`.
Два Compose-проекта изолированы. Исходники и `vendor` входят в образ приложения.

## Private Composer packages

Перед сборкой выполните `make vendor:sync`. Он клонирует неизменяемый коммит AzGuard из
`docker/private-vendors.lock.json` в игнорируемый `local/vendors/azguard`; checkout не является ни
submodule, ни runtime-артефактом образа. Dockerfile копирует в стадию зависимостей только пути
пакетов из allowlist и исключает сырой checkout из финальной стадии `application`. Образы app,
worker и Doctor несут точный SHA манифеста как OCI label и несекретную аттестацию сборки;
production Doctor проверяет эту аттестацию и установленные пакеты из allowlist без checkout и
`.git` в образе.

## Секреты (file-backed Compose)

Чувствительные значения (`APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MINIO_ROOT_PASSWORD` для
S3-совместимого хранилища Silo) живут только в игнорируемых файлах `local/secrets/` и монтируются
явными грантами Compose `secrets:` в `/run/secrets/*`; `entrypoint.d/10-secrets.sh` читает
объявленные пути `*_FILE`. `.env.production` содержит только несекретную конфигурацию.
Недостающие файлы создайте через `make secrets:init`; повторные запуски сохраняют их байты. Не
используйте команды секретов Swarm и fallback на значения окружения.

Локальный Compose сохраняет владельца и режим `0600` смонтированного файла. Поэтому старт образа
выполняет короткую предзагрузку от root только для фиксированных путей секретов, а затем
переключается на `www-data` до entrypoint ServersideUp и команды приложения; процесс приложения
никогда не работает от root.

`make bootstrap MODE=prod-build ENV_FILE=.env.production IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=agelto_build`
безопасен на машине сборки: он проверяет vendors, секреты и preflight, собирает неизменяемый образ
и рендерит контракт production. Он не готовит хост, не делает pull и пересоздание сервисов, не
применяет миграции, не ротирует учётные данные, не откатывает и не удаляет volumes.

## Release classes

Полная матрица команд — [`../process/deploy.md`](../process/deploy.md). Каждый путь требует
`IMAGE_TAG` (не `latest`) и `COMPOSE_PROJECT_NAME`: `code`, `php-deps`, `frontend` и `runtime`
делают `pull` + `up -d --no-build`, а `image-promotion` только получает готовый tag. Ни один из
них не запускает build, не монтирует source/vendor/frontend artifacts и не удаляет volumes.
Ключевой prod-специфичный момент: `stop_grace_period` контейнера `horizon` —
`${HORIZON_STOP_GRACE_PERIOD:-600s}` (Docker default 10s убил бы 300+с job); инвариант
`job timeout < supervisor timeout < queue.retry_after` — `../runbooks/horizon-backlog.md`.

После миграций, базовых данных и создания первого администратора выполните явный gate
приложения, прежде чем объявлять релиз готовым: `php artisan app:readiness --format=agent` в
контексте app, затем `make prod:doctor IMAGE_TAG=<immutable-tag>`. `/up` проверяет только
живость и их не заменяет.

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

Production Compose использует те же игнорируемые файлы `local/secrets/`, созданные
`make secrets:init`. `.env.docker.production` намеренно несекретный; сервисы получают только
объявленные для них монтирования секретов Docker.