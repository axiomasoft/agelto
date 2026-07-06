# V2-CRAWL — Сбор данных ЕИS: CSV/HTTP, детальная карточка, архив (Фазы 2/3/3b ТЗ v2)

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | V2-CRAWL |
| Title | Система сбора закупок: source-абстракция, fresh/archive, детальная карточка + документы |
| Version | 0.1.0 |
| Status | 🟢 Done |
| Document Type | Executable Master Plan |
| Authoring Model | opus |
| Last Updated | 2026-07-06 (P4.2 закрыт — план терминален целиком) |
| Repository | agelto/agelto, ветка `feat/v2-crawl` (от `feat/v2-scaffold`) |
| Related Packages | — |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · haiku (LOW recon) · opus (design/audit) |
| Approval Owner | Dmitry Vostrikov |

## 1. Context

Строим слой **сбора данных** v2-платформы поверх готовой схемы БД и моделей (закрыты
в плане V2-CORE, Фазы 0/1). Source of truth — ТЗ `docs/specs/v2/02-crawling.md` (сбор),
`docs/specs/v2/01-database.md` (схема), `docs/specs/v2/06-roadmap.md` (порядок срезов);
живая разведка — `docs/specs/v2/research/eis-csv-recon.md`, `eis-card-recon.md`.

План покрывает три roadmap-фазы: **Фаза 2** (source-абстракция + CSV/HTTP + fresh-поток),
**Фаза 3** (детальная карточка ЕИС по вкладкам + индексация/скачивание документов),
**Фаза 3b** (archive-режим — перекачка истории). Стоп-граница — **мост в пайплайн
документов** (`02-crawling.md` §8): диспетчер ставит закупки/документы в очередь стадии
подготовки. Сам пайплайн подготовки/скрининга/извлечения (Фазы 4–5) и Filament-админка
(Фаза 7) — отдельные будущие планы, вне V2-CRAWL.

Ядро инжеста уже реализовано в V2-CORE P1: `App\Services\Procurement\ProcurementIngestProcessor`
(закупка → ревизия + доменные события), `App\Services\Procurement\StatusMappingService`
(сырой статус → канонический, неизвестный → `unknown`+алерт), репозитории
`ProcurementReadRepository`/`ProcurementStoreRepository`, DTO `ProcurementSnapshotData`.
Модели и enum'ы всех таблиц (`crw_*`, `prc_*`, `doc_*`, `org_*`, `ref_*`, `plt_*`) созданы —
см. `app/Models/`, `app/Enums/`. V2-CRAWL их НЕ переписывает, а надстраивает
source-методы, менеджер прогонов, адаптер ЕИС и стадию детального анализа.

Ключевые технические факты (из разведки, не переизобретать): CSV-endpoint отдаёт
**ровно 500 строк** на блок в кодировке **windows-1251**; глубина ~10 000 строк, за
пределом сервер **молча отдаёт последний блок** → обязателен детект повторного блока
по `crw_fetches.dedup_signature` (первый `reg_number` блока). Всё — обычный HTTP через
прокси-пул, без сессии/браузера. Сырьё (CSV, документы) храним в object storage (MinIO).

## 2. Execution Rules

- Один item за раз; статус обновляется в `phases/Pn.md` до завершения работы.
- Статусы — 6 канонических строк байт-в-байт (см. skill `plan-protocol` §5).
- Каждый готовый срез — через ревью (`agelto-reviewer` read-only; тесты —
  `agelto-test-writer`) прежде чем помечать 🟢; риск-адаптивно.
- Тесты — только через Docker test-gate (`make test-preflight` →
  `docker compose exec app php artisan test --compact`), не голый `php artisan test` на хосте.
- Ингест-ядро (`ProcurementIngestProcessor`/`StatusMappingService`) — переиспользуем, не
  дублируем; новые source-методы кормят его нормализованными снапшотами.
