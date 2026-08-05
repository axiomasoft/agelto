# research/05 — Механизм отслеживания изменений, состав двух профилей, план боевого прогона

Слой 3 (design). Deliverable item'а **P4.2**. Документ сводит фактуру живой разведки
`findings/P4-eis-recon.md` (снята 2026-08-04) с фактическим состоянием кроулинг-контура и
замораживает вход для P4.3 (контракт фильтра), P4.4 (форма), P4.5 (сиды), P4.6 (правки механизма)
и P4.7 (боевой прогон).

**Якорь дерева.** Все числа и цитаты кода сняты на `82cc2ff` — item P4.2 не меняет ни одного
файла приложения (его `Files` — только этот документ), поэтому дерево item-коммита в части
`app/**`/`config/**` совпадает с `82cc2ff`. Команда приведена рядом с каждым числом, снятым
не чтением файла.

Стоит на: `findings/P4-eis-recon.md` (слой 1, фактура ЕИС 2026-08-04) ·
`artifacts/eis-filter-params.json` · `artifacts/eis-registry-settings.json` ·
`findings/repo-recon-2026-07-31.md` §10 · `findings/rag-2026-07-31.md` §1, §4, §7 ·
`brief/00-brief.md` §4 · вводная владельца 2026-08-05 (`handoff.md` после P4.1) ·
решения D3, D12, D14, D32–D39, D63, D71, D72.

**Что этот документ НЕ делает.** Не правит код, конфиги, сиды и доки (это P4.3–P4.8); не выбирает
топологию БД (OQ-1 закрыт D71 — одна БД); не предлагает перехода на XML/SOAP (D14 — по фактуре
P4.1 §7 оснований нет, вопрос остаётся владельцу); не переписывает работающий контур (D33).

---

## 1. Gap-таблица: целевая схема RAG §4 → что есть в проекте → вердикт

Целевая форма механизма (`findings/rag-2026-07-31.md` §4, RAG:✅ 2026-07-31) разобрана на
проверяемые пункты. Вердикт ставится ДО раздела с правками (анти-подгонка ТЗ P4.2).

| # | Пункт целевой схемы | Что есть в проекте (файл) | Вердикт |
|:--|:--|:--|:--|
| Ц-1 | Полноту даёт устойчивый идентификатор, а не пагинация | `prc_procurements` unique `(registry_id, registry_number)` (`database/migrations/2026_07_04_100011_create_prc_procurements_table.php`); поиск — `ProcurementIngestProcessor:37` → `ProcurementReadRepository::findProcurement()` | **покрыто** |
| Ц-2 | Хэш значимых полей карточки; совпал — не трогать | `ProcurementIngestProcessor:35` (`sha256` нормализованного `ProcurementSnapshotData::toSnapshotArray()`) → `prc_revisions.snapshot_hash`; совпадение с последней ревизией → только `touchLastSeen()` (`:129-133`) | **покрыто** |
| Ц-3 | Переобход узкими окнами по дате обновления | Механика есть целиком: `WindowFilter(dateField: 'update')` → `ArchiveWindowScanner::scan()` → `EisCsvUrlBuilder::archiveOverrides()` (`:70-71`). Но ЕДИНСТВЕННЫЙ вызов с `update` — `BacklogRescanProcessor:121-125`, то есть только как реакция на `cap_hit` | **дыра Д-2** (нет планового прохода; механика переиспользуется как есть) |
| Ц-4 | Окна ПЕРЕКРЫВАЮЩИЕСЯ (запас 1–3 суток) | Нет ни одного места, где окно расширяется назад: `FreshRunProcessor::handleCapHit()` (`:114-121`) кладёт `possible_skip_window.from` = `finished_at` последнего completed-прогона, `BacklogRescanProcessor::drainWindow()` (`:111-129`) идёт ровно от `from` до `to` | **дыра Д-1** |
| Ц-5 | Дедупликация по ID/версии | Против БД — покрыто (Ц-1 + Ц-2). ВНУТРИ блока дедупа нет: `CsvBlockIngestor::ingest()` (`:40-79`) прогоняет каждую строку блока отдельно; при 510 строках на 500 уникальных номеров (223-ФЗ, `findings/P4-eis-recon.md` §1 факт 5) вторая строка того же номера даёт другой снапшот → лишняя ревизия и событие `revision_published` на КАЖДОМ прогоне | **дыра Д-5** (узкая: 44-ФЗ 500 строк = 500 уникальных, проявляется только на многолотовых 223-ФЗ) |
| Ц-6 | Режим «nightly full-scan + delta-scan за сутки с перекрытием» | Fresh каждые 30 мин (`routes/console.php:21-24`) — это не full-scan, а обход до первого известного блока (`FreshRunProcessor:48-59`); delta-scan отсутствует (Ц-3); full-scan реестра **структурно невозможен** — 27 509 967 записей в 44-ФЗ при потолке 5 000 на выборку (`findings/P4-eis-recon.md` §5.1) | **дыра Д-2** в части delta-scan; **не применимо** в части nightly full-scan — его роль обязан выполнять суточный delta-scan по окну обновления с ценовым дроблением |
| Ц-7 | Очередь повторных попыток | `crawler:rescan-backlog` каждые 15 мин (`routes/console.php:32-34`); guard `stats->cap_hit` + `whereNull('stats->backlog_rescanned_at')` (`BacklogRescanProcessor:50-56`); упавший рескан НЕ помечает флаг и переедет в следующий sweep (`:97-103`) | **покрыто** |
| Ц-8 | Журнал причин расхождений | `prc_events`: `discovered`, `revision_published`, `status_changed`, `price_changed`, `dates_changed` — эмитятся диффом снапшотов (`ProcurementIngestProcessor::storeDiffEvents()` `:177-215`) | **покрыто** (диффом накрыты 5 полей: `status`, `initial_price`, три даты; остальные поля ревизии видны только сравнением снапшотов) |
| Ц-9 | Подтверждённые параметры окна обновления | `EisCsvUrlBuilder:70-71` строит `updateDateFrom`/`updateDateTo`; докблок `config/crawler.php:281` до сих пор помечает их как «экстраполяция, D24» | **дыра Д-3 ЗАКРЫТА разведкой**: `findings/P4-eis-recon.md` §2 — параметр реален, окно чистое (171 запись, у всех «Дата обновления» = `01.08.2026`), границы включительные, формат `dd.MM.yyyy`. Кода не требует, требует снятия метки в докблоке |
| Ц-10 | Метрика отставания как наблюдаемость механизма (`docs/specs/v2/02-crawling.md:80` называет её триггером) | Носителя нет. `prc_daily_stats` (`database/migrations/2026_07_04_100045_…`) не несёт ни лага, ни счётчика ревизий — и **у таблицы вообще нет писателя**: `grep -rln "DailyStat\|daily_stats" --include=*.php app database tests` на `82cc2ff` даёт ровно три файла — модель, фабрика, миграция | **дыра Д-4** (глубже, чем фиксировал `findings/repo-recon-2026-07-31.md` §10.3: там сказано «считает `listing_found`…», фактически не считает никто) |

