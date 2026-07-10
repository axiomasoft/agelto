---
audience: dev
type: explanation
status: draft
---

# Краулинг: internals

Краулинг реализован вне плана EST-AI (до его старта) — этот раздел даёт входную карту для
разработчика; полный рефакторинг/переписывание вне scope EST-AI (Scope Excluded P10.2/P10.3:
«диаграммы краулинга»). Бизнес-описание стадий — `docs/02_Workflow/crawling-and-discovery.md`.

## Вход: команды, не Jobs

В отличие от AI-пайплайна (`document-pipeline.md` — весь цикл на очередях Horizon), краулинг
запускается artisan-командами (`app/Console/Commands/Procurement/`), обычно из `schedule:work`
(`scheduler`-контейнер) или вручную:

- `crawler:run-fresh {crawler?}` (`RunFreshCrawler`) — обход свежих извещений по профилю площадки
  (`CrawlerMode::Fresh`).
- `crawler:run-archive {crawler} {--from=} {--to=}` (`RunArchiveCrawler`) — обход архива за диапазон
  дат (`CrawlerMode::Archive`).
- `crawler:fetch-details {procurement?}` (`FetchDetails`) — дозагрузка деталей закупки (без
  аргумента — все закупки, due по `DetailRefetchSelector`).
- `crawler:rescan-backlog` (`RescanBacklog`) — пересканирование зависшего/пропущенного бэклога.

Прогон (`$run`, `App\Enums\Procurement\RunStatus`: running/paused/completed/failed/cancelled)
оркеструется `App\Services\Procurement\CrawlerRunManager`.

## Мост в AI-пайплайн

Точка передачи — `PipelineDispatcher` (`app/Services/Procurement/PipelineDispatcher.php`):
`dispatch()` сразу после `FetchDetails`, `dispatchBacklog()` — everyMinute-поллер бэклога.
`DocumentDownloader` (`app/Services/Procurement/DocumentDownloader.php`) доводит документ до
статуса `downloaded` — с этого момента начинается зона ответственности `document-pipeline.md`
(Д2, узел `PipelineDispatcher`).

## Площадки и извлечение деталей

`Services/Procurement/Eis/**` — ЕИС-специфичные адаптеры (`EisCardUrlBuilder`, `CsvSourceMethod`,
`EisCsvRowMapper`/`EisCsvUrlBuilder`); `Services/Procurement/Detail/**` — процессоры деталей закупки
(`CommonInfoProcessor`, `EventJournalProcessor`, `OrganizationPartiesProcessor`,
`SupplierResultsProcessor`); `ArchiveWindowScanner`/`ProxyPool`/`PoliteThrottle` — вспомогательная
инфраструктура обхода. Шов `prc_eis_details` подтверждён аудитом схемы БД как расширяемый на другие
источники (не завязан на ЕИС жёстко, D-требование универсальности плана EST-AI).

## Смежное

- `docs/02_Workflow/crawling-and-discovery.md` — бизнес-язык, стадии обхода (P10.4).
- `document-pipeline.md` — что происходит с документом ПОСЛЕ `downloaded`.
- Терминология: `Platform`, `Crawler`, `CrawlerRun` — `.ai/guidelines/glossary.md`.
