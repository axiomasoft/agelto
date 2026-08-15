---
id: D59
date: 2026-08-10
status: accepted
item: P0.6
items: [P0.6, P1.3, P11.5, P12.2, P12.4, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Immutable production release identity — пара manifest digest + platform digest, связанная в
receipt с source commit и configuration revision. Mutable tag не принимается как единственный
deploy или rollback key. RAG:✅ 2026-08-10 (`findings/P0-rag-infrastructure.md`, INF-03/INF-10).

## Почему

Mutable tag не доказывает, какие bytes были запущены; digest receipt делает deploy и rollback
воспроизводимыми и связывает artifact с source/configuration provenance.

## Alternatives

1. Хранить только semantic/mutable tag. Цена: повторный pull может разрешиться в другие bytes.
2. Хранить digest и source/config provenance. Цена: build/promotion/deploy tooling должно
   материализовать и переносить receipt.

## Consequences

Выбрана альтернатива 2. Rollback сначала проверяет доступность exact digest и совместимость
configuration/schema, затем записывает новый receipt; rebuild того же source commit не считается
тем же release без совпадения digest.
