---
id: D1
date: 2026-09-10
status: accepted
item: P0.1
items: [P0.1, P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4]
supersedes: []
superseded_by: null
---
## Решение

Сохранить текущую PostgreSQL/Horizon архитектуру и исправить A1–A9, B1, NEW-1/NEW-2 в пяти
зависимых фазах. Терминальный `2026.08.06-CRAWL-HARDEN` используется как provenance и не
переоткрывается. Design-сессия меняет только документацию и carriers нового плана.

## Почему

Оба аудита называют defects конечными и локальными текущим contracts; смена платформы их не
исправляет. RAG:— (repo-grounded: `findings/00-source-synthesis.md`). **Consequences:** 17 items
детализированы сразу по прямому требованию владельца; implementation начинается только после
второго design-pass `finish` и независимого plan-audit.
