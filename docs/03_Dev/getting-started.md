---
audience: dev
type: howto
status: living
---

# Требования и локальный запуск

## Требования

- Docker + Docker Compose v2 (`docker compose`, не легаси `docker-compose`).
- `make` (обёртка над `docker/Makefile`, см. [`commands.md`](commands.md)).
- Docker + Make достаточно для текущего dev command surface. Frontend использует pinned
  `pnpm@9.15.0`; production запускает только заранее собранный immutable `IMAGE_TAG` через
  явный Compose project — build на production host не выполняется.

## Первый запуск

```bash
cp .env.example .env
make bootstrap MODE=dev ENV_FILE=.env COMPOSE_PROJECT_NAME=agelto_dev
# vendor → create-only file secrets → preflight → immutable image → dev dependencies → migrate → base seed → health
```

Bootstrap is resumable: it records only stage/status/source SHA in the ignored
`local/secrets/.bootstrap-progress`, preserves existing secret bytes and named volumes, and prints
an exact retry command after a failed stage. In dev it applies migrations and idempotent base data;
it never resets passwords, deploys, cleans up or removes volumes. Demo data is opt-in through
`make dev:seed-demo` and requires a nonempty local `DEV_SEED_PASSWORD`.

To create the first administrator, provide the password only through a readable file; it is never a
CLI value or output:

```bash
make bootstrap MODE=dev ENV_FILE=.env COMPOSE_PROJECT_NAME=agelto_dev \
  ADMIN_PASSWORD_FILE=/secure/path/initial-admin-password
```

`local/vendors/azguard` is an ignored nested checkout, never a submodule. Its tracked lock manifest
(`docker/private-vendors.lock.json`) pins the origin, full commit SHA and Composer package allowlist.
Use `make vendor:check` before a build; it refuses a missing, wrong-origin, wrong-SHA or dirty package checkout.

`infra:preflight` needs a non-secret Compose env file and generated `local/secrets/` files. It
never prints values or creates Docker resources; use `MODE=prod-build` before building an image
and `MODE=prod-runtime` before operating an already-built production stack.

For a production-image candidate, bootstrap stops before every host mutation:

```bash
make bootstrap MODE=prod-build ENV_FILE=.env.production IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=agelto_build
```

It builds and validates the immutable image/Compose contract only. Provisioning, pull, migrate,
release, rollback and backup remain production-operator work.

Release images are always a pair: `agelto/app` and `agelto/doctor` receive the same immutable tag,
source revision and private-vendor-lock attestation. Build and publish that pair only from a clean
build-machine checkout; the carrier rejects `latest` and verifies a local-registry push followed by
a clean pull before a tag can be handed to production operations.

Проверка живости: `make dev:ps`, `curl http://localhost:8080/up` (healthcheck-эндпоинт, тот же, что
слушает Docker `healthcheck` — см. [`docker/index.md`](docker/index.md)).

## `.env`: ключевые группы

- Стандартные Laravel/DB/Redis/S3(MinIO) — как в любом Laravel-проекте, см. `.env.example`.
- LLM-транспорт (Prism, `vendor/prism-php/prism/config/prism.php`): `GEMINI_API_KEY`, опционально
  `GEMINI_URL` (дефолт — `generativelanguage.googleapis.com`). Ключ получается в
  [Google AI Studio](https://aistudio.google.com/) на аккаунте владельца проекта — свой персональный
  ключ для локальной разработки, в `.env` НЕ коммитится (файл в `.gitignore`).
- Исходящий прокси для вызовов из РФ (`config('ai.gemini.proxy')` ← `AI_GEMINI_PROXY`) — заполняется
  только если аккаунт/сеть требуют прокси до `generativelanguage.googleapis.com`; конкретный прокси
  для прод-окружения не решён (см. `docs/03_Dev/runbooks/gemini-errors.md`, открытый вопрос плана
  EST-AI). Для локальной разработки обычно не нужен.
- `config/ai.php` — все остальные пороги пайплайна (rate-limit, retry, capability-матрица моделей,
  пороги подготовки/скрининга/извлечения) читаются из `env()` внутри этого файла — искать конкретный
  ключ там, не в `.env.example` (полный справочник ключей вне scope этого файла).

## Тесты

```bash
make agent:artisan ARGS="test --compact" # non-TTY Laravel test run
make test:parallel PROCESSES=2           # isolation proof; 2 or 4 only
```

Изоляция от прод/dev БД — гейт `Tests\Support\TestEnvironmentGuard`; подробности и правила именования
тестов — skill `testing-rules`, `docs/03_Dev/process/testing.md`. Parallel worker проверяет exact
token DB до первой migration; все app-owned disks остаются под `storage/framework/testing`.

## Дальше

- [`architecture.md`](architecture.md) — домены и раскладка `app/**`.
- Пайплайн своей задачи — [`document-pipeline.md`](document-pipeline.md),
  [`llm-providers.md`](llm-providers.md), [`crawling-pipeline.md`](crawling-pipeline.md).
- [`commands.md`](commands.md) — шпаргалка команд одним листом.
- [`docker/index.md`](docker/index.md) — устройство контейнерного стека подробнее.
# Local secrets

Before starting Compose, run `make secrets:init`. It creates the ignored `local/secrets/`
files with mode 0600 and never prints their values. Rerunning preserves values; use
`docker/scripts/secrets-init.sh --root . --rotate <name>` only to stage `<name>.next`.
