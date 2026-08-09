---
id: D117
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Owner gate `AUDIT-CAP` P3 закрыт приёмкой фазы `🟠 Done with deviations`; четвёртого реопена нет. F1 аудита переименован из двусмысленного «бюджета длинного crawl» в технический lease одной job. Для временного датированного archive-профиля заводится follow-up P4.7 и execution lane `long`; default всех профилей — `standard`.** Профиль хранит enum/колонку `execution_lane`, не raw seconds. `standard` сохраняет `redis/crawl`, `timeout=300`, `retry_after=360`; `long` получает logical connection `redis_crawl_long`, queue `crawl_long`, supervisor `supervisor-crawl-long`, `timeout=3600`, `retry_after=3900`, `maxProcesses=1`, `tries=1`; оба connection используют тот же Redis. Start/Restart/Resume и self-dispatch continuation обязаны сохранить lane профиля. Архивный сид 2025 получает `long`, fresh — `standard`; detailed pages/estimates, throttle и request budget P4.4 не меняются. P4.7 идёт ПЕРВЫМ в P4. Отвергнуты: (а) снять timeout — зависшая job не имеет конечного lease; (б) месяц/сутки — crash/deploy оставляют reserved job слишком надолго без фактической необходимости; (в) один connection с `retry_after=360` — Redis может повторно выдать ещё работающую long-job; (г) секунды в профиле — продуктовая запись начинает управлять инфраструктурной безопасностью; (д) новый Redis server — logical connection достаточно. **Consequences:** P3 остаётся терминальной с честно названным универсальным долгом — archive/delta/backlog не получают checkpoint-requeue по секундомеру; P4 растёт 6→7 items, инфраструктурное исключение P4.7 точечно supersedes D105 для `config/queue.php`, `config/horizon.php`, compose grace и `.env.example`; stop grace Horizon становится 4200 с, поэтому controlled deploy способен ждать до завершения long-job; если фактический годовой CSV-прогон не укладывается в час, калибровка меняется отдельной D-записью по метрикам.

## Почему

Прямое решение владельца после аудита: общего или денежного бюджета crawl нет; листинг за год скачивает CSV, детальный разбор идёт отдельными job; временному профилю с явными датами можно дать большой конечный лимит. Сохранённый замер: 194 блока × (4.577 с mean HTTP + 2.5 с mean throttle) ≈ 23 мин сетевой части, поэтому час даёт >2× запас до DB overhead. `RAG:— (repo-grounded: brief/01-refinements.md блок 2026-08-08; research/12-p4.7-long-execution-lane.md; findings/P4-long-execution-lane-2026-08-08.md; config/crawler.php; config/horizon.php; config/queue.php; CrawlRunJob.php)`
