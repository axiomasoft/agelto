=== .ai/agent-task-templates rules ===

# Шаблоны задач для агента

Краткие промпты под правила проекта (русский язык, parsing pipeline, named arguments, Docker tests). Использовать как стартовые сообщения в Cursor.

## Code review

```
Проведи ревью последнего diff: границы Module/Job/Service, влияние на стадии парсинга 1–4,
соответствие docs/workflow/parsing-process.md. Укажи только блокирующие замечания и конкретные файлы/строки.
Не предлагай рефакторинг вне области diff.
```

## Генерация тестов

```
Добавь минимальные Pest-тесты для изменённого поведения: один happy path и один негативный.
Следуй testing-rules: make test-preflight перед artisan test.
Запуск: php artisan test --compact --filter=...
```

## Security checklist

```
Проверь изменение на: утечку данных через Inertia props, обход Policy/Gate, mass assignment,
сырой SQL, утечку proxy credentials. Перечисли только реальные риски с путём исправления.
```

## Docker deploy

```
Нужен деплой после git pull. Определи минимальную make-цель: prod:deploy:code / prod:deploy:php-deps /
prod:deploy:frontend / prod:deploy:runtime / prod:deploy:image. Обоснуй выбор по diff.
```

=== .ai/architecture rules ===

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

=== .ai/glossary rules ===

# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию. Грань — простота ↔ техническая точность.

