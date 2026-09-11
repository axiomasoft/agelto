---
id: D2
date: 2026-09-10
status: superseded
item: P0.1
items: [P0.1, P1.1, P1.2, P1.3, P1.4, P2.1, P2.2, P2.3, P3.1, P3.2, P3.3, P3.4, P3.5, P4.1, P4.2, P4.3, P4.4]
supersedes: []
superseded_by: D9
---
## Решение

Порядок фаз фиксирован: partitions → crawling completeness/concurrency → trusted snapshots/current
lifecycle → recovery/storage/timeouts → independent acceptance/live/controlled-production.

## Почему

A8 ломает test/data foundation; recovery без truthful snapshot/storage плодит ложные состояния;
production gate до deterministic fixes недостоверен. RAG:— (repo-grounded:
`research/01-remediation-architecture.md`). **Consequences:** downstream item не обходит
не terminal predecessor; B3 — единственное явно неблокирующее исключение после launch decision.
