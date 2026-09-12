---
id: D5
date: 2026-09-10
status: accepted
item: P4.4
items: [P4.3, P4.4]
supersedes: []
superseded_by: null
---
## Решение

B3 выполняется отдельным P4.4 после controlled-production decision и не является prerequisite
P4.3. До P4.4 текущие raw paths нельзя называть immutable audit trail.

## Почему

B3 ухудшает расследования, но сам по себе не создаёт доказанный data-loss blocker узкого pilot.
RAG:— (repo-grounded: `artifacts/PARSING_PRODUCTION_ACCEPTANCE.md:357-378`). **Consequences:** план может
разрешить controlled production при terminal P4.3 и открытом P4.4; plan closure/архив ждёт P4.4.
