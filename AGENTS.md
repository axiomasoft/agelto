# Agelto — Codex Instructions

## Provider contract

- Codex and Claude Code are both active providers. This file is Codex's project entrypoint; `CLAUDE.md` is the Claude Code hub.
- Shared rules are sourced in `.ai/guidelines/{architecture,glossary,navigation}.md`. Change those sources for shared policy, then rebuild the Claude hub; keep this file Codex-specific and compact.
- Project skill bundles are managed by Swissknifeman under `.agents/plugins/`; do not edit generated bundles or `.agents/.swissknifeman-codex-links.json`. Use a matching skill before work; `task:*` commands come from the installed task plugin.

## Working discipline

- При оркестрации непрерывно отслеживать conductor до результата: самостоятельно устранять технические остановки, поручать исправления исполнителям и принимать их по evidence. Не завершать работу лишь потому, что запуск оставлен в фоне.
- Start with one or two relevant files. Expand context only for an unresolved term, a cross-layer change, or a conflict between rules.
- Before editing, check whether the target is Git-ignored. Preserve unrelated dirty worktree changes.
- Native Planner owns plan source and execution state. The coordinator records completion facts and commits; an executor follows its execution-input authority and returns evidence in its stage-report.
- Use the project test/quality commands and report the exact verification performed. Do not weaken tests, guards, or security checks to make a change pass.

## Plan execution

- Archived Task Layout v2 plans are historical provenance. Their carriers and `plan-lint.py`/`plan-views.py` checks are not the current execution interface.
- Codex selects the project plugins in `.codex/config.toml`, matching `.swissknife.json`; native caches supply discovery and `.agents/plugins/` carries the managed project bundles. Installed Task/cache versions are read from the native CLI, not pinned in these instructions. Claude Code uses the same declared plugin set through its marketplace configuration.
- Current work uses native Planner execution-input and its assigned authority. Coordinator owns lifecycle, acceptance evidence registration and commits; executor changes product files and returns its stage-report. Executors do not read or mutate plan carriers or run plan lifecycle commands.
- The isolated PHP harness is documented in `docs/dev/runbooks/process-test-rig.md`. Use `core-*` targets for process-core checks; never reset a dev/shared database. Future suites must fail explicitly with `suite_not_implemented` until their owning package supplies assertions.
- Fixture manifest hashes/provenance and real local HTTP/proxy/fake LLM assertions run before the env/unit proof. Server-owned sequences do not trust client attempt numbers. Keep Docker egress denial alongside `Http::preventStrayRequests()`; fixture wire checks do not prove future domain behavior.

## Architecture invariants

- The flat `app/**` tree has five domains: `Procurement` (`prc_*`), `Reference` (`ref_*`), `Organization` (`org_*`), provider-agnostic `Llm` (`llm_*`) and `Platforms/<Code>` (platform adapters across layers; existing table names stay unchanged).
- Controllers are HTTP-only. Actions/Services own short mutations; repositories own persistence; crawling, parsing and AI run as idempotent Horizon jobs, never in HTTP.
- Общие DTO, модели и резолверы не содержат полей и веток конкретной площадки. Внешние идентификаторы организаций передаются как `(platformCode, type, value)`; их разбор и форматы принадлежат адаптеру в `Services/Platforms/<Code>/`. Текущие legacy зависимости ограничены сокращаемым architecture baseline до owning cutovers.
- Crawler profile filters are typed and registry-resolved; `SourceTlsTrust` is the sole TLS-trust holder and `verify => false` is forbidden.
- Obtain outbound proxies only through `ProxyResolver`; `ProxyScope` describes request purpose, while global and per-target health remain separate.
- Follow the technical naming canon in `.ai/guidelines/glossary.md`; use factories and `test('<русский intent>', fn)` for tests.

## Routing

- Crawling/parsing: `docs/workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md`.
- Architecture/schema: `docs/dev/{architecture,database}.md`.
- Extraction, LLM, prompts, tests and migrations: use the matching project skill first.
- For generated or AI-context files: read `docs/dev/ai-context/index.md` and use `ai-context-workflow`.
