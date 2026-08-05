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
- Crawling-инфраструктура внутри Procurement: фильтр профиля площадки — типизированный
  `spatie/laravel-data` DTO, резолвится по коду реестра config-картой `crawler.filters`
  (`app/Dto/Procurement/Crawler/EisSearchFilter.php` — образец для ЕИС/44-ФЗ); отслеживание
  изменений — перекрытие окон + плановый суточный дозор (`crawler.tracking.*`,
  `DeltaScanProcessor`); доверие TLS площадки — единственный носитель `SourceTlsTrust`
  (`config('crawler.tls.bundles')`), не системный доверенный набор. Детали — `docs/03_Dev/crawling-pipeline.md`, скилл `procurement-pipeline-dev`.
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
<!-- swissknifeman:registry sha=c0b2c63eeecf skills=229 -->

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

## Скиллы вне нативного листинга (76)

> Эти скиллы установлены swissknifeman, но не подтверждены в нативном активном наборе проекта — перечислены поимённо, чтобы не потерять обнаружение.

### architect — Architecture, API and data design, security, observability, tech stack selection

- **agent-design** — Use when designing an agent: agentic loop, tool contracts, harness, permission gates.
- **api-design** — Use when designing REST/GraphQL APIs: endpoints, contracts, versioning, authentication.
- **architecture** — Use when choosing system architecture: stack choice, ADR, C4 diagrams, deployment, scalability.
- **codebase-design** — Shared vocabulary and discipline for designing DEEP modules (Ousterhout/Feathers): Module/Interface/Depth/Seam/Adapter/Leverage/Locality; deletion-test; interface = test surface; 'one adapter is a hypothetical seam, two is a real one'. Activate when designing/improving a module interface, finding deepening opportunities, choosing a seam, or raising testability/AI-navigability.
- **data-schema** — Use when designing a data schema: entities, attributes, relations. DB-agnostic — markdown tables only.
- **dev-engineering-super-skill** — Broad engineering-bucket navigator (composite of external dev skills). For specific work prefer the granular skill — architecture→architecture, API→api-design, data→data-schema, domain→domain-modeling, agents→agent-design, eval→eval-design, security→security-design, observability→observability-design, stack→tech-stack-selection, codebase→codebase-design. Use only for a cross-cutting overview when no single skill fits.
- **domain-modeling** — Active domain-model discipline: challenge terms, stress-test with scenarios, RECORD the glossary (CONTEXT.md) and ADRs INLINE as they crystallize (not in batches). Multi-context via CONTEXT-MAP.md; Rule of Two entry into the shared term-index; term steward model. Activate when clarifying terminology/ubiquitous language, recording an architectural decision, or when another skill builds a domain model (esp. fintech).
- **ecosystem-reuse-gate** — Gate against reinventing functionality when designing a new project/feature: before build-new, force a check against the ecosystem registry (radar) and run universal vendor-vs-build if-then rules. Default is reuse; build-new only when a rule explicitly fires. Activate when designing a new project, a large reusable feature, or picking a stack.
- **eval-design** — Use when evaluating LLM/AI output quality: eval-set, metrics, regression harness, automated + human review.
- **figma-mcp-core** — Figma Dev Mode MCP discipline: real tools (get_metadata, get_variable_defs, get_code, get_code_connect_map, get_screenshot, search_design_system), no reading values off screenshots, selection-driven algorithm, W3C tokens.json extraction, Code Connect reuse. Activate for any UI-from-Figma task via connected MCP, before mapping to a framework.
- **legal-compliance** — Use when checking regulatory compliance before launch: GDPR, CCPA, AI Act, DMCA, COPPA, DSA.
- **observability-design** — Use when designing observability for prod-readiness: logging, metrics, tracing, alerting, SLO/SLI, error budget, runbooks.
- **package-contribution-protocol** — Gate against bloating a depended-on package from a consumer project: Rule of Three, proposal→ADR, minimal contract, dependency direction, SemVer, read-only boundary. Activate when a project task needs editing a shared package, on 'extract common', and when adding a class/provider/interface/abstraction to a depended-on package.
- **security-design** — Data classification, auth strategy, RBAC/ABAC, OWASP Top 10, GDPR, STRIDE threat modeling, ops security checklist
- **tech-stack-selection** — Use when selecting a tech stack: trade-off matrix before architecture design, ADR-first step.

### devops — Docker environments, CI/CD pipelines, GitOps

