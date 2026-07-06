# Технические тонкости краулера (V2-CRAWL P3–P4)

Только для разработчиков. Источник — `plans/V2-CRAWL/phases/P3.md` и `P4.md` (Known Deviations / Pending Work завершённых items). План `V2-CRAWL` со временем будет удалён из `plans/` — этот файл сохраняет нюансы, которые иначе потерялись бы.

## 1. Резолв организации: коллизия при неименованном участнике (P3.7)

`SupplierResultsProcessor` резолвит участников закупки в `org_organizations` через `OrganizationResolver` (P3.4). Первый прогон (до ревью) резолвил участников **без ИНН/ОГРН по голому имени** — это дало **critical**-находку на ревью `agelto-reviewer`:

> при 2+ безымянных участниках (обычный случай) `OrganizationResolver` гарантированно схлопывал РАЗНЫХ юрлиц в одну `org_organizations` (catch-восстановление `OrganizationStoreRepository::create()` на `UniqueConstraintViolationException` подменяло второго безымянного первым найденным).

**Исправление:** участник без надёжного идентификатора (ИНН/ОГРН) вообще НЕ резолвится в организацию — `organization=null`, `prc_bids.organization_id=null`. Естественный ключ upsert заявки при `organizationId===null` переключается на `participant_ordinal` (см. п.3 ниже). Победитель всегда резолвится через `OrganizationResolver` (у него есть ИНН/ОГРН) и получает роль `winner`.

Практическое следствие: **если планируешь трогать `SupplierResultsProcessor`/`OrganizationResolver` — не резолвь по имени без ИНН/ОГРН**, это воспроизведёт ту же коллизию.

## 2. SAVEPOINT-паттерн для recovery unique-violation внутри открытой транзакции

Побочный (но важный) фикс, вскрытый P3.7: `OrganizationResolver::resolve()` ловит гонку создания одной организации двумя параллельными процессами через `catch(UniqueConstraintViolationException)` + повторный поиск (аналог D9-паттерна). Но `SupplierResultsProcessor::process()` уже открывает свою `DB::transaction()` вокруг всего среза. Если `OrganizationStoreRepository::create()` кидает `UniqueConstraintViolationException` **внутри уже открытой внешней транзакции Postgres**, вся транзакция уходит в `aborted` state — и `catch` сам бы упал следующим запросом (Postgres не позволяет выполнять команды в aborted-транзакции без ROLLBACK/SAVEPOINT).

**Исправление:** `OrganizationStoreRepository::create()` обёрнут во вложенный `DB::transaction()` — Laravel транслирует вложенный `DB::transaction()` в `SAVEPOINT`/`RELEASE SAVEPOINT`/`ROLLBACK TO SAVEPOINT` на Postgres. Это даёт локальный откат только create-попытки, не всей внешней транзакции.

**Правило на будущее:** если в коде есть паттерн «try create → catch unique violation → find existing» (D9-паттерн, уже используется в `OrganizationResolver`/`CrawlerRunManager`) и этот код может быть вызван **внутри чужой уже открытой транзакции** — оборачивай `create()` в собственный `DB::transaction()` (SAVEPOINT), иначе одна гонка уронит весь охватывающий срез.

## 3. Хрупкость `participant_ordinal` как ключа идентичности

`prc_bids` upsert для участников без `organization_id` идёт по `(procurement_id, protocol_id, organization_id, participant_ordinal)`. `participant_ordinal` берётся **из текста ячейки «№ п/п»** бид-таблицы (не из индекса строки в DOM) — это специально сделано устойчивее, чем `position_number`-fallback в P3.3 (там — индекс строки).

Тем не менее это **не формально доказанная** гарантия идентичности:

> стабилен между повторными прогонами ОДНОГО и того же HTML-снапшота, но не гарантирован при перетасовке источником порядка/номеров участников между разными снятиями страницы.

Если ЕИС когда-нибудь начнёт перевыдавать `supplier-results` с изменённой нумерацией участников между снимками — `participant_ordinal` перестанет однозначно идентифицировать участника, и upsert может создать дубль или перезаписать не ту заявку. Известный, принятый риск (Known Deviations P3.7), не блокирующий MVP.

Родственная деталь: и `prc_financing`/`prc_items`/`prc_protocols`/`prc_bids`/`prc_awards` держат идемпотентность на прикладном `updateOrCreate` по естественному ключу **без подкрепляющего DB unique-индекса** — приемлемо при `параллелизм=1` (D8 из плана V2-CRAWL, синхронная однопоточная стадия), станет реальным риском гонки при выносе detail-стадии в очереди.

## 4. N+1 на `registry` при пакетной обработке

