# Application Architecture

## Fast Entry Rules

- AI rules/skills are edited only in `.ai/...` source files.
- Never edit generated files directly: `CLAUDE.md`, `AGENTS.md`.
- For quick domain routing and context escalation, use `.ai/guidelines/navigation.md`.
- Keep this file concise: use it as a hub, escalate details to `docs/dev/*`.
- Если меняются архитектурные договорённости: синхронно обновить `docs/dev/*`, `docs/workflow/*` и при необходимости `.ai/guidelines/*` / `.ai/skills/*`, затем `composer ai:sync`.

## Domain Hub (short)

- Терминология и схема пайплайна: `docs/dev/architecture.md`, `docs/workflow/parsing-process.md`.
- Модули: `Modules/Platform` (ядро crawling/parsing), `Modules/Eis` (реализация для ЕИС).
- Очереди: Laravel Horizon (`QUEUE_CONNECTION=redis`), тяжёлые jobs — browser/Gemini parsing.

## Layer Responsibilities

**Modules (`Modules/*/app/`)**

- Доменная логика crawling, document prepare, AI analysis, estimate extraction.
- Jobs в `Process/*/Jobs/`, процессоры в `Process/*/*Processor.php`.
- Filament resources для операторских экранов.

**`app/` (ядро приложения)**

- `Services/` — инфраструктура: `BrowserService`, `GeminiService`, HTTP proxy, парсеры.
- `Dto/` — DTO для смет и browser options.
- `Http/Controllers/` — Inertia/Fortify/settings, тонкий HTTP-слой.
- `Models/Order/` — заказы и лоты после успешного парсинга.
- `Providers/` — Filament, Horizon, Fortify.

**Repositories (в модулях)**

- Read/write доступ к `plt_*` таблицам, crawling links, document analysis.

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

> For parsing stage details and table names: `docs/workflow/parsing-process.md`.
