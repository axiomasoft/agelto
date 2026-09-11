---
id: D8
date: 2026-09-10
status: accepted
item: P2.3
items: [P2.3, P4.1, P4.2, P4.3]
supersedes: [D4]
superseded_by: null
---
## Решение

Условный B2 contract D4 исполняется в точном порядке: P2.3 запечатывает
fail-closed protocol; owner-authorized P4.1 собирает live revision-pair evidence и при
недостаточном evidence внедряет bounded revalidation; P4.2 независимо принимает
результат; P4.3 не стартует без terminal P4.1 с resolved B2 и GREEN P4.2.

## Почему

D4 сохранила stale ссылку на P4.2 как владельца live probe, тогда как детальная
фаза выделила live/remediation в P4.1 и fresh independent acceptance в P4.2.
RAG:— (repo-grounded: `phases/P4/P4.md`, `phases/P4/P4.1.md`, `phases/P4/P4.2.md`).

## Consequences

**Consequences:** P4.1 один владеет сетевым owner gate и conditional application diff; P4.2 не смешивает live
разведку с независимой приёмкой; P4.3 проверяет оба terminal carrier и не интерпретирует
отсутствие counterexample как uid guarantee.
