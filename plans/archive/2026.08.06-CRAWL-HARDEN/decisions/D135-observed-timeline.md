---
id: D135
date: 2026-08-09
status: accepted
item: P10.4
items: [P10.4]
supersedes: []
superseded_by: null
---
## Решение

**Discovery фиксирует canonical initial status для любого mapped/unknown статуса. Timeline считает
только наблюдённую системой длительность: terminal-at-discovery имеет terminal timestamp равный
discovery time и total observed duration `0`, а не `null`/«идёт».**

## Почему

Без initial status builder не знает начало первого интервала и не видит terminal procurement,
если статус не менялся после discovery. Выдумывать длительность до первого наблюдения нельзя.
**Consequences:** duplicate unknown status event не создаётся; UI/docs называют метрику
наблюдённой длительностью; ingest-path tests покрывают initial bidding, переходы и completed at
first sight.

