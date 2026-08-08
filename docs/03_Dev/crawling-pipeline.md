---
audience: dev
type: explanation
status: draft
---

# Краулинг: internals

Краулинг реализован вне плана EST-AI (до его старта) — этот раздел даёт входную карту для
разработчика; полный рефакторинг/переписывание вне scope EST-AI (Scope Excluded P10.2/P10.3:
«диаграммы краулинга»). Бизнес-описание стадий — `docs/02_Workflow/crawling-and-discovery.md`.

## Вход: команды-диспетчеры, обход — на очереди (P3.2)

Fresh- и archive-обход исполняются job'ом `App\Jobs\Procurement\CrawlRunJob` на profile-driven
execution lane Horizon — как и AI-пайплайн (`document-pipeline.md`). `standard` использует
`redis/crawl` (`supervisor-crawl`, timeout 300s, retry_after 360s); временный архивный `long` —
`redis_crawl_long/crawl_long` (`supervisor-crawl-long`, 3600s/3900s). Это lease ОДНОЙ job, не
лимит всего crawl: курсор остаётся гарантией resume, а connection оба используют один Redis.
Artisan-команды (`app/Console/Commands/Procurement/`) остались тонкими диспетчерами: резолвят
профиль(и) и диспатчат job, сам обход в процессе команды больше не идёт.

- `crawler:run-fresh {crawler?}` (`RunFreshCrawler`) — диспатчит `CrawlRunJob(mode: Fresh)` по
  профилю(-ям) площадки, обычно из `schedule:work` (`scheduler`-контейнер) или вручную.
- `crawler:run-archive {crawler} {--from=} {--to=}` (`RunArchiveCrawler`) — диспатчит
  `CrawlRunJob(mode: Archive, from, to)` за диапазон дат.
- `crawler:fetch-details {procurement?}` (`FetchDetails`) — тонкий диспетчер `crawl_detail`: с
  явным id ставит одну закупку, без аргумента — ограниченную `dispatch_batch_limit` due-пачку.
  `DetailRefetchSelector` отдаёт сначала ни разу не снятые закупки, затем самые старые
  `detail_fetched_at`, со стабильной развязкой по id; расписание раз в пять минут защищено
  `withoutOverlapping()`.
- `crawler:rescan-backlog` (`RescanBacklog`) — пересканирование зависшего/пропущенного бэклога;
  синхронна.
- `crawler:recover-stale` (`RecoverStaleRuns`, планируется `everyFiveMinutes()`) — поднимает
  `running`-прогоны с протухшим heartbeat в `paused`: `CrawlerRunManager::recoverStale()`
  реализован и покрыт тестом с самого начала, но не вызывался ниоткуда — павший воркер оставлял
  прогон в `running` навсегда, а частичный unique-индекс превращал его в блокировщик профиля.

`CrawlRunJob` — checkpoint-requeue (`ShouldBeUniqueUntilProcessing`, тот же приём, что
`EstimateExtractionJob`): бюджет одного запуска — `config('crawler.run.checkpoint_seconds')`
(240с); по исчерпании `FreshRunProcessor` выходит с сохранённым курсором и статусом `running`,
job самодиспатчится продолжением. Для archive `ArchiveWindowScanner` возвращает
`ArchiveWindowScanResult`: только `Drained` разрешает дневной обёртке перейти к следующему дню;
`PlatformBudgetExhaustedException` становится `Checkpointed` через
`CrawlerRunManager::recordCheckpoint()`, а операторский pause/cancel — `Stopped`. Оба исхода
сохраняют текущие день и ценовой стек. Archive продолжает существующий `CrawlRunJob` в том же
execution lane; delta ждёт следующего cron, а backlog — следующего 15-минутного sweep без записи
`backlog_rescanned_at`. Новый job, retry-loop или sleep для этого не создаются. Для сида архива
2025 long lane остаётся конечным; если фактическая длительность превысит час, не поднимать timeout
молча — снять HTTP/throttle/DB-метрики и принять отдельное решение.

