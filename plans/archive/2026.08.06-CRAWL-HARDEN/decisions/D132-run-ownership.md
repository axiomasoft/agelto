---
id: D132
date: 2026-08-09
status: accepted
item: P10.1
items: [P10.1, P10.2]
supersedes: []
superseded_by: null
---
## Решение

**Каждый crawler run получает typed `RunPurpose` (`profile`, `delta`, `backlog`) и nullable
`subject_key`; resume разрешён только при точном совпадении `(crawler, purpose, subject_key)`.
Несовпадающий running owner даёт typed busy outcome, а не чужой `Run`.** Backlog использует
`subject_key=fresh-run:<id>`, delta — стабильный `fresh-crawler:<id>`, profile — `null`.

`CrawlerRunManager` сериализует start/resume блокировкой строки crawler. Create-ветка внутри
наружной транзакции получает вложенную транзакцию/SAVEPOINT; unique recovery выполняет SELECT
только после отката к savepoint.

## Почему

Частичный unique индекс запрещает второй running run, но не доказывает его владельца. Возврат
любого running run смешивает курсоры разных операций; catch без SAVEPOINT не работает в
PostgreSQL. **Consequences:** delta/backlog при занятости пропускают текущий заход без completion
чужого run; backlog не ставит `backlog_rescanned_at`. Существующий one-running-per-crawler индекс
сохраняется, а race/recovery проверяется реальной PostgreSQL-конкурентностью.

