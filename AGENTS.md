# Agelto — Codex Instructions

## Provider & Context Contract

- Codex (`AGENTS.md`) and Claude Code (`CLAUDE.md`) share policy from `.ai/guidelines/{architecture,glossary,navigation,cheatsheet}.md`. Update `.ai/guidelines/**` first, then sync `CLAUDE.md` and `AGENTS.md`.
- Project skill bundles under `.agents/plugins/` are managed by Swissknifeman; do not edit generated bundles or `.agents/.swissknifeman-codex-links.json`. Read the matching local skill in `.claude/skills/` before domain edits.

## Working Discipline & Verification

- При оркестрации непрерывно отслеживать conductor до результата: самостоятельно устранять технические остановки, поручать исправления исполнителям и принимать их по evidence. Не завершать работу лишь потому, что запуск оставлен в фоне.
- Start with 1–2 relevant files and `.ai/guidelines/cheatsheet.md`. Expand context only for an unresolved term, a cross-layer change, or conflicting rules. Preserve unrelated worktree changes.
- Native Planner owns plan state; archived Task Layout v2 plans (`plans/archive/**`) are historical provenance only — never run archived plan lifecycle commands.
- Run verification via the isolated `agelto_core_test` harness (`docs/dev/runbooks/process-test-rig.md`): `make core-quality`, `make core-test-file TEST_FILE=...`, and `make core-test-*`. Never reset dev/shared DB, run live crawling, call paid LLM APIs, or weaken guards/assertions.
- Run `make core-manifest-sync` after changing immutable contract or gold fixtures. Sync other staged hashes in `tests/Fixtures/ProcessCore/runtime-inputs.json` and schema inventory when their inputs change. Check `DomainLayoutTest` and `CoreKnowsNoDocumentTypeTest` when adding/removing core classes. Keep test names in Russian describing behavior without `T26`/`P1.x` plan bindings.

## Architecture Invariants (Quick Reference)

- **Five domains in flat `app/**`**: `Procurement` (`prc_*`), `Reference` (`ref_*`), `Organization` (`org_*`), `Llm` (`llm_*`), `Platforms/<Code>` (platform adapters across layers; no source-specific branches in shared DTOs/models/services; org IDs pass as `(platformCode, type, value)`).
- **Process Core**: 8 stages (`listing`, `detail`, `status_refresh`, `download`, `prepare`, `screening`, `extraction`, `publication`) delivered by `ExecuteWorkJob` + 4 daemons (`relay`, `reconcile`, `feed`, `project`). Follow canonical lock order in `.ai/guidelines/cheatsheet.md`. No object I/O or HTTP inside DB transactions; use `clock_timestamp()` for authority deadlines.
- **Network & TLS**: all outbound traffic goes through `OutboundExecutor` / `ScheduledAttemptGate` / `AnalysisProviderSendGate` + `ProxyResolver` (`ProxyScope`). Route, target, pair, and adapter health are separate. `SourceTlsTrust` is the sole TLS authority (`verify => false` is forbidden).
- **LLM Ledger**: pre-authorize (`llm_provider_requests` + `llm_budget_reservations`) → claim send (`AnalysisProviderSendGate`) → durable receipt (`ReceiptStore`) → settle & accept under subject lock.

## Routing

- Cheat-sheet (locks, stages, queues, tables, CLI): `.ai/guidelines/cheatsheet.md`.
- Process & architecture: `docs/dev/{process-core,architecture,database}.md`.
- Workflows & crawling rehearsal: `docs/workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md`, `docs/dev/crawling-pipeline.md`, `docs/dev/runbooks/archive-run.md`.
- Local skills: `.claude/skills/{procurement-pipeline-dev,estimate-extraction-dev,llm-layer-dev,prompt-engineering-agelto,project-map,testing-rules}/SKILL.md`.
