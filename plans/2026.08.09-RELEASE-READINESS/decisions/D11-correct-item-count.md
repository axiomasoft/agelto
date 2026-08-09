---
id: D11
date: 2026-08-09
status: superseded
item: P0.6
items: [P0.6]
supersedes: [D2]
superseded_by: D46
---
## Решение

Supersedes D2 только в арифметике skeleton: точный объём — 13 фаз / 74 items. Layout v2,
contract-first, Design Passes 1/3 и запрет исполнения до полной детализации остаются без
изменений. RAG:— (repo-grounded: status.md).

## Почему

Generated state/status перечислили 74 item carriers; ручная сумма 68 была ошибочной.

## Consequences

Design budget N=3 не меняется, но все owner-facing счётчики и последующий coverage sweep
обязаны использовать 74.
