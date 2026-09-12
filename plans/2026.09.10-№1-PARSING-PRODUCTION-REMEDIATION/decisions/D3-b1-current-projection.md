---
id: D3
date: 2026-09-10
status: superseded
item: P2.2
items: [P2.2, P4.1]
supersedes: []
superseded_by: D7
---
## Решение

`prc_purchase_items` и `prc_procurement_parties` считаются current projections и синхронизируются
с последним подтверждённым полным snapshot: отсутствующие строки удаляются/деактивируются в той же
transaction; incomplete/failed snapshot не меняет active set. B1 не исключается из pilot scope.

## Почему

Текущее upsert-only поведение оставляет несуществующие позиции/роли, а audit history уже хранится
в procurement revisions/events. RAG:— (repo-grounded: `artifacts/PARSING_PRODUCTION_ACCEPTANCE.md:258-277`).
**Consequences:** P2.2 обязан проверить N→N−1, N→0, renumber и rollback; способ delete/soft-delete
выбирается по фактическим FK/consumers без потери заявленной history surface.