`DetailRefetchSelector::due()` / `DetailAnalysisProcessor::run()` при батч-обработке через `crawler:fetch-details` без аргумента делает по одному `SELECT plt_registries` **на каждую закупку** вместо одного batched-запроса. Признан низким impact (справочник `plt_registries` — единицы строк на реестр), сознательно не оптимизирован (Known Deviations P3.8). Если объём закупок в батче вырастет на порядки — стоит вернуться и заэagerload'ить `registry`.

## 5. `PipelineDispatcher` НЕ мутирует статус документов — сознательное расхождение (D17)

Изначально `PipelineDispatcher::dispatch()` должен был быть «единственным owner'ом перехода статуса» моста в пайплайн документов (`docs/03_Dev/crawling-pipeline.md` §8, решение **D17**). По факту реализации (P3.8):

> в схеме (`docs/03_Dev/database.md` §5) нет отдельного поля-маркера «поставлено в очередь», а миграция не входила в Files-скоуп этого item'а; следующий переход (`downloaded→prepared`) владеет будущий consumer Фазы 4, не этот мост.

Мост сейчас **только отбирает и логирует** `doc_documents.status = downloaded` — не переводит их дальше. Это согласовано ревьюером как обоснованный компромисс при текущих ограничениях схемы, но **фактически отменяет часть D17 текстуально** — сам D17 не был переформулирован в спеке. Важно помнить при реализации Фазы 4 (consumer подготовки документов): именно она должна стать единственным owner'ом перехода `downloaded → prepared`, а не мост.

Также рядом (не сам D17, но соседнее сознательное отклонение): `DetailAnalysisProcessor` резолвит источник строго через `config('crawler.detail_source_method')` (единственный сконфигурированный `csv`), **без fallback** на альтернативный метод при деградации источника (в отличие от `FreshRunProcessor::makeWithFallback`). Причина — `Procurement` не хранит `crawler_id`, резолв «через какой именно `Crawler`-профиль» неоднозначен при нескольких профилях на реестр (см. `docs-private/open-follow-ups.md`).

## 6. Проброс `WindowFilter->dateField` через `ListingCursor` (D21)

P4.1 изначально объявил `WindowFilter->dateField` (`'publish'|'update'`), но **не пробросил его до `EisCsvUrlBuilder`** — сканер жёстко использовал `publish`, что сломало бы заявленное в Scope Included переиспользование `ArchiveWindowScanner` в P4.2 по `update`-окну. Это была **major**-находка ревью `agelto-reviewer`.

**Исправление (решение D21):** `ListingCursor` расширен агностичным полем `dateField` (`'publish'|'update'|null`, зеркалит `WindowFilter->dateField`, без ЕИС-семантики). `ArchiveWindowScanner` прокидывает `$filter->dateField` через каждую итерацию курсора; `EisCsvUrlBuilder` читает `cursor->dateField` и маппит его в ЕИС-специфичные имена параметров (`publishDateFrom/To` либо `updateDateFrom/To`) — этот маппинг остаётся **только** в `EisCsvUrlBuilder`, курсор остаётся площадко-агностиком.

**Правило:** если добавляешь новую ЕИС-специфичную date-семантику в archive/backlog-режимы — не хардкодь имя параметра в сканере/процессоре, только в `EisCsvUrlBuilder`-констант ах, курсор должен остаться агностиком (то же правило, что и D3 из `V2-CRAWL`: не дублировать инжест/курсор/lifecycle).

## 7. Неподтверждённые параметры ЕИС update-window API (D24)

Имена ЕИС-параметров `updateDateFrom`/`updateDateTo` (по аналогии с `publishDateFrom`/`publishDateTo`) и точные значения `price_ceiling`/`min_price_interval` — **калибровка на живых данных, не подтверждена** реальным API-прогоном (D24, `plan.md` §5). Указано явно в Pending Work P4.1:

> Реальный bulk-backfill против живого ЕИС — операторский прогон вне автотестов (D24, точные имена update-параметров и `price_ceiling`/`min_price_interval` — калибровка на живых данных).

При первом реальном archive/backlog-прогоне на живом ЕИС — быть готовым, что имена параметров `update*` могут не совпасть с предположением и потребуют правки в `EisCsvUrlBuilder`.

## 8. Все HTML-фикстуры карточки — синтетические

Общий риск, унаследованный от P3.1 и растиражированный на P3.3–P3.7: `tests/Fixtures/Crawler/Card/*.html` — **не реальный HTML-дамп ЕИС**, а структурные реконструкции по текстовому описанию из `research/eis-card-recon.md` (разведка не содержала сырого дампа страниц). Это касается и `organizations-block` (таблица ИНН/КПП/ОГРН на `common-info` — рабочее предположение, не подтверждено разведкой) и `supplier-results.html`. При первом реальном прогоне против живого ЕИС — сверить DOM-селекторы всех парсеров вкладок с фактической вёрсткой (риск описан в `docs/03_Dev/crawling-pipeline.md` §9, smoke-контроль — операторская задача).
