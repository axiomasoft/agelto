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
- `Ai\Prompt` → `$prompt` — версия системной инструкции; канон — оба корня (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) → `ai:prompts:sync`; `is_protected` — сидовые записи read-only в админке.
- `Ai\Correction`/`Ai\DatasetExample` — операторская правка результата и immutable gold-снапшот обучающего контура.
- `App\Enums\Llm\PromptStep` (интерфейс, ядерный `CorePromptStep` + типовые enum'ы анализаторов) — композиция промта ≠ `AnalysisStep` (шаг анализа: screening/extraction) ≠ `App\Enums\Llm\LlmPurpose` (тот же интерфейсный сейм для назначения конкретного вызова).
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
<!-- swissknifeman:registry sha=f8176958025c skills=229 -->

## Приоритет источников правил

1. Проектные правила и скиллы (`.ai/guidelines/`, `.ai/skills/`, корневые инструкции проекта) — высший приоритет.
2. Скиллы swissknifeman (индекс ниже) — архитектура, структура, практики, процессы. Если в проект добавят Laravel Boost, его версионно-специфичные скиллы приоритетнее пересекающихся.

Конфликт правил: побеждает более специфичный источник (проект > Boost > реестр swissknifeman).

## Как найти нужный скилл

1. Определи область задачи и найди бакет в индексе ниже.
2. Выбери скилл по описанию; внутри скилла открывай сниппеты только по его таблице «когда какой сниппет открывать» — не загружай всё подряд.
3. Структурные вопросы нового кода решай в порядке: структура (`laravel-structure`) -> взаимодействие (`dependency-injection`) -> реализация (профильный скилл).
4. Устройство системы скиллов в проекте (источник истины, симлинки, gitignore) — скилл `skills-ssot`.

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

## Установленные скиллы (128)

### architect — Architecture, API and data design, security, observability, tech stack selection

- **agent-design** — Agent design, agentic loop, tool contracts, harness, permission gates
- **api-design** — Designing REST/GraphQL APIs, endpoints, contracts, versioning, authentication
- **architecture** — System architecture, stack choice, ADR, C4 diagrams, deployment, scalability
- **codebase-design** — Shared vocabulary and discipline for designing DEEP modules (Ousterhout/Feathers): Module/Interface/Depth/Seam/Adapter/Leverage/Locality; deletion-test; interface = test surface; 'one adapter is a hypothetical seam, two is a real one'. Activate when designing/improving a module interface, finding deepening opportunities, choosing a seam, or raising testability/AI-navigability.
- **data-schema** — Data schema, entities, attributes, relations. DB-agnostic — markdown tables only
- **domain-modeling** — Active domain-model discipline: challenge terms, stress-test with scenarios, RECORD the glossary (CONTEXT.md) and ADRs INLINE as they crystallize (not in batches). Multi-context via CONTEXT-MAP.md; Rule of Two entry into the shared term-index; term steward model. Activate when clarifying terminology/ubiquitous language, recording an architectural decision, or when another skill builds a domain model (esp. fintech).
- **ecosystem-reuse-gate** — Gate against reinventing functionality when designing a new project/feature: before build-new, force a check against the ecosystem registry (radar) and run universal vendor-vs-build if-then rules. Default is reuse; build-new only when a rule explicitly fires. Activate when designing a new project, a large reusable feature, or picking a stack.
- **eval-design** — Evaluate LLM/AI output quality — eval-set, metrics, regression harness, automated + human review
- **figma-mcp-core** — Figma Dev Mode MCP discipline: real tools (get_metadata, get_variable_defs, get_code, get_code_connect_map, get_screenshot, search_design_system), no reading values off screenshots, selection-driven algorithm, W3C tokens.json extraction, Code Connect reuse. Activate for any UI-from-Figma task via connected MCP, before mapping to a framework.
- **legal-compliance** — GDPR, CCPA, AI Act, DMCA, COPPA, DSA — pre-launch regulatory checklist
- **observability-design** — Logging, metrics, tracing, alerting. SLO/SLI, error budget, runbooks for prod-readiness
- **package-contribution-protocol** — Gate against bloating a depended-on package from a consumer project: Rule of Three, proposal→ADR, minimal contract, dependency direction, SemVer, read-only boundary. Activate when a project task needs editing a shared package, on 'extract common', and when adding a class/provider/interface/abstraction to a depended-on package.
- **security-design** — Data classification, auth strategy, RBAC/ABAC, OWASP Top 10, GDPR, STRIDE threat modeling, ops security checklist
- **tech-stack-selection** — Tech stack selection with trade-off matrix before architecture design. ADR-first step

### devops — Docker environments, CI/CD pipelines, GitOps

- **ci-cd** — GitHub Actions / GitLab CI pipelines for Laravel: lint, tests, build, release + security gates (SAST, dependency audit, image scan) + coverage-gate in CI (Clover threshold, jobs)
- **db-test-preflight** — Pre-flight check of test DB (Postgres in Docker) BEFORE running tests: container up (docker compose ps / pg_isready), target DB exists and its name ends with _test (not dev/prod), migrations applied; distinguishes host vs docker connection. Activate when running Postgres tests, wiring hooks/CI, or when 'tests fail on DB connection'.
- **docker** — Router for Dockerizing a Laravel/PHP app: directs to the right sub-skill (PHP image, Postgres, dev/prod compose split, full service stack, Vite HMR, Makefile). Use when the task involves Docker/Compose for this stack.
- **docker-dev-prod** — Split docker-compose into dev (build + bind-mount) and prod (prebuilt image + named volumes), local override, &app_env anchor
- **docker-php** — Production PHP Docker image: alpine-fpm or all-in-one serversideup/php (FPM+Nginx), entrypoint.d hooks, healthcheck on /up
- **docker-postgres** — PostgreSQL in Docker: pg_isready healthcheck, named volume for data, init script creating test DB <app>_test for test isolation
- **docker-services** — Full Laravel service stack from one image: app (fpm-nginx), queue, scheduler, reverb, optional docs; healthcheck /up and depends_on service_healthy
- **docker-vite** — Vite dev server as separate compose service for Laravel: named volume for node_modules, HMR via localhost, host 0.0.0.0
- **gitops** — Team Git strategy: branch model (main/develop/feature/hotfix), PR workflow, branch protection
- **makefile** — Makefile as single entrypoint: help, .PHONY, Docker command aliases; delegating root Makefile pattern with catch-all to docker/Makefile
- **node-pnpm-preflight** — Check Node/pnpm availability on host before commands and git hooks that use pnpm (commitlint, lint, build).

### frontend — Frontend core: общие фронт-скиллы (web design guidelines, document writer, file upload)

- **document-writer** — Use when writing blog posts or documentation markdown files - provides writing style guide (active voice, present tense), content structure patterns, and MDC component usage. Overrides brevity rules for proper grammar. Use nuxt-content for MDC syntax, nuxt-ui for component props.
- **filepond-upload** — File upload via FilePond in Vue + Laravel: register vue-filepond + plugins (validate-type/size, image-preview, file-poster), server via rahulhaque/laravel-filepond (process/revert on /filepond), bind serverId to form, previews/posters. Activate on file upload, drag-and-drop, FilePond field, image preview, filepond/vue-filepond/laravel-filepond/process/revert/serverId.
- **web-design-guidelines** — Review UI code for Web Interface Guidelines compliance. Use when asked to "review my UI", "check accessibility", "audit design", "review UX", or "check my site against best practices".

### frontend-backend — Backend integration layer: Inertia+Vue, Laravel Echo realtime client, backend→frontend type sync

- **backend-type-sync** — Backend→frontend type sync via spatie/laravel-typescript-transformer: #[TypeScript] on enum/DTO/Spatie-Data, php artisan typescript:transform → generated.d.ts, use App.Enums.* / App.Data.* in Vue/TS. Activate: new enum/DTO needed on frontend, type drift FE↔BE, type-sync setup. Boundary with wayfinder (routes ≠ types).
- **inertia-vue** — Inertia + Vue in Laravel: pages, forms, navigation and layered resources/js organization (pages, layouts, components, composables, stores, features, events, types, actions/wayfinder).
- **laravel-echo-client** — Frontend realtime client Laravel Echo (Reverb/Pusher) in Vue 3: configure Echo from runtime-config/env, subscribe to private/presence channels, listen on broadcastAs events, unsubscribe in onUnmounted, inject X-Socket-ID into axios. Activate wiring websockets on frontend, useEcho/useChannel composable, .broadcastAs event, window.echo/laravel-echo in Vue.

### frontend-linting — JS/TS tooling and style: antfu config, ESLint flat config, Spatie JS style, TS library authoring

- **antfu** — Anthony Fu's opinionated tooling and conventions for JavaScript/TypeScript projects. Use when setting up new projects, configuring ESLint/Prettier alternatives, monorepos, library publishing, or when the user mentions Anthony Fu's preferences.
- **eslint-flat-config** — ESLint flat config (eslint.config.js) for Vue 3 + TypeScript: defineConfigWithVueTs/typescript-eslint + eslint-plugin-vue, @stylistic, import/order and import boundaries, padding-line-between-statements, Prettier chain (eslint-config-prettier + tailwindcss + organize-imports), lint/lint:fix scripts. Triggers: setup/edit eslint or prettier, new Vue/TS project.
- **js-code-style** — JS/TS style per Spatie: 4 spaces, width 120, single quotes, const-first, strict ===, function for named functions, destructuring, named imports.

### frontend-tailwind — Tailwind CSS v4 conventions and dark-mode theming

- **dark-mode** — Dark theme light|dark|system on Vue 3: useDarkMode composable (matchMedia prefers-color-scheme, localStorage + cookie for SSR, toggle .dark class on documentElement, change listener for system), pre-mount init without theme flash, mini toggle. Activate when adding theme switching, fighting light-theme flash (FOUC), syncing theme with system, or restoring theme under SSR.
- **tailwind-conventions** — Tailwind CSS v4 conventions for Laravel/Vue: CSS-first config (@import tailwindcss, @theme tokens instead of tailwind.config.js), dark mode (.dark + dark:* + useAppearance), cn() (clsx+tailwind-merge), prettier-plugin-tailwindcss. Activate editing *.vue/*.css with Tailwind, configuring tailwind/postcss/prettier, dark mode work.

### frontend-vite — Vite build tooling: config/plugins/SSR, project module loader, multi-build, VitePress

- **vite** — Vite build tool configuration, plugin API, SSR, and Vite 8 Rolldown migration. Use when working with Vite projects, vite.config.ts, Vite plugins, or building libraries/SSR apps with Vite.
- **vite-module-loader** — Vite as primary frontend bundler: base config, aliases, build practices
- **vite-multi-build** — Two independent Vite builds in one Laravel project: main app + Filament admin, with separate Vite/Tailwind configs, hotfiles and build dirs.

### frontend-vue — Vue 3 ecosystem: Composition API, best practices, router, VueUse, Pinia state, Vitest testing

- **pinia** — Pinia official Vue state management library, type-safe and extensible. Use when defining stores, working with state/getters/actions, or implementing store patterns in Vue apps.
- **pinia-stores** — Pinia (Vue 3) global state: setup stores (defineStore with function), ref=state/computed=getters/function=actions, use*Store naming, store composition, access outside components, hydration, persist. Activate creating/editing resources/js/stores/**, defineStore, useXxxStore, or 'how to store global state / share data between components'.
- **vitest** — Vitest for Vue/Inertia-on-Laravel: jsdom, globals, tests in resources/js/tests mirror sources, v8 coverage with mandatory exclude of autogenerated code.
- **vue** — Vue 3 Composition API, script setup macros, reactivity system, and built-in components. Use when writing Vue SFCs, defineProps/defineEmits/defineModel, watchers, or using Transition/Teleport/Suspense/KeepAlive.
- **vue-best-practices** — MUST be used for Vue.js tasks. Strongly recommends Composition API with `<script setup>` and TypeScript as the standard approach. Covers Vue 3, SSR, Volar, vue-tsc. Load for any Vue, .vue files, Vue Router, Pinia, or Vite with Vue work. ALWAYS use Composition API unless the project explicitly requires Options API.
- **vue-composition-api** — Project Composition API conventions: ref-first and composables pattern. NOT an API reference (`<script setup>`, defineProps/Emits, reactivity) — that's in `vue`; NOT general workflow — that's in `vue-best-practices`. Only delta-decisions and pointers.
- **vue-dynamic-icons** — Vue: one icon component rendering SVG by string name from a set (lucide-vue-next etc.) via <component :is>; activate on dynamic icon, icon by name, component is icon, passing size/strokeWidth/color, type-safe icon name, swapping icon set.

### frontend-wayfinder — Laravel Wayfinder: type-safe routes/controller actions in the frontend

- **wayfinder** — Laravel Wayfinder: typesafe routes and controller actions for the frontend. Generation, named imports, form(), import architecture boundaries via ESLint.

### general — General agent workflow conventions: git commits, task briefs, project maps, cross-layer checklists

- **anti-drift** — Discipline against agent rabbit-holing: think before coding, simplicity first, surgical edits, circuit-breaker on dragging iterations.
- **compact-responses** — Activate ultra-compact response mode: minimal prose, code-only, no explanations unless asked. Use for terse output — quick edits, code reviews, repeated tasks with established context.
- **complex-task-orchestrator** — Complex-task orchestration: decomposition, context routing, risk management, Definition of Done, minimal verification loop.
- **context-economy** — Claude Code context economy in a project: CLAUDE.md ≤200 lines, path-scoped .claude/rules/, /compact vs /clear, Plan→Clear→Execute, MCP audit, model routing. Officially-supported mechanisms only — no myths like .claudeignore.
- **cross-layer-change-checklist** — Universal checklist for changes spanning multiple layers: code, authorization, UI, docs, tests.
- **git-commit-rules** — Git workflow: commit prep, husky/commitlint, message format, safe action order.
- **git-safety** — Guard against destructive git commands via a Claude Code PreToolUse hook: blocks push/reset --hard/clean -f/branch -D/checkout ./rebase --onto BEFORE execution. Activate on project init (esp. production/embedded) or when asked to add git guardrails / forbid dangerous git.
- **grill-with-docs** — Ruthless grilling session that SIMULTANEOUSLY builds a domain model: refines a plan/design one question at a time, cross-checking code AND external docs (API/RFC/banking specs), and writes the glossary (CONTEXT.md) and ADRs as it goes. Invoke EXPLICITLY to turn a vague idea into a strict spec with fixed terminology.
- **implement** — Implement a chunk of work from a spec/PRD/issue via a minimal chain: tdd-discipline on agreed seams, regular typecheck/tests, a full run at the end, code-review, commit to the current branch. Invoke EXPLICITLY (writes code) — the final step after spec-interview/grill-with-docs/to-issues.
- **mcp-advisor** — Pick MCP servers for a project at onboarding/sync: which to connect (context7/playwright/github/fetch/sequential-thinking + maind hub), why, when NOT to, and the cost in context/permissions/Node-deps. Activate when adding/onboarding/syncing a project, editing .mcp.json, or choosing MCP — emits a justified connection plan, not a dump of every server.
- **naming-conventions** — Naming discipline (language-agnostic): name reveals intent, not implementation; fight noise words (Manager/Helper/data/process) and combinatorial method explosion (findByXOrFail/getOrNull). Activate when naming or reviewing names of functions, classes, variables, methods.
- **packages-stack** — Project external-dependency registry methodology: which packages are used and for what, a per-package rule (when to use, what NOT to do by hand), reliance on OFFICIAL docs. Activate when choosing/adding a dependency or mapping the stack at project init; language-agnostic.
- **plan-protocol** — Executable plans in plans/<ID>: schemas, 6 statuses, DoR, neutral class/effort/capability routing, semantic Next with provider renderers, handoff and archive.
- **session-handoff** — Cross-session/agent context handoff: compress a session into a structured doc — state, decisions, open items, next steps.
- **shell** — Growing cookbook of shell constructs compatible with this environment's hooks (currently rm/rmdir vs the safe-dirs auto-approve hook; more patterns get added over time). Activate on a hard-deny-hook block and before writing rm/rmdir commands.
- **skills-ssot** — Single source of truth for skills across multiple AI agents: .ai/skills + symlinks, gitignore, update cycle
- **spec-interview** — Pre-code interview: agent asks one question at a time, reads the codebase itself where it can, proposes a recommended answer; output — a filled task brief per task-brief-template.
- **task-brief-template** — Brief template for complex agent tasks: goal, scope, invariants, explicit assumptions, out of scope, acceptance criteria, definition of done. Plus Plan→Clear→Execute pattern and loop fields for iterative runs: verifier, exit condition, hard-stop.
- **verify-claims** — Before a verdict on an EXTERNAL fact (library version/API, 'still current', best practice, market, service/MCP capability) — verify via RAG, not memory. Ladder: context7 → Perplexity → WebSearch → research for high-stakes. Activate on audit/plan review, big planning, library/stack choice, claiming 'this is standard'. Repo-grounded/stable knowledge — skip.
- **workflow-craft** — Activate on \"write a workflow\"/multi-agent orchestration: match 4 canonical templates first, then design-vs-dev stage matrix; slots per README.
- **writing-style** — Tone & language of code artifacts: Russian by default for comments, commit bodies, test descriptions it()/test(), docstrings, internal docs; live language, no bureaucratese, explain WHY not WHAT. Activate when writing comments, commit bodies, test descriptions, internal docs (git format/mechanics → git-commit-rules).

### laravel-architecture — Scaling architecture: modular monolith/DDD, enum state machines, enum attributes, subagent orchestration

- **config-driven-abstraction** — Config-driven cache-safe Registry+Builder for multi-panel/multi-tenant Laravel: immutable registry key→instance, fluent builder w/ feature-flags, config name-maps not closures, per-variant class not runtime $table. Activate designing multi-panel/multi-tenant resolution, pluggable drivers. Triggers: registry, builder pattern, config:cache, driver resolution.
- **eloquent-accessors** — Activate when writing/refactoring Eloquent accessors and mutators: when you see legacy getXxxAttribute()/setXxxAttribute() and should move to modern Attribute::make() (get/set), or when deciding where to place and how to type a computed model property. Triggers: getFooAttribute, Attribute::make, accessor, mutator, computed attribute, Illuminate\\Database\\Eloquent\\Casts\\Attribute
- **enum-attributes** — Use for enum metadata: replace label()/color()/description() match-ladders with PHP attributes #[Label]/#[Color]/#[Description] on cases + reflection resolver; or export enum as TS type via #[TypeScript]. Triggers: PHP enum, attributes on cases, getLabel()/getColor(), Filament HasLabel, laravel-typescript-transformer.
- **enum-state-machine** — Activate when a domain status (Order/Document/Subscription) needs transition rules: edge graph via enum transitionDefinitions(), runtime registry (canTransition/allowedFrom), Action-executed transition under DB::transaction(). Triggers: PHP status enum, allowed transitions, StateMachine, canTransitionTo, illegal status transition.
- **laravel-subagents** — Subagent orchestration on large Laravel tasks: activation threshold, delegating research to Explore agents, review via laravel-reviewer, tests via laravel-test-writer, worktree isolation of parallel edits. Do not apply to small/medium tasks.
- **modular-architecture** — Scale Laravel code as it grows: organize by business domain, Modular Monolith and DDD patterns; implement with nwidart/laravel-modules (module:make*, module structure, namespace without Modules\\, Filament discovery); starting structure — php/laravel-structure

### laravel-auth — Authorization & security: permissions/RBAC, attribute-based gates, security audit

- **attribute-authorization** — Declarative method-level authorization via #[CheckPermission] attribute (enum permission + route args) and reflection-middleware CheckAccess over Gate. Activate for declarative action-level checks, enum permissions instead of strings, reflection middleware.
- **laravel-permissions** — Manual/native Laravel RBAC: policies, gates, entity-scoped permissions without a dedicated package. Need azguard package → skill azguard; declarative permission attributes on actions → attribute-authorization.
- **laravel-security-audit** — Audit Laravel code for sharp edges: raw Eloquent queries, mass assignment, Blade XSS, CSRF bypass, open redirect, unsafe serialization, authz holes.

### laravel-data-layer — Typed data + realtime: spatie/laravel-data DTOs (TS export), Echo/Reverb broadcasting

- **laravel-broadcasting** — Laravel Echo + Reverb/Pusher/Ably: broadcast events, channel authorization, presence/whisper, model broadcasting.
- **laravel-data** — DTOs with spatie/laravel-data: typed Data classes, nested DataCollection, casts/transformers, computed properties, mapName/mapInputName, from(request) with validation, API/Inertia output, #[TypeScript] annotation. Activate: need a type-safe DTO instead of array/array-access, parse incoming request into an object, single data shape between back and front, replace ad-hoc JsonResource with Data.

### laravel-extras — Optional Laravel runtime packages: media library, typed settings, Pennant feature flags

- **medialibrary** — spatie/laravel-medialibrary: attach files to Eloquent models, collections, conversions, get URLs
- **pennant-development** — Laravel Pennant feature flags: define/active/for-scope, the @feature directive, activation/deactivation, A/B and gradual rollouts. Adapted from laravel/boost (strategy=notify).
- **spatie-settings** — spatie/laravel-settings: typed settings classes (extends Settings, group()), migrations add/migrate/rename, cache, runtime vs config/ choice, Filament SettingsPage binding, infra-config (websocket/Reverb) admin→frontend via DTO. Activate creating/editing a settings class/property/migration, an edit screen, or moving infra setting to runtime override.

### laravel-filament — Filament admin panels: resources, schemas, tables, widgets, actions, tenancy

- **filament** — Filament v5 — Panels, Resources, Schemas, Tables, Widgets, Actions: file structure, make:filament-* CLI reference, correct namespaces, authorization, tenancy, common pitfalls. Scaffold only via artisan.

### laravel-testing — Laravel testing: Pest feature/unit, DB isolation guard, Dusk browser tests, health checks

- **health-checks** — spatie/laravel-health in Laravel: custom Check classes (disk, queue, scheduler heartbeat, TCP port, cache, DB) via Health::checks(); /health endpoint (live/ready, JSON/Filament); scheduled runs, failure notifications. Activate on: health check, liveness/readiness, /health, spatie health, disk/queue/cache/heartbeat checks.
- **laravel-dusk** — Laravel Dusk: browser E2E tests, isolated test DB, run from Docker via host ChromeDriver, Page Objects
- **laravel-testing** — Feature/unit/pest testing patterns: test DB isolation, Docker/local env detection, coverage gate, Pest composition (setUp traits, fixtures, beforeEach fake/freeze, datasets)
- **test-isolation-guard** — Guard Laravel tests against running on prod/dev DB and prod media disk: bootstrap.php sets DB_DATABASE=*_test and media-test before autoload, TestEnvironmentGuard aborts createApplication() on isolation breach, safe RefreshDatabase. Activate when setting up the test environment, protecting CI, or suspecting tests hit dev/prod DB.

### operator — Incident response, runbooks, postmortems, on-call and capacity planning

- **capacity-planning** — Capacity planning — load forecasting, headroom, autoscaling policy, cost budget, what to monitor, when to scale up/down
- **incident-response** — Prod incident response process — severity levels, roles (IC/Comms/SME), communication, action log, handoff to postmortem
- **oncall-rotation** — On-call rotation design — schedule, primary/secondary, handoff, compensation, burnout defusing, paging policy
- **postmortem** — Blameless postmortem after SEV-1/2 incident — timeline, root cause (5 Whys), contributing factors, action items, knowledge distribution
- **runbook** — Operational runbook template per P1/P2 alert — symptoms, diagnostics, mitigation steps, escalation, post-actions

### oss-dev — Open-source development: releases, governance, DX, dependency audits + language references

- **gh-review** — Review and handoff changes via GitHub CLI (gh) with context economy: gh pr diff/view/comment/review, gh api for targeted file reads instead of loading whole files. Activate when reviewing PRs, handing off to a dev, reading others' changes on GitHub.

### php — PHP and Laravel core: architecture, structure, conventions, DI, database, repositories, patterns, static analysis

- **code-style-spatie** — Laravel/PHP code style per Spatie guidelines: Laravel conventions over PSR-12, typing instead of docblocks, early returns, happy path last.
- **cursor-pagination-design** — Stable keyset (seek) pagination for infinite scroll/feeds: sortable non-nullable key, composite index, handle ties/concurrent inserts, avoid offset drift. Activate building infinite-scroll, cursor/seek pagination, feed lists, or fixing 'duplicates/skips while scrolling'. Triggers: keyset, seek pagination, paginate after, offset drift.
- **database** — Database migrations, models, and table naming. Activate when writing migrations, creating models, defining schema, foreign keys, or working in database/migrations/.
- **dependency-injection** — Layer interaction via constructor injection: final readonly + promotion, injection matrix, Action composition
- **laravel** — Laravel architecture patterns: actions, repositories, resources
- **laravel-structure** — Laravel project structure canon for greenfield and adding domains: domain taxonomy, layer-mirror rule, class placement and naming
- **named-arguments** — PHP rule: method/function calls use named arguments; exceptions; activate when writing/reviewing calls or when readability/parameter order is mentioned
- **namespace-refactor-safety** — Discipline for large PHP/Laravel namespace or class-move refactors: three-phase alias → update use-statements → hard rename; anchor find-replace on FQN to dodge prefix collisions; keep a contract at the seam for phpstan; arch-tests for layer parity. Activate on: namespace refactor, move class, rename namespace, domain restructure, mass rename.
- **pao** — Laravel PAO — agent-optimized PHP tool output: PHPUnit/Pest/Paratest/PHPStan/Rector/Artisan collapse to compact JSON (~20 tokens vs thousands) when run inside an AI agent. Install, verify, limits.
- **php-naming-conventions** — Naming Laravel/PHP entities: repository methods by intent (not findByCodeOrFail-explosion), Action/DTO/VO/Enum/Event/Job, tables/columns/routes. Kills junk names AI loves to generate. Activate when creating or reviewing class/method/migration names.
- **php-patterns** — PHP domain patterns: VO, DTO, pipeline, specification — selection guide
- **php-upgrade-checklist** — PHP version upgrade checklist (minor/major): sync image/CI, composer update + clear deprecations, phpstan baseline, full suite, update rector sets, review RFCs for incompatibilities. Activate before bumping PHP version.
- **production-readiness-checklist** — Verify production-readiness invariants happy-path tests miss: transaction atomicity, race-condition-safe idempotency, config defaults, non-nullable cursor keys, after-commit side-effects, soft-delete, id-strategy compatibility, single queue-retry owner, atomic state transitions. Activate before/at review of risky Laravel slices (services, queues, drivers, migrations), queue semantics, or before merge.
- **repositories** — Laravel repositories: read/store split, model scopes, delta-DTO, facade, VisibilityService
- **static-analysis** — PHP/Laravel static analysis & linting: Laravel Pint, PHPStan, Rector locally + CI, reference configs
- **transactional-outbox-relay** — At-least-once async event delivery via transactional outbox: write+outbox in one transaction, relay-sweep sole retry owner, idempotent consumers, backoff+DLQ, after-commit effects. Activate wiring webhook/event delivery, an outbox, or async jobs that must not be lost/double-fired. Triggers: outbox, at-least-once, duplicate events, relay sweep.

### pm — Product management: BRD, PRD, roadmap, monetization, unit economics

- **brd** — Business Requirements Document: functional requirements, user stories, acceptance criteria
- **business-process** — Business processes, workflow, FSM, sequence/state diagrams, error handling

### quality — Code review, refactoring plans, tech debt audits, test strategy

- **artifact-review** — Review taxonomy by artifact type: three profiles (plan/spec, documentation, code), each with a conformance constitution and dimensions; shared severity axis Blocker/Major/Minor/Nit + independent Conventional-Comments label. Activate when reviewing any artifact — pick the profile first, then measure.
- **code-review** — Code review process & checklist: mandatory checks, comment severity grades, async vs sync, PR-comment template
- **code-simplifier** — Simplify freshly written code without changing behavior: dead code, needless abstractions, dups, align to project patterns; YAGNI ladder before coding + diff delete-list (ponytail discipline). Tests pin behavior.
- **diagnosing-bugs** — Discipline for diagnosing hard bugs and performance regressions: FIRST build a tight reproducible feedback loop (that IS the skill), only then 3–5 ranked hypotheses, targeted instrumentation tagged [DEBUG-xxxx], a regression test on the right seam, cleanup. Activate on 'debug/fix this', a reproducible or flaky bug, 'crashes/throws/is slow'.
- **mutation-testing** — PHP mutation testing via Infection: infection.json5 (source/excludes, mutators, logs, minMsi/minCoveredMsi gates), run with --threads and --git-diff-lines for CI increment, reading MSI and escaped mutants. Activate when setting up Infection, adding a mutation test-quality gate to CI, reading an MSI report, or investigating surviving mutants.
- **playwright-e2e** — Browser E2E with Playwright for a web app (frontend-agnostic: Vue/Inertia/Livewire): playwright.config with webServer + projects, single-login auth via storageState, data-testid convention, data/media isolation between runs, CI run. Activate when adding/fixing an end-to-end browser scenario, configuring playwright.config, or storageState login.
- **refactoring-plan** — Safe refactoring plan: scope, Mikado / strangler pattern, atomic steps, done criteria, rollback
- **tdd-discipline** — TDD discipline via red-green-refactor by VERTICAL slices (tracer bullets), NOT horizontally: explicit ban on writing all tests before implementation; tests verify behavior through the PUBLIC interface and survive refactors. Activate when building a feature/fix test-first, mentioning red-green-refactor, or integration tests.
- **tech-debt-audit** — Tech-debt inventory, classification (architectural/code/dependency/test/process), prioritization by impact × effort, payback ROI calc
- **test-strategy** — Test strategy: pyramid (unit/integration/e2e), coverage policy, mock vs real, which features need which test level, test-suite composition (Arch/Feature/Unit) and Pest Arch architecture tests
- **testing-safety-report** — Safety report after a risky change (migrations, schema/layer refactor, dependency upgrade): what changed structurally, which tests ran and with what result, what risks and control checks remain. Do it after the change to make it auditable.

### research — Research and knowledge management

- **query-craft** — How to FORMULATE a search/doc-engine query (Perplexity Web · WebSearch · context7): version/stack, timeframe, format, limit; pick engine+mode and sources. Activate BEFORE any external search, esp. a library/framework: ban bare 'Laravel how to X?'. Not when-to-verify (verify-claims) nor what-to-connect (mcp-advisor) — only HOW to ask.

### system — Local environment topology: the three hub nodes (Brain vault, swissknifeman, projects base) and how agents navigate between projects and their docs

- **agent-packs** — Install and VERSIONED upgrade of agent-packs (Claude Code agents/workflows/hooks) from harness into a consumer project: stack detect -> interview -> render+copy -> lock; upgrade via CHANGELOG delta with 3-way reasoning over sha256 in lock, no diff engine. Activate on: install/upgrade agent-pack, add roles+dev-loop+review-gate to project, versioned upgrade of agent templates, agent-pack.lock.
- **brain-locator** — Reach the Brain vault (ecosystem Obsidian docs) from a project — non-hardcoded path resolution (maind/$HOME), own and linked-project docs, sync
- **local-topology** — Root map of the local dev environment — three hub nodes (Brain vault, swissknifeman, projects base) and navigation between projects, their code and docs. Read from ~/.swissknifeman/topology.json
- **session-observer** — EXPERIMENTAL (enable per-project). Post-hoc Claude Code session analysis from JSONL logs — finds weak spots on 3 axes: agents (skill-skip, drift, loops), skills (gaps, dead weight, thin), settings (hooks, permissions). Findings → learnings via memory-hook, human promotes. Activate AFTER a work cycle / at session end / on request «analyze session» — only if enabled in project.
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