Детальная стадия работает отдельным `FetchProcurementDetailJob` на `redis/crawl_detail`
(`supervisor-crawl-detail`, timeout 300s < retry_after 360s). Job — `ShouldBeUnique` по id
закупки и не имеет свойств очередного ретрая: retry остаётся в `detail_*` на закупке. После
успешного `DetailAnalysisProcessor::run()` job передаёт скачанные документы в `PipelineDispatcher`;
исчерпание platform budget возвращает job без error-блока, роста `detail_attempts` или передачи в
мост. Лимит пачки — `crawler.stages.detail.dispatch_batch_limit` (100 по умолчанию, не
калиброван) и проходит через `CrawlSettingsResolver`/`StageSettings`.

Остановить УЖЕ ВЫПОЛНЯЮЩИЙСЯ job средствами Laravel/Horizon нельзя — остановка кооперативна:
`App\Services\Procurement\RunStopGate::signal()` читает `prc_crawler_runs.stop_requested_at`/
`stop_kind` между блоками (`FreshRunProcessor::run()`, `ArchiveWindowScanner::scan()`), сигнал
`Pause` сохраняет курсор и переводит в `paused` (resumable), `Cancel` — в `cancelled`
(терминальный). Флаг выставляет операторское действие в админке (P3.4) — P3.2 несёт только
механизм, кнопок нет.

Прогон (`$run`, `App\Enums\Procurement\Crawler\RunStatus`: running/paused/completed/failed/cancelled)
оркеструется `App\Services\Procurement\CrawlerRunManager`.

## Наблюдаемость

Каждый HTTP-забор пишет `prc_fetches` через единственный `FetchLogger`: листинг связан с прогоном,
а detail/document — с закупкой. В строке видны стадия, статус ответа, длительность, прокси и ожидание
слота. Суммарное и максимальное ожидание листинга сохраняются в `prc_crawler_runs.stats`.
Операторские сбои пишутся через `OperationLogWriter` в `prc_operation_logs`; read-only витрина
«Краулинг → Operation logs» фильтрует их по уровню, стадии и сущности. Файловые `Log::*` остаются
для разработчика и не заменяются этой витриной.

## Мост в AI-пайплайн

Точка передачи — `PipelineDispatcher` (`app/Services/Procurement/PipelineDispatcher.php`):
`dispatch()` сразу после `FetchProcurementDetailJob`, `dispatchBacklog()` — everyMinute-поллер бэклога.
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

## Типизированный фильтр профиля

`prc_crawlers.filter` хранится прежним `jsonb` (схема не менялась), но наружу отдаётся типизированным
`spatie/laravel-data`-объектом — состав полей определяется площадкой, а не общим мешком ключей.
Резолв класса фильтра — по коду реестра через `CrawlerFilterFactory::for(Registry): class-string<Data>`
(config-карта `crawler.filters`, тот же приём, что `SourceMethodFactory`/`crawler.source_methods`;
`match`-лестниц и `if ($registry->code === …)` в билдере нет). Для ЕИС/44-ФЗ —
`App\Dto\Procurement\Crawler\EisSearchFilter` (`app/Dto/Procurement/Crawler/EisSearchFilter.php`):
этапы закупки (`stageApplications`/`stageCommission`/`stageCompleted`/`stageCancelled`), цена
от/до (`priceFrom`/`priceTo` — `priceTo = 0` запрещён валидацией: у площадки это обнуляет выдачу,
а не снимает ограничение), даты публикации, строка поиска + флаг морфологии. Неизвестный ключ
фильтра отклоняется ДВАЖДЫ — явной проверкой в `EisSearchFilter::validateAndCreate()` (родитель
`spatie/laravel-data` лишние ключи молча игнорирует) и хуком модели `Crawler::booted()` на
`saving` — опечатка оператора не должна дожить до боевого запроса. Только площадко-специфичные
параметры, у которых фильтрующее действие подтверждено живой пробой, входят в контракт; поля со
статусом «не установлено» (например, `okpd2Ids`) в DTO намеренно отсутствуют. Перевод типизированных
полей в конкретные query-параметры площадки (`stageCompleted`→`pc=on`, форматы дат) живёт РОВНО в
билдере площадки (`EisCsvUrlBuilder::filterParams()`) — DTO несёт смысл, не представление площадки.

## Отслеживание изменений: перекрытие, delta-scan, дедуп, метрика отставания

