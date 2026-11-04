# Application Architecture

## Resident rules

- `CLAUDE.md` is the generated Claude Code hub; `AGENTS.md` is the compact Codex entrypoint. Shared rules live in `.ai/guidelines/**`; do not recreate retired `GEMINI.md`.
- Keep this hub short. Use `.ai/guidelines/cheatsheet.md` for lock order, stage queues and schema map; read `docs/dev/**` or `docs/workflow/**` only for the active task. Architectural changes update their source docs and this guideline.
- Native Planner execution-input defines the current scope and authority. Executors change product files and return stage-reports; the coordinator owns lifecycle, acceptance evidence and commits. Archived Task Layout v2 carriers and `plan-lint.py`/`plan-views.py` are historical provenance, not an execution interface.

## Domain boundaries

- Flat `app/**`, no modules: `Procurement` (`prc_*`: process core, network, acquisition, documents, estimates, AI), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`: provider-agnostic ledger, execution, cache and schema), `Platforms/<Code>` (platform-specific adapters across layers; existing table names stay unchanged).
- Process execution: `Services/Procurement/Process/**`, `Repositories/Procurement/Process/**`, `ExecuteWorkJob` (delivery-only wakeup), and 4 daemons (`relay`, `reconcile`, `feed`, `project`). Stage handlers (`Listing`, `Detail`, `StatusRefresh`, `Download`, `Prepare`, `Screening`, `Extraction`, `Publication`) run under lease + `SubjectLockResolver` prefix fencing and commit via `WorkFinalizer`.
- Общие DTO, модели и резолверы не содержат полей и веток конкретной площадки. Внешние идентификаторы организаций передаются как `(platformCode, type, value)`; их разбор и форматы принадлежат адаптеру в `Services/Platforms/<Code>/`. Текущие legacy зависимости ограничены сокращаемым architecture baseline до owning cutovers.
- Outbound network & TLS: all source/provider calls go through `OutboundExecutor` / `ScheduledAttemptGate` / `AnalysisProviderSendGate` with `ProxyResolver` (`ProxyScope`) and separate route/target/route-target/adapter health. `SourceTlsTrust` (`crawler.tls.bundles`) is the sole TLS-trust holder; `verify => false` is forbidden.
- Controllers handle HTTP only; Actions/Services own short mutations and transactions; repositories access persistence. Crawling, document preparation and AI never run in HTTP.
- A file in `app/<Layer>/<Domain>/` has one subdomain directory; only domain-core and cross-subdomain files may be at its root (`tests/Unit/Architecture/DomainLayoutTest.php`).
- `Platforms/<Code>` uses matching roots in Services/Dto/Enums/Models/Filament, factories and seeders. Service subdirectories: Card, Csv, Listing, Status, Documents, Filters, Validators, Diagnostics, Persistence; one level only (`PlatformsDomainLayoutTest` + `tests/Unit/Architecture/platforms-dependency-baseline.php`).

## Runtime

- Process-core checks use only the dedicated `agelto_core_test` Compose project and its internal network. Local HTTP/proxy/fake LLM fixtures are the only outbound targets; `Http::preventStrayRequests()` supplements network isolation. Deterministic checks never run live crawling, paid model calls or external schedulers.
- Fixture inputs have versioned manifests with hashes and sanitized provenance. HTTP sequences and request logs are server-owned. Implemented suites require executed assertions without skips; future suites fail explicitly with `suite_not_implemented` (exit 78).
- Browser automation runs only in the `horizon` worker (`shm_size`, `CHROME_BIN`).
- Development keeps source bind-mounted and Vite runs with pinned `pnpm`; production consumes a promoted immutable `IMAGE_TAG` without source, `vendor`, or frontend bind mounts and never builds on the host. The deploy class is explicit: `code`, `php-deps`, `frontend`, `runtime`, or `image-promotion`.
- Internal VitePress output is built in the frontend stage into `var/private-documentation/site` with its manifest beside it; the application image copies it read-only outside `public` and writable volumes. Documentation source changes require an image artifact, never `prod:deploy:code`.

For pipeline stages and schema detail: `.ai/guidelines/cheatsheet.md`, `docs/dev/process-core.md`, `docs/dev/architecture.md`, `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md`.
