# Navigation Guide

When a task requires more detail than what's in the current context, load the relevant file below. Don't load files preemptively — only when you hit a gap.

## Quick Start (3 steps)

1. Start `quick`: read only 1–2 most relevant sources.
2. If domain term is unclear (`Crawler`, `Link`, `Document`, `Platform`, `EIS`, стадии парсинга) — open `docs/02_Workflow/crawling-and-discovery.md` and `docs/03_Dev/architecture.md`.
3. If task is cross-layer (Procurement domain + jobs + Filament + frontend) — open skill `procurement-pipeline-dev`.
4. If task is specifically LLM-транспорт/провайдер-адаптер — skill `llm-layer-dev`; extraction-контракты/discovery/preflight/unprocessable — skill `estimate-extraction-dev`; тексты промтов/композиция/eval — skill `prompt-engineering-agelto`.

## Agent File Edit Safety (High Priority)

- Before any file edit, check whether the target path is ignored by Git (`.gitignore`).
- Do not hand-edit `CLAUDE.md` — assembled manually from `.ai/guidelines/**`; edit the source instead. No `AGENTS.md`/`GEMINI.md`/`.cursor/` — retired (single-provider Claude Code decision), do not recreate.
- `.claude/skills/*`: vendor/managed skills carry an in-file `swissknifeman:managed` marker and are overwritten by `skiller sync` — don't hand-edit; local domain skills have no marker and ARE edited directly (skill `ai-context-workflow`). There is no `.ai/skills/` — only `.ai/guidelines/**` are source files edited before regenerating `CLAUDE.md`.
- Prefer source paths under `.ai/guidelines/**` when changing AI RULES (not skills — see above); if a requested guideline-derived file is ignored/generated (`CLAUDE.md`), stop and use the source instead.

## Fast Term Routing (Minimal Context First)

- Непонятные термины домена (Crawler, Link, Document, стадии 1–4) — сначала `docs/02_Workflow/crawling-and-discovery.md`.
- Имена классов/методов/переменных, канон глаголов (`find/get`), нейминг тестов — `.ai/guidelines/glossary.md` (канон) и `docs/02_Workflow/glossary.md` (полный справочник).
- Изменение пайплайна парсинга / jobs / очередей — skill `procurement-pipeline-dev`.
- Извлечение смет (контракты P2, discovery/windowed vs row-range, preflight, checkpoint-requeue, unprocessable) — skill `estimate-extraction-dev`.
- LLM-транспорт (провайдер-адаптер, кэш-инварианты, capability-матрица/EOL-модели, стоимость) — skill `llm-layer-dev`.
- Тексты промтов, композиция, protected-промты, eval-цикл — skill `prompt-engineering-agelto`.
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
| Cross-layer change in parsing pipeline (dependency map, test-gate, PR checklist) | skill `procurement-pipeline-dev` |
| Extraction смет: контракты P2, discovery/windowed vs row-range, preflight, checkpoint-requeue, unprocessable | skill `estimate-extraction-dev`, `docs/specs/v2/08-estimate-extraction.md` |
| LLM-транспорт: провайдер-адаптер, кэш-инварианты, capability-матрица/EOL-модели | skill `llm-layer-dev`, `docs/03_Dev/llm-providers.md` |
| Промты: файл→сид→is_protected, композиция/приоритеты, hints, eval-цикл | skill `prompt-engineering-agelto`, `docs/03_Dev/ai/prompts.md` |
| PR-чеклист по докам (docs/SOP обновлены?) — для ЛЮБОГО PR | `.ai/guidelines/docs-pr-checklist.md` |
| Task-starter prompts (review/tests/security/deploy) | `/code-review`, `/test-gen`, `/security-check`, `/docker-deploy` |
| DB schema, table prefixes | `docs/03_Dev/database.md` |
| Crawling pipeline internals | `docs/03_Dev/crawling-pipeline.md` |
| Document/estimate pipeline internals (FSM, стадии, очереди, failure modes) | `docs/03_Dev/document-pipeline.md` |
| Инцидент пайплайна (завис анализ, quota/429, backlog) | `docs/03_Dev/runbooks/_index.md` |
| Оператор: проверить/скорректировать/перезапустить AI-разбор | `docs/04_Operators/_index.md` |
| AI-контекст: как устроены `.ai/`/скилы/генерация CLAUDE.md | `docs/03_Dev/ai-context/index.md`, skill `ai-context-workflow` |
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