**Итог по объёму (анти-подгонка).** Из четырёх дыр, названных D33, работой P4.6 остаются три
(Д-1 перекрытие, Д-2 плановый delta-scan, Д-4 метрика); четвёртая (Д-3, подтверждение параметров)
закрыта фактурой P4.1 и сводится к снятию метки в докблоке. Добавилась одна узкая дыра
Д-5 (дедуп внутри блока), обязательная только для ветки 223-ФЗ. Работа P4.6 по механизму
**не выросла** — выросла работа по РАСКЛАДКЕ КОЛОНОК (раздел 3, группа Б), и это отдельная,
не предусмотренная ТЗ фазы находка.

---

## 2. Перекрытие окон и календарь прогонов — с числами

### 2.1. Величина перекрытия: **2 суток**, стартовое значение

Наблюдённой задержки индексации ЕИС нет: P4.1 §9 прямо относит дневной темп сдвига выдачи к
«не установлено», задержка индексации не измерялась вовсе. Поэтому величина — **стартовая,
калибруемая на прогоне** (Implementation Rules P4.2 п. 2), но не «с запасом», а с тремя
основаниями:

1. **Гранулярность источника — сутки, дробнее не бывает.** Формат всех дат — `dd.MM.yyyy`
   (`findings/P4-eis-recon.md` §2), среди 45 именованных полей формы расширенного поиска нет ни
   одного временнóго (вводная владельца 2026-08-05 п. 4). Перекрытие измеряется только целыми
   сутками; минимальный ненулевой запас = 1 сутки.
2. **Одни сутки съедаются часовыми поясами.** Журнал карточки отдаёт время в зоне заказчика
   (`05.08.2026 00:03 (МСК+4)`, `findings/P4-eis-recon.md` §4), а планировщик контейнера работает
   в UTC (`docker-compose.yml`, переменных TZ у сервиса `scheduler` нет). Разброс зон заказчиков
   по стране — до МСК+9, то есть до 12 часов относительно UTC. Запас в 1 сутки при таком разбросе
   не гарантирован; 2 суток дают ровно одни полные гарантированные сутки слака.
3. **Цена перекрытия ограничена и известна.** Повторный проход по окну идемпотентен по
   `snapshot_hash` (Ц-2) — БД не растёт, событий не создаётся, платится только HTTP. Сутки
   обновлений 44-ФЗ = 16 166 записей (`03.08.2026`, `findings/P4-eis-recon.md` §5.1), то есть при
   потолке 5 000 — минимум 3 ценовых интервала × до 10 блоков = ≈33 блока по 500 строк; при
   троттле 1–4 с (`config/crawler.php:47-50`) это ≈1–2,5 минуты чистого HTTP на каждые лишние
   сутки перекрытия.

Носитель — `config('crawler.tracking.window_overlap_days')`, не константа в коде (правится
калибровкой P4.7).

### 2.2. Календарь

| Прогон | Что делает | Периодичность | Носитель | Состояние на `82cc2ff` |
|:--|:--|:--|:--|:--|
| `crawler:run-fresh <id>` | обход листинга по убыванию даты обновления до первого известного блока | cron профиля, дефолт `*/30 * * * *` | `routes/console.php:21-24` (только `is_active=true` профили) | есть; профиль сидируется ВЫКЛЮЧЕННЫМ (D37), включение — решение владельца после P4.7 |
| `crawler:rescan-backlog` | sweep `cap_hit` → archive-рескан окна простоя по `updateDate` | `everyFifteenMinutes` | `routes/console.php:32-34` | есть; окно берётся встык (Д-1) |
| **`crawler:run-delta` (новый)** | плановый delta-scan окна `[сегодня − overlap; сегодня]` по `updateDate` на archive-профиле реестра | суточно, `0 23 * * *` UTC (= 02:00 МСК) | `routes/console.php` под флагом `crawler.tracking.delta_scan_enabled` | **нет** (Д-2) |
| `crawler:run-archive <id> --from --to` | перекачка истории по дням публикации | только явной командой, в расписание не попадает по построению | `ArchiveRunProcessor` | есть |
| `crawler:fetch-details` | детальная стадия | `everyFiveMinutes` | `routes/console.php:27-29` | есть; на сегодняшнем дереве падает на каждой карточке (раздел 3, группа Б) |

**Почему 02:00 МСК.** Единственное измерение темпа сдвига выдачи снято в ночном окне: голова
блока держалась ≈17 минут и сменилась между 20:57:09Z и 21:00:01Z (`findings/P4-eis-recon.md` §1
факт 8). Дневной темп не измерялся — ночной прогон идёт по наименее подвижной выдаче из
измеренных. Величина проверяемая (K-7 раздела 8), не догма.

**Гейт включения delta-scan (обязателен).** Флаг `crawler.tracking.delta_scan_enabled` по
умолчанию `false`, включается владельцем ПОСЛЕ P4.7 — по той же причине, по которой fresh-профиль
сидируется выключенным (D37): P4.6 закрывается раньше боевого прогона, а активный archive-профиль
реестра появляется уже в P4.5. Без флага регистрация delta-scan в планировщике означала бы
непрерывный боевой трафик к ЕИС посреди незаконченной фазы.

