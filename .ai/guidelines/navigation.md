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
| PR documentation, commits, risky multi-layer work | `.ai/guidelines/docs-pr-checklist.md`; skills `git-commit-rules`, `complex-task-orchestrator` |
| Runbooks, operators, training loop | `docs/03_Dev/runbooks/_index.md`, `docs/04_Operators/_index.md`, `docs/03_Dev/ai/training-loop.md` |
| AI-context or skill-generation changes | `docs/03_Dev/ai-context/index.md`, skill `ai-context-workflow` |
