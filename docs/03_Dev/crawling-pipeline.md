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

Прогон (`$run`, `App\Enums\Procurement\Crawler\RunStatus`: running/paused/completed/failed/cancelled)
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
`SupplierResultsProcessor`); `ArchiveWindowScanner`/`ProxyResolver`/`PoliteThrottle` — вспомогательная
инфраструктура обхода. Шов `prc_eis_details` подтверждён аудитом схемы БД как расширяемый на другие
источники (не завязан на ЕИС жёстко, D-требование универсальности плана EST-AI).

## Прокси: слой маршрутизации

Прокси — не список узлов, а слой маршрутизации перед КАЖДЫМ исходящим запросом кроулинга (и LLM,
см. `llm-providers.md`). Единственная точка выдачи — `ProxyResolver::resolve(ProxyScope $scope,
string $target, ?ProxyRequirements $requirements)` (`app/Services/Procurement/Proxy/ProxyResolver.php`),
единственная точка обратной связи — `reportOutcome()`.

- **`ProxyScope`** (`App\Enums\Procurement\Proxy\ProxyScope`) — точка исхода трафика, а не сам узел:
  `CrawlListing`/`CrawlDetail`/`DocumentDownload`/`Llm`. Три HTTP-канала кроулинга
  (`CsvSourceMethod::collectListing`/`collectTab`, `DocumentDownloader::fetch`) и LLM-канал
  (`PoolOutboundProxy`, порт `OutboundProxy`) каждый передаёт свой скоуп резолверу — сам резолвер
  скоуп не выбирает.
- **Отбор кандидата:** фильтр (`is_active`, скоуп в `scopes`, гейт запрещённых стран
  `crawler.proxy.policy.<scope>.forbidden_countries`, гео-политика скоупа
  `crawler.proxy.policy.<scope>.required_country` (`crawl_*`/`document_download` → `RU`, `llm` →
  `null`), требование профиля `ProxyRequirements` при его наличии, глобальный/target-breaker не
  открыт) → score (`ProxyScoreCalculator`, вход —
  `success_ratio`/`latency_ms_avg`/`fails_count`) → weighted random внутри top-K
  (`crawler.proxy.resolver.top_k`), не голый argmax — не забивает лучший узел трафиком.
  `forbidden_countries` и `required_country` складываются союзом (обе гео-политики жёсткие,
  конфликт даёт пустое множество и `NoProxyAvailableException`); пустая политика запретов для
  скоупа `llm` не проходит тихо — резолвер бросает `ProxyPolicyMisconfiguredException`, которую
  `PoolOutboundProxy` не ловит (env-фолбэк LLM не подменяет непроверенный запрет).
- **Двухуровневое здоровье:** глобальное на `prc_proxies` (`open_until`/`open_count`/`fails_count`) —
  разрыв соединения/авторизации прокси/etc, узел плох ВЕЗДЕ; per-target на `prc_proxy_targets`
  (`success_ratio`/`latency_ms_avg`/`consecutive_failures`, EWMA) — 403/429/пустое тело от конкретной
  цели, узел плох для НЕЁ. Пустой пул = узел не выдан ни для одной цели.
- **Circuit breaker** — экспоненциальный cooldown (`open_count` — степень бэкоффа), half-open проба
  через `claimGlobalProbe()`/`claimTargetProbe()` (единственность гарантирует БД: `affectedRows`, не
  блокировка приложения). Таксономия причин отказа — `App\Enums\Procurement\Proxy\ProxyFailureReason`
  (`network_timeout`/`tls_error`/`connect_failed`/`proxy_auth_failed`/`http_403`/`http_429`/
  `empty_body`/`unknown`); уровень реакции (глобальный/target) — `ProxyCircuitBreaker::level()`.
- **Привязка к профилю (`prc_crawlers`):** `use_proxy` (false — намеренный прямой запрос, не отказ)
  и `proxy_country` (ISO 3166-1 alpha-2, желаемая страна узла) потребляются РОВНО на скоупе
  `crawl_listing` (`CsvSourceMethod::collectListing`) — единственном call-site'е, у которого есть
  `$crawler` под рукой. `crawl_detail` (`collectTab`) и `document_download`
  (`DocumentDownloader::fetch`) профиля не видят (детальная стадия и скачивание работают по
  `Registry`/URL, не по профилю, D81) — их гео закрывает ИСКЛЮЧИТЕЛЬНО политика скоупа
  `required_country`, без привязки к конкретному профилю. Колонки скоупа на профиле НЕТ — скоуп
  определяется точкой исхода, а не профилем.
- **`use_proxy = false`** — намеренный операторский режим ТОЛЬКО для `crawl_listing`: обходит ОБЕ
  гео-политики (и `forbidden_countries`, и `required_country`) целиком, резолвер не вызывается,
  уход виден логом (`Log::info`, «профиль отключил прокси»). Для `crawl_detail`/`document_download`
  такого переключателя нет — они всегда идут через резолвер.
- **Пустой пул** никогда не тихий: `NoProxyAvailableException` + `Log::warning` на каждый отказ
  выдачи (нет молчаливого прямого запроса, кроме явного `use_proxy=false` на `crawl_listing`).

## Смежное

- `docs/02_Workflow/crawling-and-discovery.md` — бизнес-язык, стадии обхода (P10.4).
- `document-pipeline.md` — что происходит с документом ПОСЛЕ `downloaded`.
- Терминология: `Platform`, `Crawler`, `CrawlerRun` — `.ai/guidelines/glossary.md`.
