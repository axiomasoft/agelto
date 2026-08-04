# Application Architecture

## Fast Entry Rules

- AI rules are edited only in `.ai/guidelines/**` source files; local domain skills (no `swissknifeman:managed` marker) are edited directly in `.claude/skills/**` — there is no `.ai/skills/`.
- Never hand-edit the generated `CLAUDE.md` (single AI-provider hub — `AGENTS.md`/`GEMINI.md` retired: single-provider Claude Code decision, do not recreate).
- For quick domain routing and context escalation, use `.ai/guidelines/navigation.md`.
- Keep this file concise: use it as a hub, escalate details to `docs/03_Dev/*`.
- Если меняются архитектурные договорённости: синхронно обновить `docs/03_Dev/*`, `docs/02_Workflow/*` и при необходимости `.ai/guidelines/*` / локальные `.claude/skills/*`, затем вручную перенести изменения в `CLAUDE.md` (автокомпозиции нет — Boost подключён только как MCP, см. skill `ai-context-workflow`).

## Domain Hub (short)

- Терминология и схема пайплайна: `docs/03_Dev/architecture.md`, `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.
- Структура плоская (`app/**`), без модулей: четыре домена по короткому префиксу таблицы — `Procurement` (`prc_*`, единый домен закупок: заказы, документы, сметы, AI-подомен предметки, crawling-инфраструктура, площадки, логи), `Reference` (`ref_*`, общие справочники ОКПД2/КТРУ/ОКВЭД2/КБК), `Organization` (`org_*`, организации), `Llm` (`llm_*`, провайдер-агностичный LLM-транспорт: провайдеры, маршруты, промты, вызовы — D22).
- AI-подомен внутри Procurement: пайплайн-стадии — `app/Services/Procurement/{Prepare,Screening,Extraction}/` + `app/Jobs/Procurement/*Job`; предметные модели анализа/датасета — `app/Models/Procurement/Ai/*`, модель промта — `app/Models/Llm/Prompt.php` (домен транспорта, D22). Каталог типа документа (сейм анализатора) — `app/Services/Procurement/Analyzers/**`; консольные команды — `app/Console/Commands/Procurement/{Ai,Analyzers/Estimate}/` (`Ai/` — кросс-документные `ai:*`, `Analyzers/Estimate/` — сметные `estimate:*`). LLM-транспорт (провайдер-агностичный, домен не знает про Prism/Gemini напрямую) — собственный домен `Llm`: `app/Services/Llm/**` (`Contracts/LlmProvider`, `Providers/*`, `Schema/*`, `Validation/*`), модели/фабрики/репозитории — `app/Models/Llm/**`/`database/factories/Llm/**`/`app/Repositories/Llm/**`. Глубже — скилы `estimate-extraction-dev`, `llm-layer-dev`, `prompt-engineering-agelto`.
- Очереди: Laravel Horizon (`QUEUE_CONNECTION=redis`), тяжёлые jobs — browser/Gemini parsing.
- Внутри доменной папки слоя (`app/<Слой>/<Домен>/`) файл принадлежит ровно одной подобласти,
  выражаемой подкаталогом (заводится вместе с первым файлом подобласти); в корне легально лежат
  только ядровая подобласть домена и кросс-подобластные файлы — правило и машинный гейт
  (`tests/Unit/Architecture/DomainLayoutTest.php`) целиком см. `docs/03_Dev/architecture.md`
  («Правило размещения внутри доменной папки слоя»).

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
- `Proxy` → `$proxy` — узел исходящей маршрутизации (`prc_proxies`); единственная точка выдачи — `ProxyResolver::resolve(ProxyScope, target)`, скоуп — назначение запроса (`CrawlListing`/`CrawlDetail`/`DocumentDownload`/`Llm`), не сам узел.

### AI-подомен (`App\Models\Procurement\Ai\*`, `App\Services\Llm\*`) — полностью: `.ai/guidelines/glossary.md`

- `Ai\Analysis` → `$analysis` — сессия анализа версии документа; re-run — `parent_analysis_id`, shadow-прогон — `is_shadow`.
- `Ai\Prompt` → `$prompt` — версия системной инструкции; канон — оба корня (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) → `ai:prompts:sync`; `is_protected` — сидовые записи read-only в админке.
- `Ai\Correction`/`Ai\DatasetExample` — операторская правка результата и immutable gold-снапшот обучающего контура.
- `App\Enums\Llm\PromptStep` (интерфейс, ядерный `CorePromptStep` + типовые enum'ы анализаторов) — композиция промта ≠ `AnalysisStep` (шаг анализа: screening/extraction) ≠ `App\Enums\Llm\LlmPurpose` (тот же интерфейсный сейм для назначения конкретного вызова).
- `DocumentStatus::Unprocessable` + `UnprocessableReason` — терминальный «не можем обработать» (лимит провайдера/формат/пароль/zip-бомба) — константация, не сбой, не ретраится.
- `ExtractionRoute` (`Whole`/`Windowed`) — путь извлечения, выбранный `ExtractionPreflight` по capability-матрице ДО первого upload.
- `App\Attributes\Ai\Description` (P2.3) — семантика поля контракта ответа AI, читает `ContractAttributeReader`; структуру ответа (типы/`description`/порядок) целиком несёт исходящая схема (`response_schema`), системный текст промта — только правила поведения + словарь синонимов `#[Alias]` (разделение «схема / правила», P2.3–P2.5).

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
| Обучающий контур целиком: эталон → прогон → расход → eval/diff → тюнинг промта → экспорт под локальную LLM | `docs/03_Dev/ai/training-loop.md` |
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
# Swissknifeman: хаб скиллов

<!-- Сгенерировано scripts/generate-hub.sh из реестра swissknifeman. -->
<!-- НЕ редактируйте вручную: перегенерация затрёт правки. -->
<!-- swissknifeman:registry sha=7046e720edde skills=229 -->

## Приоритет источников правил

1. Проектные правила и скиллы (`.ai/guidelines/`, `.ai/skills/`, корневые инструкции проекта) — высший приоритет.
2. Скиллы swissknifeman (нативный листинг скиллов клиента) — архитектура, структура, практики, процессы. Если в проект добавят Laravel Boost, его версионно-специфичные скиллы приоритетнее пересекающихся.

Конфликт правил: побеждает более специфичный источник (проект > Boost > реестр swissknifeman).

## Как найти нужный скилл

1. Скиллы swissknifeman уже перечислены НАТИВНЫМ листингом клиента (имя + описание каждого доступного скилла резидентны с начала сессии) — ищи по нему; здесь индекс не дублируется.
2. Выбери скилл по описанию; внутри скилла открывай сниппеты только по его таблице «когда какой сниппет открывать» — не загружай всё подряд.
3. Структурные вопросы нового кода решай в порядке: структура (`laravel-structure`) -> взаимодействие (`dependency-injection`) -> реализация (профильный скилл).
4. Устройство системы скиллов в проекте (источник истины, симлинки, gitignore) — скилл `skills-ssot`.
5. Полный индекс с описаниями (если нужен человеку): `skiller status` в проекте либо `generate-hub.sh --target <проект> --full-index --dry-run`.

## Агенты проекта (10)

Роли в `.claude/agents/`. Преднагруженные скиллы — поле `skills:` (⚠ = имя не найдено в реестре, preload молча не сработает).

| Агент | Назначение | Преднагруженные скиллы |
|---|---|---|
| `agelto-blade-review` | >- | — |
| `agelto-db-impact` | >- | — |
| `agelto-orchestrator` | >- | — |
| `agelto-reviewer` | >- | repositories, laravel-structure, dependency-injection, laravel-security-audit, code-style-spatie, laravel |
| `agelto-route-mapper` | >- | — |
| `agelto-security-review` | >- | — |
| `agelto-slice-builder` | >- | repositories, laravel-structure, dependency-injection, laravel-data, laravel, database, code-style-spatie, named-arguments |
| `agelto-spec-writer` | >- | architecture, api-design, data-schema, task-brief-template |
| `agelto-test-verifier` | >- | — |
| `agelto-test-writer` | >- | laravel-testing, test-isolation-guard, repositories |
<!-- swissknifeman:hub:end -->
<!-- maind:healthcheck hash=f269dd753069 (managed by maind — не редактировать вручную) -->
## ⚠ ПРИОРИТЕТ №1 — связь с mAInd (общая память + граф)

Перед началом работы убедись, что проект подключён к хабу **mAInd**. Это критично:
без связи изменения этой сессии **не попадут** в общую память и граф — в истории
разработки появятся невосстановимые пробелы, и дальнейшие связи будет не вывести.

1. Проверь связь: `maind health --cwd`
2. Если статус `partial` / `standalone` — почини: `maind health --cwd --fix`
   (поднимет демон памяти и допишет MCP-серверы хаба в этот проект).
3. Приступай к задаче только при статусе `connected`.

Память **общая** (демон mAInd), scope этого проекта — `project:agelto`; факты всей экосистемы — в
scope `global`. Связи проекта с остальными: `maind graph --project agelto`.
<!-- /maind:healthcheck -->
