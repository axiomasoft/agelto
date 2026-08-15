---
id: D15
date: 2026-08-09
status: accepted
item: P0.3
items: [P0.3, P0.4, P0.5, P0.6]
supersedes: [D13]
superseded_by: null
---
## Решение

Supersedes D13: P0 не выдаёт plan-owned workflow scripts. P0.3→P0.4→P0.5
становятся явно последовательной цепью Inputs→Deliverables; P0.6 делает
bounded self-audit, а после phase close запускается fresh-context `task:plan-audit P0`.
RAG:— (repo-grounded: `findings/P0-workflow-static-review-2026-08-09.md`).

## Почему

Обязательный static reviewer вернул RED: канонические research/audit templates
могут скрыть `null` как успех. RED по протоколу блокирует выдачу, а
slot-only rule запрещает локально форкать логику.

## Consequences

Потерян параллелизм P0.3–P0.5 и увеличено wall-clock время; взамен каждый
следующий query track получает запечатанные findings предыдущего, а fail-open поведение
канонических templates не попадает в execution path.