**Дробление fresh-потока (бриф §4, D38) — не вводится.** Фактура P4.1 не показывает приближения
штатного потока к cap: сутки обновлений = 16 166 записей (`findings/P4-eis-recon.md` §5.1), то
есть ≈337 записей за 30-минутный интервал расписания при потолке 5 000 — запас ×14. Требование
закрывается фактом, страховка от простоя уже есть (cap-детект → `possible_skip_window` →
backlog-рескан). Величина проверяется на прогоне (K-10).

---

## 3. Поимённый перечень правок для P4.6

Две группы. **Группа А** — механизм отслеживания (предмет ТЗ P4.6). **Группа Б** — раскладка
колонок и резолв карточки: это блокеры, без которых боевой прогон P4.7 заносит закупки без цены
и не снимает НИ ОДНОЙ карточки. Группа Б не была видна при детализации фазы и в `Files` P4.6 не
попала — см. §3.3.

### 3.1. Группа А — механизм отслеживания

| # | Файл | Что меняется | Как проверяется |
|:--|:--|:--|:--|
| П-1 | `config/crawler.php` | новый блок `tracking`: `window_overlap_days` = 2 (§2.1), `delta_scan_enabled` = `false` (§2.2), `delta_scan_cron` = `'0 23 * * *'`; из докблока блока `archive` снимается метка «`updateDateFrom/To` — экстраполяция, D24» со ссылкой на `findings/P4-eis-recon.md` §2 | `grep -n "экстраполяция" config/crawler.php` — пусто; значения читаются тестами П-2/П-3 |
| П-2 | `app/Services/Procurement/Modes/BacklogRescanProcessor.php` (`drainWindow`, `:111-129`) | старт окна сдвигается назад на `window_overlap_days`; `Log` называет ФАКТИЧЕСКИЕ границы окна (иначе на прогоне не отличить «окно расширено» от «окно то же») | `--filter=BacklogRescanProcessor` — окно `[from − 2 сут; to]`; `--filter=ChangeTrackingWindow` — регрессионный: обновление, попавшее в стык окон, не теряется; **красная проба до правки обязательна** |
| П-3 | новый `app/Services/Procurement/Modes/DeltaScanProcessor.php` + команда `app/Console/Commands/Procurement/RunDeltaScan.php` (`crawler:run-delta {crawler?}`) + регистрация в `routes/console.php` | дренаж окна `[сегодня − overlap; сегодня]` по `dateField: 'update'` ЧЕРЕЗ существующий `ArchiveWindowScanner` (новой механики нет — переиспользование, D33); выбор профиля — как в `BacklogRescanProcessor:62-67` (активный archive-профиль реестра); регистрация под флагом `delta_scan_enabled` + schema-guard, как у соседей | `--filter=DeltaScan`; `php artisan schedule:list` при `delta_scan_enabled=true` показывает `crawler:run-delta` с cron `0 23 * * *`, при `false` — не показывает |
| П-4 | `app/Services/Procurement/CrawlerRunManager.php` + `app/Services/Procurement/Modes/FreshRunProcessor.php` | метрика отставания в `prc_crawler_runs.stats` (jsonb, писатель уже есть — миграция НЕ нужна): `scan_gap_seconds` = пауза от `finished_at` предыдущего completed-прогона профиля до старта текущего, и `oldest_update_seen` = минимальная «Дата обновления» в снятых блоках прогона | `--filter=FreshRunProcessor` — оба ключа в `stats` после прогона; на прогоне P4.7 значения попадают в `findings/P4-load-baseline.md` |
| П-5 | `app/Services/Procurement/CsvBlockIngestor.php` (`ingest`, `:40-57`) | дедуп строк блока по `registryNumber` ДО инжеста (первая строка выигрывает — порядок сервера), счётчик схлопнутых строк в `FetchResult`/`prc_fetches`; стоп-условие `rowsTotal < blockSize` в `ArchiveWindowScanner:66` **не трогается** — оно про полноту блока сервера, а не про уникальность | `--filter=CsvBlockIngestor` — блок из 510 строк с 500 уникальными номерами даёт 500 инжестов и не создаёт вторых ревизий |
| П-9 | `app/Services/Procurement/ArchiveWindowScanner.php` (`:39`) | стартовый ценовой стек берётся из фильтра профиля (`priceFrom`/`priceTo` контракта раздела 4), `config('crawler.archive.price_ceiling')` остаётся дефолтом при незаполненном поле — прямое требование владельца 2026-08-05 п. 1: «потолок обязан быть полем профиля и правиться из Filament, а не жить константой конфига» | `--filter=ArchiveWindowScanner` — профиль с `priceTo` = 200 000 000 даёт стартовый интервал `0:200000000`, профиль без него — `0:1000000000` |

### 3.2. Группа Б — раскладка колонок и резолв карточки (блокеры прогона)

Все пять — расхождения «наблюдённое ↔ код». Р-1 назван разведкой; Р-12…Р-15 выведены в этом
item'е сопоставлением `artifacts/eis-filter-params.json` с константами кода и разведкой не
цитировались отдельно — уровень уверенности проставлен у каждого.

