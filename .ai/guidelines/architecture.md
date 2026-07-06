# Application Architecture

## Fast Entry Rules

- AI rules/skills are edited only in `.ai/...` source files.
- Never edit generated files directly: `CLAUDE.md`, `AGENTS.md`.
- For quick domain routing and context escalation, use `.ai/guidelines/navigation.md`.
- Keep this file concise: use it as a hub, escalate details to `docs/03_Dev/*`.
- Если меняются архитектурные договорённости: синхронно обновить `docs/03_Dev/*`, `docs/02_Workflow/*` и при необходимости `.ai/guidelines/*` / `.ai/skills/*`, затем вручную перенести изменения в `CLAUDE.md`/`AGENTS.md`/`GEMINI.md` (автокомпозиции нет — Boost подключён только как MCP, см. skill `ai-context-workflow`).

## Domain Hub (short)

- Терминология и схема пайплайна: `docs/03_Dev/architecture.md`, `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.
- Структура плоская (`app/**`), без модулей: три домена по короткому префиксу таблицы — `Procurement` (`prc_*`, единый домен закупок: заказы, документы, сметы, AI-пайплайн, crawling-инфраструктура, площадки, логи), `Reference` (`ref_*`, общие справочники ОКПД2/КТРУ/ОКВЭД2/КБК), `Organization` (`org_*`, организации).
- Очереди: Laravel Horizon (`QUEUE_CONNECTION=redis`), тяжёлые jobs — browser/Gemini parsing.

## Layer Responsibilities

**`app/` (плоская структура, без `Modules/`)**

- `Models/{Procurement,Reference,Organization}/` — Eloquent-модели по доменам.
- `Services/{Procurement,Reference,Organization}/` — доменные сервисы (crawling, document indexing, AI-анализ, классификаторы), внутри `Procurement/` — подпапки `Eis/`, `Modes/`, `Contracts/`, `Exceptions/`, `Detail/`.
- `Dto/Procurement/` — DTO для смет, crawling-результатов, browser options.
- `Enums/{Procurement,Organization}/` — статусы, типы, режимы.
- `Repositories/{Procurement,Organization}/` — read/store-репозитории.
- `Console/Commands/{Procurement,Reference}/` — artisan-команды доменов.
- `Http/Controllers/` — Inertia/Fortify/settings, тонкий HTTP-слой.
- `Providers/` — Filament, Horizon, Fortify.

**Repositories**

- Read/write доступ к `prc_*`/`org_*` таблицам (crawling, документы, анализ) — `app/Repositories/Procurement/`, `app/Repositories/Organization/`.

**Controllers**

- Только HTTP: auth, validate, delegate to Action/Service/Job dispatch, response.

## Transaction & Jobs Policy (short)

1. Короткие синхронные мутации — в Action/Service с явной транзакцией при необходимости.
2. Длительный crawling/parsing/AI — queued Jobs через Horizon, не в HTTP-request.
3. Browser automation (Chromium) — только в worker-контейнере `horizon` (Docker: `shm_size`, `CHROME_BIN`).
4. Idempotency: повтор job не должен дублировать Link/Document без guard по статусу.

## Docker / Runtime (Agelto-specific)

- Runtime-image лёгкий: PHP extensions + Node/yarn, **без** `composer install` в Dockerfile.
- `vendor` и `public/build` — через volumes + `make prod:composer-install` / `make prod:deploy:frontend`.
- Package manager: **yarn** (не pnpm).

> For parsing stage details and table names: `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`, `docs/03_Dev/architecture.md`.