- **ci-cd** — GitHub Actions / GitLab CI pipelines for Laravel: lint, tests, build, release + security gates (SAST, dependency audit, image scan) + coverage-gate in CI (Clover threshold, jobs)
- **db-test-preflight** — Pre-flight check of test DB (Postgres in Docker) BEFORE running tests: container up (docker compose ps / pg_isready), target DB exists and its name ends with _test (not dev/prod), migrations applied; distinguishes host vs docker connection. Activate when running Postgres tests, wiring hooks/CI, or when 'tests fail on DB connection'.
- **docker** — Router for Dockerizing a Laravel/PHP app: directs to the right sub-skill (PHP image, Postgres, dev/prod compose split, full service stack, Vite HMR, Makefile). Use when the task involves Docker/Compose for this stack.
- **docker-dev-prod** — Split docker-compose into dev (build + bind-mount) and prod (prebuilt image + named volumes), local override, &app_env anchor
- **docker-php** — Production PHP Docker image: alpine-fpm or all-in-one serversideup/php (FPM+Nginx), entrypoint.d hooks, healthcheck on /up
- **docker-postgres** — PostgreSQL in Docker: pg_isready healthcheck, named volume for data, init script creating test DB <app>_test for test isolation
- **docker-services** — Full Laravel service stack from one image: app (fpm-nginx), queue, scheduler, reverb, optional docs; healthcheck /up and depends_on service_healthy
- **docker-vite** — Vite dev server as separate compose service for Laravel: named volume for node_modules, HMR via localhost, host 0.0.0.0
- **gitops** — Use when defining team Git strategy: branch model (main/develop/feature/hotfix), PR workflow, branch protection.
- **makefile** — Makefile as single entrypoint: help, .PHONY, Docker command aliases; delegating root Makefile pattern with catch-all to docker/Makefile
- **node-pnpm-preflight** — Check Node/pnpm availability on host before commands and git hooks that use pnpm (commitlint, lint, build).

### laravel-auth — Authorization & security: permissions/RBAC, attribute-based gates, security audit

- **attribute-authorization** — Declarative method-level authorization via #[CheckPermission] attribute (enum permission + route args) and reflection-middleware CheckAccess over Gate. Activate for declarative action-level checks, enum permissions instead of strings, reflection middleware.
- **laravel-permissions** — Manual/native Laravel RBAC: policies, gates, entity-scoped permissions without a dedicated package. Need azguard package → skill azguard; declarative permission attributes on actions → attribute-authorization.
- **laravel-security-audit** — Audit Laravel code for sharp edges: raw Eloquent queries, mass assignment, Blade XSS, CSRF bypass, open redirect, unsafe serialization, authz holes.

### laravel-data-layer — Typed data + realtime: spatie/laravel-data DTOs (TS export), Echo/Reverb broadcasting

- **laravel-broadcasting** — Laravel Echo + Reverb/Pusher/Ably: broadcast events, channel authorization, presence/whisper, model broadcasting.
- **laravel-data** — DTOs with spatie/laravel-data: typed Data classes, nested DataCollection, casts/transformers, computed properties, mapName/mapInputName, from(request) with validation, API/Inertia output, #[TypeScript] annotation. Activate: need a type-safe DTO instead of array/array-access, parse incoming request into an object, single data shape between back and front, replace ad-hoc JsonResource with Data.

### laravel-testing — Laravel testing: Pest feature/unit, DB isolation guard, Dusk browser tests, health checks

- **health-checks** — spatie/laravel-health in Laravel: custom Check classes (disk, queue, scheduler heartbeat, TCP port, cache, DB) via Health::checks(); /health endpoint (live/ready, JSON/Filament); scheduled runs, failure notifications. Activate on: health check, liveness/readiness, /health, spatie health, disk/queue/cache/heartbeat checks.
- **laravel-dusk** — Laravel Dusk: browser E2E tests, isolated test DB, run from Docker via host ChromeDriver, Page Objects
- **laravel-testing** — Feature/unit/pest testing patterns: test DB isolation, Docker/local env detection, coverage gate, Pest composition (setUp traits, fixtures, beforeEach fake/freeze, datasets)
- **test-isolation-guard** — Guard Laravel tests against running on prod/dev DB and prod media disk: bootstrap.php sets DB_DATABASE=*_test and media-test before autoload, TestEnvironmentGuard aborts createApplication() on isolation breach, safe RefreshDatabase. Activate when setting up the test environment, protecting CI, or suspecting tests hit dev/prod DB.

### operator — Incident response, runbooks, postmortems, on-call and capacity planning