- Всё платформо-специфичное ЕИС (URL-шаблоны, флаги колонок, парсинг карточки) — в
  адаптере площадки (`app/Services/Crawler/Eis/` или `Modules/Eis`), ядро о ЕИС не знает.
- Идемпотентность — инвариант: повтор блока/карточки/скачивания не плодит дублей.
- План разошёлся с ТЗ (`02-crawling.md`/`01-database.md`) → не импровизировать,
  эскалировать (`plan-protocol` §8), правку ТЗ предлагать отдельным шагом.
- После закрытия item/фазы — перезаписать `plans/V2-CRAWL/handoff.md`.
- **Items фаз пока скелеты** — перед `plan-exec` каждую фазу детализировать
  `/task:plan-design V2-CRAWL Pn` (заполнить все 16 полей, Code Guidance обязателен).

## 3. Routing

Без отклонений от дефолтов `plan-protocol` §7. Recon-разведка адаптера ЕИС (структура
вкладок, флаги колонок) — `repo-explorer` haiku/low; синтез адаптера и ревью — дефолт.

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P2 | Сбор: CSV/HTTP, fresh-поток | 6/6 | 🟢 Done |
| P3 | Детальная карточка + документы | 8/8 | 🟢 Done |
| P4 | Архив (перекачка истории) | 2/2 | 🟢 Done |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-05 | Границы V2-CRAWL — roadmap Фазы 2/3/3b (сбор → детальная карточка+документы → архив); стоп на мосте в пайплайн документов (`02` §8). Фазы 4–5 (пайплайн) и Фаза 7 (админка) — отдельные будущие планы | Вертикальный срез «сбор данных» самодостаточен; схема и модели уже готовы (V2-CORE P1) |
| D2 | 2026-07-05 | Нумерация фаз продолжает roadmap/V2-CORE: P2 (Фаза 2), P3 (Фаза 3), P4 (Фаза 3b «Архив»). «3b» переименован в P4 — протокол не поддерживает буквенные суффиксы фаз | Сквозная сверка с `06-roadmap.md`; P0/P1 закрыты в V2-CORE |
| D3 | 2026-07-05 | Ingest-ядро (`ProcurementIngestProcessor`/`StatusMappingService`, репозитории, DTO) переиспользуется из V2-CORE P1, не переписывается | DRY; §11-чек-лист уже покрыт тестами в V2-CORE |
| D4 | 2026-07-05 | Мост в пайплайн документов (`02` §8) размещён в конце P3 (после индексации документов), не в P2 | Диспетчер отбирает закупки/документы; документы появляются только в P3 |
| D5 | 2026-07-05 | Fresh backlog>cap-защита разбита: детект cap + флаг «возможен пропуск» — в P2.6 (fresh); собственно archive-рескан окна простоя — item P4.2 (нужен archive-режим P4.1) | Избегаем forward-dependency; доска честно показывает P4.2 отдельным открытым item |
| D6 | 2026-07-05 | Новая ветка `feat/v2-crawl` от `feat/v2-scaffold` (там уже схема/модели V2-CORE) | Изоляция среза сбора от каркаса |
| D7 | 2026-07-05 | Сырой CSV → `Storage::disk(config('crawler.raw_disk','s3'))`, путь `raw/csv/{crawlerId}/{runId}/{from}.csv`; тесты — `Storage::fake` | Object storage S3/MinIO (`01` §9); в репо сконфигурирован только `s3`-диск → имя диска через config, без хардкода |
| D8 | 2026-07-05 | HTTP-транспорт — Laravel `Http` + прокси из пула; вежливый темп — `Illuminate\Support\Sleep` (fakeable) + джиттер; параллелизм в P2 = 1 (последовательный забор блоков) | Пул конкурентных соединений — YAGNI для fresh (мало блоков за скан); вводить при archive/нагрузке, не сейчас |
| D9 | 2026-07-05 | Инвариант «один running на профиль» — владелец частичный unique-индекс БД (уже в миграции); `CrawlerRunManager` ловит `UniqueConstraintViolationException` и резолвит в существующий run, второй PHP-проверки не заводим; `recoverStale` → `paused` (resumable), не `failed` | Атомарность и single-owner (`production-readiness-checklist`); `paused` сохраняет курсор для возобновления |
| D10 | 2026-07-05 | Слой краулинга: агностик — `App\Services\Crawler\*`, контракты — `…\Contracts\*`, DTO — `App\Dto\Crawler\*`, ЕИС-специфика — `App\Services\Crawler\Eis\*` | В репо нет `Modules/` (канон V2-CORE — `app/`); ядро о ЕИС не знает (`02` §2), специфика изолирована в `Eis\*` |
| D11 | 2026-07-05 | В P2.5 применяется только denylist `crw_ignored_items` (skip до создания закупки); `crw_ignore_rules` (правила на созданную закупку) — вне P2 | Держим P2 тонким; правила — enrichment/админка, отдельно; §3.4 требует лишь дедуп+инжест |
| D12 | 2026-07-05 | HTML-парсинг карточки — `symfony/dom-crawler` + `symfony/css-selector` (добавить в composer при первом использовании, P3.3); ЕИС-селекторы ТОЛЬКО в `App\Services\Crawler\Eis\Card\*`-парсерах | Laravel уже тянет symfony-компоненты транзитивно → низкое трение; DOM+CSS-селектор устойчивее регэкспа для хрупкой вёрстки ЕИС (`02` §9) |
| D13 | 2026-07-05 | `ProcurementDetails` расширяется до композита сырого HTML вкладок (`commonInfoHtml`/`documentsHtml`/`eventJournalHtml`/`supplierResultsHtml`); парсинг+маппинг — отдельными tab-процессорами (P3.3–P3.7), оркестратор `DetailAnalysisProcessor` собирается в P3.8 | Как P2.6 собрал куски P2: независимо тестируемые кирпичи (HTML-фикстура → строки БД) + один сборщик; забор (P3.1) не знает о доменных таблицах |
| D14 | 2026-07-05 | P3 покрывает ТОЛЬКО 44-ФЗ карточку (вкладки по типу процедуры). 223-ФЗ ветка `notice223`/`lot-list`/`lot-info` (лоты первичны, нужен `noticeGuid`/`lotGuid`, `eis-card-recon.md` §10) — Scope Excluded, отдельный follow-up | 223-lot-first — существенный отдельный объём; не блокирует 44-приёмку roadmap Фазы 3; схема (`source_guid`/`lot_guid`) уже готова принять его позже |
| D15 | 2026-07-05 | Организации+роли (P3.4) владеют `org_procurement_parties` end-to-end и денормализацией `prc_procurements.customer_org_id`; P3.3 (common-info) роли/customer_org_id НЕ трогает | Вся org-логика (entity resolution + pivot ролей) в одном item; избегаем forward-dep P3.3→P3.4 |
| D16 | 2026-07-05 | ref_* загрузчик (P3.2) даёт команду+резолвер; массовый продакшн-импорт официальных выгрузок Росстат/Минфин — операторская задача (Pending Work), не блокирующий item | Downstream (P3.3) всегда хранит сырой `classifier_code` и резолвит FK опционально (nullable, `01` §0 п.10) → не блокируется незаполненными справочниками; отдельный тайм-гейтовый item не нужен |
| D17 | 2026-07-05 | Мост в пайплайн (P3.8) — тонкий `PipelineDispatcher`: отбор `doc_documents` в статусе `discovered` + идемпотентный guard-переход статуса; сам consumer стадии подготовки — Фаза 4 (вне V2-CRAWL) | `02` §8 требует лишь диспатч+guard; стадия подготовки/скрининга/извлечения — отдельные будущие планы; мост — единственный owner перехода статуса (`transactional-outbox-relay`) |
| D18 | 2026-07-05 | Детальный слой пишется через новый `ProcurementDetailStoreRepository` + типизированные сущности §4 и `storeEvent`; ingest-ядро (`ProcurementIngestProcessor`) НЕ трогаем | Ядро — про листинг-ревизии/дифф; детальный слой — отдельные типизированные сущности (`prc_eis_details`/items/financing/parties/protocols/…), другой контракт записи |
| D19 | 2026-07-05 | `PipelineDispatcher::dispatch()` (P3.8) отбирает `doc_documents.status=downloaded`, НЕ `discovered` — вопреки буквальной формулировке Scope Included P3.8. `doc_documents` не имеет поля-маркера «поставлено в очередь» (`01` §5) и не мутирует статус (следующий переход `downloaded→prepared` — вне V2-CRAWL, Фаза 4) | Ревью `agelto-reviewer` указало на конфликт с FSM `03-document-pipeline.md` §2 (`discovered──download──>downloaded──prepare──>prepared`): документы, оставшиеся `discovered` после синхронного `DocumentDownloader` в этом же срезе, — это НЕ удавшиеся закачки без контента, готовить нечего; частично отменяет буквальную часть D17 («мост — единственный owner перехода статуса») — D17 не переформулирован текстуально, но фактическое поведение мутации не производит |
| D20 | 2026-07-05 | Переполнение окна в archive (P4.1) детектируется БЕЗ счётчика записей: (a) окно дренировано при `rowsTotal < block_size` (частичный блок); (b) cap (повтор `dedupSignature`) = окно > серверного потолка → ценовое дробление. Отдельные явные ветки, тестируются раздельно | CSV-экспорт ЕИС не отдаёт total count; переиспользуем уже готовый cap-детект по `dedupSignature` (P2.4) вместо изобретения счётчика; `rowsTotal<block_size` — надёжный сигнал последнего блока |
| D21 | 2026-07-05 | Ядро дренажа одного date-окна с ценовым дроблением — общий `ArchiveWindowScanner` + `WindowFilter` DTO (`dateField: publish|update`); P4.1 гоняет по дням (publish), P4.2 переиспользует по update-окну. ЕИС date/price-маппинг (`publishDateFrom`/`priceFromGeneral`/`updateDateFrom`) — только в расширенном `EisCsvUrlBuilder` под управлением агностичного `ListingCursor->day`/`priceStack`; `CsvSourceMethod` прокидывает курсор в билдер | DRY между archive и backlog-рескан; ЕИС-специфика изолирована (`02` §2, D10), курсор остаётся платформо-агностиком; fresh-ветка билдера (курсор без `day`) не ломается |
| D22 | 2026-07-05 | Backlog-рескан (P4.2) развязан с fresh через флаг `cap_hit` (outbox-паттерн): `FreshRunProcessor` только поднимает флаг (P2.6), sweep `crawler:rescan-backlog` — единственный потребитель; идемпотентность — пометка `stats.backlog_rescanned_at` (единственный state-переход, повторный sweep обработанные не берёт; at-least-once при падении scan) | `transactional-outbox-relay`: producer пишет флаг в транзакции прогона, relay-sweep — единственный владелец повторов; развязка избегает прямого coupling fresh→archive и forward-dependency P2.6→P4 |
| D23 | 2026-07-05 | Backlog-рескан владеет своим run на archive-профиле того же реестра (отдельный `crw_crawlers`-ряд mode=archive), НЕ на fresh-профиле; нет archive-профиля → sweep логирует warning и оставляет флаг непомеченным (операторская донастройка) | Инвариант «один running на профиль» (частичный unique-индекс) остаётся чистым — рескан не конфликтует с fresh-cron и не перетирает fresh-курсор; переиспользует P4.1-инфру целиком |
| D24 | 2026-07-05 | Имена ЕИС-параметров update-окна (`updateDateFrom`/`updateDateTo`) для backlog-рескана не подтверждены разведкой (`eis-csv-recon.md` их не фиксирует) — задаются константами в `EisCsvUrlBuilder`, дефолт `updateDateFrom`/`updateDateTo`, smoke-подтверждение при первом реальном прогоне | Publish-параметры (`publishDateFrom/To`) и price (`priceFromGeneral/To`) документированы в `02` §4.2; update-окно экстраполировано по аналогии — держим в одном месте (`Eis\*`) для правки без охоты по коду |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-05 | issue-planner/opus | План создан из `docs/specs/v2/06-roadmap.md` (режим `new`): plan.md, скелеты фаз P2/P3/P4, handoff. Границы и нумерация зафиксированы (D1–D6). ACTIVE → V2-CRAWL |
| 2026-07-05 | issue-planner/opus | Детализирована фаза P2 (6 items P2.1–P2.6, все 16 полей + Code Guidance) по `02-crawling.md` §1–§4,§6 и готовому ingest-ядру V2-CORE. Зафиксированы D7–D11 (storage/транспорт/one-active/слой/denylist) |
| 2026-07-05 | plan-exec/sonnet | P2.2 закрыт: `CrawlerRunManager` (startOrResume/heartbeat/updateCursor/complete/fail/recoverStale), `heartbeat_timeout_seconds` в конфиге. Ревью `agelto-reviewer` — 1 major + 1 minor, оба исправлены (тесты heartbeat/updateCursor добавлены; complete/fail обёрнуты в DB::transaction). 8 тестов, полный набор 43 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P2.3 закрыт: `ProxyPool` (pick/reportFailure/reportSuccess/proxyOptions) + `PoliteThrottle` (Sleep-based джиттер), ключи `proxy_max_fails`/`throttle` в конфиге. Ревью `agelto-reviewer` — approve, 2 major + 2 minor: major «`proxyOptions()` без тестов» исправлен (2 теста добавлены), major «прямые Eloquent-мутации в сервисе» принят как архитектурное исключение для инфры краулинга; 2 minor (round-robin буквально не реализован при равном fails_count; reportFailure не в транзакции) — оставлены как Known Deviations (D8: параллелизм в P2=1). 7 тестов, полный набор 50 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P2.4 закрыт: `CsvSourceMethod`+`EisCsvUrlBuilder` (HTTP-забор блока листинга, win1251→UTF-8, ретраи 429/5xx+пустой 200, `dedupSignature`). Тест-райтер нашёл баг: `dedupSignature` искался по несуществующему ключу `reg_number` вместо реального русского заголовка ЕИС-CSV — cap-детект не сработал бы никогда; исправлено (принудительный флаг `registryNumberCsv` + чтение по литеральному заголовку «Реестровый номер закупки», `research/eis-csv-recon.md`). Ревью `agelto-reviewer` — approve, замечаний нет. 8+6 тестов, полный набор 64 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P2.5 закрыт: `EisCsvRowMapper` (русские колонки CSV → `ProcurementSnapshotData`, `EisProcedureType` — закрытая карта 44-ФЗ) + `CsvBlockIngestor` (агностик: denylist `crw_ignored_items` → `ProcurementIngestProcessor` → raw CSV в storage → `crw_fetches`). Ревью `agelto-reviewer` — needs-work: major «`crw_fetches.proxy_id` никогда не заполнялся» (`ListingBatch`/`CsvSourceMethod` не прокидывали id прокси) — исправлено (`ListingBatch.proxyId` + прокидка из `CsvSourceMethod`); minor «двойной SELECT на строку для new/updated-метрики» — принят как Known Deviation, не блокирует. 10+7 тестов, полный набор 81 passed, pint чисто |
| 2026-07-05 | issue-planner/opus | Детализирована фаза P3 (8 items P3.1–P3.8, все 16 полей + Code Guidance) по `02-crawling.md` §5,§8 и `01-database.md` §2–§5. Разложение: забор вкладок (P3.1) + классификаторы (P3.2) + 5 tab-процессоров (P3.3–P3.7) + сборщик-оркестратор с триггерами пересъёма и мостом (P3.8). Зафиксированы D12–D18 (dom-crawler/композит-DTO/44-only scope/org-владелец/ref-загрузчик/тонкий-мост/detail-store-repo). Phase-first: P3.3→P3.2, P3.7→P3.4/P3.5, P3.8→все — все зависимости назад |
| 2026-07-05 | plan-exec/sonnet | P2.6 закрыт: `FreshRunProcessor` (два раздельных стоп-условия — известный блок / cap, safety hard-cap) + `crawler:run-fresh` + планировщик в `routes/console.php`. Ревью `agelto-reviewer` — needs-work: 3 major (`possible_skip_window.from` брался от старта текущего прогона вместо последнего успешного скана; `routes/console.php` эагерно бил в БД на каждый artisan-вызов, включая до миграций; `RunFreshCrawler` не был покрыт тестами) + 2 minor (мутация `Run` в обход `CrawlerRunManager`; строковая интерполяция в `Schedule::command`) — все исправлены. Попутно впервые закоммичен `app/Services/Crawler/CrawlerRunManager.php` (существовал только в рабочем дереве с P2.2 — см. Phase Handoff P2, зафиксирован пре-существующий риск незакоммиченных файлов P1–P2.3). 4+5 тестов, полный набор 90 passed, pint чисто. **Фаза P2 терминальна (6/6)** |
| 2026-07-05 | plan-exec/sonnet | P3.1 закрыт: `CsvSourceMethod::collectDetails()` (URL-шаблоны из `Registry->settings`, забор 4 вкладок карточки ЕИС, `supplier-results` только для завершающих статусов, опциональный gzip-снапшот в storage). Новые `EisCardUrlBuilder`, `CardUrlTemplateMissingException`. Ревью `agelto-reviewer` — approve, 2 minor исправлены (удалён обесточенный `NotImplementedException`; заполнены Known Deviations — синтетические HTML-фикстуры карточки, не реальный дамп ЕИС). 10 новых тестов, полный набор 99 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.2 закрыт: `ClassifierResolver` (resolveOkpd2/Ktru/Okved2/Kbk — точный `code` → id или `null`) + `ClassifierImporter` (lazy CSV → нормализованные строки) + команда `ref:load-classifiers` (идемпотентный upsert по `code`, чанками, `config/reference.php`). Ревью `agelto-reviewer` — approve, 1 major «двойное JSON-кодирование `attributes`» исправлен (убран повторный `json_encode`); 2 minor приняты как Known Deviations (NOT NULL `valid_from`/рассинхрон колонок при неподтверждённом формате выгрузки — D16, чинить при переходе на реальные данные). 11 новых тестов (4+7), полный набор 110 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.4 закрыт: `OrganizationResolver` (entity resolution `(inn,kpp)`→`ogrn`→создать, гонка через `UniqueConstraintViolationException`, аналог D9) + `PartiesParser` (DOM, роли-карта из `organizations-block`, неизвестная роль — лог+пропуск) + `OrganizationPartiesProcessor` (upsert `org_procurement_parties`, `customer_org_id` из роли `customer`, `DB::transaction()`). Ревью `agelto-reviewer` — approve, 1 major (прямые Eloquent-мутации в обход репозитория) — исправлен: персистентность вынесена в новые `OrganizationStoreRepository`/`ProcurementPartyStoreRepository`. 11 новых тестов (3 Unit + 4+4 Feature), полный набор 131 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.3 закрыт: `CommonInfoParser` (DOM, ИКЗ на 8 разрядов, разбор ОКПД2/КТРУ, фильтр строки «Всего», fallback `position_number`) + DTO (`CommonInfoData`/`FinancingRowData`/`PurchaseItemRowData`) + `CommonInfoProcessor` (резолв кодов через `ClassifierResolver` P3.2, `DB::transaction()`) + `ProcurementDetailStoreRepository` (`storeEisDetails`/`replaceFinancing`/`upsertItem`). Ревью `agelto-reviewer` — needs-work: 1 major (fallback `position_number` не покрыт тестом) + 2 minor (`classifierScheme` ошибочный дефолт при отсутствии кодов; процессор без общей транзакции) — все исправлены + докрыты тестами. Добавлены `symfony/dom-crawler`+`symfony/css-selector` (D12). 10 новых тестов (6+4), полный набор 120 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.5 закрыт: `DocumentsParser` (DOM, вкладка `documents`, `external_id`=`uid` из ссылки `filestore/...`, относительный href → абсолютный URL через `crawler.eis.base_url`) + `DocumentIndexer` (upsert `doc_documents` по `(procurement_id,external_id)`, идемпотентен, снятые документы → `removed_at`+событие `document_removed`) + `DocumentDownloader` (тот же HTTP-паттерн, что `CsvSourceMethod`, дедуп версии по `sha256`/`unique(document_id,content_hash)`, storage `documents/<hash-prefix>/<sha256>`, событие `document_added`/`document_replaced`, ошибка сети → error-блок без падения). Ревью `agelto-reviewer` — needs-work: 1 major (прямые Eloquent-мутации в обход репозитория, вопреки прецеденту P3.3/P3.4) — исправлен новым `DocumentStoreRepository`; 1 minor (молчаливый пропуск строки без файла-ссылки) — исправлен `Log::warning`; 2 minor (нет общей транзакции в даунлоадере; нет обработки гонки на unique-индексе документа) — приняты как Known Deviations (D8, параллелизм=1). 11 новых тестов (3+3+5), полный набор 142 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.6 закрыт: `EventJournalParser` (DOM, вкладка `event-journal`, дата парсится форматом `d.m.Y H:i` после отбрасывания суффикса часового пояса, строка без даты/текста — пропуск+лог) + DTO `JournalEntryData` (только дата+сырой текст, без бизнес-типа) + `EventJournalProcessor` (best-effort regex-маппинг в закрытый enum `ProcurementEventType` — только «переведена на этап «X»»→StatusChanged, «отменен*»→Cancelled, «восстановлен*»→Restored; идемпотентность через новый `ProcurementStoreRepository::hasEvent()` exists-check перед `storeEvent()`; не создаёт протоколы/awards/bids, P3.7 не затронут). Ревью `agelto-reviewer` — approve, 2 minor исправлены (агрегированный `Log::warning` со счётчиком пропусков в конце `process()`; лог в общем `catch` парсера вместо тихого пропуска). 5 новых тестов (3+2), полный набор 147 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.7 закрыт: `SupplierResultsParser`+`SupplierResultsProcessor` (итоги завершённой закупки — `prc_protocols`/`prc_bids`/`prc_awards`, победитель→роль `winner` через `OrganizationResolver`). Ревью — 1-й проход needs-work: critical (участники без ИНН схлопывались в одну `org_organizations` при 2+ безымянных) — исправлен (не резолвить оргу без надёжного идентификатора, `organization_id=null`+новый ключ `participant_ordinal`); 2-й проход approve, 2 minor приняты как Known Deviations. `prc_clarifications`/`prc_cancellations`/`document_version_id`/`lot_id` — не реализованы (Pending Work, источник не описан в задании/разведке). 7 новых тестов, полный набор 154 passed, pint чисто |
| 2026-07-05 | plan-exec/sonnet | P3.8 закрыт: `DetailAnalysisProcessor` (оркестратор вкладок common-info→organizations→documents(+download)→event-journal→supplier-results, `markDetailFetched`/`markDetailFailed` error-блок) + `DetailRefetchSelector` (триггеры §5.2: не снято/новая ревизия(=и смена этапа, статус в snapshot-хэше)/ретрай/периодический ре-скан evaluation-awarded) + команда `crawler:fetch-details`+планировщик каждые 5 минут + тонкий мост `PipelineDispatcher` (`02` §8). Ревью `agelto-reviewer` — 1-й проход needs-work: major — `PipelineDispatcher` изначально отбирал `doc_documents.status=discovered` буквально по тексту item'а, но это расходится с FSM `03-document-pipeline.md` §2 (discovered→download→downloaded→prepare) — документы, оставшиеся discovered после синхронного скачивания в этом же срезе, это НЕ удавшиеся закачки (без контента, готовить нечего); исправлено на `DocumentStatus::Downloaded`. 2 minor приняты как Known Deviations (мост не мутирует status — нет поля-маркера в схеме, расходится с буквальной D17; detail-стадия без fallback-метода — Procurement не хранит crawler_id). Добавлен `Procurement::registry(): BelongsTo`. 16 новых тестов (4+6+2+4), полный набор 170 passed, pint чисто. **Фаза P3 терминальна (8/8)** |
| 2026-07-05 | issue-planner/opus | Детализирована фаза P4 (2 items P4.1–P4.2, все 16 полей + Code Guidance) по `02-crawling.md` §3.3,§4.1,§4.2 и готовой инфре P2. Разложение: archive-режим по дням + ценовое дробление + общий `ArchiveWindowScanner` (P4.1) → backlog-рескан окна простоя как sweep-потребитель cap-флага (P4.2, переиспользует scanner). Зафиксированы D20–D24 (count-free детект переполнения / общий scanner+WindowFilter / outbox-развязка fresh↔archive / run на archive-профиле / update-параметры экстраполированы). Phase-first: P4.2→P4.1 (forward внутри фазы, D5) |
| 2026-07-06 | plan-exec/sonnet | P4.1 закрыт: `WindowFilter`+`ArchiveWindowScanner` (дренаж окна: drain по `rowsTotal<block_size`, cap по повтору `dedupSignature`→бинарное дробление ценового work-стека, safety `max_blocks_per_window`) + `ArchiveRunProcessor` (цикл дней `[from,to]`, курсор `day`, resume) + команда `crawler:run-archive` + archive-оверрайды `EisCsvUrlBuilder`/`CsvSourceMethod` + конфиг `archive.*`. Ревью `agelto-reviewer` — needs-work: 1 major (`WindowFilter->dateField` не долетал до `EisCsvUrlBuilder`, сканер жёстко бы использовал `publish` даже для будущего P4.2 `update`-окна) — исправлен прокидкой `dateField` через новое агностичное поле `ListingCursor->dateField` (D21-совместимо). 13 новых тестов (4+2+3+4), полный набор 183 passed, pint чисто |
| 2026-07-06 | plan-exec/sonnet | P4.2 закрыт: `BacklogRescanProcessor::sweep()` (отбор `crw_runs` completed+`cap_hit`+непомеченных, резолв активного archive-профиля того же реестра, day-loop `drainWindow()` по update-окну `possible_skip_window` через общий `ArchiveWindowScanner` — зеркалит `ArchiveRunProcessor`, resume с курсора СВОЕГО archive-run; исключение внутри дренажа → archive-run failed, флаг непомечен, sweep продолжает со следующим run) + команда `crawler:rescan-backlog` + `CrawlerRunManager::markBacklogRescanned` + sweep-расписание в `routes/console.php` под `Schema::hasTable`-гвардом. Ревью `agelto-reviewer` — approve, 1 minor (`pendingFreshRuns()` не фильтровал по `status=Completed`, sweep мог бы подхватить run между `flagCapHit`/`complete()`) — исправлен. 7 новых тестов, полный набор 190 passed, pint чисто. **Фаза P4 терминальна (2/2) → V2-CRAWL как план завершён целиком** |