| # | Файл | Что меняется | Уверенность | Как проверяется |
|:--|:--|:--|:--|:--|
| П-6 (Р-1, Р-13, Р-14, Р-15) | `app/Services/Procurement/Eis/EisCsvRowMapper.php` (`:20-44`) | четыре константы заголовков приводятся к фактическим: `PROCUREMENT_METHOD_COLUMN` → `'Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'`; `INITIAL_PRICE_COLUMN` `'НМЦК'` → `'Начальная (максимальная) цена контракта'`; `OKPD2_COLUMN` `'ОКПД2'` → `'Классификация по ОКПД2'`; `CUSTOMER_NAME_COLUMN` `'Наименование заказчика'` → `'Наименование Заказчика'` (прописная «З») | Р-1 и Р-13 — **подтверждено** (`findings/P4-eis-recon.md` §8; `artifacts/eis-filter-params.json` → `column_flags`, поле `заголовок_в_csv`); Р-14 и Р-15 — **вероятно** (в артефакте только подпись дерева модалки, отдельного `заголовок_в_csv` нет) | одна CSV-проба блока с полным набором флагов раздела 5 фиксирует фактическую строку заголовка; тест `--filter=EisCsvRowMapper` на фикстуре с РЕАЛЬНЫМИ заголовками |
| П-7 (Р-2) | `app/Enums/Procurement/EisProcedureType.php` (`fromRawLabel`, `:27-41`) | покрытие 3 из 9 наблюдённых формулировок → 9 из 9: три варианта «…п. 8 ч. 1 ст. 33» отображаются в те же кейсы, что базовые формулировки (основание — у них ТОТ ЖЕ сегмент URL `ea20`/`zk20`/`ok20`, то есть сам источник считает их той же процедурой); три оставшихся получают новые кейсы, потому что у них отдельный сегмент: `ezt20` (ч. 12 ст. 93), `ea615` (капремонт МКД), `po615` (предварительный отбор) | подтверждено (`findings/P4-eis-recon.md` §8 Р-2, `artifacts/eis-registry-settings.json`) | `--filter=EisProcedureType` — все девять строк артефакта резолвятся в кейс |
| П-8 (Р-12, **новое**) | `app/Services/Procurement/Eis/CsvSourceMethod.php` (`:139`) | тип карточки резолвится по `procedure_type_raw`, а не по `procedure_type`: ключи `procedure_url_types` в артефакте — СЫРЫЕ строки CSV (`artifacts/eis-registry-settings.json` `_meta`), а `EisCardUrlBuilder::resolveProcedureUrlType()` (`:28`) ищет по значению переданной строки. На сегодняшнем дереве в него уезжает нормализованное значение enum (`electronic_auction`) — карта по сырым ключам не совпадёт НИКОГДА, даже после П-6 и П-7 | подтверждено чтением обоих носителей | `--filter=EisCardUrlBuilder` / `--filter=CardCollectDetails` — карточка резолвится из сида P4.5 без правки самого сида |
| П-10 (Р-9) | `app/Services/Procurement/Eis/CsvSourceMethod.php` (`collectDetails`, `:144-157`) | отсутствие шаблона вкладки в `card_url_templates` — не исключение, а «вкладки нет»: `$html[$tab] = null`. Сегодня `EisCardUrlBuilder::buildUrl()` (`:46-52`) бросает `CardUrlTemplateMissingException`, и она убивает ВЕСЬ детальный разбор закупки (`DetailAnalysisProcessor:93` ловит на уровне закупки) — уже снятые вкладки `common-info`/`documents`/`event-journal` выбрасываются, документы не индексируются, попытка повторяется 5 раз. Затрагивает `ea615`/`po615`, у которых `supplier-results` физически `404` | подтверждено (`findings/P4-eis-recon.md` §8 Р-9; `artifacts/eis-registry-settings.json` `_observed`) | `--filter=CardCollectDetails` — тип без вкладки `supplier-results` даёт снятые остальные три и `detail_fetched_at` |

**Почему это блокеры, а не улучшения.** `EisCsvRowMapper::map()` возвращает `null` (строка
отбрасывается целиком), если не разобрана «Дата размещения» ИЛИ «Дата обновления» (`:59-61`) —
эти два флага обязательны в профиле (раздел 5). Р-1 без П-6/П-8 означает `procedure_type_raw` =
`null` у каждой строки → `CardUrlTemplateMissingException` на каждой карточке → `detail_failed`
на всём корпусе прогона при формально «успешном» листинге. Р-13 означает `initial_price` = `null`
у всех закупок: событие `price_changed` не сработает никогда, а НМЦК в БД не появится вовсе.

Косвенное подтверждение того, что раскладка колонок никогда не проверялась против реального
экспорта: единственная фикстура `tests/Fixtures/Crawler/eis-block-win1251.csv` несёт заголовок
`Реестровый номер закупки;Предмет закупки;Цена;Регион` — из четырёх колонок с константами
маппера совпадает одна (`head -1 tests/Fixtures/Crawler/eis-block-win1251.csv | iconv -f cp1251
-t utf8`, на `82cc2ff`).

### 3.3. Что обязано измениться в ТЗ P4.6 до его старта

`Files` item'а P4.6 (`phases/P4.md`) перечисляет `BacklogRescanProcessor`, `FreshRunProcessor`,
`ArchiveWindowScanner`, `config/crawler.php`, `routes/console.php`, `DailyStat`, миграции и два
теста. Правки П-3 (новые процессор и команда), П-4 (`CrawlerRunManager`), П-5
(`CsvBlockIngestor`) и вся группа Б (`EisCsvRowMapper`, `EisProcedureType`, `CsvSourceMethod`,
их тесты) в этот перечень не входят — исполнитель P4.6 обязан считать дифф по ним материальным
отклонением. Правка чужого ТЗ за пределами `Files` P4.2, поэтому здесь она только НАЗЫВАЕТСЯ:

- дополнить `Files` P4.6 путями `app/Services/Procurement/Modes/DeltaScanProcessor.php`,
  `app/Console/Commands/Procurement/RunDeltaScan.php`, `app/Services/Procurement/CrawlerRunManager.php`,
  `app/Services/Procurement/CsvBlockIngestor.php`,
  `app/Services/Procurement/Eis/{EisCsvRowMapper,CsvSourceMethod}.php`,
  `app/Enums/Procurement/EisProcedureType.php`,
  `tests/Unit/Crawler/{EisCsvRowMapperTest,EisProcedureTypeTest}.php`,
  `tests/Feature/Crawler/{CsvBlockIngestorTest,CardCollectDetailsTest,DeltaScanTest}.php`;
- `DailyStat`/миграция из `Files` P4.6 не нужны: носитель метрики — существующий
  `prc_crawler_runs.stats` (П-4), новой колонки нет;
- альтернатива, которую вправе выбрать `plan-design`: вынести группу Б отдельным item'ом
  («раскладка колонок и резолв карточки») ПЕРЕД P4.6 — предмет и риск у групп разные, а группа Б
  блокирует P4.7 сильнее.

Решение принимает `plan-design`/владелец: это правка ТЗ, а не работа исполнителя (`plan-protocol`
§10).

### 3.4. Что НЕ чинится в этой фазе (адрес — за её пределами)

