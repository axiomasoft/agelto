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
