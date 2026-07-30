---
audience: dev
type: howto
status: draft
---

# Требования и локальный запуск

## Требования

- Docker + Docker Compose v2 (`docker compose`, не легаси `docker-compose`).
- `make` (обёртка над `docker/Makefile`, см. [`commands.md`](commands.md)).
- Node.js + `yarn` — фронтенд-сборка (Vite; пакетный менеджер строго yarn, не pnpm — канон
  CLAUDE.md §Docker/Runtime). `composer install` НЕ входит в runtime-образ — только через
  `make composer-install`/`make prod:composer-install` (volume-монтирование `vendor`).

## Первый запуск

```bash
cp .env.example .env
cp .env.docker.example .env.docker   # опционально: dev-специфичные переменные для compose
make up                              # docker-compose.yml: app/horizon/scheduler/postgres/redis/minio/browser
make composer-install                # vendor как bind-mount, устанавливается ИЗ контейнера
make db-migrate                      # либо make db-fresh — migrate:fresh на dev
make db-seed                         # PromptSeeder + сиды llm_routes/ролей — идемпотентно
yarn install && yarn dev             # фронтенд (Vite HMR)
```

Проверка живости: `make ps`, `curl http://localhost:8080/up` (healthcheck-эндпоинт, тот же, что
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
make db:create-test   # разовая настройка тестовой БД agelto_test
make test-php         # ENV=dev по умолчанию; docker compose exec app php artisan test --compact
```

Изоляция от прод/dev БД — гейт `Tests\Support\TestEnvironmentGuard`; подробности и правила именования
тестов — skill `testing-rules`, `docs/03_Dev/process/testing.md`.

## Дальше

- [`architecture.md`](architecture.md) — домены и раскладка `app/**`.
- Пайплайн своей задачи — [`document-pipeline.md`](document-pipeline.md),
  [`llm-providers.md`](llm-providers.md), [`crawling-pipeline.md`](crawling-pipeline.md).
- [`commands.md`](commands.md) — шпаргалка команд одним листом.
- [`docker/index.md`](docker/index.md) — устройство контейнерного стека подробнее.