| # | Дефект | Почему не здесь |
|:--|:--|:--|
| Р-8 | `archive.max_blocks_per_window` = 100 недостижим (при потолке 5 000 в интервале максимум 10 блоков), а его ветка при срабатывании делает `array_pop($stack)` — выбрасывает ценовой интервал БЕЗ дробления (`ArchiveWindowScanner:47-58`) | Константа и ветка связаны: снизить значение до достижимого (11–12, штатный детект повтора `dedupSignature` срабатывает на 11-м запросе) БЕЗ починки ветки — значит включить тихую потерю интервала. Починка ветки = изменение семантики ценового дробления, прямо исключённое из Scope и P4.2, и P4.6 (D33). При значении 100 ветка остаётся недостижимой и данные не теряются → оставляем как есть, дефект — в `open-questions.md` |
| Р-4 | `EisCsvUrlBuilder::currentPriceInterval()` (`:88`) при пустом `priceStack` даёт `'0:0'`, а `priceFromGeneral=0&priceToGeneral=0` обнуляет выдачу (`findings/P4-eis-recon.md` §5.2) | Латентно: единственные вызовы с `cursor->day !== null` — `ArchiveWindowScanner` (стек всегда непуст) и delta-scan (тот же сканер). Ловушка выражена дефолтом `?? '0:0'`, а не проверкой — follow-up |
| Р-11 | Ветка карточки 223-ФЗ требует `noticeGuid`, которого в CSV-листинге нет | Способ добора GUID (GET `/223/purchase/public/purchase/info/common-info.html?regNumber=<N>` и извлечение из разметки) разведкой НЕ проверялся — проектировать по гипотезе запрещено. Если владелец выбирает 223-ФЗ, прогон ограничивается ЛИСТИНГОМ (раздел 6), детальная стадия 223-ФЗ — отдельная разведка |
| Р-6 | Докблок `docs/specs/v2/02-crawling.md:60` утверждает cap «около 10 000» при фактических 5 000 | Правка доков — P4.8 |
| Р-10 | Сертификат ЕИС не проверяется дефолтным набором корней; у боевого Guzzle политики нет | Владелец 2026-08-04: ставить сертификаты, только если начнутся проблемы. Стоп-сигнал прогона (раздел 6, С-5), а не предварительная правка |

---

## 4. Контракт типизированного фильтра (вход P4.3)

**Форма.** Data-класс (`spatie/laravel-data`) `EisSearchFilter`, хранение — прежний `jsonb`
`prc_crawlers.filter` (D34, схема не меняется). Резолв класса — по коду реестра через
config-карту рядом с `crawler.source_methods`:

```php
'filters' => [
    'fz44' => EisSearchFilter::class,
    // 'fz223' => …  — заводится, когда владелец включит ветку 223-ФЗ (раздел 6)
],
```

**Что типизируется.** Только параметры, проверенные ДЕЙСТВИЕМ (`artifacts/eis-filter-params.json`
→ `статус: подтверждено`). Параметры со статусом «не установлено» в контракт **не входят**: их
фильтрующее действие не наблюдалось, а неизвестный ключ ЕИС игнорирует молча
(`findings/P4-eis-recon.md` §6) — типизировать непроверенный параметр значит закрепить в коде
предположение.

| Поле DTO | Параметр ЕИС | Тип | Обяз. | Примечание |
|:--|:--|:--|:--|:--|
| `stageApplications` | `af` | `bool` | нет | «Подача заявок»; `false` → ключ не эмитится |
| `stageCommission` | `ca` | `bool` | нет | «Работа комиссии» |
| `stageCompleted` | `pc` | `bool` | нет | «Закупка завершена» |
| `stageCancelled` | `pa` | `bool` | нет | «Закупка отменена» |
| `priceFrom` | `priceFromGeneral` | `?int` | нет | границы включительные; потребитель — стартовый ценовой стек (П-9) |
| `priceTo` | `priceToGeneral` | `?int` | нет | `null` → дефолт `config('crawler.archive.price_ceiling')`. **Значение 0 запрещено валидацией** — обнуляет выдачу (`findings/P4-eis-recon.md` §5.2) |
| `publishedFrom` / `publishedTo` | `publishDateFrom` / `publishDateTo` | `?CarbonImmutable` | нет | **только для профилей без окна**: в archive/delta-прогоне окно задаёт сканер (`EisCsvUrlBuilder::archiveOverrides()` перекрывает эти ключи) — DTO их не дублирует и не переопределяет |
| `searchString` | `searchString` | `?string` | нет | принимает и реестровый номер |
| `morphology` | `morphology` | `bool` | нет | имеет смысл только вместе с `searchString` |

**Чего в DTO нет и почему.** `fz44`/`fz223` — добавляет билдер из `registry->code`
(`EisCsvUrlBuilder:42`); `from`/`to`, `sortBy`, `sortDirection`, `registryNumberCsv` — принудительные
значения билдера (`:40-47`), фильтр их не дублирует (Implementation Rules P4.3 п. 4);
`updateDateFrom/To` — ставит сканер по `dateField`; флаги колонок — другой носитель (`options.columns`);
`recordsPerPage`/`pageNumber` — на CSV-выгрузку не влияют (`findings/P4-eis-recon.md` §5);
`okpd2Ids` — статус «не установлено», нужен внутренний id справочника (§6).

**Неизвестный ключ.** Исключение с именем ключа и списком допустимых — не тихое игнорирование.
Основание фактурой, а не вкусом: ЕИС на неизвестный параметр не отвечает ошибкой и не сужает
выдачу (`zzzNoSuchParam` → выдача идентична нефильтрованной), то есть опечатка РАСШИРЯЕТ выборку
и ничем себя не проявляет.

**Резолв по реестру.** `CrawlerFilterFactory::for(Registry $registry): string` читает
`config('crawler.filters')`; реестра нет в карте → исключение с кодом реестра.
`match`-лестница и `if ($registry->code === 'fz44')` в билдере запрещены — прецедент
config-driven резолва в этом же домене уже есть (`SourceMethodFactory` +
`config('crawler.source_methods')`).

---

## 5. Два профиля обхода — буквальные значения (вход P4.5 и P4.4)

Каждый ключ ниже присутствует в `artifacts/eis-filter-params.json` (проверка Validation P4.2).

### 5.1. Общая часть обоих профилей

