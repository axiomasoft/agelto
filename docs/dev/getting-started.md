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

Bootstrap возобновляем: он записывает только этап, статус и SHA исходников в игнорируемый
`local/secrets/.bootstrap-progress`, сохраняет существующие байты секретов и именованные volumes и
после неудачного этапа печатает точную команду повтора. В dev он применяет миграции и
идемпотентные базовые данные; он никогда не сбрасывает пароли, не развёртывает, не чистит и не
удаляет volumes. Демо-данные включаются явно через `make dev:seed-demo` и требуют непустого
локального `DEV_SEED_PASSWORD`.

Чтобы создать первого администратора, передайте пароль только через читаемый файл; он никогда
не передаётся значением CLI и не выводится:

```bash
make bootstrap MODE=dev ENV_FILE=.env COMPOSE_PROJECT_NAME=agelto_dev \
  ADMIN_PASSWORD_FILE=/secure/path/initial-admin-password
```

`local/vendors/azguard` — игнорируемый вложенный checkout, а не submodule. Отслеживаемый
lock-манифест (`docker/private-vendors.lock.json`) закрепляет origin, полный SHA коммита и
allowlist пакетов Composer. Перед сборкой выполните `make vendor:check`: он отказывает при
отсутствующем checkout, чужом origin, неверном SHA или грязном рабочем дереве пакета.

`infra:preflight` нужен несекретный env-файл Compose и сгенерированные файлы `local/secrets/`.
Он никогда не печатает значения и не создаёт ресурсы Docker; используйте `MODE=prod-build` перед
сборкой образа и `MODE=prod-runtime` перед работой с уже собранным production-стеком.

Для кандидата production-образа bootstrap останавливается перед любой мутацией хоста:

```bash
make bootstrap MODE=prod-build ENV_FILE=.env.production IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=agelto_build
```

Он только собирает и проверяет контракт неизменяемого образа и Compose. Подготовка хоста, pull,
миграции, релиз, откат и резервное копирование остаются работой оператора production.

Релизные образы всегда идут парой: `agelto/app` и `agelto/doctor` получают один и тот же
неизменяемый тег, ревизию исходников и аттестацию lock приватных vendors. Собирайте и публикуйте
эту пару только из чистого checkout на машине сборки; носитель отклоняет `latest` и проверяет push
в локальный registry с последующим чистым pull, прежде чем тег можно передать в production.

Проверка живости: `make dev:ps`, `curl http://localhost:8080/up` (healthcheck-эндпоинт, тот же, что
слушает Docker `healthcheck` — см. [`docker/index.md`](docker/index.md)).

## `.env`: ключевые группы

- Стандартные Laravel/DB/Redis/S3(MinIO) — как в любом Laravel-проекте, см. `.env.example`.
- LLM-транспорт (см. [контур LLM](llm-providers.md)): `GEMINI_API_KEY`, опционально
  `GEMINI_URL` (дефолт — `generativelanguage.googleapis.com`). Ключ получается в
  [Google AI Studio](https://aistudio.google.com/) на аккаунте владельца проекта — свой персональный
  ключ для локальной разработки, в `.env` НЕ коммитится (файл в `.gitignore`).
- Исходящий прокси для вызовов из РФ (`config('ai.gemini.proxy')` ← `AI_GEMINI_PROXY`) — заполняется
  только если аккаунт/сеть требуют прокси до `generativelanguage.googleapis.com`; конкретный прокси
  для прод-окружения не решён (см. `docs/dev/runbooks/gemini-errors.md`, открытый вопрос плана
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
тестов — skill `testing-rules`, `docs/dev/process/testing.md`. Parallel worker проверяет exact
token DB до первой migration; все app-owned disks остаются под `storage/framework/testing`.

## Дальше

- [`architecture.md`](architecture.md) — домены и раскладка `app/**`.
- Пайплайн своей задачи — [`document-pipeline.md`](document-pipeline.md),
  [`llm-providers.md`](llm-providers.md), [`crawling-pipeline.md`](crawling-pipeline.md).
- [`commands.md`](commands.md) — шпаргалка команд одним листом.
- [`docker/index.md`](docker/index.md) — устройство контейнерного стека подробнее.
# Local secrets

Перед запуском Compose выполните `make secrets:init`. Он создаёт игнорируемые файлы
`local/secrets/` с режимом 0600 и никогда не печатает их значения. Повторный запуск сохраняет
значения; `docker/scripts/secrets-init.sh --root . --rotate <name>` используйте только для
подготовки `<name>.next`.