- **capacity-planning** — Capacity planning — load forecasting, headroom, autoscaling policy, cost budget, what to monitor, when to scale up/down
- **incident-response** — Prod incident response process — severity levels, roles (IC/Comms/SME), communication, action log, handoff to postmortem
- **oncall-rotation** — On-call rotation design — schedule, primary/secondary, handoff, compensation, burnout defusing, paging policy
- **operations-cx-super-skill** — Broad operations/CX-bucket navigator (composite of external CX skills). For specific work prefer the granular skill — incidents→incident-response, postmortem→postmortem, on-call→oncall-rotation, capacity→capacity-planning, runbooks→runbook. Use only for a cross-cutting overview when no single skill fits.
- **postmortem** — Blameless postmortem after SEV-1/2 incident — timeline, root cause (5 Whys), contributing factors, action items, knowledge distribution
- **runbook** — Operational runbook template per P1/P2 alert — symptoms, diagnostics, mitigation steps, escalation, post-actions

### php — PHP and Laravel core: architecture, structure, conventions, DI, database, repositories, patterns, static analysis

- **code-style-spatie** — Laravel/PHP code style per Spatie guidelines: Laravel conventions over PSR-12, typing instead of docblocks, early returns, happy path last.
- **cursor-pagination-design** — Stable keyset (seek) pagination for infinite scroll/feeds: sortable non-nullable key, composite index, handle ties/concurrent inserts, avoid offset drift. Activate building infinite-scroll, cursor/seek pagination, feed lists, or fixing 'duplicates/skips while scrolling'. Triggers: keyset, seek pagination, paginate after, offset drift.
- **database** — Database migrations, models, and table naming. Activate when writing migrations, creating models, defining schema, foreign keys, or working in database/migrations/.
- **dependency-injection** — Layer interaction via constructor injection: final readonly + promotion, injection matrix, Action composition
- **laravel** — Use when applying Laravel architecture patterns: actions, repositories, resources.
- **laravel-best-practices** — Laravel best practices: 20 rule categories (Eloquent, validation, cache, queues, security) with routing by task.
- **laravel-structure** — Laravel project structure canon for greenfield and adding domains: domain taxonomy, layer-mirror rule, class placement and naming
- **named-arguments** — PHP rule: method/function calls use named arguments; exceptions; activate when writing/reviewing calls or when readability/parameter order is mentioned
- **namespace-refactor-safety** — Discipline for large PHP/Laravel namespace or class-move refactors: three-phase alias → update use-statements → hard rename; anchor find-replace on FQN to dodge prefix collisions; keep a contract at the seam for phpstan; arch-tests for layer parity. Activate on: namespace refactor, move class, rename namespace, domain restructure, mass rename.
- **pao** — Laravel PAO — agent-optimized PHP tool output: PHPUnit/Pest/Paratest/PHPStan/Rector/Artisan collapse to compact JSON (~20 tokens vs thousands) when run inside an AI agent. Install, verify, limits.
- **php-naming-conventions** — Naming Laravel/PHP entities: repository methods by intent (not findByCodeOrFail-explosion), Action/DTO/VO/Enum/Event/Job, tables/columns/routes. Kills junk names AI loves to generate. Activate when creating or reviewing class/method/migration names.
- **php-patterns** — Use when choosing a PHP domain pattern: VO, DTO, pipeline, specification — selection guide.
- **php-upgrade-checklist** — PHP version upgrade checklist (minor/major): sync image/CI, composer update + clear deprecations, phpstan baseline, full suite, update rector sets, review RFCs for incompatibilities. Activate before bumping PHP version.
- **production-readiness-checklist** — Verify production-readiness invariants happy-path tests miss: transaction atomicity, race-condition-safe idempotency, config defaults, non-nullable cursor keys, after-commit side-effects, soft-delete, id-strategy compatibility, single queue-retry owner, atomic state transitions. Activate before/at review of risky Laravel slices (services, queues, drivers, migrations), queue semantics, or before merge.
- **repositories** — Use when building Laravel repositories: read/store split, model scopes, delta-DTO, facade, VisibilityService.
- **static-analysis** — PHP/Laravel static analysis & linting: Laravel Pint, PHPStan, Rector locally + CI, reference configs
- **transactional-outbox-relay** — At-least-once async event delivery via transactional outbox: write+outbox in one transaction, relay-sweep sole retry owner, idempotent consumers, backoff+DLQ, after-commit effects. Activate wiring webhook/event delivery, an outbox, or async jobs that must not be lost/double-fired. Triggers: outbox, at-least-once, duplicate events, relay sweep.

### quality — Code review, refactoring plans, tech debt audits, test strategy

