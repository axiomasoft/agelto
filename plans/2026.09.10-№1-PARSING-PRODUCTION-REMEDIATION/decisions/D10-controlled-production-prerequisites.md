---
id: D10
date: 2026-09-10
status: superseded
item: P4.3
items: [P4.1, P4.2, P4.3]
supersedes: [D6]
superseded_by: D12
---
## Решение

Controlled-production gate остаётся owner-authorized 72-часовым ограниченным окном D6, но
точные prerequisites таковы: P4.1 terminal с resolved D8/B2 verdict, P4.2 GREEN свежим
независимым actor/context, затем durable owner authorization на environment/profile/scope,
request ceilings и reversible stop action.

## Почему

D6 использовала `GREEN P4.1` и `resolved P4.2`, хотя детальные ТЗ назначают B2 resolution
на P4.1, а independent GREEN acceptance — на P4.2. Метрики, NO-GO semantics и 72-hour окно
не меняются. RAG:— (repo-grounded: `phases/P4/P4.1.md`, `phases/P4/P4.2.md`, `phases/P4/P4.3.md`).

## Consequences

**Consequences:** P4.3 fail-closed проверяет оба terminal carrier в их точном смысле; ни terminal P4.1 без
resolved B2, ни не-GREEN P4.2, ни неформальный чатовый ответ владельца не разрешают
controlled-production traffic.
