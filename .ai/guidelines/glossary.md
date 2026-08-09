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