- **artifact-review** — Review taxonomy by artifact type: three profiles (plan/spec, documentation, code), each with a conformance constitution and dimensions; shared severity axis Blocker/Major/Minor/Nit + independent Conventional-Comments label. Activate when reviewing any artifact — pick the profile first, then measure.
- **code-review** — Code review process & checklist: mandatory checks, comment severity grades, async vs sync, PR-comment template
- **code-simplifier** — Simplify freshly written code without changing behavior: dead code, needless abstractions, dups, align to project patterns; YAGNI ladder before coding + diff delete-list (ponytail discipline). Tests pin behavior.
- **diagnosing-bugs** — Discipline for diagnosing hard bugs and performance regressions: FIRST build a tight reproducible feedback loop (that IS the skill), only then 3–5 ranked hypotheses, targeted instrumentation tagged [DEBUG-xxxx], a regression test on the right seam, cleanup. Activate on 'debug/fix this', a reproducible or flaky bug, 'crashes/throws/is slow'.
- **mutation-testing** — PHP mutation testing via Infection: infection.json5 (source/excludes, mutators, logs, minMsi/minCoveredMsi gates), run with --threads and --git-diff-lines for CI increment, reading MSI and escaped mutants. Activate when setting up Infection, adding a mutation test-quality gate to CI, reading an MSI report, or investigating surviving mutants.
- **playwright-e2e** — Browser E2E with Playwright for a web app (frontend-agnostic: Vue/Inertia/Livewire): playwright.config with webServer + projects, single-login auth via storageState, data-testid convention, data/media isolation between runs, CI run. Activate when adding/fixing an end-to-end browser scenario, configuring playwright.config, or storageState login.
- **refactoring-plan** — Use when planning a safe refactor: scope, Mikado / strangler pattern, atomic steps, done criteria, rollback.
- **tdd-discipline** — TDD discipline via red-green-refactor by VERTICAL slices (tracer bullets), NOT horizontally: explicit ban on writing all tests before implementation; tests verify behavior through the PUBLIC interface and survive refactors. Activate when building a feature/fix test-first, mentioning red-green-refactor, or integration tests.
- **tech-debt-audit** — Tech-debt inventory, classification (architectural/code/dependency/test/process), prioritization by impact × effort, payback ROI calc
- **test-strategy** — Test strategy: pyramid (unit/integration/e2e), coverage policy, mock vs real, which features need which test level, test-suite composition (Arch/Feature/Unit) and Pest Arch architecture tests
- **testing-safety-report** — Safety report after a risky change (migrations, schema/layer refactor, dependency upgrade): what changed structurally, which tests ran and with what result, what risks and control checks remain. Do it after the change to make it auditable.

### system — Local environment topology: the three hub nodes (Brain vault, swissknifeman, projects base) and how agents navigate between projects and their docs

- **agent-packs** — Install and VERSIONED upgrade of agent-packs (Claude Code agents/workflows/hooks) from harness into a consumer project: stack detect -> interview -> render+copy -> lock; upgrade via CHANGELOG delta with 3-way reasoning over sha256 in lock, no diff engine. Activate on: install/upgrade agent-pack, add roles+dev-loop+review-gate to project, versioned upgrade of agent templates, agent-pack.lock.
- **brain-locator** — Reach the Brain vault (ecosystem Obsidian docs) from a project — non-hardcoded path resolution (maind/$HOME), own and linked-project docs, sync
- **cross-project-coordinator** — Orchestrate cross-project code analysis: walk linked projects (via topology), find dups/divergence/shared-package candidates, emit prioritized unification recommendations. Read-only — no edits, no PRs. Run for broad cross-project work, not a single repo.
- **local-topology** — Root map of the local dev environment — three hub nodes (Brain vault, swissknifeman, projects base) and navigation between projects, their code and docs. Read from ~/.swissknifeman/topology.json
- **session-observer** — EXPERIMENTAL (enable per-project). Post-hoc Claude Code session analysis from JSONL logs — finds weak spots on 3 axes: agents (skill-skip, drift, loops), skills (gaps, dead weight, thin), settings (hooks, permissions). Findings → learnings via memory-hook, human promotes. Activate AFTER a work cycle / at session end / on request «analyze session» — only if enabled in project.
- **shared-memory** — Constellation shared brain via hook ~/.claude/hooks/memory/ + mAInd daemon. Recall before work, remember after. Project canon = MODE=maind (daemon :4111, scope isolation maind-side): private project:<id> + tag-shared ns:<namespace> + global; recall unions them; SessionStart auto-injects digest (native memory off). Routing rule: one fact in one store. Swappable backend: maind|native|file|federation|off.
- **swissknifeman-locator** — Reach swissknifeman (skills node) from an external repo — non-hardcoded path resolution, root config.json as registry of linked projects, project↔hub link scheme
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
