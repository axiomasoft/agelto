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
make infra:preflight MODE=dev ENV_FILE=.env.example  # проверка без запуска Docker stack
make dev:up                          # docker-compose.yml: app/horizon/scheduler/postgres/redis/minio/browser
make dev:composer ARGS="install"     # Composer внутри dev app
make dev:artisan ARGS="migrate"       # миграции
make agent:artisan ARGS="test --compact" # non-TTY запуск для агента
```

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
```

Изоляция от прод/dev БД — гейт `Tests\Support\TestEnvironmentGuard`; подробности и правила именования
тестов — skill `testing-rules`, `docs/03_Dev/process/testing.md`.

## Дальше

- [`architecture.md`](architecture.md) — домены и раскладка `app/**`.
- Пайплайн своей задачи — [`document-pipeline.md`](document-pipeline.md),
  [`llm-providers.md`](llm-providers.md), [`crawling-pipeline.md`](crawling-pipeline.md).
- [`commands.md`](commands.md) — шпаргалка команд одним листом.
- [`docker/index.md`](docker/index.md) — устройство контейнерного стека подробнее.
