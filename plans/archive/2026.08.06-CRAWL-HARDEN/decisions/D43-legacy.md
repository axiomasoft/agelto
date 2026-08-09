---
id: D43
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Штатное middleware очереди `RateLimited`/`RateLimitedWithRedis` для бюджета НЕ применяется**: оно отпускает job обратно в очередь, а отпущенный job расходует попытку — при `tries = 1` (наш канон во всех supervisor'ах, защищённый арх-сторожем) job просто не вернётся. Ожидание слота происходит В ТОЧКЕ ИСХОДА. **Consequences:** воркер, ждущий слот, занят и не берёт другие job'ы своей очереди — поэтому максимальное ожидание ограничено и обязано укладываться в чекпоинт-бюджет прогона (гейт числами в P4.4)

## Почему

`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, queues.md «Rate Limiting», «Preventing Job Overlaps»: «Releasing a rate limited job back onto the queue will still increment the job's total number of attempts… leaving Tries to 1 as it is by default would prevent any overlapping job from being retried later»; findings/P4-rag-throttle-2026-08-06.md §3)` + `RAG:— (repo-grounded: config/horizon.php tries=1 во всех supervisor'ах; tests/Unit/Architecture/JobRetryContractGuardTest.php)`
