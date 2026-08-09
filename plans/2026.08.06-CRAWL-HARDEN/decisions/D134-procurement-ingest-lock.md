---
id: D134
date: 2026-08-09
status: accepted
item: P10.3
items: [P10.3, P10.4, P10.5]
supersedes: []
superseded_by: null
---
## Решение

**Procurement ingest сериализуется PostgreSQL transaction-scoped advisory lock по паре
`(registry_id, registry_number)` до первого find/create. После lock все procurement/revision
данные перечитываются, а `revision_number` вычисляется только из свежего locked state.**

## Почему

Job uniqueness действует на crawler, а не на procurement; row lock невозможен до создания
первой строки. Глобальная блокировка registry неоправданно сериализовала бы независимые закупки.
Partitioned unique constraint включает `detected_at` и не может обеспечить уникальность
логического номера ревизии. **Consequences:** identical concurrent snapshots дают одну логическую
ревизию, разные — монотонную последовательность и корректный head; два реальных DB connection /
process обязаны доказать это. Индекс секционирования не ослабляется.

