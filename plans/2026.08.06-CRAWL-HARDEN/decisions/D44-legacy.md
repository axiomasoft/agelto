---
id: D44
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Реализация бюджета — `Illuminate\Support\Facades\RateLimiter` (счётчик поверх общего кэш-стора) + цикл ожидания на `Illuminate\Support\Sleep`, а не `Redis::throttle()`.** Отвергнут `Redis::throttle()`: тестовое окружение работает на `array`-сторе, и прямое обращение к Redis сделало бы тесты бюджета зависимыми от живого сервиса; на проде оба варианта одинаково лежат на Redis. **Consequences:** окно фиксированное, а не скользящее — всплеск на стыке двух окон возможен и гасится джиттерной паузой стадии; это записывается докблоком, а не умалчивается

## Почему

Готового носителя «общий бюджет на внешний хост поверх нескольких job-классов и синхронной команды» во фреймворке нет — ни одного класса с такой семантикой в документации 13.x. Счётчик лимитера живёт в кэш-сторе, то есть один ключ виден воркеру, artisan-процессу и вебу. `RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, rate-limiting.md «Basic Usage»/«Cache Configuration», queues.md «Job Middleware»; findings/P4-rag-throttle-2026-08-06.md §1, §2, §7)` + `RAG:— (repo-grounded: config/cache.php:20 default = redis; phpunit.xml CACHE_STORE=array)`