| Поле `prc_crawlers` | Значение | Основание |
|:--|:--|:--|
| `registry_id` | реестр `fz44` (сид того же item'а) | D39 |
| `source_method` | `csv` | `config('crawler.source_methods')` — единственная реализация |
| `fallback_method` | `null` | `html`/`api` — задел контракта, реализаций нет |
| `use_proxy` | `true` | D3: сетевые прогоны против ЕИС — только через прокси-скоуп |
| `proxy_country` | `'RU'` | прямой доступ с хоста не работает (`findings/repo-recon-2026-07-31.md` §10.1); политика скоупа `crawl_listing` и так требует `RU` (`config/crawler.php:125-128`) — поле профиля складывается с ней, не заменяет |

**`options.columns` — одинаков у обоих профилей.** Ровно те флаги, чьи заголовки читает
`EisCsvRowMapper` (после П-6); лишние колонки удлиняют ответ, недостающие тихо теряют данные.

| Флаг | Колонка CSV | Зачем |
|:--|:--|:--|
| `registryNumberCsv` | Реестровый номер закупки | ключ идентичности + `dedup_signature`; билдер добавляет принудительно, в профиле дублируется для читаемости формы |
| `publishDateCsv` | Дата размещения | **обязателен**: `map()` вернёт `null` и отбросит строку без него (`EisCsvRowMapper:59-61`) |
| `lastDateChangeCsv` | Дата обновления | **обязателен**, там же; плюс вход механизма отслеживания |
| `stepOrderPlacementCsv` | Этап закупки | `source_stage` → `StatusMappingService` |
| `methodOrderPurchaseCsv` | Способ определения поставщика… | `procedure_type_raw` → URL карточки (П-8) |
| `nameOrderCsv` | Наименование закупки | `title` |
| `purchaseNumbersCsv` | Идентификационный код закупки (ИКЗ) | `ikz` |
| `maxContractPriceCsv` | Начальная (максимальная) цена контракта | `initial_price` (Р-13) |
| `currencyCodeCsv` | Валюта | `currency` |
| `scopeOkpd2Csv` | Классификация по ОКПД2 | `okpd2_code`/`okpd2_codes` (Р-14) |
| `startDateRequestCsv` | Начало подачи заявок | `bidding_starts_at` |
| `endDateRequestCsv` | Окончание подачи заявок | `bidding_ends_at` |
| `customerNameCsv` | Наименование Заказчика | `attributes.customer_name` (Р-15) |

Двенадцать флагов из двадцати пяти; остальные тринадцать (`placementCsv`, `numberLotCsv`,
`nameLotCsv`, `maxPriceContractCurrencyCsv`, `currencyCodeContractCurrencyCsv`, `scopeOkdpCsv`,
`scopeOkpdCsv`, `scopeKtruCsv`, `ea615ItemCsv`, `organizationOrderPlacementCsv`, `ea615DateCsv`,
`featureOrderPlacementCsv` и сам `registryNumberCsv` в его принудительной роли) читателя в
`EisCsvRowMapper` не имеют — включать их нечем и незачем.

### 5.2. Профиль 1 — архивный (обучающий корпус)

| Поле | Значение | Основание |
|:--|:--|:--|
| `name` | `ЕИС 44-ФЗ — архив 2025 (завершённые)` | — |
| `mode` | `archive` | |
| `is_active` | `true` | в расписание не попадает по построению: `routes/console.php:21` регистрирует только `mode=fresh` |
| `schedule` | `null` | запуск явной командой `crawler:run-archive` |
| `filter` | `stageCompleted: true` (`pc=on`) | бриф §4: «парсинг завершённых закупок за 2025» |
| `filter` | `priceFrom`/`priceTo` — не заполнены | вводная владельца 2026-08-05 п. 1: сегодняшняя потеря 8 714 закупок выше 1 млрд ₽ (Р-3) принимается как ТЕКУЩАЯ настройка; поле существует и правится в Filament (П-9 + P4.4) |
| окно дат | задаётся аргументами команды `--from=2025-…` `--to=2025-…`, не фильтром | `ArchiveRunProcessor::run()` идёт по дням; `EisCsvUrlBuilder::archiveOverrides()` ставит `publishDateFrom/To` = день |
| сортировка | `sortDirection=true` (по возрастанию) — ставит сканер | бриф §4 «с датой по возрастанию»; `EisCsvUrlBuilder:78` |

### 5.3. Профиль 2 — fresh (продакшен-слежение)

| Поле | Значение | Основание |
|:--|:--|:--|
| `name` | `ЕИС 44-ФЗ — свежий поток` | — |
| `mode` | `fresh` | |
| `is_active` | **`false`** | D37: активный fresh-профиль попадает в планировщик на КАЖДОМ artisan-вызове; включение — решение владельца после P4.7 |
| `schedule` | `*/30 * * * *` | спека `02-crawling.md` §4.1; запас против cap ×14 (§2.2) |
| `filter` | пуст (ни одного этапа) | fresh обязан видеть ЛЮБОЕ обновление: фильтр по этапу отсечёт переходы, ради которых профиль существует |
| `filter` | `priceFrom`/`priceTo` — не заполнены | ценовое сужение «лакомых смет» — операторская настройка в Filament, а не сид (вводная владельца п. 1) |
| окно дат | нет (курсор без `day`) | `FreshRunProcessor` листает от `from=1` по убыванию даты обновления до первого известного блока (`:48-59`); `archiveOverrides` не включается |

### 5.4. Ветка 223-ФЗ — что решено и что не решено

Владелец 2026-08-05 п. 2: в P4.2 рассматриваются оба реестра, какой запускать на проде — решение
после синтеза. Состояние:

- **листинг 223-ФЗ работает как есть** — тот же endpoint, флаг `fz223=on`, тот же набор колонок
  (`findings/P4-eis-recon.md` §1 факт 5); нужен сид реестра `fz223` и профиль по образцу §5.2;
- **обязателен П-5** (дедуп внутри блока): 510 строк на 500 уникальных номеров;
- **словарь этапов другой**: `Закупка отменена` вместо `Определение поставщика отменено` и
  служебное `Сведения включены в описание каждого лота` (§3.2 разведки) — `StatusMappingSeeder`
  для `fz223` заводится отдельно, неизвестная пара `(registry, stage)` и так даёт `unknown` +
  событие (`ProcurementIngestProcessor::storeUnknownStatusEvent()`);
- **`procedure_type` не нормализуется** — способ 223-ФЗ свободный текст (`EisCsvRowMapper:99-101`
  резолвит enum только для `fz44`), в БД остаётся `procedure_type_raw`;
- **детальная стадия 223-ФЗ невозможна на снятой фактуре** (Р-11, §3.4).

Вердикт для P4.7: ветка 223-ФЗ включается **только как листинг** и только по отдельному решению
владельца; смоук-прогон фазы идёт по 44-ФЗ.

---

## 6. План боевого прогона P4.7 и стоп-сигналы

### 6.1. Предусловия (проверяются ДО первого запроса)

1. P1 и P6 терминальны (D36/D12) — на `82cc2ff` обе `🟠 Done with deviations`.
2. Сиды P4.5 применены; `StatusMappingSeeder` не пишет `warn`.
3. P4.6 закрыт **вместе с группой Б** (§3.2) — иначе листинг занесёт закупки без НМЦК и без
   единой снятой карточки.
4. Канал проверен ДО ЕИС: egress-проба через тот же прокси показывает RU-адрес (приём P4.1 —
   `ipinfo.io/json` до первого запроса к источнику).

### 6.2. Выбор окна прогона

Объём — смоук 2–3 дня 2025 года (D35), расширение — отдельное решение владельца.

Дни выбираются **по снятым числам, а не заранее**: перед стартом для каждого дня-кандидата
снимается `totalCount` одним GET (`results.html?fz44=on&pc=on&publishDateFrom=<d>&publishDateTo=<d>`,
регулярка `/downloadCsv\('[^']*',\s*'(\d+)'\)/` — `artifacts/eis-filter-params.json` →
`total_count_source`). Требование покрытия: **один день ниже 5 000** (ценовое дробление не
включается — проверяется базовый путь) и **один день выше 5 000** (дробление включается —
проверяется рекурсия и стоп по `min_price_interval`).

Опорные числа разведки: день публикации `15.01.2025` = 1 270 без фильтра этапа; год 2025 целиком =
2 562 682, то есть в среднем ≈7 021 в день — типичный день 2025 выше потолка, а январь заметно
ниже (`findings/P4-eis-recon.md` §5.1). Фильтр `pc=on` числа только уменьшает, поэтому оба класса
дней существуют, но конкретные даты фиксируются пробой.

Команда прогона:

```
docker exec agelto_app php artisan crawler:run-archive <crawler_id> --from=<YYYY-MM-DD> --to=<YYYY-MM-DD>
```

Детальная стадия запускается **отдельно и вручную** (`crawler:fetch-details`), не через
планировщик: трафик к источнику должен быть под контролем оператора, а не тикать каждые 5 минут
параллельно листингу.

### 6.3. Стоп-сигналы (сработал — прогон останавливается, решение о продолжении у владельца)

| # | Сигнал | Как виден | Почему стоп |
|:--|:--|:--|:--|
| С-1 | Ушли мимо прокси | строка лога `профиль отключил прокси, запрос уходит напрямую` (`CsvSourceMethod:114`) ИЛИ `select count(*) from prc_fetches where proxy_id is null` > 0 | прямое нарушение D3; адрес утечёт в источник |
| С-2 | Источник закрылся | HTTP 403 или 429 на двух блоках подряд (`prc_fetches.http_status`), либо открылся breaker узла (`prc_proxies.open_until` не null) | бан единственного RU-узла останавливает и разведку, и прогон |
| С-3 | Пул пуст | `NoProxyAvailableException` / `Log::warning` резолвера | продолжать нечем |
| С-4 | Блок приходит, а данных нет | `rows_total` = 500 при `rows_new + rows_updated` = 0 (`prc_fetches`) | признак дефекта раскладки колонок — прогон пишет пустоту и выглядит успешным |
| С-5 | TLS-отказ боевого клиента | `ProxyFailureReason::TlsError` в исходах, `ConnectionException` с `SSL` | Р-10: сертификат ЕИС не в дефолтных корнях; решение — политика `verify`/корень Минцифры, не «поретраим» |
| С-6 | Дробление упёрлось в дно | `Log::warning` `price interval at minimum, dropping remainder` (`ArchiveWindowScanner:99-104`) | потеря данных на краю интервала: допустима для датасета, но факт обязан быть зафиксирован, а не проглочен |
| С-7 | Детальная стадия глухо красная | доля закупок с `detail_attempts > 0` и `detail_fetched_at is null` > 20 % на первых 50 карточках | группа Б не закрыта либо справочник карточки неполон — дальнейший трафик бесполезен |

### 6.4. Что считается успехом

- прогон завершился `RunStatus::Completed` (либо остановлен по названному сигналу с
  зафиксированной причиной);
- `prc_fetches`: у КАЖДОЙ записи `proxy_id is not null` (машинное доказательство D3);
- `prc_procurements` содержит закупки окна, `prc_revisions` — ревизии первого поколения,
  `prc_events` — `discovered` на каждую;
- **идемпотентность на живых данных**: повторный прогон того же дня не создаёт вторых ревизий
  (`select count(*) from prc_revisions where revision_number > 1` не растёт) — это же свойство
  делает безопасным перекрытие окон (§2.1);
- у документов есть скачанные версии (детальная стадия дошла до `documents`);
- метрики раздела 7 сняты в трёх точках.

---

## 7. Метрики и команды снятия (вход `findings/P4-load-baseline.md`)

Все команды проверены на `82cc2ff` (пустая БД, `docker exec agelto_postgres psql -U agelto -d
agelto`). Снимаются **трижды**: `t0` до старта, `t1` в середине прогона, `t2` после завершения —
иначе дельта не считается (Implementation Rules P4.7 п. 4). Вывод складывается машинно в
`artifacts/p4-run-metrics.json`.

| # | Метрика | Команда | Когда |
|:--|:--|:--|:--|
| M-1 | Размеры таблиц и индексов | `psql -At -c "select relname, pg_total_relation_size(relid), pg_relation_size(relid), pg_indexes_size(relid) from pg_stat_user_tables where relname like 'prc_%' order by 1;"` | t0/t1/t2 (важен ПРИРОСТ) |
| M-2 | Живые/мёртвые кортежи, отставание autovacuum | `psql -At -c "select relname, n_live_tup, n_dead_tup, n_mod_since_analyze, last_autovacuum, last_autoanalyze, autovacuum_count from pg_stat_user_tables where relname like 'prc_%' order by 1;"` | t0/t1/t2 |
| M-3 | Скорость генерации WAL | t0: `psql -At -c "select pg_current_wal_lsn(), clock_timestamp();"`; t1/t2: `psql -At -c "select pg_wal_lsn_diff(pg_current_wal_lsn(), '<lsn_t0>'), clock_timestamp();"` → байты / секунды | t0 + t1/t2 |
| M-4 | Накопительные счётчики WAL | `psql -At -c "select wal_records, wal_fpi, wal_bytes, stats_reset from pg_stat_wal;"` | t0/t2 |
| M-5 | Wait events (contention) | `psql -At -c "select wait_event_type, wait_event, count(*) from pg_stat_activity where backend_type='client backend' group by 1,2;"` — **серия сэмплов** каждые 30 с во время прогона | t1 (только во время) |
| M-6 | Неиспользуемые индексы | `psql -At -c "select relname, indexrelname, idx_scan, pg_relation_size(indexrelid) from pg_stat_user_indexes where relname like 'prc_%' order by idx_scan;"` + `psql -At -c "select stats_reset from pg_stat_database where datname='agelto';"` | t0/t2 (обязательно вместе со `stats_reset`: «0 за период» и «0 с момента reset» — разные утверждения. На `82cc2ff` второй запрос возвращает ПУСТО — `stats_reset` у базы `agelto` = `NULL`, счётчики не сбрасывались ни разу, значит `idx_scan` = 0 читается как «за всю жизнь БД») |
| M-7 | Прикладные счётчики прогона | `psql -At -c "select (select count(*) from prc_procurements), (select count(*) from prc_revisions), (select count(*) from prc_events), (select count(*) from prc_fetches), (select count(*) from prc_fetches where proxy_id is null), (select count(*) from prc_documents);"` | t0/t1/t2 |
| M-8 | Профиль блоков листинга | `psql -At -c "select count(*), avg(duration_ms)::int, max(duration_ms), sum(rows_total), sum(rows_new), sum(rows_updated) from prc_fetches;"` | t1/t2 |
| M-9 | Отставание fresh-скана | `psql -At -c "select id, stats->>'scan_gap_seconds', stats->>'oldest_update_seen' from prc_crawler_runs order by id desc limit 10;"` (носитель заводит П-4) | t2 |

**Что снять НЕЛЬЗЯ — называется неснятым, а не реконструируется** (`findings/rag-2026-07-31.md`
§7, правило P7.2):

| Метрика | Почему недоступна | Проверка на `82cc2ff` |
|:--|:--|:--|
| Bloat % (строгий) | расширение `pgstattuple` не установлено; `n_dead_tup / (n_live+n_dead)` — доля мёртвых строк, а не bloat, и подавать её как bloat нельзя | `select count(*) from pg_extension where extname='pgstattuple'` → **0** |
| Per-query write pressure и contention | `pg_stat_statements` отсутствует, требует `shared_preload_libraries` → рестарт кластера (P7.4) | `select count(*) from pg_extension where extname='pg_stat_statements'` → **0** |
| Replica lag | реплики нет; на одиночном primary не измеряется в принципе | — |

Версия кластера — `17.10 (Debian 17.10-1.pgdg13+1)`
(`select current_setting('server_version')`, снято на `82cc2ff`).

---

## 8. Калибровочные вопросы к константам и решениям

Каждый снимается на прогоне P4.7; ответ уезжает в `findings/P4-load-baseline.md`, правка
константы — в `config/crawler.php` с фактом-основанием.

| # | Вопрос | Константа/решение | Чем снимается |
|:--|:--|:--|:--|
| K-1 | Блок 44-ФЗ действительно 500 строк = 500 уникальных? | `eis.block_size` = 500; необходимость П-5 для 44-ФЗ | `sum(rows_total)` vs число уникальных `registry_number` за прогон (M-7/M-8) |
| K-2 | Достаточно ли дна рекурсии 1000 ₽? | `archive.min_price_interval` | число срабатываний `price interval at minimum` в логах (С-6); ноль — дно не достигнуто, значение не калибруется этим прогоном |
| K-3 | Какова фактическая потеря выше потолка? | `archive.price_ceiling` = 1e9 (поле профиля после П-9) | `totalCount` за дни прогона с `priceFromGeneral=1000000001` — доля от общего числа дня |
| K-4 | Хватает ли троттла 1000–4000 мс? | `throttle.min_ms`/`max_ms` | доля 429/403 (С-2), `avg`/`max duration_ms` (M-8) |
| K-5 | Верно ли перекрытие в 2 суток? | `tracking.window_overlap_days` | число закупок, найденных ТОЛЬКО за счёт перекрытия: ревизии с `source_updated_at` старше нижней границы неперекрытого окна |
| K-6 | В каком поясе «Дата обновления» в CSV — МСК или зона заказчика? | обоснование §2.1 п. 2 | сверка значения колонки с датой первого события в `event-journal` той же карточки (журнал несёт пояс явно) |
| K-7 | Дневной темп сдвига выдачи | выбор часа delta-scan (§2.2) | два одинаковых запроса блока 1 с интервалом 15 мин днём — сравнение головы блока |
| K-8 | Попадают ли типы `ea615`/`po615` в выдачу под `fz44=on`? | нужен ли П-10 в 44-ФЗ-контуре | `select procedure_type_raw, count(*) from prc_procurements group by 1 order by 2 desc` после прогона |
| K-9 | Сколько строк отбрасывает маппер? | контроль П-6 | `rows_skipped` из `FetchResult` в логах / разница `rows_total` − (`rows_new` + `rows_updated`) при первом прогоне |
| K-10 | Каков реальный объём обновлений за 30 минут? | решение D38 (дробление fresh не вводится) | `totalCount` окна `updateDateFrom=updateDateTo=<сегодня>` в двух пробах с интервалом 30 мин |
| K-11 | Нужен ли боевому клиенту корень Минцифры? | Р-10, политика `verify` | наличие С-5 в прогоне; нет — политика не трогается (решение владельца 2026-08-04) |
