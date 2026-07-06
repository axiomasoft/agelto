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

## Layer Responsibilities (short)

Controllers — только HTTP: auth, validate, delegate to Action/Service/Job dispatch, response.
Repositories — read/write доступ к `prc_*`/`org_*` таблицам. Полная раскладка `app/` по доменным
папкам (Models/Services/Dto/Enums/Repositories/Console) — `docs/03_Dev/architecture.md` (актуальнее
и подробнее любой копии здесь — не дублировать).

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

---

# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию. Грань — простота ↔ техническая точность.

Полный справочник (термины, enum'ы, таблицы, исключения): `docs/02_Workflow/glossary.md`. Табличные префиксы и доменная структура: `docs/03_Dev/architecture.md`. Общие правила сущностей PHP/Laravel: skill `naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

Актуально на дату этого файла (сверено с `app/`, после переименования v1→v2 — см. `docs/02_Workflow/glossary.md#изменения-терминов`):

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»).
- `CrawlerRun` → `$run` — один прогон (не голый `Run`/`Crawl`).
- `Procurement` → `$procurement` — закупка (в v1 называлась `Link`, термин больше не используется).
- `Document` → `$document`/`$documents` — документ закупки (в v1 — `LinkAttachment`).
- `DocumentVersion` → `$documentVersion` — конкретный скачанный файл документа.
- `Ai\Analysis` → `$analysis` — сессия AI-анализа (в v1 — `DocumentAnalysis`).
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Lot` / `PurchaseItem` (`prc_items`, не путать с `Estimate\Item`/`prc_estimate_items` — позицией сметы) — лот / позиция объекта закупки.

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Глаголы методов

- `find*` — поиск по идентичности, **nullable**, без суффиксов `ByXAndY` (`findCrawler($id)`, `findProcurement($platformId, $sourceNumber)`).
- `get*` — вернуть определённое/вычисленное (`getPendingProcurements()`); если существительное читается ясно — без `get` (`owner()`, `systemInstructionsFor($step)`).
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса.
- `run()` — точка входа процессора (одна на класс). `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- **Избегать:** `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`; `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, **одна ответственность на файл**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`, `->primaryAnalysisFailed()`); цепочки — `->for()`/`->has()` или именованный билдер.
- **Без** tuple-возвратов и boolean-флагов в хелперах (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$procurement`, `$document`, `$analysis`, `$estimate`).
- Test doubles → `tests/Support/Doubles/` (`Tests\Support\Doubles`); общие mock-сборки — хелперы (`fakeBrowserClient()`).
- Повторяющиеся проверки — кастомные expectations, не копипаста запросов.

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).

---

# Navigation Guide

When a task requires more detail than what's in the current context, load the relevant file below. Don't load files preemptively — only when you hit a gap.

## Quick Start (3 steps)

1. Start `quick`: read only 1–2 most relevant sources.
2. If domain term is unclear (`Crawler`, `Link`, `Document`, `Platform`, `EIS`, стадии парсинга) — open `docs/02_Workflow/crawling-and-discovery.md` and `docs/03_Dev/architecture.md`.
3. If task is cross-layer (Procurement domain + jobs + Filament + frontend) — open skill `procurement-pipeline-dev`.

## Agent File Edit Safety (High Priority)

- Before any file edit, check whether the target path is ignored by Git (`.gitignore`).
- Do not edit generated agent files directly:
  - `CLAUDE.md`
  - `AGENTS.md`
  - `.claude/skills/`
  - `.cursor/rules/`
- `CLAUDE.md` and `AGENTS.md` are generated artifacts. For AI rules/skills updates, edit only source files under `.ai/...`.
- Prefer source paths under `.ai/...` when changing AI rules/skills.
- If a requested file is ignored/generated, stop and use the source file instead.

## Fast Term Routing (Minimal Context First)

- Непонятные термины домена (Crawler, Link, Document, стадии 1–4) — сначала `docs/02_Workflow/crawling-and-discovery.md`.
- Имена классов/методов/переменных, канон глаголов (`find/get`), нейминг тестов — `.ai/guidelines/glossary.md` (канон) и `docs/02_Workflow/glossary.md` (полный справочник).
- Изменение пайплайна парсинга / jobs / очередей — skill `procurement-pipeline-dev`.
- Большая/неоднозначная задача на несколько слоёв — skill `complex-task-orchestrator`.
- Первый проход минимальный: 1–2 файла, затем расширять по необходимости.

## Context Escalation Rule

- Start in `quick` mode (minimal reads and narrow scope).
- Expand context only when:
  - term meaning is still ambiguous after workflow docs;
  - change spans more than one layer (backend + frontend + jobs/tests);
  - there is a direct conflict between instructions/rules.
- When expanding, follow `Reference Table` top-down and stop once enough context is gathered.

## Reference Table

| Situation | Load this file |
|---|---|
| Crawling, parsing, AI screening, estimate extraction | `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md` |
| Procurement / Reference / Organization домены, терминология | `docs/03_Dev/architecture.md` |
| Имена сущностей/методов/переменных, канон глаголов, нейминг тестов | `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md` |
| Layer boundaries, where logic lives | `.ai/guidelines/architecture.md` |
| Cross-layer change in parsing pipeline (dependency map, test-gate, PR checklist) | skill `procurement-pipeline-dev` |
| Task-starter prompts (review/tests/security/deploy) | `/code-review`, `/test-gen`, `/security-check`, `/docker-deploy` |
| DB schema, table prefixes | `docs/03_Dev/database.md` |
| Crawling pipeline internals | `docs/03_Dev/crawling-pipeline.md` |
| Document/estimate pipeline internals | `docs/03_Dev/document-pipeline.md` |
| LLM providers, AI routing | `docs/03_Dev/llm-providers.md` |
| Admin panel / Filament observability | `docs/03_Dev/admin-panel.md` |
| Writing or running tests | skill `testing-rules` |
| Creating commits | skill `git-commit-rules` |
| Inertia + Vue pages/forms | skill `inertia-vue` |
| Filament admin panel | skill `filament` |
| Frontend routes from backend | skill `wayfinder` |
| DB schema, migrations | skill `database` |
| Eloquent Attribute::make() accessors/mutators | skill `eloquent-accessors` |
| PHP style (final, strict types, named args) | skills `code-style-spatie`, `named-arguments`, `static-analysis` |
| Folder map and layer ownership | skill `project-map` |
| Complex multi-step task | skill `complex-task-orchestrator` |
| Backend + frontend + auth/tests checklist | skill `cross-layer-change-checklist` |
| Editing `.ai/`, `boost.json`, agent generation cycle | skill `ai-context-workflow` |
| Natural wording for comments/commits | skill `writing-style` |
| Terse responses | skill `compact-responses` |