Полный справочник (термины, enum'ы, таблицы, исключения): `docs/dev/glossary.md`. Общие правила сущностей PHP/Laravel: skill `naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»).
- `CrawlerRun` → `$run` — один прогон (не голый `Run`/`Crawl`).
- `Link` → `$link` — найденное извещение ЕИС (не `Url`).
- `LinkAttachment` → `$attachment` — файл-вложение (не голый `Document`/`Attachment` для модели).
- `DocumentAnalysis` → `$analysis` — сессия AI-анализа вложения.
- `DocumentAnalysisResponse` → `$response` — ответ AI на шаге.
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Order` / `Lot` / `PurchaseObject` — заказ / лот / позиция.

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Глаголы методов

- `find*` — поиск по идентичности, **nullable**, без суффиксов `ByXAndY` (`findCrawler($id)`, `findLink($platformId, $regNumber)`).
- `get*` — вернуть определённое/вычисленное (`getPendingLinks()`); если существительное читается ясно — без `get` (`owner()`, `systemInstructionsFor($step)`).
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса.
- `run()` — точка входа процессора (одна на класс). `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- **Избегать:** `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`; `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, **одна ответственность на файл**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`, `->primaryAnalysisFailed()`); цепочки — `->for()`/`->has()` или именованный билдер.
- **Без** tuple-возвратов и boolean-флагов в хелперах (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$link`, `$attachment`, `$analysis`).
- Test doubles → `tests/Support/Doubles/` (`Tests\Support\Doubles`); общие mock-сборки — хелперы (`fakeBrowserClient()`).
- Повторяющиеся проверки — кастомные expectations, не копипаста запросов.

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).

=== .ai/human-writing-style rules ===

# Human Writing Style

## Цель

Сделать формулировки комментариев и commit message естественными и «человеческими»:
понятными, предметными, без канцелярита и инфинитивов.

## Комментарии в коде

- Писать только при реальной необходимости, когда без комментария сложно понять «почему».
- Предпочитать короткие формулировки в прошедшем/настоящем времени: «проверяем», «ограничили», «оставили».
- Избегать шаблонов «выполняет/осуществляет/производит» и общих фраз, которые дублируют код.
- Все комментарии в коде — на русском языке.

Пример:

```php
// Ограничили выборку активными ссылками, чтобы не перезапускать уже обработанные.
```

## Сообщения коммитов

- Использовать conventional commit + русский язык.
- Заголовок формулировать как завершенное действие: `добавили`, `исправили`, `убрали`.
- Не использовать инфинитивы в заголовке: `добавить`, `исправить`, `сделать`.
- В body писать причину и эффект изменения в 1–2 коротких строках.

Пример:

```text
fix(platform): исправили повторный dispatch PrimaryAnalysisJob

Добавили guard по статусу документа, чтобы не дублировать вызовы Gemini.
```

=== .ai/named-arguments rules ===

# Named Arguments

Все вызовы методов и функций в PHP должны использовать именованные аргументы.

```php
// ✅ Правильно
$user = User::query()->updateOrCreate(
    attributes: ['email' => $email],
    values: ['name' => $name],
);

$link = Link::query()->firstOrCreate(
    attributes: ['reg_number' => $regNumber],
    values: ['status' => LinkStatus::Pending],
);
```

```php
// ❌ Неправильно
$user = User::query()->updateOrCreate(['email' => $email], ['name' => $name]);
```

Применяется ко всем вызовам: методы объектов, статические методы, Eloquent, Laravel-хелперы.

Исключения: встроенные PHP-функции с одним аргументом, где именование не добавляет ясности.

=== .ai/navigation rules ===

# Navigation Guide

When a task requires more detail than what's in the current context, load the relevant file below. Don't load files preemptively — only when you hit a gap.

## Quick Start (3 steps)

1. Start `quick`: read only 1–2 most relevant sources.
2. If domain term is unclear (`Crawler`, `Link`, `Document`, `Platform`, `EIS`, стадии парсинга) — open `docs/workflow/parsing-process.md` and `docs/dev/architecture.md`.
3. If task is cross-layer (Platform module + jobs + Filament + frontend) — open `.ai/guidelines/project-development.md`.

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

- Непонятные термины домена (Crawler, Link, Document, стадии 1–4) — сначала `docs/workflow/parsing-process.md`.
- Имена классов/методов/переменных, канон глаголов (`find/get`), нейминг тестов — `.ai/guidelines/glossary.md` (канон) и `docs/dev/glossary.md` (полный справочник).
- Изменение пайплайна парсинга / jobs / очередей — `.ai/guidelines/project-development.md`.
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
| Crawling, parsing, AI screening, estimate extraction | `docs/workflow/parsing-process.md` |
| Platform / EIS / модули, терминология | `docs/dev/architecture.md` |
| Имена сущностей/методов/переменных, канон глаголов, нейминг тестов | `.ai/guidelines/glossary.md`, `docs/dev/glossary.md` |
| Layer boundaries, where logic lives | `.ai/guidelines/architecture.md` |
| Cross-layer change in parsing pipeline | `.ai/guidelines/project-development.md` |
| Docker dev/prod, Makefile deploy targets | `docs/dev/docker/` |
| Proxy setup for EIS | `docs/dev/proxies.md` |
| Writing or running tests | skill `testing-rules` |
| Creating commits | skill `git-commit-rules` |
| Inertia + Vue pages/forms | skill `inertia-vue-development` |
| Filament admin panel | skill `filament-development` |
| Frontend routes from backend | skill `wayfinder-development` |
| DB schema, migrations | skill `database` |
| Eloquent Attribute::make() | skill `laravel-attributes` |
| PHP style (final, named args) | `.ai/guidelines/php-coding-standards.md`, skill `named-arguments` |
| Folder map and layer ownership | skill `project-map` |
| Complex multi-step task | skill `complex-task-orchestrator` |
| Backend + frontend + auth/tests checklist | skill `cross-layer-change-checklist` |
| Editing `.ai/`, `boost.json`, agent generation cycle | skill `ai-context-workflow`, `docs/ai/source-of-truth.md` |
| Natural wording for comments/commits | `.ai/guidelines/human-writing-style.md` |
| Terse responses | skill `compact-responses` |

=== .ai/php-coding-standards rules ===

# PHP Coding Standards

## Финальные классы

Все классы должны быть `final`, кроме абстрактных базовых классов, которые явно предназначены для наследования.

```php
// ✅ Правильно
final class SmetaParserService
{
    public function parse(Estimate $estimate): array { ... }
}

// ❌ Неправильно (не final)
class SmetaParserService
{
    public function parse(Estimate $estimate): array { ... }
}
```

## Именованные аргументы

См. `named-arguments.md` — отдельный гайдлайн с примерами.

## Строгая типизация

- `declare(strict_types=1);` в новых PHP-файлах.
- Явные return types и parameter types.
- Constructor property promotion где уместно.

## Actions vs Services

- **`Action`** — атомарная бизнес-операция с мутацией (если используется в проекте).
- **`Service`** — инфраструктура и оркестрация без HTTP (`BrowserService`, `GeminiService`, `SmetaParserService`).
- **Jobs** — длительные асинхронные стадии пайплайна (crawling, AI, parsing).
- **HTTP** — Form Request, Policy/Gate, тонкий Controller.

## Modules

- Код домена parsing/crawling — в `Modules/Platform` / `Modules/Eis`, не дублировать в `app/` без причины.

=== .ai/project-development rules ===

# Project Development (Parsing Pipeline)

Короткий техрегламент для изменений в домене crawling/parsing/AI analysis.  
Цель: не ломать многостадийный пайплайн и держать согласованность между модулями, jobs, Filament и docs.

Правки AI-правил — только в `.ai/...`; `CLAUDE.md` и `AGENTS.md` не редактируются напрямую.

## 1) Стадии пайплайна (source of truth)

Master-описание: `docs/workflow/parsing-process.md`.

| Стадия | Суть | Типичный код |
|---|---|---|
| 1 Collector | сбор ссылок из ЕИС | `Modules/Eis`, `Process/Crawling` |
| 2 Document Indexer | список вложений | `Process/DocumentPrepare` |
| 3 AI Screening | быстрая проверка «это смета?» | `Process/DocumentAnalysis` |
| 4 Full Extraction | полный разбор позиций | `Process/EstimateAnalysis`, `OrderParsing` |

Не переносить логику стадии N в стадию N−1 «для ускорения» без явного решения — это ломает фильтрацию и расход API.

## 2) Структура и нейминг

- Platform-agnostic код — `Modules/Platform/`.
- Площадка ЕИС — `Modules/Eis/`.
- Job: `*Job` в `Process/*/Jobs/`.
- Processor: `*Processor` — оркестрация одной стадии.
- DTO: `Modules/Platform/app/Dto/` или `app/Dto/` для cross-cutting.
- Filament: `Modules/Platform/app/Filament/Resources/`.

## 3) Dependency map (что менять вместе)

При изменении стадии, статуса или модели:

1. **Миграции / модели** — `Modules/Platform/database/migrations`, Eloquent models.
2. **Enums / DTO** — статусы analysis, crawling, estimate schema.
3. **Jobs + Processors** — dispatch, retry, failed handling.
4. **Horizon** — queue names, supervisors (если добавляется новая очередь).
5. **Filament** — ресурсы, если меняется операторский UI.
6. **Docs** — `docs/workflow/parsing-process.md`, `docs/dev/architecture.md`.
7. **Tests** — feature/unit для изменённого поведения.

## 4) Минимальный test-gate (Docker)

```bash
make test-preflight
make test-php

# или точечно:

docker compose exec app php artisan test --compact --filter=ChangedArea
```

## 5) Frontend

- Inertia pages: `resources/js/pages/`.
- Маршруты backend — через Wayfinder (`@/actions`, `@/routes`), без хардкода URL.
- Сборка: `yarn build` / `make frontend-build`.

## 6) Proxy / EIS

- Локальная разработка с прокси: `docs/dev/proxies.md`.
- Docker `extra_hosts` для `eis-auth.local`, `eis-api.local` — не удалять без замены.

## 7) PR checklist

- Какая стадия пайплайна затронута (1–4).
- Новые/изменённые jobs и очереди.
- Влияние на Gemini/browser (стоимость, timeout, retry).
- Обновлены ли workflow/dev docs.
- Прогнан test-gate.

=== .ai/swissknifeman-hub rules ===

# Swissknifeman: хаб скиллов

<!-- Изначально сгенерировано из реестра swissknifeman; далее ведётся вручную. -->
<!-- swissknifeman:registry sha=5d8e1c5befe7 skills=195 -->

## Приоритет источников правил

1. Проектные правила и скиллы (`.ai/guidelines/`, `.ai/skills/`, корневые инструкции проекта) — высший приоритет.
2. Скиллы swissknifeman (индекс ниже) — архитектура, структура, практики, процессы.

Конфликт правил: побеждает более специфичный источник (проект > реестр swissknifeman).

> Laravel Boost — **только MCP-инструменты** (database-query/tinker/logs/docs), НЕ источник правил/скиллов.
> Версионно-специфичные темы (Laravel/Pest/Inertia/Livewire/Tailwind/Filament) бери из скиллов реестра.

## Как найти нужный скилл

1. Определи область задачи и найди бакет в индексе ниже.
2. Выбери скилл по описанию; внутри скилла открывай сниппеты только по его таблице «когда какой сниппет открывать» — не загружай всё подряд.
3. Структурные вопросы нового кода решай в порядке: структура (`laravel-structure`) -> взаимодействие (`dependency-injection`) -> реализация (профильный скилл).
4. Устройство системы скиллов в проекте (источник истины, симлинки, gitignore) — скилл `skills-ssot`.

## Агенты проекта (4)

Роли в `.claude/agents/`. Преднагруженные скиллы — поле `skills:` (⚠ = имя не найдено в реестре, preload молча не сработает).

| Агент | Назначение | Преднагруженные скиллы |
|---|---|---|
| `agelto-reviewer` | >- | repositories, laravel-structure, dependency-injection, laravel-security-audit, code-style-spatie |
| `agelto-slice-builder` | >- | repositories, laravel-structure, dependency-injection, laravel-data, laravel, database, code-style-spatie, named-arguments |
| `agelto-spec-writer` | >- | architecture, api-design, data-schema, task-brief-template |
| `agelto-test-writer` | >- | laravel-testing, test-isolation-guard, repositories |

## Установленные скиллы (106)

### architect — Architecture, API and data design, security, observability, tech stack selection

- **agent-design** — Agent design, agentic loop, tool contracts, harness, permission gates
- **api-design** — Designing REST/GraphQL APIs, endpoints, contracts, versioning, authentication
- **architecture** — System architecture, stack choice, ADR, C4 diagrams, deployment, scalability
- **codebase-design** — Shared vocabulary and discipline for designing DEEP modules (Ousterhout/Feathers): Module/Interface/Depth/Seam/Adapter/Leverage/Locality; deletion-test; interface = test surface; 'one adapter is a hypothetical seam, two is a real one'. Activate when designing/improving a module interface, finding deepening opportunities, choosing a seam, or raising testability/AI-navigability.
- **data-schema** — Data schema, entities, attributes, relations. DB-agnostic — markdown tables only
- **dev-engineering-super-skill** — Broad engineering-bucket navigator (composite of external dev skills). For specific work prefer the granular skill — architecture→architecture, API→api-design, data→data-schema, domain→domain-modeling, agents→agent-design, eval→eval-design, security→security-design, observability→observability-design, stack→tech-stack-selection, codebase→codebase-design. Use only for a cross-cutting overview when no single skill fits.
- **domain-modeling** — Active domain-model discipline: challenge terms, stress-test with scenarios, RECORD the glossary (CONTEXT.md — domain language only) and ADRs INLINE as they crystallize (not in batches). Multi-context via CONTEXT-MAP.md. Activate when clarifying terminology/ubiquitous language, recording an architectural decision, or when another skill builds a domain model (esp. fintech: banking APIs, fiscal).
- **eval-design** — Evaluate LLM/AI output quality — eval-set, metrics, regression harness, automated + human review
- **figma-mcp-core** — Discipline for the official Figma Dev Mode MCP: real tools (get_metadata, get_variable_defs, get_code, get_code_connect_map, get_screenshot, search_design_system), no extracting values from screenshots, selection-driven algorithm, design-token extraction to W3C tokens.json, component reuse via Code Connect, design-system audit, antipatterns. Activate for any UI-from-Figma task via a connected MCP server, before mapping to a concrete framework.
- **legal-compliance** — GDPR, CCPA, AI Act, DMCA, COPPA, DSA — pre-launch regulatory checklist
- **observability-design** — Logging, metrics, tracing, alerting. SLO/SLI, error budget, runbooks for prod-readiness
- **package-contribution-protocol** — Gate against bloating a depended-on package from a consumer project: Rule of Three, proposal→ADR, minimal contract, dependency direction, SemVer, read-only boundary. Activate when a project task needs editing a shared package, on 'extract common', and when adding a class/provider/interface/abstraction to a depended-on package.
- **security-design** — Data classification, auth strategy, RBAC/ABAC, OWASP Top 10, GDPR, STRIDE threat modeling, ops security checklist
- **tech-stack-selection** — Tech stack selection with trade-off matrix before architecture design. ADR-first step

### devops — Docker environments, CI/CD pipelines, GitOps

- **ci-cd** — GitHub Actions / GitLab CI pipelines for Laravel: lint, tests, build, release + security gates (SAST, dependency audit, image scan) + coverage-gate in CI (Clover threshold, jobs)
- **db-test-preflight** — Pre-flight check of test DB (Postgres in Docker) BEFORE running tests: container up (docker compose ps / pg_isready), target DB exists and its name ends with _test (not dev/prod), migrations applied; distinguishes host vs docker connection. Activate when running Postgres tests, wiring hooks/CI, or when 'tests fail on DB connection'.
- **docker** — Advanced Docker containerization expertise: container optimization, security hardening, multi-stage builds, orchestration patterns, and production deployment strategies
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
- **filepond-upload** — File upload via FilePond in Vue + Laravel: register vue-filepond + plugins (validate-type/size, image-preview, file-poster), server-side via rahulhaque/laravel-filepond (process/revert on /filepond), bind uploaded file serverId to form, previews and posters. Activate when adding/editing file upload, drag-and-drop upload, FilePond field, image preview in form, on words filepond/vue-filepond/laravel-filepond/file upload/process/revert/serverId.
- **web-design-guidelines** — Review UI code for Web Interface Guidelines compliance. Use when asked to "review my UI", "check accessibility", "audit design", "review UX", or "check my site against best practices".

### frontend-backend — Backend integration layer: Inertia+Vue, Laravel Echo realtime client, backend→frontend type sync

- **backend-type-sync** — Backend→frontend type sync via spatie/laravel-typescript-transformer: #[TypeScript] on enum/DTO/Spatie-Data, php artisan typescript:transform → generated.d.ts, use App.Enums.* / App.Data.* in Vue/TS. Activate: new enum/DTO needed on frontend, type drift FE↔BE, type-sync setup. Boundary with wayfinder (routes ≠ types).
- **inertia-vue** — Inertia + Vue in Laravel: pages, forms, navigation and layered resources/js organization (pages, layouts, components, composables, stores, features, events, types, actions/wayfinder).
- **laravel-echo-client** — Frontend realtime client Laravel Echo (Reverb/Pusher) in Vue 3: configure Echo from runtime-config/env, subscribe to private/presence channels, listen on broadcastAs events, unsubscribe in onUnmounted, inject X-Socket-ID into axios. Activate when: wiring websockets on frontend, writing useEcho/useChannel composable, listening to .broadcastAs event, seeing window.echo / Echo / laravel-echo / reactive realtime in Vue.

### frontend-linting — JS/TS tooling and style: antfu config, ESLint flat config, Spatie JS style, TS library authoring

- **antfu** — Anthony Fu's opinionated tooling and conventions for JavaScript/TypeScript projects. Use when setting up new projects, configuring ESLint/Prettier alternatives, monorepos, library publishing, or when the user mentions Anthony Fu's preferences.
- **eslint-flat-config** — ESLint flat config (eslint.config.js) for Vue 3 + TypeScript: defineConfigWithVueTs/typescript-eslint + eslint-plugin-vue, @stylistic, import/order and import boundaries, padding-line-between-statements, Prettier chain (eslint-config-prettier + tailwindcss + organize-imports), lint/lint:fix scripts. Triggers: setup/edit eslint or prettier, new Vue/TS project.
- **js-code-style** — JS/TS style per Spatie: 4 spaces, width 120, single quotes, const-first, strict ===, function for named functions, destructuring, named imports.
- **ts-library** — Use when authoring TypeScript libraries or npm packages - covers project setup, package.json exports, build tooling (tsdown/unbuild), API design patterns, type inference tricks, testing, and publishing to npm. Use when bundling, configuring dual CJS/ESM output, or setting up release workflows.

### frontend-reka — Reka UI headless primitives + CVA variants

- **reka-ui-cva** — UI component library on Reka UI headless primitives + class-variance-authority (cva) variants. Activate when adding/editing a component in components/ui/, defining cva variants, wrapping a reka-ui primitive in your own component, building composite Root/Trigger/Content (Dialog/Dropdown/Tooltip), merging classes via cn(), or wiring to Tailwind.

### frontend-tailwind — Tailwind CSS v4 conventions and dark-mode theming

- **dark-mode** — Dark theme light|dark|system on Vue 3: useDarkMode composable (matchMedia prefers-color-scheme, localStorage + cookie for SSR, toggle .dark class on documentElement, change listener for system), pre-mount init without theme flash, mini toggle. Activate when adding theme switching, fighting light-theme flash (FOUC), syncing theme with system, or restoring theme under SSR.
- **tailwind-conventions** — Tailwind CSS v4 conventions for Laravel/Vue: CSS-first config (@import tailwindcss, @theme tokens instead of tailwind.config.js), design tokens, dark mode (.dark + dark:* + useAppearance), cn() (clsx+tailwind-merge), prettier-plugin-tailwindcss + tailwindFunctions, spacing discipline. Activate when editing *.vue/*.css with Tailwind, configuring tailwind/postcss/prettier for Tailwind, working with dark mode.

### frontend-vite — Vite build tooling: config/plugins/SSR, project module loader, multi-build, VitePress

- **vite** — Vite build tool configuration, plugin API, SSR, and Vite 8 Rolldown migration. Use when working with Vite projects, vite.config.ts, Vite plugins, or building libraries/SSR apps with Vite.
- **vite-module-loader** — Vite as primary frontend bundler: base config, aliases, build practices
- **vite-multi-build** — Two independent Vite builds in one Laravel project: main app + Filament admin, with separate Vite/Tailwind configs, hotfiles and build dirs.
- **vitepress** — VitePress static site generator powered by Vite and Vue. Use when building documentation sites, configuring themes, or writing Markdown with Vue components.

### frontend-vue — Vue 3 ecosystem: Composition API, best practices, router, VueUse, Pinia state, Vitest testing

- **pinia** — Pinia official Vue state management library, type-safe and extensible. Use when defining stores, working with state/getters/actions, or implementing store patterns in Vue apps.
- **pinia-stores** — Pinia (Vue 3) global state: setup stores (defineStore with function), ref=state/computed=getters/function=actions, use*Store naming, store composition, store access outside components, hydration from props, persist. Activate on creating/editing files under resources/js/stores/**, defineStore, useXxxStore, or questions 'how to store global state / share data between components / where to move state out of a component'.
- **vitest** — Vitest for Vue/Inertia-on-Laravel: jsdom, globals, tests in resources/js/tests mirror sources, v8 coverage with mandatory exclude of autogenerated code.
- **vitest-vue** — Vitest fast unit testing framework powered by Vite with Jest-compatible API. Use when writing tests, mocking, configuring coverage, or working with test filtering and fixtures.
- **vue** — Vue 3 Composition API, script setup macros, reactivity system, and built-in components. Use when writing Vue SFCs, defineProps/defineEmits/defineModel, watchers, or using Transition/Teleport/Suspense/KeepAlive.
- **vue-best-practices** — MUST be used for Vue.js tasks. Strongly recommends Composition API with `<script setup>` and TypeScript as the standard approach. Covers Vue 3, SSR, Volar, vue-tsc. Load for any Vue, .vue files, Vue Router, Pinia, or Vite with Vue work. ALWAYS use Composition API unless the project explicitly requires Options API.
- **vue-composition-api** — Project Composition API conventions: ref-first and composables pattern. NOT an API reference (`<script setup>`, defineProps/Emits, reactivity) — that's in `vue`; NOT general workflow — that's in `vue-best-practices`. Only delta-decisions and pointers.
- **vue-dynamic-icons** — Vue: one icon component rendering SVG by string name from a set (lucide-vue-next etc.) via <component :is>; activate on dynamic icon, icon by name, component is icon, passing size/strokeWidth/color, type-safe icon name, swapping icon set.
- **vue-router-best-practices** — Vue Router 4 routing and navigation: typed/named routes, lazy-loaded views, route meta, RETURN-API navigation guards (return route/false/path instead of deprecated next()), per-route vs global guards, beforeEnter, scrollBehavior, NavigationFailure/onError, useRoute/useRouter/useLink. Activate when editing router/index.ts, routes or guards, or asking how to protect a route / redirect in a guard / why next() hangs / lazy-load a page / restore scroll.
- **vue-testing-best-practices** — Testing practices for Vue 3 components with Vitest + Vue Test Utils 2: mount vs shallowMount, isolating composables, createTestingPinia, async/flushPromises, resilient data-testid, mocking children/slots/emits, and what NOT to test.
- **vueuse-functions** — Apply VueUse composables where appropriate to build concise, maintainable Vue.js / Nuxt features.

### frontend-wayfinder — Laravel Wayfinder: type-safe routes/controller actions in the frontend

- **wayfinder** — Laravel Wayfinder: typesafe routes and controller actions for the frontend. Generation, named imports, form(), import architecture boundaries via ESLint.

### general — General agent workflow conventions: git commits, task briefs, project maps, cross-layer checklists

- **anti-drift** — Discipline against agent rabbit-holing: think before coding, simplicity first, surgical edits, circuit-breaker on dragging iterations.
- **compact-responses** — Activate ultra-compact response mode: minimal prose, code-only, no explanations unless asked. Use for terse output — quick edits, code reviews, repeated tasks with established context.
- **context-economy** — Claude Code context economy in a project: CLAUDE.md ≤200 lines, path-scoped .claude/rules/, /compact vs /clear, Plan→Clear→Execute, MCP audit, model routing. Officially-supported mechanisms only — no myths like .claudeignore.
- **mcp-advisor** — Pick MCP servers for a project at onboarding/sync: which to connect (context7/playwright/github/fetch/sequential-thinking + maind hub), why, when NOT to, and the cost in context/permissions/Node-deps. Activate when adding/onboarding/syncing a project, editing .mcp.json, or choosing MCP — emits a justified connection plan, not a dump of every server.
- **session-handoff** — Cross-session/agent context handoff: compress a session into a structured doc — state, decisions, open items, next steps.
- **task-brief-template** — Brief template for complex agent tasks: goal, scope, invariants, explicit assumptions, out of scope, acceptance criteria, definition of done. Plus Plan→Clear→Execute pattern and loop fields for iterative runs: verifier, exit condition, hard-stop.
- **verify-claims** — Before a decision/verdict that rests on an EXTERNAL fact (library version/API, 'still current', best practice, market, another service's/MCP's capabilities) — verify via RAG, don't assert from memory (knowledge cutoff goes stale). Ladder: context7 (docs) → Perplexity (native MCP if API key, else perplexity-web) → WebSearch → research for deep. Activate on audit/plan review, big planning, library/stack/dependency choice, claiming 'this is standard/modern'. Repo-grounded facts (read the code) and stable knowledge — no check.

### laravel-architecture — Scaling architecture: modular monolith/DDD, enum state machines, enum attributes, subagent orchestration

- **enum-attributes** — Use for enum metadata: replace label()/color()/description() match-ladders with PHP attributes #[Label]/#[Color]/#[Description] on cases + Concern traits with a reflection resolver; or export an enum as a TS type via #[TypeScript] stack (App.Enums.* union in .d.ts). Triggers: PHP enum, attributes on cases, getLabel()/getColor(), Filament HasLabel, spatie/laravel-typescript-transformer, local enum-concern via path-repository
- **enum-state-machine** — Activate when a domain status (Order/Document/Subscription) grows into a transition graph: single edge graph as enum method transitionDefinitions() (from→to + guard + action + label), a runtime transition registry (canTransition/allowedFrom) over it, and transition execution in an Action under DB::transaction() with a domain event. Triggers: PHP status enum, allowed transitions, StateMachine/workflow, parallel transition graph to unify into one source, canTransitionTo, allowedTransitions, illegal status transition
- **laravel-subagents** — Subagent orchestration on large Laravel tasks: activation threshold, delegating research to Explore agents, review via laravel-reviewer, tests via laravel-test-writer, worktree isolation of parallel edits. Do not apply to small/medium tasks.
- **modular-architecture** — Scale Laravel code as it grows: organize by business domain, Modular Monolith and DDD patterns; implement with nwidart/laravel-modules (module:make*, module structure, namespace without Modules\, Filament discovery); starting structure — php/laravel-structure

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
- **spatie-settings** — spatie/laravel-settings: typed settings classes (extends Settings, public properties, group()), settings migrations add/migrate/rename, cache, runtime-settings vs config/ choice, Filament SettingsPage binding, runtime infra-config (websocket/Reverb) from admin to frontend via DTO. Activate when creating/editing a settings class, adding a property, a settings migration, an edit screen, or moving an infra setting to a runtime override.

### laravel-filament — Filament admin panels: resources, schemas, tables, widgets, actions, tenancy

- **filament** — Filament v5 — Panels, Resources, Schemas, Tables, Widgets, Actions: file structure, make:filament-* CLI reference, correct namespaces, authorization, tenancy, common pitfalls. Scaffold only via artisan.

### laravel-testing — Laravel testing: Pest feature/unit, DB isolation guard, Dusk browser tests, health checks

- **health-checks** — spatie/laravel-health in Laravel: custom Check classes (disk, queue, scheduler heartbeat, service TCP port, cache, DB) on Result::ok/failed, registration in config/health and Health::checks(), /health endpoint (live/ready, JSON/Filament), run schedule and notifications. Activate on: health check, healthcheck, liveness/readiness, /health, spatie health, availability monitoring, disk/queue/cache/heartbeat checks, editing app/Health/** or config/health.php.
- **laravel-dusk** — Laravel Dusk: browser E2E tests, isolated test DB, run from Docker via host ChromeDriver, Page Objects
- **laravel-testing** — Feature/unit/pest testing patterns: test DB isolation, Docker/local env detection, coverage gate, Pest composition (setUp traits, fixtures, beforeEach fake/freeze, datasets)
- **test-isolation-guard** — Guard Laravel tests against running on prod/dev DB and prod media disk: bootstrap.php sets DB_DATABASE=*_test and media-test before autoload, TestEnvironmentGuard aborts createApplication() on isolation breach, safe RefreshDatabase. Activate when setting up the test environment, protecting CI, or suspecting tests hit dev/prod DB.

### operator — Incident response, runbooks, postmortems, on-call and capacity planning

- **operations-cx-super-skill** — Broad operations/CX-bucket navigator (composite of external CX skills). For specific work prefer the granular skill — incidents→incident-response, postmortem→postmortem, on-call→oncall-rotation, capacity→capacity-planning, runbooks→runbook. Use only for a cross-cutting overview when no single skill fits.

### oss-dev — Open-source development: releases, governance, DX, dependency audits + language references

- **gh-review** — Review and handoff changes via GitHub CLI (gh) with context economy: gh pr diff/view/comment/review, gh api for targeted file reads instead of loading whole files. Activate when reviewing PRs, handing off to a dev, reading others' changes on GitHub.

### php — PHP and Laravel core: architecture, structure, conventions, DI, database, repositories, patterns, static analysis

- **code-style-spatie** — Laravel/PHP code style per Spatie guidelines: Laravel conventions over PSR-12, typing instead of docblocks, early returns, happy path last.
- **database** — Database migrations, models, and table naming. Activate when writing migrations, creating models, defining schema, foreign keys, or working in database/migrations/.
- **dependency-injection** — Layer interaction via constructor injection: final readonly + promotion, injection matrix, Action composition
- **laravel** — Laravel architecture patterns: actions, repositories, resources
- **laravel-best-practices** — Laravel best practices: 20 rule categories (Eloquent, validation, cache, queues, security) with routing by task.
- **laravel-structure** — Laravel project structure canon for greenfield and adding domains: domain taxonomy, layer-mirror rule, class placement and naming
- **named-arguments** — PHP rule: method/function calls use named arguments; exceptions; activate when writing/reviewing calls or when readability/parameter order is mentioned
- **pao** — Laravel PAO — agent-optimized PHP tool output: PHPUnit/Pest/Paratest/PHPStan/Rector/Artisan collapse to compact JSON (~20 tokens vs thousands) when run inside an AI agent. Install, verify, limits.
- **php-naming-conventions** — Naming Laravel/PHP entities: repository methods by intent (not findByCodeOrFail-explosion), Action/DTO/VO/Enum/Event/Job, tables/columns/routes. Kills junk names AI loves to generate. Activate when creating or reviewing class/method/migration names.
- **php-patterns** — PHP domain patterns: VO, DTO, pipeline, specification — selection guide
- **php-upgrade-checklist** — PHP version upgrade checklist (minor/major): sync image/CI, composer update + clear deprecations, phpstan baseline, full suite, update rector sets, review RFCs for incompatibilities. Activate before bumping PHP version.
- **repositories** — Laravel repositories: read/store split, model scopes, delta-DTO, facade, VisibilityService
- **static-analysis** — PHP/Laravel static analysis & linting: Laravel Pint, PHPStan, Rector locally + CI, reference configs

### quality — Code review, refactoring plans, tech debt audits, test strategy

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

### system — Local environment topology: the three hub nodes (Brain vault, swissknifeman, projects base) and how agents navigate between projects and their docs

- **agent-packs** — Install and VERSIONED upgrade of agent-packs (Claude Code agents/workflows/hooks) from harness into a consumer project: stack detect -> interview -> render+copy -> lock; upgrade via CHANGELOG delta with 3-way reasoning over sha256 in lock, no diff engine. Activate on: install/upgrade agent-pack, add roles+dev-loop+review-gate to project, versioned upgrade of agent templates, agent-pack.lock.
- **local-topology** — Root map of the local dev environment — three hub nodes (Brain vault, swissknifeman, projects base) and navigation between projects, their code and docs. Read from ~/.swissknifeman/topology.json
- **session-observer** — EXPERIMENTAL (enable per-project). Post-hoc Claude Code session analysis from JSONL logs — finds weak spots on 3 axes: agents (skill-skip, drift, loops), skills (gaps, dead weight, thin), settings (hooks, permissions). Findings → learnings via memory-hook, human promotes. Activate AFTER a work cycle / at session end / on request «analyze session» — only if enabled in project.
