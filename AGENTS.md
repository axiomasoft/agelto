# Agelto — Codex Instructions

## Provider contract

- Codex and Claude Code are both active providers. This file is Codex's project entrypoint; `CLAUDE.md` is the Claude Code hub.
- Shared rules are sourced in `.ai/guidelines/{architecture,glossary,navigation}.md`. Change those sources for shared policy, then rebuild the Claude hub; keep this file Codex-specific and compact.
- Project skill bundles are managed by Swissknifeman under `.agents/plugins/`; do not edit generated bundles or `.agents/.swissknifeman-codex-links.json`. Use a matching skill before work; `task:*` commands come from the installed task plugin.

## Working discipline

- Start with one or two relevant files. Expand context only for an unresolved term, a cross-layer change, or a conflict between rules.
- Before editing, check whether the target is Git-ignored. Preserve unrelated dirty worktree changes.
- Plans and phase status live in `plans/`; use the task-plan workflow for planned work and record completion facts in the plan/handoff, not only in chat.
- Use the project test/quality commands and report the exact verification performed. Do not weaken tests, guards, or security checks to make a change pass.

## Plan layout v2

- `plans/archive/2026.08.06-CRAWL-HARDEN` is the terminal **Layout v2** archive. Its historical execution path is `plan.md` → `status.md` → `handoff.md` → `phases/Pn/Pn.md` → `phases/Pn/Pn.m.md` + `Pn.m.state.md`. `artifacts/legacy-v1-*` are provenance/rollback only and are not normal execution input.
- Codex receives the native global `task@swissknifeman` plugin (currently v0.10.0) from the local Swissknifeman marketplace; the project deliberately has no `.agents` plugin bundle or copied Task runtime. Claude Code uses the same declared plugin set through its marketplace configuration.
- Use native `$ task:` plan commands for this plan. Layout-aware commands select the v2 read path and never create v1 phase carriers, Phase Status tables, or outcome fields in item specifications.
- Before and after plan work, run the Task checks from the Swissknifeman marketplace root in dependency order: `plan-lint.py`, then `plan-views.py state --all`, `decisions`, and `status` with `--check`. An `unresolvable_entry` is a plan-input defect to resolve or record in its owning work; never bypass it by falling back to v1.

## Architecture invariants

- The flat `app/**` tree has four domains: `Procurement` (`prc_*`), `Reference` (`ref_*`), `Organization` (`org_*`) and provider-agnostic `Llm` (`llm_*`).
- Controllers are HTTP-only. Actions/Services own short mutations; repositories own persistence; crawling, parsing and AI run as idempotent Horizon jobs, never in HTTP.
- Crawler profile filters are typed and registry-resolved; `SourceTlsTrust` is the sole TLS-trust holder and `verify => false` is forbidden.
- Obtain outbound proxies only through `ProxyResolver`; `ProxyScope` describes request purpose, while global and per-target health remain separate.
- Follow the technical naming canon in `.ai/guidelines/glossary.md`; use factories and `test('<русский intent>', fn)` for tests.

## Routing

- Crawling/parsing: `docs/02_Workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md`.
- Architecture/schema: `docs/03_Dev/{architecture,database}.md`.
- Extraction, LLM, prompts, tests and migrations: use the matching project skill first.
- For generated or AI-context files: read `docs/03_Dev/ai-context/index.md` and use `ai-context-workflow`.
