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
| Crawling, discovery, document & estimate workflow | `docs/workflow/{crawling-and-discovery,document-and-estimate-pipeline}.md` |
| Domain terms, layers, schema | `docs/dev/{architecture,database}.md`, `.ai/guidelines/glossary.md` |
| Estimate extraction, windows, chunks, publication | skill `estimate-extraction-dev`, `docs/dev/ai/estimate-extraction.md` |
| LLM ledger, send gate, receipts, assets, pricing | skill `llm-layer-dev`, `docs/dev/llm-providers.md` |
| Prompts, protected records, eval | skill `prompt-engineering-agelto`, `docs/dev/ai/prompts.md` |
| Isolated `core-*` test rig, concurrency, manifest | skill `testing-rules`, `docs/dev/runbooks/process-test-rig.md` |
| Filament / Inertia / routes | skills `filament`, `inertia-vue`, `wayfinder` |
| PR documentation, commits, risky multi-layer work | `.ai/guidelines/docs-pr-checklist.md`; `docs/meta/structure.md`; `tests/Unit/Docs/DocsIntegrityTest.php` |
| Runbooks, operators, training loop | `docs/dev/runbooks/_index.md`, `docs/operators/_index.md`, `docs/dev/ai/training-loop.md` |
| AI-context or skill-generation changes | `docs/dev/ai-context/index.md`, skill `ai-context-workflow` |