- **Перекрытие окон** — `config('crawler.tracking.window_overlap_days')` (стартовое значение — 2
  суток): и рескан бэклога (`BacklogRescanProcessor::drainWindow()`), и плановый суточный дозор
  сдвигают старт окна назад на эту величину. Источник (ЕИС) отдаёт дату обновления с точностью до
  суток, а расписание работает в UTC против часовых поясов заказчиков по стране — без запаса
  обновление на границе окна теряется.
- **Плановый суточный дозор (delta-scan)** — `App\Services\Procurement\Modes\DeltaScanProcessor`
  (переиспользует `ArchiveWindowScanner`, профиль резолвится тем же способом, что рескан бэклога) +
  команда `crawler:run-delta {crawler?}`; регистрация в `routes/console.php` — под флагом
  `config('crawler.tracking.delta_scan_enabled')` (по умолчанию `false`, включается владельцем
  ПОСЛЕ боевого прогона площадки) и cron `config('crawler.tracking.delta_scan_cron')`
  (`'0 23 * * *'` UTC — единственное измерение темпа сдвига выдачи снято ночью).
- **Дедуп внутри блока** — `CsvBlockIngestor::ingest()` схлопывает строки одного блока по реестровому
  номеру ДО инжеста (первая строка выигрывает), считает схлопнутые в `FetchResult::$rowsDuplicated`
  и пишет `Log::warning` при значении > 0; актуально для многолотовых процедур (223-ФЗ), где один
  блок может нести несколько строк на один номер.
- **Метрика отставания** — `prc_crawler_runs.stats` (jsonb, без миграции): `scan_gap_seconds`
  (пауза от завершения предыдущего прогона профиля до старта текущего) и `oldest_update_seen`
  (минимальная «Дата обновления» в снятых блоках), пишутся только fresh-прогоном
  (`CrawlerRunManager::recordScanGap()`/`recordOldestUpdateSeen()`).

## Сиды площадки ЕИС

`database/seeders/Procurement/EisPlatformSeeder.php` заводит площадку `code=eis`, реестр `fz44` и
его `settings` (`procedure_url_types` — сегменты URL по способу закупки, `card_url_templates` —
шаблоны вкладок карточки по типу процедуры); `CrawlerProfileSeeder.php` — два профиля обхода
(архивный, `filter=stageCompleted:true`, `execution_lane=long`, `is_active=true`, запуск явной командой; «свежий поток»,
`filter` пуст — обязан видеть любое обновление, `is_active=false` до решения владельца о запуске
боевого слежения, `execution_lane=standard`, `schedule='*/30 * * * *'`). Оба сидера `updateOrCreate`-идемпотентны, но
`CrawlerProfileSeeder` перезаписывает профиль безусловно — повторный прогон стирает правки,
сделанные оператором в форме краулера.

## Живые ограничения площадки ЕИС

Снято живой разведкой 2026-08-04/05 (`plans/archive/2026.07.31-SYS-INTEGRITY/findings/P4-eis-recon.md`,
`findings/P4-load-baseline.md`):

- Реальный потолок глубины листинга — **5 000** записей на выборку (не ≈10 000, как считалось
  раньше по устаревшей разведке) — за пределом площадка молча повторяет последний блок
  (`dedup_signature` совпадает с предыдущим), а не отвечает ошибкой.
- Блок выгрузки — 500 строк за запрос; троттлинг — 1000–4000 мс между запросами.
- Ценовое дробление архивного обхода: `archive.max_blocks_per_window` откалиброван до **12**
  (наблюдённый максимум — 11 блоков на ценовой интервал); `archive.price_ceiling` (1 млрд ₽) и
  `archive.min_price_interval` (1000 ₽) подтверждены боевым прогоном без изменений.
- **Доверие TLS площадки прошито в образе, не в системном доверенном наборе.** Площадка использует
  сертификат российского удостоверяющего центра; бандл (`docker/php/certs/russian-trusted-ca.pem`)
  копируется в образ фиксированным путём (`docker/php/Dockerfile`, `update-ca-certificates` НЕ
  вызывается — доверие не должно расширяться на прочий исходящий трафик приложения). Единственный
  читатель — `App\Services\Procurement\SourceTlsTrust::optionsFor(string $host)` по карте
  `config('crawler.tls.bundles')`; результат складывается с опциями лизы прокси на обоих боевых
  call-site'ах (`CsvSourceMethod::request()`, `DocumentDownloader::request()`). `verify => false`
  запрещён абсолютно — у правила ровно один носитель.

