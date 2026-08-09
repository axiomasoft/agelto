---
id: D93
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Канон каркаса документации переезжает из архивного плана в живой `docs/meta/structure.md` (P9.8) — самостоятельным документом, а не ссылкой.** Здесь же зафиксировано отношение к P8.4: её пометки «раздел описывает снесённые таблицы» в `docs/specs/v2/{01,07}` умирают вместе с файлами при роспуске, и это НЕ потеря — их содержание к тому моменту уже живёт в `docs/03_Dev/database.md`, который P8.4 привела к фактической схеме

## Почему

Сегодня `docs/meta/editing.md` отправляет за раскладкой в `plans/archive/2026.07.07-EST-AI/research/H-docs-structure.md`: архив append-only, то есть у правил каркаса нет легального места записи, а P9 их меняет. `RAG:— (repo-grounded: docs/meta/editing.md §«Где что живёт»; phases/P8.md P8.4 Scope Included п. 5)`
