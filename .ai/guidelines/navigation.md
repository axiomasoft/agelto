# Navigation Guide

When a task requires more detail than what's in the current context, load the relevant file below. Don't load files preemptively — only when you hit a gap.

## Quick Start (3 steps)

1. Start `quick`: read only 1–2 most relevant sources.
2. If domain term is unclear (`Crawler`, `Link`, `Document`, `Platform`, `EIS`, стадии парсинга) — open `docs/02_Workflow/crawling-and-discovery.md` and `docs/03_Dev/architecture.md`.
3. If task is cross-layer (Procurement domain + jobs + Filament + frontend) — open `.ai/guidelines/project-development.md`.

## Agent File Edit Safety (High Priority)

- Before any file edit, check whether the target path is ignored by Git (`.gitignore`).
- Do not edit generated agent files directly:
  - `CLAUDE.md`
  - `AGENTS.md`
  - `.claude/skills/`
  - `.cursor/rules/`
- `CLAUDE.md` and `AGENTS.md` are generated artifacts. For AI rules/skills updates, edit only source files under `.ai/...`.
- Prefer source paths under `.ai/...` when changing AI rules/skills.
- If a requested file is ignored/generated, stop and use the source file instead.

## Fast Term Routing (Minimal Context First)

- Непонятные термины домена (Crawler, Link, Document, стадии 1–4) — сначала `docs/02_Workflow/crawling-and-discovery.md`.
- Имена классов/методов/переменных, канон глаголов (`find/get`), нейминг тестов — `.ai/guidelines/glossary.md` (канон) и `docs/02_Workflow/glossary.md` (полный справочник).
- Изменение пайплайна парсинга / jobs / очередей — `.ai/guidelines/project-development.md`.
- Большая/неоднозначная задача на несколько слоёв — skill `complex-task-orchestrator`.
- Первый проход минимальный: 1–2 файла, затем расширять по необходимости.

## Context Escalation Rule

- Start in `quick` mode (minimal reads and narrow scope).
- Expand context only when:
  - term meaning is still ambiguous after workflow docs;
  - change spans more than one layer (backend + frontend + jobs/tests);
  - there is a direct conflict between instructions/rules.
- When expanding, follow `Reference Table` top-down and stop once enough context is gathered.

## Reference Table

| Situation | Load this file |
|---|---|
| Crawling, parsing, AI screening, estimate extraction | `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md` |
| Procurement / Reference / Organization домены, терминология | `docs/03_Dev/architecture.md` |
| Имена сущностей/методов/переменных, канон глаголов, нейминг тестов | `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md` |
| Layer boundaries, where logic lives | `.ai/guidelines/architecture.md` |
| Cross-layer change in parsing pipeline | `.ai/guidelines/project-development.md` |
| DB schema, table prefixes | `docs/03_Dev/database.md` |
| Crawling pipeline internals | `docs/03_Dev/crawling-pipeline.md` |
| Document/estimate pipeline internals | `docs/03_Dev/document-pipeline.md` |
| LLM providers, AI routing | `docs/03_Dev/llm-providers.md` |
| Admin panel / Filament observability | `docs/03_Dev/admin-panel.md` |
| Writing or running tests | skill `testing-rules` |
| Creating commits | skill `git-commit-rules` |
| Inertia + Vue pages/forms | skill `inertia-vue` |
| Filament admin panel | skill `filament` |
| Frontend routes from backend | skill `wayfinder` |
| DB schema, migrations | skill `database` |
| Eloquent Attribute::make() accessors/mutators | skill `eloquent-accessors` |
| PHP style (final, strict types, named args) | skills `code-style-spatie`, `named-arguments`, `static-analysis` |
| Folder map and layer ownership | skill `project-map` |
| Complex multi-step task | skill `complex-task-orchestrator` |
| Backend + frontend + auth/tests checklist | skill `cross-layer-change-checklist` |
| Editing `.ai/`, `boost.json`, agent generation cycle | skill `ai-context-workflow` |
| Natural wording for comments/commits | skill `writing-style` |
| Terse responses | skill `compact-responses` |