## Раскладка HTML детальной карточки (снято 2026-08-05)

Вёрстка страницы карточки менялась с последней разведки — актуальная раскладка (селекторы, откуда
берётся каждый блок) снята живой пробой и записана в
`plans/archive/2026.07.31-SYS-INTEGRITY/findings/P4-card-layout.md`; при следующей смене вёрстки источника
пере-снимать оттуда же (карточка не версионируется площадкой явно, признак смены — падение парсера
на живом прогоне, не заранее известная дата). Кратко: вложения — `div.blockFilesTabDocs` →
`div.attachment` → ссылка `filestore` (имя файла — атрибут `title`, не текст ссылки); общие данные
карточки — плоские пары `section.blockInfo__section` (не `dt`/`dd`, как считалось раньше); журнал
событий — `table#event.displaytagTable`; финансирование — год в ЗАГОЛОВКЕ колонки (не в строке);
код классификатора позиции — один код на ячейку, признак КТРУ поверх ОКПД2 — дефис-суффикс, не
пара через слэш. Состав сторон закупки (`org_procurement_parties`) и путь «есть победитель»
вкладки `supplier-results` на этой раскладке не встретились ни на одной снятой карточке — это
**не подтверждено, не факт отсутствия**, парсеры под них не трогались (`[UNVERIFIED]`, снимается
следующим прогоном на карточке нужного вида).

## Транспорт детальной стадии и сторож «пришла не та страница»

`CsvSourceMethod::request()` не передаёт в Guzzle пустой массив `queryParams` — до фикса это молча
стирало query-строку запрошенного URL (в т.ч. `?regNumber=…` карточки), источник отвечал штатной
страницей «не найдено», и она проходила по пайплайну как «карточка снята успешно». Сторож на этот
класс отказа («пришла валидная страница, но не та, что запрошена») — `assertCardPage()` в
`collectTab()`: несоответствие = ответ с ошибкой ИЛИ тело без запрошенного реестрового номера;
на любой вкладке пишется `Log::warning` (вкладка, URL, статус, размер тела), а на `common-info`
дополнительно бросается `App\Services\Procurement\Exceptions\CardPageMismatchException`, которую
ловит `DetailAnalysisProcessor::run()` (переводит в `detail_last_error` + backoff, `markDetailFetched`
не ставится). `CommonInfoProcessor` не создаёт `prc_eis_details`, если разбор дал полностью пустой
результат (все атрибуты `null`, нет строк финансирования, нет позиций) — пустая запись раньше была
неотличима от «карточка снята, данных нет».

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

## Healthcheck площадки: реакция на смену вердикта

`crawler:health-check` сохраняет каждый результат в `prc_platform_checks`. После сохранения
`PlatformHealthChecker` сравнивает вердикт с последним результатом той же пары «площадка + вид
проверки»: ухудшение до `degraded`/`broken`, восстановление до `ok` и напоминание о неизменившейся
поломке после `crawler.health.repeat_alert_hours` порождают `PlatformHealthChanged`. Его
синхронный автодискаверимый слушатель `NotifyPlatformHealthChanged` передаёт текст в существующий
`PipelineAlert`; второй, часовой рубеж антиспама остаётся в самом транспорте.

Healthcheck не останавливает и не ставит на паузу прогоны: результат — сигнал для человека, а не
команда конвейеру. Telegram не настроен — `PipelineAlert` пишет предупреждение в лог, поэтому
сигнал не исчезает молча.

## Смежное

- `docs/02_Workflow/crawling-and-discovery.md` — бизнес-язык, стадии обхода (P10.4).
- `document-pipeline.md` — что происходит с документом ПОСЛЕ `downloaded`.
- Терминология: `Platform`, `Crawler`, `CrawlerRun` — `.ai/guidelines/glossary.md`.
