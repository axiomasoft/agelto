---
id: D48
date: 2026-08-09
status: accepted
item: P0.0
items: [P0.0, P0.1]
supersedes: []
superseded_by: null
---
## Решение

До первого product/research item вводится отдельный prerequisite P0.0 — единственный producer
project-level `plans/verify-citations.py`. Carrier реализует замороженный stdlib CLI: live-plan
scan, двухбазовый repo-root→plan-root resolve, path line/range/list, symbol и JSON-key citations,
data-only known exceptions, embedded selftest и `--strict` fail-closed exit. P0.1 и следующие
items используют одну точную команду; execution до terminal P0.0 запрещён. RAG:—
(repo-grounded: `plan.md`, audit design 2026-08-09 finding F1, Task plan-tooling contract).

## Почему

Существующего executable carrier нет ни в репозитории, ни в установленном Task runtime, а общий
gate уже является acceptance condition 51 item'а. Делать P0.1 неявным producer'ом оставило бы
его собственную Validation циклической; пропускать gate до поздней фазы означало бы fail-open.

## Alternatives

1. Заменить citation check на `plan-lint` — отклонено: structural lint не резолвит evidence links.
2. Встроить verifier в P0.1 — отклонено: inventory и shared tooling имеют разные Files/acceptance.
3. Отдельный P0.0 с deterministic selftest и единым downstream invocation — принято.

## Consequences

План получает 75-й item и один новый repo-level plans-tool; Routing/status/roadmap и bundles
пересобираются. Product code не меняется, existing item statuses остаются неизменными.
