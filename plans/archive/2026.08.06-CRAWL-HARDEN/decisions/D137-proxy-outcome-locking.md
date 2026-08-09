---
id: D137
date: 2026-08-09
status: accepted
item: P10.6
items: [P10.6]
supersedes: []
superseded_by: null
---
## Решение

**Proxy outcome persistence выполняется короткой транзакцией с блокировками в фиксированном
порядке `proxy → proxy_target`, повторным чтением state и вычислением breaker/EWMA под lock.
HTTP остаётся вне транзакции; transition logging выполняется после commit.**

## Почему

Detached models и обычные updates теряют concurrent outcomes; атомарный increment один не решает
EWMA и переход breaker state. Фиксированный порядок предотвращает lock inversion между global и
target paths. **Consequences:** два одновременных failure учитываются дважды, threshold открывает
breaker ровно один раз, оба latency samples входят в EWMA; concurrency test использует отдельные
PostgreSQL connections/processes.

