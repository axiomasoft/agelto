---
id: D75
date: 2026-08-11
status: accepted
item: P8.3
items: [P5.2, P8.3]
supersedes: []
superseded_by: null
---
## Решение

P8.3 получает один item-owned executable carrier
`docker/tests/p8.3-status-mapping-contract.sh`. Пока P8.3 не реализован, upstream downstream-gate
возвращает `unknown`, а не исполняет prose fragments будущей Validation. RAG:— (repo-grounded:
P5.2 downstream record on `fd286156`, D63/D68 precedent).

## Почему

P5.2 изменяет shared database documentation and mechanically matched P8.3; its prose Validation
was incorrectly treated as shell commands and produced a false RED unrelated to P5.2.

## Alternatives

1. Treat the red as a P5.2 regression. Rejected: no P8 schema/runtime path exists yet.
2. Remove the dependency edge. Rejected: hides the shared schema/documentation surface.
3. Normalize P8.3 to an item-owned carrier. Selected.

## Consequences

P8.3 retains its full acceptance matrix, but only its future product carrier executes it. P5.2
downstream evidence is rerun against the changed consumer spec; no P8 product code is created here.
