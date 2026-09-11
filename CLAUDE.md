# Application Architecture

## Resident rules

- `CLAUDE.md` is the generated Claude Code hub; `AGENTS.md` is the compact Codex entrypoint. Shared rules live in `.ai/guidelines/**`; do not recreate retired `GEMINI.md`.
- Keep this hub short. Read `docs/03_Dev/**` or `docs/02_Workflow/**` only for the active task. Architectural changes update their source docs and this guideline.

## Domain boundaries

- Flat `app/**`, no modules: `Procurement` (`prc_*`: procurements, documents, estimates, AI, crawling), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`: provider-agnostic transport).
- Procurement AI pipeline: `Services/Procurement/{Prepare,Screening,Extraction}`, `Jobs/Procurement/*Job`, `Models/Procurement/Ai/*`; LLM transport stays in `Services/Llm/**` with `Models/Llm/**`, repositories and factories. Use its local skills for extraction, LLM transport and prompts.
- Crawling contracts: typed profile filters are resolved through `crawler.filters`; TLS trust exists only in `SourceTlsTrust` / `crawler.tls.bundles`. See `docs/03_Dev/crawling-pipeline.md`.
- Controllers handle HTTP only; Actions/Services own short mutations and transactions; repositories access persistence. Long crawling/parsing/AI work runs as Horizon jobs, never in HTTP.
- A file in `app/<Layer>/<Domain>/` has one subdomain directory; only domain-core and cross-subdomain files may be at its root. `tests/Unit/Architecture/DomainLayoutTest.php` enforces this.
- Jobs must be idempotent. AI jobs use lease + status guard, prefix-stable system prompt and checkpoint-requeue without double dispatch.

## Runtime

- Browser automation runs only in the `horizon` worker (`shm_size`, `CHROME_BIN`).
- Development keeps source bind-mounted and Vite runs with pinned `pnpm`; production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host. The deploy class is explicit: `code`, `php-deps`, `frontend`, `runtime`, or `image-promotion`.
- Internal VitePress output is built in the frontend stage into `var/private-documentation/site` with its manifest beside it; the application image copies it read-only outside `public` and writable volumes. Documentation source changes require an image artifact, never `prod:deploy:code`.

For pipeline stages and schema detail: `docs/03_Dev/architecture.md`, `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.

# Glossary & Naming Canon

Use the technical names below in identifiers; business terms are fine in UI/comments. The full, versioned glossary is `docs/02_Workflow/glossary.md`; table/domain detail is `docs/03_Dev/architecture.md`. Rename a canon term consistently.

## Core entities

- `Platform` → `$platform`: source platform; `Crawler` → `$crawler`: crawl profile; `CrawlerRun` → `$run`: one run.
- `Procurement` → `$procurement`: procurement; `Document`/`DocumentVersion` → `$document`/`$documentVersion`: procurement document and downloaded version.
- `Estimate` → `$estimate`: parsed estimate DTO; never use `Smeta` in identifiers. `Lot`/`PurchaseItem` is not `Estimate\Item`.
- `Proxy` → `$proxy`: outbound node. Obtain it only through `ProxyResolver`; `ProxyScope` names the request purpose, not a node. Per-target and global health are distinct.
- Crawler filters are typed DTOs resolved by registry through `crawler.filters`; an unmapped registry fails, never falls back silently.
- `DeltaScanProcessor` performs the scheduled overlap-window update scan; `SourceTlsTrust` is the sole platform TLS-trust holder. `verify => false` is forbidden.

## AI terms

- `Ai\Analysis` → `$analysis`: version-analysis session; re-run has `parent_analysis_id`, shadow run `is_shadow`.
- `Ai\Prompt` → `$prompt`: versioned system instruction; prompt files sync via `ai:prompts:sync`; protected seeded prompts are read-only.
- `Ai\Correction` is an operator correction; `Ai\DatasetExample` is an immutable approved gold snapshot.
- `PromptStep` composes a system instruction; do not confuse it with `AnalysisStep` or `LlmPurpose`.
- `DocumentStatus::Unprocessable` is terminal and not retried. `ExtractionRoute` (`Whole`/`Windowed`) is selected by pre-upload preflight.
- `#[Description]` is the source of response-schema semantics; `#[Alias]` only describes document spelling variants.

## Methods and tests

- `find*` is nullable identity lookup; `get*` returns computed/required value; `store*` writes; `mark<State>` changes state; `run()` is a processor entrypoint; `start()` begins a lifecycle.
- Do not use `fetch*`/`load*` for DB reads (`load` is eager loading), generic `handle`/`process`/`do`, or needless `get` accessors.
- Tests: `tests/{Feature|Unit}/<Domain>/<Subject>Test.php`, one responsibility; `test('<русский intent>', fn)`, factories/states/`->for()`/`->has()`, domain-canon variables, doubles in `tests/Support/Doubles/`.
- Keep existing data-affecting exceptions: `CrawlingStatus` UPPER_CASE cases and `ProcessStage::DocParsing = 'parsing_docs'`.

# Navigation Guide

Read 1–2 relevant sources first; expand only for an unresolved term, a cross-layer change, or conflicting rules. Do not pre-load reference material.

## Safety

- Before an edit, check whether the target is Git-ignored.
- Edit shared rules in `.ai/guidelines/**`; update the generated Claude hub only from them. `AGENTS.md` is the Codex-specific entrypoint. Managed `.claude/skills/**` carry `swissknifeman:managed` and are overwritten by sync; local skills without it may be edited.

## Routing

| Task | Load/use |
|---|---|
| Crawling, parsing, screening, extraction | `docs/02_Workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md` |
| Domain terms, layers, schema | `docs/03_Dev/{architecture,database}.md`, full glossary as needed |
| Parsing pipeline/jobs/queues | skill `procurement-pipeline-dev` |
| Estimate discovery, extraction, unprocessable | skill `estimate-extraction-dev`, `docs/03_Dev/ai/estimate-extraction.md` |
| LLM provider, cache, capability, EOL | skill `llm-layer-dev`, `docs/03_Dev/llm-providers.md` |
| Prompts, protected records, eval | skill `prompt-engineering-agelto`, `docs/03_Dev/ai/prompts.md` |
| Tests, migrations, PHP style | skills `testing-rules`, `database`, `code-style-spatie`, `named-arguments`, `static-analysis` |
| Filament / Inertia / routes | skills `filament`, `inertia-vue`, `wayfinder` |
| PR documentation, commits, risky multi-layer work | `.ai/guidelines/docs-pr-checklist.md`; `docs/meta/structure.md`; `tests/Unit/Docs/DocsIntegrityTest.php`; skills `git-commit-rules`, `complex-task-orchestrator` |
| Runbooks, operators, training loop | `docs/03_Dev/runbooks/_index.md`, `docs/04_Operators/_index.md`, `docs/03_Dev/ai/training-loop.md` |
| AI-context or skill-generation changes | `docs/03_Dev/ai-context/index.md`, skill `ai-context-workflow` |

<!-- swissknifeman:hub:start -->
# Swissknifeman: хаб скиллов

<!-- Сгенерировано scripts/generate-hub.sh из реестра swissknifeman. -->
<!-- НЕ редактируйте вручную: перегенерация затрёт правки. -->
<!-- swissknifeman:registry sha=3e4e73877360 skills=232 -->

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
