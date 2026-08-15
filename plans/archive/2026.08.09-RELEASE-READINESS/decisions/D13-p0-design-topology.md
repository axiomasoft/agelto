---
id: D13
date: 2026-08-09
status: superseded
item: P0.6
items: [P0.1, P0.2, P0.3, P0.4, P0.5, P0.6]
supersedes: []
superseded_by: D15
---
## Решение

P0 остаётся design-only фазой без product writes. P0.1 и P0.2 идут последовательно; P0.3–P0.5 исполняются plan-owned research-fanout workflow; P0.6 выполняет frontier synthesis, после чего отдельный read-only workflow аудирует contracts до GREEN или двух fix rounds. RAG:— (repo-grounded: `research/01-P0-execution-design.md` и plan-protocol §7).

## Почему

Три RAG-трека имеют независимый scope и deterministic validation, что активирует orchestration criterion. Межтрековый contract freeze остаётся в main loop дорогой модели.

## Consequences

План несёт два workflow scripts и pipeline carrier; P0.3–P0.5 не запускаются поодиночке, а P0.6 не закрывается без GREEN audit report.
