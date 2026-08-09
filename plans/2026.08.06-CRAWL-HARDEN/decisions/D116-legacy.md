---
id: D116
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Исполнительский маршрут перенесён с Claude на Codex без изменения тиров §3 Routing:** `economy` → `gpt-5.6-luna`, `implementation` → `gpt-5.6-terra`, `frontier` → `gpt-5.6-sol`; effort остаётся значением из Routing. Workflow P7 использует `terra` для implementation/green/gate и `sol` для review. **Consequences:** новые launch-block'и указывают реальные модели Codex; исторические `sonnet`/`opus` в Update Log, journal и аудитах не переписываются, поскольку фиксируют уже состоявшиеся исполнения.

## Почему

Предыдущий runtime не принимает имена Claude-моделей; без явного отображения будущий запуск workflow P7 и handoff не исполнимы. `RAG:— (repo-grounded: task-plan-close SKILL.md — implementation/low → gpt-5.6-terra/low; task-plan-design SKILL.md — frontier/high → gpt-5.6-sol/high)`
