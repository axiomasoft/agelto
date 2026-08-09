---
id: D27
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Прогон краулера исполняется queued job'ом `CrawlRunJob` на собственной очереди `crawl` со своим supervisor'ом Horizon; artisan-команды становятся тонкими диспетчерами.** Длинный обход продолжается самодиспатчем в пределах бюджета (`checkpoint_seconds < supervisor timeout < queue.redis.retry_after`), job несёт `ShouldBeUniqueUntilProcessing` и НЕ объявляет свойств очередного ретрая. Закрывает развилку № 13. Отвергнуты: `Artisan::queue('crawler:run-fresh')` (у команды нет ни уникальности, ни бюджета, ни точки остановки), синхронный вызов из админки (HTTP-запрос живёт секунды, обход — часы), batch API (отмена батча не прерывает выполняющийся job — D28). **Consequences:** появляется ещё одна очередь и ещё один supervisor, которые надо держать в инварианте таймаутов при каждом изменении конфигурации очередей; зато исчезает расхождение между брифом («всё через job'ы, Horizon») и кодом, и кнопки P3.4 становятся выразимыми

## Почему

Бриф требует очереди трижды, а сегодня краулинг синхронен внутри команды — это зафиксировано и в коде, и в собственной документации. `RAG:— (repo-grounded: app/Console/Commands/Procurement/RunFreshCrawler.php:38; app/Jobs/Procurement/ — ни одного job'а краулинга; docs/03_Dev/crawling-pipeline.md §«Вход: команды, не Jobs»)`. Канон checkpoint-requeue и запрет свойств ретрая уже оплачены в extraction-контуре: `RAG:— (repo-grounded: tests/Unit/Architecture/JobRetryContractGuardTest.php; config/queue.php `redis.retry_after` = 360)`
