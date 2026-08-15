---
id: D46
date: 2026-08-09
status: superseded
item: finish
items: [finish]
supersedes: [D11]
superseded_by: D52
---
## Решение

Supersedes D11 только в design-budget: владелец явно сокращает `Design Passes` с 1/3 до 1/1 и
разрешает выполнить один полный cross-phase `finish`. Точный объём D11 — 13 фаз / 74 items —
сохраняется. Исполнение по-прежнему запрещено до чистого `finish` и отдельного fresh-context
`plan-audit design` на frontier/xhigh. RAG:— (repo-grounded:
`brief/01-refinements.md`, owner decision 2026-08-09).

## Почему

Владелец выбрал целостный единый финальный reconcile вместо двух дополнительных независимых
design-проходов. Сам `finish` не сокращается: он обязан сверить контракты, producers/consumers,
Required Reads, brief trace, DoR и roadmap всех фаз.

## Consequences

Сокращение бюджета повышает риск коррелированной слепой зоны одного авторского прохода. Этот риск
не считается снятым самоаудитом: обязательный adversarial `plan-audit design` остаётся отдельным
блокирующим шагом, а его `ATTENTION|RED` возвращает план в адресный re-design до execution.
