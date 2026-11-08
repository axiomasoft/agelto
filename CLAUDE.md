# Application Architecture

## Resident rules

- `CLAUDE.md` is the generated Claude Code hub; `AGENTS.md` is the compact Codex entrypoint. Shared rules live in `.ai/guidelines/**`; do not recreate retired `GEMINI.md`.
- Keep this hub short. Use `.ai/guidelines/cheatsheet.md` for lock order, stage queues and schema map; read `docs/dev/**` or `docs/workflow/**` only for the active task. Architectural changes update their source docs and this guideline.
- Native Planner execution-input defines the current scope and authority. Executors change product files and return stage-reports; the coordinator owns lifecycle, acceptance evidence and commits. Archived Task Layout v2 carriers and `plan-lint.py`/`plan-views.py` are historical provenance, not an execution interface.

## Domain boundaries

- Flat `app/**`, no modules: `Procurement` (`prc_*`: process core, network, acquisition, documents, estimates, AI), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`: provider-agnostic ledger, execution, cache and schema), `Platforms/<Code>` (platform-specific adapters across layers; existing table names stay unchanged).
- Process execution: `Services/Procurement/Process/**`, `Repositories/Procurement/Process/**`, `ExecuteWorkJob` (delivery-only wakeup), and 4 daemons (`relay`, `reconcile`, `feed`, `project`). Stage handlers (`Listing`, `Detail`, `StatusRefresh`, `Download`, `Prepare`, `Screening`, `Extraction`, `Publication`) run under lease + `SubjectLockResolver` prefix fencing and commit via `WorkFinalizer`.
- Общие DTO, модели и резолверы не содержат полей и веток конкретной площадки. Внешние идентификаторы организаций передаются как `(platformCode, type, value)`; их разбор и форматы принадлежат адаптеру в `Services/Platforms/<Code>/`. Зависимости ограничены постоянными ролями в `Tests\Support\Architecture\PlatformSourceRules`.
- Outbound network & TLS: all source/provider calls go through `OutboundExecutor` / `ScheduledAttemptGate` / `AnalysisProviderSendGate` with `ProxyResolver` (`ProxyScope`) and separate route/target/route-target/adapter health. `SourceTlsTrust` (`outbound.http.tls_bundles`) is the sole TLS-trust holder; `verify => false` is forbidden.
- Controllers handle HTTP only; Actions/Services own short mutations and transactions; repositories access persistence. Crawling, document preparation and AI never run in HTTP.
- A file in `app/<Layer>/<Domain>/` has one subdomain directory; only domain-core and cross-subdomain files may be at its root (`tests/Unit/Architecture/DomainLayoutTest.php`).
- `Platforms/<Code>` uses matching roots in Services/Dto/Enums/Models/Filament, factories and seeders. Service subdirectories: Card, Csv, Listing, Status, Documents, Filters, Validators, Diagnostics, Persistence; one level only (`PlatformsDomainLayoutTest` + `PlatformsBaselineOriginTest`).

## Runtime

- Process-core checks use only the dedicated `agelto_core_test` Compose project and its internal network. Local HTTP/proxy/fake LLM fixtures are the only outbound targets; `Http::preventStrayRequests()` supplements network isolation. Deterministic checks never run live crawling, paid model calls or external schedulers.
- Fixture inputs have versioned manifests with hashes and sanitized provenance. HTTP sequences and request logs are server-owned. Implemented suites require executed assertions without skips; future suites fail explicitly with `suite_not_implemented` (exit 78).
- Browser automation runs only in the `horizon` worker (`shm_size`, `CHROME_BIN`).
- Development keeps source bind-mounted and Vite runs with pinned `pnpm`; production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host. The deploy class is explicit: `code`, `php-deps`, `frontend`, `runtime`, or `image-promotion`.
- Internal VitePress output is built in the frontend stage into `var/private-documentation/site` with its manifest beside it; the application image copies it read-only outside `public` and writable volumes. Documentation source changes require an image artifact, never `prod:deploy:code`.

For pipeline stages and schema detail: `.ai/guidelines/cheatsheet.md`, `docs/dev/process-core.md`, `docs/dev/architecture.md`, `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md`.

# Glossary & Naming Canon

Use the technical names below in identifiers; business terms are fine in UI/comments. Full glossary: `docs/workflow/glossary.md`; schema/stage cheat-sheet: `.ai/guidelines/cheatsheet.md`.

## Core & Process entities

- `Platform` → `$platform`: source platform; `Crawler` → `$crawler`: crawl profile; `CrawlRun` / `CrawlBlock` → `$crawlRun` / `$block`: acquisition run and partition block.
- `ProcessRun` → `$run`: process run; `OperationIntent` → `$intent`: coalesced `(subject_kind, subject_id, stage)` intent (`active` + at most one `pending`).
- `WorkId` / `AttemptLease` → `$workId` / `$lease`: process work item and fenced attempt lease (`owner_token`, `dispatch_generation`).
- `Procurement` → `$procurement`: procurement (`head_generation`); `Document` / `DocumentVersion` → `$document` / `$documentVersion`: procurement document (`head_generation`) and immutable version bytes.
- `Estimate` → `$estimate`: parsed estimate DTO/model; never use `Smeta` in identifiers. `Lot` / `PurchaseItem` is not `Estimate\Item`.
- `Proxy` → `$proxy`: outbound node. Obtain it only through `ProxyResolver`; `ProxyScope` names request purpose. Route (`prc_route_health`), target (`prc_target_health`), pair (`prc_route_target_health`), and adapter (`prc_adapter_health`) health are separate.
- Crawler filters are typed DTOs declared on each platform's `PlatformDefinition::acquisitionProfile` (`AcquisitionProfileDescriptor`) and restored via `ListingFilterCodec`; an unmapped filter fails, never falls back silently. `SourceTlsTrust` (`outbound.http.tls_bundles`) is the sole TLS-trust holder (`verify => false` is forbidden).

## AI & LLM Ledger terms

- `AnalysisRunSnapshot` / `AnalysisWindowSnapshot` → `$run` / `$window`: canonical version-analysis run (`prc_analysis_runs`) and extraction window (`prc_analysis_windows`) with sealed `declared_coverage` and `AnalysisRecipe`.
- `ProviderOperationPlan` / `AuthorizedProviderOperation` → `$plan` / `$operation`: pre-authorized provider call in `llm_provider_requests` / `llm_provider_calls` backed by `llm_budget_reservations`.
- `VerifiedReceipt` → `$receipt`: immutable v1 response envelope in `ReceiptStore` verified before financial settlement or chunk/asset acceptance.
- `Prompt` → `$prompt`: versioned system instruction (`ai:prompts:sync`); protected seeded prompts (`is_protected = true`) are read-only in UI. `PromptStep` composes RULES; `#[Description]` defines response-schema semantics; `#[Alias]` defines document spelling variants.

## Methods and tests

- `find*` is nullable identity lookup; `get*` returns computed/required value; `store*` writes; `mark<State>` changes state; `execute()` is a `StageHandler` entrypoint.
- Do not use `fetch*`/`load*` for DB reads (`load` is eager loading), generic `handle`/`process`/`do`, or needless `get` accessors.
- Tests: `tests/{Feature|Unit|Integration}/<Domain>/<Subject>Test.php`, one responsibility; `test('<русский intent>', fn)` without plan item codes (`T26`, `P1.x`); factories/states, domain-canon variables, local fixtures/doubles in `tests/Support/`.

# Navigation Guide

Read 1–2 relevant sources first; expand only for an unresolved term, a cross-layer change, or conflicting rules. Do not pre-load reference material.

## Safety

- Before an edit, check whether the target is Git-ignored.
- Edit shared rules in `.ai/guidelines/**`; update `CLAUDE.md` and `AGENTS.md` from them. Managed `.claude/skills/**` carry `swissknifeman:managed` and are overwritten by sync; local skills without it may be edited.

## Routing

| Task | Load/use |
|---|---|
| Lock order, stage queues, table map, fast CLI tricks | `.ai/guidelines/cheatsheet.md` |
| Process core, admission, recovery, network, storage, AI | `docs/dev/process-core.md`, skill `procurement-pipeline-dev` |
| Crawling, discovery, document & estimate workflow | `docs/workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md`, `docs/dev/crawling-pipeline.md`, `docs/dev/runbooks/archive-run.md` |
| Domain terms, layers, schema | `docs/dev/{architecture,database}.md`, `.ai/guidelines/glossary.md` |
| Estimate extraction, windows, chunks, publication | skill `estimate-extraction-dev`, `docs/dev/ai/estimate-extraction.md` |
| LLM ledger, send gate, receipts, assets, pricing | skill `llm-layer-dev`, `docs/dev/llm-providers.md` |
| Prompts, protected records, eval | skill `prompt-engineering-agelto`, `docs/dev/ai/prompts.md` |
| Isolated `core-*` test rig, concurrency, manifest | skill `testing-rules`, `docs/dev/runbooks/process-test-rig.md` |
| Filament / Inertia / routes | skills `filament`, `inertia-vue`, `wayfinder` |
| PR documentation, commits, risky multi-layer work | `.ai/guidelines/docs-pr-checklist.md`; `docs/meta/structure.md`; `tests/Unit/Docs/DocsIntegrityTest.php`; skills `git-commit-rules`, `complex-task-orchestrator` |
| Runbooks, operators, training loop | `docs/dev/runbooks/_index.md`, `docs/operators/_index.md`, `docs/dev/ai/training-loop.md` |
| AI-context or skill-generation changes | `docs/dev/ai-context/index.md`, skill `ai-context-workflow` |

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=695f52f0312f skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
<!-- maind:healthcheck hash=416fa168d489 (managed by maind — do not edit manually) -->
## mAInd — temporary project context and graph

Memory defaults to this project's private scope `project:agelto`. Read `ns:*` or `global`
only for an explicit cross-project request and when the scope is authorized.

Store only short useful temporary context: 48-hour default TTL, 72-hour maximum,
800 characters, at most 8 live entries per scope. Use one replaceable `active-handoff`
for continuity, then forget it when work finishes. Keep plans, decisions, progress,
commit history and durable rules in repository artifacts. Empty memory is normal.

Use `maind health --cwd` for connection symptoms or setup changes; repair with
`maind health --cwd --fix`. A memory outage does not block ordinary repository work.
Project connections: `maind graph --project agelto`.
<!-- /maind:healthcheck -->
