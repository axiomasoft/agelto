# Application Architecture

## Fast Entry Rules

- AI rules/skills are edited only in `.ai/...` source files.
- Never hand-edit the generated `CLAUDE.md` (single AI-provider hub — `AGENTS.md`/`GEMINI.md` retired: single-provider Claude Code decision, do not recreate).
- For quick domain routing and context escalation, use `.ai/guidelines/navigation.md`.
- Keep this file concise: use it as a hub, escalate details to `docs/03_Dev/*`.
- Если меняются архитектурные договорённости: синхронно обновить `docs/03_Dev/*`, `docs/02_Workflow/*` и при необходимости `.ai/guidelines/*` / локальные `.claude/skills/*`, затем вручную перенести изменения в `CLAUDE.md` (автокомпозиции нет — Boost подключён только как MCP, см. skill `ai-context-workflow`).

## Domain Hub (short)

- Терминология и схема пайплайна: `docs/03_Dev/architecture.md`, `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.
- Структура плоская (`app/**`), без модулей: три домена по короткому префиксу таблицы — `Procurement` (`prc_*`, единый домен закупок: заказы, документы, сметы, AI-пайплайн, crawling-инфраструктура, площадки, логи), `Reference` (`ref_*`, общие справочники ОКПД2/КТРУ/ОКВЭД2/КБК), `Organization` (`org_*`, организации).
- AI-подомен внутри Procurement: пайплайн-стадии — `app/Services/Procurement/{Prepare,Screening,Extraction}/` + `app/Jobs/Procurement/*Job`; провайдер-агностичный LLM-транспорт — `app/Services/Llm/**`; модели анализа/промтов/датасета — `app/Models/Procurement/Ai/*`. Глубже — скилы `estimate-extraction-dev`, `llm-layer-dev`, `prompt-engineering-agelto`.
- Очереди: Laravel Horizon (`QUEUE_CONNECTION=redis`), тяжёлые jobs — browser/Gemini parsing.

## Layer Responsibilities (short)

Controllers — только HTTP: auth, validate, delegate to Action/Service/Job dispatch, response.
Repositories — read/write доступ к `prc_*`/`org_*` таблицам. Полная раскладка `app/` по доменным
папкам (Models/Services/Dto/Enums/Repositories/Console) — `docs/03_Dev/architecture.md`.

## Transaction & Jobs Policy (short)

1. Короткие синхронные мутации — в Action/Service с явной транзакцией при необходимости.
2. Длительный crawling/parsing/AI — queued Jobs через Horizon, не в HTTP-request.
3. Browser automation (Chromium) — только в worker-контейнере `horizon` (Docker: `shm_size`, `CHROME_BIN`).
4. Idempotency: повтор job не должен дублировать Link/Document без guard по статусу; для AI-анализов — lease + status-guard, prefix-stable системный промт (кэш), checkpoint-requeue не двойного диспатча.

## Docker / Runtime (Agelto-specific)

- Runtime-image лёгкий: PHP extensions + Node/yarn, **без** `composer install` в Dockerfile; `vendor`/`public/build` — через volumes (`make prod:composer-install`/`prod:deploy:frontend`).
- Package manager: **yarn** (не pnpm).

> For parsing stage details and table names: `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`, `docs/03_Dev/architecture.md`.

---

# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию.

Полный справочник: `docs/02_Workflow/glossary.md`. Табличные префиксы и доменная структура: `docs/03_Dev/architecture.md`. Общие правила именования PHP/Laravel: skills `naming-conventions`, `php-naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»); `CrawlerRun` → `$run` — один прогон.
- `Procurement` → `$procurement` — закупка (в v1 — `Link`).
- `Document`/`DocumentVersion` → `$document`/`$documentVersion` — документ закупки и его скачанный файл (в v1 — `LinkAttachment`).
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Lot`/`PurchaseItem` (`prc_items`, не путать с `Estimate\Item`/`prc_estimate_items` — позицией сметы) — лот / позиция объекта закупки.

### AI-подомен (`App\Models\Procurement\Ai\*`, `App\Services\Llm\*`) — полностью: `.ai/guidelines/glossary.md`

- `Ai\Analysis` → `$analysis` — сессия анализа версии документа; re-run — `parent_analysis_id`, shadow-прогон — `is_shadow`.
- `Ai\Prompt` → `$prompt` — версия системной инструкции; канон — файл `resources/ai/prompts/**` → `ai:prompts:sync`; `is_protected` — сидовые записи read-only в админке.
- `Ai\Correction`/`Ai\DatasetExample` — операторская правка результата и immutable gold-снапшот обучающего контура.
- `PromptStep` (композиция промта) ≠ `AnalysisStep` (шаг анализа: screening/extraction) ≠ `LlmPurpose` (назначение конкретного вызова).
- `DocumentStatus::Unprocessable` + `UnprocessableReason` — терминальный «не можем обработать» (лимит провайдера/формат/пароль/zip-бомба) — константация, не сбой, не ретраится.
- `ExtractionRoute` (`Whole`/`Windowed`) — путь извлечения, выбранный `ExtractionPreflight` по capability-матрице ДО первого upload.

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Глаголы методов

- `find*` — nullable поиск по идентичности, без суффиксов `ByXAndY`. `get*` — вернуть определённое/вычисленное; если существительное читается ясно — без `get`.
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса. `run()` — точка входа процессора. `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- Избегать: `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей), безобъектные `handle`/`process`/`do`, `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, одна ответственность на файл; только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states; цепочки — `->for()`/`->has()`; без tuple-возвратов и boolean-флагов в хелперах.
- Переменные — доменный канон; test doubles → `tests/Support/Doubles/`; LLM-вызовы — только `FakeLlmProvider` (skill `testing-rules`).

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).

---

# Navigation Guide

When a task requires more detail than what's in the current context, load the relevant file below. Don't load files preemptively — only when you hit a gap.

## Quick Start (4 steps)

1. Start `quick`: read only 1–2 most relevant sources.
2. If domain term is unclear (`Crawler`, `Link`, `Document`, `Platform`, `EIS`, стадии парсинга) — open `docs/02_Workflow/crawling-and-discovery.md` and `docs/03_Dev/architecture.md`.
3. If task is cross-layer (Procurement domain + jobs + Filament + frontend) — open skill `procurement-pipeline-dev`.
4. If task is LLM-транспорт/провайдер-адаптер — skill `llm-layer-dev`; extraction-контракты/discovery/preflight/unprocessable — skill `estimate-extraction-dev`; тексты промтов/композиция/eval — skill `prompt-engineering-agelto`.

## Agent File Edit Safety (High Priority)

- Before any file edit, check whether the target path is ignored by Git (`.gitignore`).
- Do not hand-edit `CLAUDE.md` — assembled manually from `.ai/guidelines/**`; edit the source instead. No `AGENTS.md`/`GEMINI.md`/`.cursor/` — retired (single-provider Claude Code), do not recreate.
- `.claude/skills/*`: vendor/managed skills carry an in-file `swissknifeman:managed` marker and are overwritten by `skiller sync` — don't hand-edit; local domain skills have no marker and ARE edited directly (skill `ai-context-workflow`).

## Fast Term Routing (Minimal Context First)

- Непонятные термины домена (Crawler, Link, Document, стадии 1–4) — сначала `docs/02_Workflow/crawling-and-discovery.md`.
- Имена классов/методов/переменных, канон глаголов, нейминг тестов — `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md`.
- Изменение пайплайна парсинга / jobs / очередей — skill `procurement-pipeline-dev`.
- Извлечение смет (discovery/windowed vs row-range, preflight, checkpoint-requeue, unprocessable) — skill `estimate-extraction-dev`.
- LLM-транспорт (провайдер-адаптер, кэш-инварианты, capability-матрица/EOL-модели) — skill `llm-layer-dev`.
- Тексты промтов, композиция, protected-промты, eval-цикл — skill `prompt-engineering-agelto`.
- Большая/неоднозначная задача на несколько слоёв — skill `complex-task-orchestrator`.

## Context Escalation Rule

- Start in `quick` mode. Expand only when: term meaning is still ambiguous after workflow docs; change spans more than one layer (backend + frontend + jobs/tests); there is a direct conflict between instructions/rules.
- When expanding, follow `Reference Table` top-down and stop once enough context is gathered.

## Reference Table

| Situation | Load this file |
|---|---|
| Crawling, parsing, AI screening, estimate extraction | `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md` |
| Procurement / Reference / Organization домены, терминология | `docs/03_Dev/architecture.md` |
| Имена сущностей/методов/переменных, канон глаголов, нейминг тестов | `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md` |
| Layer boundaries, where logic lives | `.ai/guidelines/architecture.md` |
| Cross-layer change in parsing pipeline (dependency map, test-gate, PR checklist) | skill `procurement-pipeline-dev` |
| Extraction смет: контракты, discovery/windowed vs row-range, preflight, checkpoint-requeue, unprocessable | skill `estimate-extraction-dev`, `docs/specs/v2/08-estimate-extraction.md` |
| LLM-транспорт: провайдер-адаптер, кэш-инварианты, capability-матрица/EOL-модели | skill `llm-layer-dev`, `docs/03_Dev/llm-providers.md` |
| Промты: файл→сид→is_protected, композиция/приоритеты, hints, eval-цикл | skill `prompt-engineering-agelto`, `docs/03_Dev/ai/prompts.md` |
| PR-чеклист по докам (docs/SOP обновлены?) — для ЛЮБОГО PR | `.ai/guidelines/docs-pr-checklist.md` |
| Task-starter prompts (review/tests/security/deploy) | `/code-review`, `/test-gen`, `/security-check`, `/docker-deploy` |
| DB schema, table prefixes | `docs/03_Dev/database.md` |
| Crawling pipeline internals | `docs/03_Dev/crawling-pipeline.md` |
| Document/estimate pipeline internals (FSM, стадии, очереди, failure modes) | `docs/03_Dev/document-pipeline.md` |
| Инцидент пайплайна (завис анализ, quota/429, backlog) | `docs/03_Dev/runbooks/_index.md` |
| Оператор: проверить/скорректировать/перезапустить AI-разбор | `docs/04_Operators/_index.md` |
| AI-контекст: как устроены `.ai/`/скилы/генерация CLAUDE.md | `docs/03_Dev/ai-context/index.md`, skill `ai-context-workflow` |
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

<!-- swissknifeman:hub:start -->
# Swissknifeman: skill hub

<!-- Generated by scripts/generate-hub.sh from the swissknifeman registry. -->
<!-- Do not edit by hand. Regeneration overwrites this block. -->
<!-- swissknifeman:registry sha=d7abc1fb0904 skills=207 -->

## Rule source priority

1. Project rules and skills come first (`.ai/guidelines/`, `.ai/skills/`, root project instructions).
2. Swissknifeman skills (index below) cover architecture, structure, practices, and process. If this project adds Laravel Boost later, its version-specific skills win over any overlap.

On conflict, the more specific source wins: project > Boost > swissknifeman registry.

## Using skills

Claude Code indexes each installed skill's `description` from its SKILL.md frontmatter at session start. You don't need to search a catalog for the right skill — it attaches on its own based on the task. Full list of installed skills: `skiller status`.

1. Inside a skill, open its snippets only when its own table says to. Don't load everything at once.
2. For structural questions on new code, check in this order: structure (`laravel-structure`), then interaction (`dependency-injection`), then the specific implementation skill.
3. For how the skill system itself works here (source of truth, symlinks, gitignore), see `skills-ssot`.

## Project agents (4)

Roles live in `.claude/agents/`. Preloaded skills come from the `skills:` field (⚠ means the name isn't in the registry — preload silently fails).

| Agent | Purpose | Preloaded skills |
|---|---|---|
| `agelto-reviewer` | >- | repositories, laravel-structure, dependency-injection, laravel-security-audit, code-style-spatie |
| `agelto-slice-builder` | >- | repositories, laravel-structure, dependency-injection, laravel-data, laravel, database, code-style-spatie, named-arguments |
| `agelto-spec-writer` | >- | architecture, api-design, data-schema, task-brief-template |
| `agelto-test-writer` | >- | laravel-testing, test-isolation-guard, repositories |

Skills installed: 129 (buckets: 23 — architect, devops, frontend, frontend-backend, frontend-linting, frontend-tailwind, frontend-vite, frontend-vue, frontend-wayfinder, general, laravel-architecture, laravel-auth, laravel-data-layer, laravel-extras, laravel-filament, laravel-testing, operator, oss-dev, php, pm, quality, research, system). Each skill's description is already in context natively. Not duplicated here.
<!-- swissknifeman:hub:end -->
