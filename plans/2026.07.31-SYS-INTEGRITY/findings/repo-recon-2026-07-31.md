# Разведка репозитория — 2026-07-31

Слой 1 (raw). Всё ниже — факты, прочитанные в дереве `agelto@main` (коммит `8f2ccdb`).
Маркер каждого пункта — `RAG:—` (repo-grounded), путь указан явно. Цель разведки —
отделить «этого нет» от «это есть, но иначе», прежде чем планировать: бриф владельца в
четырёх пунктах из девяти исходит из «этого нет», и в трёх случаях это неверно.

## 1. Прокси — существует, но не связан с профилем обхода и не знает про гео

Есть:

- `database/migrations/2026_07_04_100040_create_prc_proxies_table.php` — колонки
  `service, host, port, username, password, targets(jsonb), is_active, fails_count`.
- `app/Models/Procurement/Crawler/Proxy.php`, `.../ProxyCheck.php`, `.../Fetch.php` +
  фабрики `database/factories/Procurement/Crawler/{Proxy,ProxyCheck,Fetch}Factory.php`.
- `app/Services/Procurement/ProxyPool.php` — `pick(?string $target)`, `reportFailure()`,
  `reportSuccess()`, `proxyOptions()`.
- Filament: `app/Filament/Resources/Procurement/Crawler/Proxies/**` (Resource, Form, Table,
  3 страницы) + `app/Guards/Admin/Permissions/ProxyPermission.php`.
- Потребители: `app/Services/Procurement/DocumentDownloader.php`,
  `app/Services/Procurement/CsvBlockIngestor.php`,
  `app/Services/Procurement/Eis/CsvSourceMethod.php`.

Чего нет (дыры, адресуемые фазой P1):

1. **Нет региона/страны.** Колонки `country`/`region` не существует ни в таблице, ни в
   модели. Требование брифа «не запустить Gemini через российский прокси» сегодня не
   выражено ничем — ни данными, ни гейтом.
2. **Скоуп нетипизирован.** `targets` — свободный `jsonb`-массив строк, выборка идёт
   `whereJsonContains('targets', $target)` (`ProxyPool.php`). Ни enum'а, ни валидации: опечатка
   в строке молча даёт пустую выборку и «работаем без прокси».
3. **`prc_crawlers` не связан с прокси вообще.**
   `database/migrations/2026_07_04_100038_create_prc_crawlers_table.php` (номер исправлен при
   детализации P1 2026-07-31: в первой редакции разведки стояло `100030` — это миграция
   `prc_ai_dataset_examples`, опечатка) содержит
   `registry_id, name, mode, source_method, fallback_method, filter, schedule, options,
   is_active` — колонок `use_proxy`/`proxy_scope`/`proxy_country` нет. Требование брифа «для
   каждого профиля выбирать, использует он прокси или нет» и «дочерние элементы черпают
   прокси профиля» не реализуемо на текущей схеме.
4. **Второй, несвязанный источник для LLM.** `config/ai.php:28` — `'proxy' => env('AI_GEMINI_PROXY')`,
   читается `app/Services/Llm/Providers/PrismGeminiProvider.php`. Пул БД и LLM-прокси живут
   в двух непересекающихся мирах; «единая система» из брифа отсутствует.
5. **Выбор детерминирован, не случаен.** `ProxyPool::pick()` — `orderBy('fails_count')->orderBy('id')->first()`:
   всегда один и тот же узел, пока он здоров. Бриф просит «рандомным образом черпать».
6. **Браузерного канала нет.** Ни одного вызова Playwright/Chromium с проксёй в `app/**`
   (grep по `proxy` в `app/` даёт только перечисленное выше). MCP-Playwright в прод-пайплайне
   не участвует — это инструмент разведки, а не рантайма.
7. **Здоровье одномерно.** `fails_count` + `is_active` + порог `config('crawler.proxy_max_fails', 5)`.
   Нет per-target здоровья, нет cooldown/half-open, нет причины отказа — узел, забаненный
   одной площадкой, глобально выключается для всех.

8. **Прокси фактически не используется: в `pick()` уходит полный URL, а хранится домен**
   (дописано при детализации P1, 2026-07-31 — дыра не была видна на уровне схемы).
   `CsvSourceMethod.php:71` — `pick(target: $endpoint)`, где `$endpoint` =
   `https://zakupki.gov.ru/epz/order/orderCsvSettings/download.html`; `:160` — то же с полным URL
   вкладки карточки; `DocumentDownloader.php:84` — `pick(target: $url)` со ссылкой на файл.
   В `targets` при этом лежат домены (`tests/Unit/Crawler/ProxyPoolTest.php:14` —
   `['zakupki.gov.ru']`), а выборка идёт `whereJsonContains('targets', $target)` — точным
   совпадением элемента массива. Значит на любом узле со заполненным `targets` выборка пуста и
   запрос уходит НАПРЯМУЮ: в листинге это залогировано (`'CsvSourceMethod: no active proxy,
   requesting directly.'`), в скачивании документов — молча. Требование брифа «прогон работает
   через прокси» (D3) сегодня не выполняется даже при настроенном пуле. Единственный режим, в
   котором прокси реально берётся, — `targets = null` у всех узлов, то есть без скоупа вообще.
   Адрес — P1.6 (перевод call-site'ов на скоупы), схема — P1.2.

**Про «считывать на каждой итерации».** `ProxyPool::pick()` физически ходит в БД на каждый
вызов — кэша нет. То есть требование брифа частично уже выполнено для прокси; НЕ выполнено
оно для **настроек кроулера** (их связи с прокси просто не существует, п. 3). Дополнительно
(2026-07-31, детализация P1): даже когда связь появится, снимок профиля живёт весь прогон —
`FreshRunProcessor.php:42-86` держит одну и ту же модель `$crawler` в `for`-цикле блоков, то же
в `ArchiveWindowScanner.php:61`; правка профиля в админке посреди суточного прогона до запроса
не дойдёт. Адрес — P1.3.

## 2. DTO-схемы для LLM — существуют, и в БОЛЕЕ развитом виде, чем в `Old/`

Бриф ссылается на `Old/app/Dto/Estimates/Header.php` как на ориентир. Фактическое содержимое
файла (`git show HEAD:Old/app/Dto/Estimates/Header.php`) — тонкий DTO без единого атрибута:
`columns_count, start_row_index, end_row_index, start_col_index, end_col_index, array $schema`.
Форма с `#[Alias]`/`#[Description]` на каждом столбце, которую описывает бриф, в кодовой базе
`Old/` **отсутствует** — владелец описывает ещё более раннюю версию по памяти. Ориентиром для
дизайна служит описание в брифе, не файл.

В v2 контур **есть и работает**:

- `app/Services/Llm/Schema/JsonSchemaGenerator.php` → `Dto/JsonSchema.php` → `responseJsonSchema`
  провайдера (`app/Services/Llm/Providers/PrismGeminiProvider.php:45`).
- `app/Services/Llm/Schema/PromptContextGenerator.php` — печатает **очищенный PHP-исходник**
  Data-классов в системную инструкцию; транзитивное замыкание типов (`DataTypeGraph`),
  детерминированный порядок (обёртки `*Response` последними) ради стабильности
  implicit-cache-префикса.
- `app/Services/Llm/PromptComposer.php:30` — `compose(PromptStep $step, array $schemaClasses)`.
- Call-sites: `Screening/ScreeningProcessor.php`, `Analyzers/Estimate/RowRangeChunkSource.php`,
  `Analyzers/Estimate/WindowedChunkSource.php`, `Tuning/PromptCritic.php`.
- Атрибут `app/Attributes/Ai/Alias.php` — есть (`TARGET_PROPERTY|TARGET_CLASS_CONSTANT`).
- Контракты: 68 файлов в `app/Dto/Procurement/**`, из них 20 — `Analyzers/Estimate/*`
  (`EstimateRow`, `HeaderColumn`, `RowAttributes`, `RowsChunkResponse`, `PassportResponse`, …).
  Enum типа документа — `app/Enums/Procurement/AiDocumentType.php`; DTO скрининга —
  `app/Dto/Procurement/Ai/Screening/ScreeningResult.php`.

Реальные дыры (адресуются фазой P2):

1. **`#[Description]` не существует.** В `app/Attributes/Ai/` ровно один файл — `Alias.php`.
   Семантика полей живёт **сплошным докблоком класса**: `EstimateRow` несёт ~30 строк
   маркдаун-списка «- `unit_price_base`: …» перед `final class`. Машиночитаемого носителя
   описания поля нет ⇒ `description` в JSON Schema не попадает.
2. **Схема дублируется в двух каналах.** Один и тот же контракт уходит и в `responseJsonSchema`
   (структура), и в system instruction (исходник класса). Это прямо противоречит рекомендации
   вендора — см. `rag-2026-07-31.md` §3. Дублирование здесь не блажь, а **обходной путь дыры 1**:
   докблок — единственное место, где живут описания.
3. **`#[Alias]` применён, но узко.** grep `Alias(` по `app/` даёт 10 совпадений в ДВУХ файлах:
   `app/Dto/Procurement/Analyzers/Estimate/EstimateHeaderMap.php` (6 свойств) и
   `app/Enums/Procurement/ResourceType.php` (4 кейса). `EstimateHeaderMap` — это **ровно та
   форма, которую описывает бриф** («схема шапки описывает все столбцы» с `#[Alias]` на
   свойстве), и её докблок прямо фиксирует приём: «класс — чистый носитель `#[Alias]`-метаданных:
   исходник подмешивается в системную инструкцию (приём v1, §6)». То есть требование брифа
   реализовано — но **только для 6 колонок шапки**, тогда как `EstimateRow` несёт 17 полей, а
   остальные ~66 Data-контрактов не несут ни одного алиаса. Дыра — не в отсутствии механизма,
   а в **покрытии** и в отсутствии второго атрибута (`#[Description]`, дыра 1).
4. **Промты тонкие.** 10 файлов, `wc -l`: `global` 18, `screening-global` 30,
   `extraction-totals` 32, `extraction-discovery` 33, `screening-classify` 44,
   `extraction-passport` 47, `extraction-global` 55, `extraction-rows` 57. Оценка владельца
   («слабо написаны») фактуре не противоречит.

5. **Исходящий канал схемы — НЕ `responseJsonSchema`, а Gemini `response_schema`**
   (дописано при детализации P2, 2026-07-31 — на уровне имён в доках и спеке этого не видно).
   `app/Services/Llm/Providers/PrismGeminiProvider.php:162` —
   `->withSchema(new RawSchema(name: 'response', schema: (new GeminiResponseSchemaAdapter)->adapt(...)))`;
   докблок `GeminiResponseSchemaAdapter.php:10-17` фиксирует проверку чтением вендора: «Prism
   v0.100.1 шлёт схему именно в `response_schema`, а не в `responseJsonSchema`»
   (`vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:131`). То есть канал —
   OpenAPI-3.0-подмножество, а не полная JSON Schema: `$ref`/`$defs` разворачиваются инлайном до
   `MAX_SELF_DEPTH = 3`, `additionalProperties` отбрасывается, nullable переводится в
   `type` + `nullable:true`. При этом `GeminiResponseSchemaAdapter::resolveObject()` (строки
   91–111) **пересобирает объектный узел из трёх ключей** — `type`, `properties`, `required`:
   всё остальное на объектных узлах теряется. Значит эмиссия `description`/`propertyOrdering`
   (шаг 2 порядка D8) — это правка ДВУХ классов, а не одного `JsonSchemaGenerator`, и
   поддержка этих полей проверяется по документации именно `response_schema`, а не
   `responseJsonSchema`. Имя `responseJsonSchema` в доках и в `docs/specs/v2/08-estimate-extraction.md:107`
   — дрейф терминологии, вход P2.7.

6. **Ни один атрибут контракта не читается кодом.** `grep -rn "getAttributes(" app/` — **ноль
   вхождений**; `Alias::class` вне самого файла атрибута не упоминается нигде (дописано при
   детализации P2, 2026-07-31). `#[Alias]` работает исключительно потому, что
   `PromptContextGenerator::cleanSource()` печатает ИСХОДНИК класса в системную инструкцию — вместе
   с докблоками и атрибутами. Следствие, определяющее порядок работ P2: снять печать исходника, не
   заведя reflection-ридер атрибутов, = молча потерять и синонимы `#[Alias]`, и любые
   `#[Description]`. Атрибут без ридера — украшение, а не носитель.

Дополнительно к дыре 3: единственный носитель словарей шага —
`app/Services/Procurement/Analyzers/Estimate/EstimateStepDictionaries.php` (`forStep()` →
`EstimateHeaderMap` для `extraction:rows`); он объявляет классы, до которых обход графа типов от
обёртки ответа НЕ достаёт. Это и есть предмет OQ-2: `EstimateHeaderMap` не часть JSON-ответа ни
одного шага, поэтому в исходящую схему его описания уехать не могут в принципе.

## 3. Доменная раскладка — плоско там, где бриф и указывает

`app/Enums/Procurement/` — **36 файлов в одном каталоге** + один подкаталог `Analyzers/Estimate/`
(2 файла). Файлы принадлежат минимум пяти разным подобластям: crawling
(`CrawlerMode, RunMode, RunStatus, SourceMethod, PipelineStage`), документ
(`DocumentStatus, UnprocessableReason, RepresentationKind, ExtractionRoute`), смета
(`RowType, TotalKind, CalcMethod, ModifierKind, ResourceType, EstimateType, EstimateTarget,
EstimateSourceKind, EstimateValidationStatus, IncompleteReason, DiscoveryMarker`), AI-контур
(`AnalysisStatus, AnalysisStep, AnalysisRunStatus, CorrectionReason, GoldSource, FixtureClass,
PromptSuggestionKind, PromptSuggestionStatus, FinishReason`), закупка
(`ProcurementStatus, ProcurementEventType, BidStatus, AwardStatus, ProtocolType,
EisProcedureType, OperationLogLevel`).

Для сравнения — соседние слои того же домена уже разложены:
`app/Models/Procurement/{Crawler,Document,Estimate,Ai,Platform}/`,
`app/Dto/Procurement/{Analyzers,Details,Prepare,Gold,Fixture,Ai,Bench}/`,
`app/Filament/Resources/Procurement/Crawler/{Crawlers,Proxies,Runs,IgnoreRules}/`.
То есть «слабая доменная система» — это не общий диагноз, а **локальная рассинхронизация
одного слоя** с уже принятой раскладкой остальных. Есть ли ещё такие слои — предмет P3.

## 4. Кроулер-профили и фильтры — jsonb без типа

- `prc_crawlers.filter` — `jsonb` с комментарием «даты/цены/ОКПД2/этапы»; `options` — `jsonb`
  «recordsPerPage, лимиты дробления, колонки CSV». Типизированного DTO фильтра нет.
- Filament-форма: `app/Filament/Resources/Procurement/Crawler/Crawlers/Schemas/CrawlerForm.php` —
  форма существует; специфичных для реестра (площадки) полей фильтра, о которых говорит бриф,
  надо проверять отдельно при детализации P4.
- Дробление в конфиге есть и осмысленно: `config/crawler.php` → `eis.block_size = 500`,
  `archive.{price_ceiling, min_price_interval, max_blocks_per_window, date_format}`,
  `fresh_max_blocks = 40`. То есть механика дробления по цене **реализована**; вопрос брифа
  «учтён ли лимит 5000» упирается в то, откуда взяты константы — комментарий прямо признаёт:
  «Стартовые значения калибруются на живых данных (см. handoff Open risks)».
- **Сидов кроулеров нет.** `database/seeders/` содержит ровно 6 файлов: `AiRoutingSeeder`,
  `DatabaseSeeder`, `PlatformSeeder`, `PromptSeeder`, `StatusMappingSeeder`,
  `User/AdminUserSeeder`. Ни `Crawler`-сидера, ни `Proxy`-сидера нет — требование брифа
  «создай сид для заполнения двух кроулингов» строится с нуля (P4), опираясь на существующий
  `PlatformSeeder` как на образец стиля.

## 5. Сметная модель — богатая, но с конкретными пробелами против методики

Есть (`database/migrations/*create_prc_estimate_*`):

- `prc_estimates` — `estimate_number, total_amount, currency, base_year, price_level,
  calc_method, labor_hours_workers, labor_hours_machinists, document_number, document_date,
  period_start, period_end, passport(jsonb), raw_result(jsonb), rows_expected, rows_extracted,
  validation_status, verified_at/by, source_sheet_name, source_row_start/end,
  source_page_start/end, platform_id, procurement_id(nullable), analysis_id, ordinal`.
- `prc_estimate_sections` — дерево (`parent_id`, `position`, `title`).
- `prc_estimate_items` — `row_index, code, name, unit, unit_base, unit_multiplier, quantity,
  unit_price_base, unit_price_curr, total_price_base, total_price_curr, index_code,
  index_value, source_position_number, work_type_id, attributes(jsonb)`,
  `unique(estimate_id, row_index)`.
- `prc_estimate_resources` — `resource_type, code, name, unit, unit_base, unit_multiplier,
  quantity, price_base, price_curr, index_code, index_value`.
- `prc_estimate_totals` — дерево (`parent_id`), `label, kind, amount, chapter_number, rate`.
- `prc_estimate_work_types` — дерево, `overhead_code/rate/rate_curr, profit_code/rate/rate_curr`.
- `RowAttributes` (`app/Dto/.../RowAttributes.php`) — фиксированная схема `attributes`:
  `modifiers[]`, `amount_since_start`, `amount_since_year_start`, `amount_reporting_period`;
  неизвестный ключ отклоняется в `EstimateRow::validateAndCreate()`.

Пробелы против состава ЛСР по 421/пр (сверка — `rag-2026-07-31.md` §5; каждый требует
подтверждения на реальных фикстурах, это вход P5):

1. **НР и СП как СУММЫ на позиции** — нет. Есть только нормативы-ставки на `work_type`
   и агрегаты в `totals`. В печатной форме ЛСР НР/СП — колонки позиции.
2. **Разбивка прямых затрат позиции (ОТ / ЭМ, в т.ч. ЗПМ / МАТ)** — нет отдельных сумм.
   Частично восстановима из `prc_estimate_resources`, но агрегатов на позиции нет ⇒ сортировка
   и фильтрация по ним, о которой говорит бриф, невозможна без пересчёта.
3. **Тип нормы** (ГЭСН / ФЕР / ТЕР / ГЭСНм / ГЭСНр / ФССЦ / прайс) — нет; `code` хранится
   строкой без классификации.
4. **Признак «неучтённый ресурс»** на `prc_estimate_resources` — нет.
5. **Источник цены ресурса** (ФССЦ / ТССЦ / конъюнктурный анализ / прайс) — нет.
6. **База начисления** на `prc_estimate_totals` — есть `rate` и `amount`, но нет носителя
   «от чего процент».
7. **Признак НДС на смете** (в т.ч./сверх/без) — только через строку `totals.kind='vat'`.
8. **Версия нормативной базы / дата ценового среза** — частично (`base_year`, `price_level`),
   без нормативного документа и даты индекса.

## 6. Хранилище — своё, без medialibrary; диск не хранится в БД

- `spatie/laravel-medialibrary` в `composer.json` **отсутствует** (единственный `spatie` —
  `spatie/laravel-data ^4.23`). Предположение брифа «мы же используем media library» — неверно.
- Слой свой: `Storage::disk(config('crawler.documents_disk'))` и родственные.
  `config/crawler.php` → `raw_disk = 's3'`, `documents_disk = 's3'`, `raw_html_disk = 's3'`.
- `prc_document_versions` хранит `content_hash (sha256), size_bytes, filename, extension, mime,
  storage_path, prepared_storage_path, parent_version_id`, `unique(document_id, content_hash)`.
  **Колонки `disk` нет.** Нет её и у `prc_ai_fixtures` (`storage_path` голым текстом).
- **Прецедент правильного решения в проекте уже есть:**
  `database/migrations/2026_07_27_100055_create_prc_document_representations_table.php:33` —
  `$table->text('disk')->nullable()->comment('null = диск документов config(crawler.documents_disk)')`.
  Это единственная таблица из всех носителей файлов, где диск хранится рядом с путём.
  Задача P6 — не изобрести приём, а **распространить уже принятый в проекте** на
  `prc_document_versions` (+`prepared_storage_path`), `prc_ai_fixtures` и сырые артефакты
  CSV/HTML, и добавить чтение с fallback. Поимённый список носителей — вход P6.
- Путь контент-адресован (`documents/<hash-prefix>/<sha256>`, комментарий в `config/crawler.php`)
  — это делает миграцию между провайдерами копированием безопасной и идемпотентной, но
  **сегодня адрес объекта берётся из конфига в момент чтения**, а не из записи. Смена
  `documents_disk` = мгновенная потеря доступа ко всем историческим файлам.

## 7. Партиционирование и retention — уже есть

`database/migrations/`: `setup_pg_partman.php`, `set_pg_partman_retention.php`,
`set_hot_partition_autovacuum.php`. То есть вариант (a) из развилки «две БД» в проекте уже
частично реализован — это меняет постановку п.7 брифа с «делить или нет» на «есть ли
измеренные основания идти дальше» (вход P7).

## 8. Прочее по инфраструктуре плана

- `plans/ACTIVE.md` — `**Active:** —` (активного плана нет, захват свободен).
- Предыдущий план `2026.07.28-EST-ARCH` закрыт целиком и архивирован (`git log`: `8f2ccdb`,
  `0a90999`, `60253ae`).
- В рабочем дереве — незакоммиченное массовое удаление `Old/**` и правка `.env.example`
  (`git status`). **Чужой диф**: план его не трогает, не коммитит и не откатывает
  (`plan-protocol` §6). Named here, чтобы исполнитель не принял его за свой.

## 9. Дополнение 2026-07-31 (design-сессия P3) — фактура раскладки и поверхность переноса

Дописано при детализации фазы P3. Все числа сняты машинно на дереве `8f2ccdb` (+ незакоммиченный
чужой диф `Old/**`/`.env.example`, слоя `app/**` не касается); команда указана рядом с числом.

### 9.1 Поправка к §3: файлов не 36, а 38

```bash
find app/Enums/Procurement -maxdepth 1 -type f -name '*.php' | wc -l   # → 38
```

Разбивка по пяти подобластям в §3 покрывает 36 имён; **вне разбивки остались два файла** —
`AiDocumentType` (тип документа, вход маршрутизации анализа) и `ReviewStatus` (операторская
приёмка результата). Разбивка §3 — черновой набросок, а не носитель истины: адрес каждого файла
определяет правило P3.1, и перечень пересчитывается машинно, а не цитируется из этого раздела.

### 9.2 Плоскими лежат не только enum'ы (вход инвентаря P3.1)

Число файлов В КОРНЕ слоя (без подкаталогов), команда —
`find <dir> -maxdepth 1 -type f -name '*.php' | wc -l`:

| Слой | Файлов в корне | Подкаталоги есть? |
|:--|:--|:--|
| `app/Enums/Procurement` | 38 | да — `Analyzers/Estimate/` (2 файла) |
| `app/Repositories/Procurement` | 15 | нет |
| `app/Models/Procurement` | 14 | да — `Crawler/`, `Document/`, `Estimate/`, `Ai/`, `Platform/` |
| `app/Exceptions/Procurement` | 13 | нет |
| `app/Services/Procurement` | 12 | да — `Prepare/`, `Screening/`, `Eis/`, `Detail/`, `Gold/`, … |
| `app/Console/Commands/Procurement` | 8 | да — `Ai/`, `Analyzers/Estimate/` |
| `app/Dto/Procurement` | 7 | да — `Analyzers/`, `Details/`, `Prepare/`, `Gold/`, `Fixture/`, `Ai/`, `Bench/` |

То есть §3 («соседние слои уже разложены») верен лишь наполовину: у `Models`/`Dto`/`Services`
подкаталоги ЕСТЬ, но в корне слоя одновременно лежит 7–14 файлов. Легально это или нет —
определяет правило размещения P3.1: гипотеза «в корне слоя живут агрегат-корни/кросс-подобластные
классы, в подкаталогах — подобласти» не проверена и правилом пока не является.

### 9.3 Поверхность переноса enum'ов — сколько файлов держат ссылку

```bash
grep -rl "App\\\\Enums\\\\Procurement" app | wc -l          # → 188
grep -rl "App\\\\Enums\\\\Procurement" tests | wc -l        # →  99
grep -rl "use App\\\\Enums\\\\Procurement" database/factories | wc -l   # → 21
grep -rl "^use App\\\\Enums\\\\Procurement" database/migrations | wc -l #  →  9
grep -rl "use App\\\\Enums\\\\Procurement" database/seeders | wc -l     # →   2
grep -rl "use App\\\\Enums\\\\Procurement" config | wc -l               # →   2  (ai.php, crawler.php)
grep -rl "App.Enums.Procurement\|app/Enums/Procurement" docs .ai .claude/skills | wc -l # → 12
```

Итого ≈ 333 файла. **Все ссылки — `use`-импорты и текст доков; строковых `class_string` в БД,
конфигах и промтах нет** (проверено встречным грепом: единственное строковое вхождение —
комментарий колонки `database/migrations/2026_07_27_100055_create_prc_document_representations_table.php:30`
`comment('значение App\Enums\Procurement\RepresentationKind')`, он не резолвится кодом).

### 9.4 Три места, которые молча ломаются или упираются в правило проекта

1. **Применённые миграции (9 файлов).** Держат `use App\Enums\Procurement\…` и падают на
   `class not found` сразу после переноса — а `RefreshDatabase` гоняет миграции в каждом тестовом
   прогоне, то есть красным станет весь сюит. При этом `.claude/rules/migrations.md` (generated-core)
   запрещает менять уже применённые миграции. Правка `use`-строки схему/`down()`/значения не
   меняет — но разрешение обязано быть явным (D29), а не додуманным исполнителем.
2. **`phpstan-baseline.neon` — 74 записи, каждая привязана к `path:`** (`grep -c "message:"`).
   Записей на `app/Enums/**` НЕТ (`grep -n "Enums" phpstan-baseline.neon` → пусто), поэтому перенос
   enum'ов baseline не трогает. Перенос ЛЮБОГО другого слоя (`Services`/`Repositories`/`Console`)
   потребует переписать `path:` в затронутых записях; при `reportUnmatchedIgnoredErrors: true`
   несовпавшая запись — ошибка, а не тишина.
3. **Комментарий колонки** (§9.3) — единственная строковая ссылка; после переноса становится
   ложным указателем. Менять его в применённой миграции — тот же вопрос п.1.

### 9.5 Чего в проекте НЕТ (риски, которые можно снять с повестки)

- **Нет `config/typescript-transformer.php`** и ни одного `#[TypeScript]` на enum'ах Procurement
  (`grep -rn "TypeScript" app/Enums/Procurement/*.php` → пусто): фронтовые типы от переноса не
  едут. Скил `backend-type-sync` описывает механизм, который здесь не подключён.
- **Нет discovery enum'ов по пути внутри Procurement**: единственный сканер каталога —
  `AdminGuardPanelProvider::discoverPermissionEnums()` (`config/az-guard-filament.php:123`), он
  ходит по `app/Guards/Admin/Permissions`, а не по домену.

### 9.6 Прецедент формы arch-гейта (вход P3.4)

В проекте два arch-теста: `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php` и
`tests/Feature/Architecture/TrainingUiIndependenceTest.php`. Оба стоят на выводах
`plans/archive/2026.07.28-EST-ARCH/findings/core-arch-gate-2026-07-30.md`:

- `expect([много таргетов])->not->toUse(…)` **ложно-зелёный** — множество таргетов работает по
  «ИЛИ»; таргеты обязаны перебираться ПООДИНОЧКЕ;
- `->ignoring()` ослепляет проверку `uses`-стороны — вычитание делается фильтром по классам;
- периметр собирается ДИНАМИЧЕСКИ `Symfony\Component\Finder` — рукописный перечень молча
  перестаёт ловить новый файл.

Для гейта РАСКЛАДКИ (а не зависимостей) отсюда следует: предмет проверки — файловая система и
соответствие «namespace ↔ путь», а не граф `uses`, то есть Finder-тест, а не `pest-plugin-arch`.

## 10. Дополнение 2026-07-31 (design-сессия P4) — фактура кроулинг-контура

Дописано при детализации фазы P4 на дереве `8f2ccdb`. §4 разведки был снят «сверху» (схема +
конфиг + отсутствие сидов) и в трёх местах даёт неверную картину объёма работ: разведка ЕИС уже
проводилась, механизм отслеживания изменений в основном построен, а сида площадки нет вообще —
не только профилей.

### 10.1 Живая разведка ЕИС УЖЕ проведена — `research/eis-csv-recon.md` (2026-07-03)

Файл лежит в КОРНЕ репозитория (`research/eis-csv-recon.md`, 88 строк), а не в планах, поэтому
не попал в §4. Снят вживую через Playwright по прокси на `zakupki.gov.ru`. Что там уже есть:

- механика выгрузки: кнопка → `localStorage['criteria_csv_url…']` → модалка
  `GET /epz/order/orderCsvSettings/loadSettings.html?totalCount=N`; сама выгрузка — **GET**
  `download.html` со всеми параметрами поиска + `from`/`to` + флаги колонок;
- **сессия не нужна** (`credentials: omit` → 200) — но «через RU-прокси; **напрямую с хоста ЕИС
  не отвечает**». Это факт, определяющий гейт D3 не только для прогона, но и для самой разведки;
- `from` округляется к границе блока 500, `to` фактически игнорируется — всегда ровно 500 строк;
- UI-лимит 5000, **фактический cap глубины ≈10 000**, за пределом сервер **тихо** отдаёт последний
  блок без ошибки — отсюда детект по повтору `dedup_signature`, а не по статусу;
- один endpoint обслуживает `fz44` и `fz223`;
- **полный список 25 флагов колонок** с русскими названиями (`registryNumberCsv`,
  `lastDateChangeCsv`, `stepOrderPlacementCsv`, `scopeOkpd2Csv`, …);
- этапы закупки фильтра листинга: Подача заявок → Работа комиссии → Закупка завершена /
  Закупка отменена; в выдаче встречались также «Определение поставщика завершено» и «Определение
  поставщика отменено» (детальные статусы карточки богаче фильтра);
- вкладка `documents.html` — SSR, ссылки на файлы есть в сыром HTML, браузер не нужен;
- сам документ называет непроверенным: пороги rate-limit/бан, **стабильность списка флагов между
  релизами ЕИС** (версия на момент снятия — hotfix/16.2.1.25 от 02.07.2026), точное значение cap.

Следствие для P4.1: это не разведка с нуля, а **ре-верификация годовалой фактуры + добор
недостающего**. Недостающее (в старом recon отсутствует): полный перечень событий журнала
(бриф §7 просит «завершена / победила / возвращена на доработку»), существование и семантика
`updateDateFrom`/`updateDateTo`, доступность `viewXml.html`/SOAP без авторизации (OQ-3),
фактические `card_url_templates` и `procedure_url_types` для сида реестра.

### 10.2 Параметры update-окна в коде — ЭКСТРАПОЛЯЦИЯ, а не наблюдённый факт

`app/Services/Procurement/Eis/EisCsvUrlBuilder.php:60-77` строит
`updateDateFrom`/`updateDateTo`, когда `WindowFilter::dateField === 'update'`; докблок
`config/crawler.php` (блок `archive`) прямо признаёт: «формат даты для `publishDateFrom/To`
(`updateDateFrom/To` — экстраполяция, D24)». То есть весь backlog-рескан окна простоя
(`BacklogRescanProcessor`, §10.3) стоит на паре параметров, которые никто не наблюдал вживую.
`tests/Unit/Crawler/EisCsvUrlBuilderTest.php` фиксирует ожидание кода, а не поведение источника.

### 10.3 Механизм отслеживания изменений — в основном ПОСТРОЕН

Бриф §4 («нужен надёжный механизм отслеживания») и §4 разведки читаются как «этого нет». Факты:

- **Устойчивый идентификатор** — `prc_procurements.unique(registry_id, registry_number)`
  (`2026_07_04_100011`), плюс `registry_guid`, `uuid`.
- **Хэш значимых полей** — `prc_revisions.snapshot_hash`
  (`2026_07_04_100012`, партиционирована по `detected_at`); считает
  `ProcurementIngestProcessor:35` — `sha256` нормализованного снапшота
  (`ProcurementSnapshotData::toSnapshotArray()`); совпал с последней ревизией → только
  `touchLastSeen()`, новой ревизии нет (`ProcurementIngestProcessor:129`). Это ровно тот приём,
  который RAG §4 рекомендует как основу полноты.
- **События** — `prc_events` (`2026_07_04_100013`): `discovered`, `revision_published`,
  `status_changed`, `price_changed`, `dates_changed` эмитятся диффом снапшотов
  (`ProcurementIngestProcessor::storeDiffEvents()`).
- **Детект «тихого cap»** — `dedup_signature` = первый реестровый номер блока
  (`CsvSourceMethod:112`), повтор на полном блоке → стоп (`FreshRunProcessor:66`,
  `ArchiveWindowScanner:80`).
- **Реакция на возможный пропуск** — `FreshRunProcessor::handleCapHit()` пишет
  `stats.possible_skip_window` (от `finished_at` последнего успешного прогона до `now`), а
  `BacklogRescanProcessor` (`crawler:rescan-backlog`, в расписании каждые 15 мин) гоняет по этому
  окну archive-скан **по `dateField: 'update'`**, по дням.
- **Ценовое дробление** — `ArchiveWindowScanner::splitTopInterval()`: бинарное деление
  `priceFromGeneral/priceToGeneral`, стоп по `archive.min_price_interval`, safety-cap
  `archive.max_blocks_per_window`.
- **Расписание** — `routes/console.php:17-32`: активные fresh-профили регистрируются в
  `Schedule::command('crawler:run-fresh', [id])->cron($crawler->schedule ?? '*/30 * * * *')`
  **на каждом artisan-вызове** (со schema-guard); `crawler:fetch-details` — каждые 5 мин;
  `crawler:rescan-backlog` — каждые 15 мин.

Чего в этом контуре НЕТ против целевой схемы RAG §4:

1. **Перекрытия окон** (запас 1–3 дня) — ни delta-окно `BacklogRescanProcessor`, ни archive-окно
   не расширяются назад; окно берётся встык от `finished_at` предыдущего прогона.
2. **Штатного delta-scan по `updateDate`** — рескан по update-окну существует ТОЛЬКО как реакция
   на `cap_hit`; планового «ночной full-scan + delta-scan за сутки» нет.
3. **Подтверждённых update-параметров** (§10.2).
4. **Наблюдаемости отставания** — `prc_daily_stats` (`2026_07_04_100045`) считает
   `listing_found/errors`, `detail_*`, `docs_*`, `screened`, `extracted_items`, но ни ревизий, ни
   лага fresh-скана в нём нет; спека `02-crawling.md:80` называет метрику «отставание fresh-скана»
   триггером механизма — метрики не существует.

### 10.4 Сидов нет НЕ только у профилей — нет самой площадки ЕИС

`database/seeders/` — 6 файлов + два пустых каталога (`Ai/`, `Procurement/`);
`find database/seeders -type f` даёт `AiRoutingSeeder`, `DatabaseSeeder`, `PlatformSeeder`,
`PromptSeeder`, `StatusMappingSeeder`, `User/AdminUserSeeder`.

- `PlatformSeeder` заводит РОВНО одну площадку — служебную «Обучалку»
  (`Platform::TRAINING_CODE`, `is_active=false`): это носитель обучающего контура, а не ЕИС.
- `StatusMappingSeeder` начинается с `Registry::query()->where('code','fz44')->get()` и при пустом
  результате **делает no-op с `warn`** — то есть в чистой БД матрица статусов не заводится вообще.
- `prc_registries.settings` (jsonb) — носитель ЕИС-специфики: `procedure_url_types` и
  `card_url_templates` читает `EisCardUrlBuilder`; без них `collectDetails()` кидает
  `CardUrlTemplateMissingException`. Значений этих справочников в репозитории нет нигде —
  ни в сиде, ни в фабрике (`RegistryFactory` кладёт только `records_per_page`).

Итого сид P4 — это цепочка «площадка ЕИС → реестр `fz44` с настройками карточки → два профиля
обхода», а не только профили; и без неё детальная стадия не работает в принципе.

### 10.5 Фильтр профиля: jsonb + `AsCollection`, форма — `KeyValue`

- `prc_crawlers`: `filter jsonb` («даты/цены/ОКПД2/этапы»), `options jsonb`
  («recordsPerPage, лимиты дробления, колонки CSV»), `schedule text` («cron-выражение»).
- `Crawler::casts()` — `'filter' => AsCollection::class`, `'options' => AsCollection::class`:
  типа нет, есть коллекция произвольных ключей.
- `EisCsvUrlBuilder::buildQueryParams()` берёт `$crawler->filter?->toArray()`, приводит ВСЕ
  значения к строке и подмешивает в query как есть; `options['columns']` — карта «флаг → bool»,
  включённые уходят как `<флаг>=on`; принудительно добавляются `from`/`to`,
  `<код реестра>=on`, `sortBy=UPDATE_DATE`, `sortDirection=false`, `registryNumberCsv=on`.
  То есть **опечатка в ключе фильтра молча уезжает в боевой URL** и меняет выборку.
- Filament: `CrawlerForm` — `KeyValue::make('filter')` и `KeyValue::make('options')`, то есть
  свободные пары «строка→строка»; специфичных для площадки полей, о которых говорит бриф §4,
  нет. Прецедент config-driven резолва в том же домене есть:
  `config('crawler.source_methods')` — карта «значение enum → класс» через `SourceMethodFactory`.

### 10.6 Что уже покрыто тестами (периметр, который нельзя уронить)

`tests/Feature/Crawler/` — 9 файлов (`ArchiveRunProcessorTest`, `ArchiveWindowScannerTest`,
`BacklogRescanProcessorTest`, `CsvBlockIngestorTest`, `CsvSourceMethodTest`, `FreshRunProcessorTest`,
`CrawlerRunManagerTest`, `CardCollectDetailsTest`, `DetailAnalysisProcessorTest`);
`tests/Unit/Crawler/` — 11 файлов (в т.ч. `EisCsvUrlBuilderTest`, `EisCardUrlBuilderTest`,
`EisCsvRowMapperTest`, `ProxyPoolTest`); `tests/Feature/Console/{RunArchiveCrawler,RunFreshCrawler}Test.php`.
Фикстуры: `tests/Fixtures/Crawler/eis-block-win1251.csv` + четыре HTML-вкладки карточки.

## 11. Дополнение 2026-07-31 (design-сессия P5) — фактура сметного контура

Снято при детализации P5 (разведчик по дереву + прямое чтение). Меняет предмет фазы: главный
дефект — не отсутствие колонок, а **потеря уже извлечённых величин на записи**.

### 11.1 Персистер не пишет то, что контракт извлекает, а схема держит

`app/Services/Procurement/Analyzers/Estimate/EstimatePersister.php:100-181` и
`app/Repositories/Procurement/Analyzers/Estimate/EstimateStoreRepository.php:149-167` — полный
перечень маппинга. Класс **A** (поле в контракте ЕСТЬ, колонка ЕСТЬ, персистер НЕ пишет) —
**17 колонок + 3 ключа `attributes`**:

| Таблица | Не пишется | Где поле контракта |
|:--|:--|:--|
| `prc_estimates` | `calc_method`, `labor_hours_workers`, `labor_hours_machinists`, `document_number`, `document_date`, `period_start`, `period_end` (7) | `PassportResponse` (`PassportResponse.php:37-58`) |
| `prc_estimate_items` | `unit_price_base`, `total_price_base`, `index_code`, `index_value`, `source_position_number` (5) | `EstimateRow` (`EstimateRow.php:47-70`) |
| `prc_estimate_items.attributes` | ключи `amount_since_start`, `amount_since_year_start`, `amount_reporting_period` (3) — пишется ТОЛЬКО `modifiers` (`EstimatePersister.php:165-167`) | `RowAttributes` (`RowAttributes.php:19-44`) |
| `prc_estimate_resources` | `price_base`, `index_code`, `index_value` (3) | `RowResource` (`RowResource.php:26-38`) |
| `prc_estimate_totals` | `chapter_number`, `rate` (2) | `TotalLine` (`TotalLine.php:27-39`) |

Класс **B** (колонка ЕСТЬ, поля в контракте НЕТ, писателя нет вовсе) — 5 колонок:
`prc_estimate_items.unit_base` · `.unit_multiplier` · `.work_type_id`;
`prc_estimate_resources.unit_base` · `.unit_multiplier`. По `research/06-schema-decision.md`
(EST-TRAIN) SR-15 заполняющая сторона `unit_base`/`unit_multiplier` — **бэкенд**
(детерминированная нормализация `unit`, явное исключение из D33); нормализатора в дереве нет
(`grep -rn "unit_multiplier" app/Services` — только DTO/маппер gold).

**Второй writer-путь дублирует ту же дыру:** `RerunRangeAction.php:100-115` пишет тот же
усечённый набор полей позиции — правка обязана быть синхронной, иначе точечный re-run затирает
то, что записал полный persist.

**Мёртвый параметр:** `EstimateStoreRepository::findOrCreateSection()` (строки 51-63) читает и
пишет `section_number`, но колонка удалена (EST-TRAIN D100), и `Section::$fillable`
(`app/Models/Procurement/Estimate/Section.php:28-33`) её не содержит → значение молча
отбрасывается mass assignment, а сравнение в строке 58 всегда истинно при непустом аргументе
(лишний `update()` на каждый резолв раздела).

### 11.2 Эталонный корпус — что физически доступно

`config/ai.php:498-512` (раздел `fixtures`): `manifest_path=estimates/manifest.json`,
`gold_dir=estimates/gold`, `source_dir=estimates/files`, `splits_path=estimates/eval-splits.json`.
Фактически на диске: `estimates/files` — **185 файлов**, `estimates/manifest.json` — **24 записи**,
`estimates/gold` — **6 файлов `*.gold.json`** (extraction) + 8 `*.profile.json` + 9
`*.screening.json`. Сплиты: holdout — `EST-XLSX-KS2-001`, `EST-XLSX-LSR-001`; tune — остальные 4.
Сидеров, наполняющих `prc_ai_fixtures`/`prc_ai_dataset_examples`, нет
(`find database/seeders -iname "*fixture*" -o -iname "*dataset*"` — пусто): корпус попадает в БД
только командами `estimate:fixtures:import` / `estimate:gold:import`.

Формат `*.gold.json` — 1:1 с контрактом: верхний уровень повторяет `PassportResponse`
(`passport, estimate_number, currency, base_year, price_level, rows_expected, totals, calc_method,
labor_hours_workers, labor_hours_machinists, document_number, document_date, period_start,
period_end, work_types, rows`), строка — `EstimateRow` (включая `unit_price_base`,
`total_price_base`, `index_code`, `index_value`, `source_position_number`, `work_type_name`).
`EST-XLSX-LSR-001` — 155 строк. **То есть gold-эталон несёт ровно те величины, которые боевой
персистер выбрасывает** — потеря доказывается сравнением gold и БД, без новых прогонов.

### 11.3 Измеримость: где живёт список сравниваемых полей

`app/Services/Procurement/Analyzers/Estimate/EvalMetrics.php` — `FIELD_MAP` (строки 43-56:
`row_type, code, name, unit, quantity, price→total_price_curr, unit_price_base, total_price_base,
index_code, index_value, source_position_number, work_type_name`), `ROW_NUMERIC_FIELDS` (64),
`PASSPORT_FIELD_MAP` (75-94, 18 полей), `TOTALS_FIELD_MAP` (107: `value, kind, chapter_number,
rate`). `app/Services/Procurement/Tuning/EvalRunComparator.php` читает уже посчитанный
`details['per_field']` и от нового поля НЕ ломается — но и не видит его: новое поле не
измеряется, пока не дописано в соответствующий `*_FIELD_MAP` вручную.

### 11.4 Схема ответа собирается рефлексией — отдельной правки не требует

`app/Services/Llm/Schema/JsonSchemaGenerator.php:35-186` обходит конструктор Data-класса
рефлексией (`Data`-тип → `$ref`, enum → `enumSchema()`): новое public-поле контракта попадает в
исходящую схему автоматически. Промт правится только там, где нужно ПОВЕДЕНЧЕСКОЕ правило.
Промты извлечения — `resources/estimates/ai/prompts/` (5 файлов, 224 строки:
`extraction-global` 55, `extraction-rows` 57, `extraction-passport` 47, `extraction-discovery` 33,
`extraction-totals` 32); шаги объявлены в `EstimateAnalyzer.php:56-78`; словари `#[Alias]`,
недостижимые обходом от обёртки ответа, — `EstimateStepDictionaries.php:29-35`.

### 11.5 Прочее по сметному контуру

- Add/alter-миграций к `prc_estimate_*` в дереве нет (все шесть — `create_*`); эталонный
  паттерн добавления колонки — `database/migrations/2026_07_31_100060_add_analysis_id_to_prc_ai_dataset_examples_table.php`.
- `prc_estimate_work_types` боевым путём не заполняется вовсе (только `.gsfx`-маппер) — принятый
  владельцем долг донора (`research/08-schema-change-impact.md` §7 плана EST-TRAIN).
- Индексы: на `prc_estimate_items` — `estimate_id`, `section_id`, `work_type_id`,
  `unique(estimate_id, row_index)`; на `prc_estimate_resources` — только `item_id` (фильтр по
  `resource_type` — full scan); на `prc_estimate_totals` — `estimate_id`, `parent_id` (нет на
  `kind`). Сортировка/фильтрация по величинам сметы, о которой говорит бриф §5, сегодня
  неиндексирована нигде.
- Enum'ов «тип нормы», «источник цены ресурса», «признак НДС» в `app/Enums/Procurement/` нет
  (37 файлов; сметные — `CalcMethod`, `EstimateType`, `EstimateTarget`, `EstimateValidationStatus`,
  `ResourceType`, `ModifierKind`, `TotalKind`, `RowType`, `EstimateSourceKind`).
- `#[Description]` в дереве не используется (`grep -rl "#\[Description" app/` — 0 файлов),
  `#[Alias]` в сметных DTO — только `EstimateHeaderMap` и кейсы `ResourceType`.

### 11.6 Статус плана-донора `2026.07.26-EST-TRAIN` (важная поправка)

План **НЕ закрыт**: `Status | 🟡 In progress`, P8 и P9 — `⛔ Skipped by decision`, P7.6 —
`⬜ Not started` (ждёт владельца). Терминальны фазы, от которых зависит P5: **P4** (аудит и правка
схемы смет, 🟠, 3/5 🟢), **P5** (gold-парсер и наполнение эталонов, 🟠), **P7** (боевые промты и
метрики, 🟠). Формулировка «корпус ЗАКРЫТОГО плана» в `handoff.md`/`research/01` неточна —
зависимость идёт от терминальных ФАЗ донора, а не от закрытия плана целиком.

Ключевое: **фаза P4 донора уже провела аудит того же предмета** — критерий D32 (порог
подтверждения N = 9 файлов корпуса + три теста участия), матрица SR-01…SR-17
(`research/06-schema-decision.md`), обратная сверка 60 носителей, карта следствий
(`research/08-schema-change-impact.md`). Восемь «пробелов» §5 этой разведки выведены из методики
421/пр БЕЗ сверки с этой матрицей — часть из них там уже получила вердикт «не хранится» с
обоснованием (базы начисления `@NaclMask`/`@PlanMask` — SR-08; формула начисления — SR-11;
комментарий ресурса — SR-16; разряд работы — SR-07).

## 12. Хранилище: поимённые носители файловых ссылок (дописано в дизайн-сессии P6, 2026-07-31)

Раздел §6 назвал приём и прецедент, но не носителей. Ниже — машинный инвентарь: что именно в
БД адресует файл, каким конфиг-ключом, кто пишет и кто читает. Основание —
`grep -rn "Storage::disk" app/` (39 вхождений) и грепы по колонкам миграций.

### 12.1. Носители, которые адресуют файл ИЗ ЗАПИСИ БД

| Таблица.колонка | Что хранит | Диск сегодня | Writer | Колонка `disk` |
|:--|:--|:--|:--|:--|
| `prc_document_versions.storage_path` | оригинал файла версии документа | `config('crawler.documents_disk')` (фолбэк `raw_disk`) | `DocumentDownloader::store()` (`:132-142`) | **нет** |
| `prc_document_representations.storage_path` | подготовленное представление (PDF и т.п.) | `disk` записи ?? `documents_disk` | стратегии `Prepare/*Strategy` | **есть** (прецедент, `100055:33`) |
| `prc_ai_fixtures.storage_path` | файл эталонного корпуса | `config('ai.fixtures.disk')` = `estimates` | `FixtureUploader:61`, `FixtureRegistrar:75`, `FixturesImportCommand:40` | **нет** |
| `prc_estimates.raw_result_ref` | сырой ответ LLM > ~1 МБ | `config('crawler.raw_disk')` | `EstimatePersister:249-262` | **нет** |
| `llm_calls.request_ref` / `response_ref` | сырые request/response вызова | `config('crawler.raw_disk')` | `LlmCallRecorder::storeRaw()` (`:117-126`) | **нет**; таблица ПАРТИЦИОНИРОВАНА (`100026`, сырой SQL) |
| `prc_fetches.artifact_ref` | сырой CSV-блок листинга | `config('crawler.raw_disk')` | `CsvBlockIngestor::storeRawArtifact()` (`:143-152`) | **нет**; таблица ПАРТИЦИОНИРОВАНА (`100041`, сырой SQL) |
| `prc_ai_dataset_examples.gold_ref` | крупный эталон в object storage | не определён | **никто**: `GoldExampleRecorder:78` пишет `'gold_ref' => null` | **нет**; носитель заведён, но не используется |

### 12.2. Файлы БЕЗ записи-носителя (адрес выводится конвенцией)

- **Сырые HTML-снапшоты** — `CsvSourceMethod::storeRawHtml()` (`:184-191`), диск
  `config('crawler.raw_html_disk')`, путь `raw/html/{registryId}/{registryNumber}/{tab}.html.gz`,
  запись в БД **не создаётся вообще**; пишутся только при
  `crawler.raw_html_retention_enabled` (по умолчанию `false`).
- **Экспорт датасета** — `DatasetExportCommand:65-78`, диск из опции `--disk` либо
  `crawler.raw_disk`; продукт команды, не персистентная ссылка.
- **Рабочие копии эталонов** — `WorkingCopyProvisioner:73-75` копирует файл фикстуры на
  `documents_disk` по детерминированному контент-адресованному пути; адрес выводится из хэша.

### 12.3. Три поправки к постановке фазы P6 (скелет писался до этого инвентаря)

1. **`prepared_storage_path` больше не существует.** Колонка удалена миграцией
   `2026_07_27_100056_drop_prepared_storage_path_from_prc_document_versions_table.php` (D25/P3.1
   плана-донора): единственное представление версии переехало в `prc_document_representations`.
   Формулировка скелета «`prc_document_versions` (включая `prepared_storage_path`)» описывает
   несуществующий носитель.
2. **Сейм резолвера уже есть.** `app/Services/Procurement/Prepare/RepresentationResolver.php`
   возвращает `array{storagePath, disk, extension, mime}` и уже реализует фолбэк
   `$representation->disk ?? $documentsDisk` (`:44`, `:56`), а при отсутствии представления
   отдаёт оригинал версии с `'disk' => $documentsDisk` (`:67-72`) — то есть **ровно в этой точке
   диск версии выводится из конфига**. Потребители контракта: `ExtractionOrchestrator:101-111`,
   `ScreeningProcessor:87-89`, `RerunRangeAction:148-150`, `FormatBenchRunner:199`. Фаза не
   заводит резолвер с нуля — она расширяет существующий и делает его единственной точкой.
3. **Эталоны корпуса живут на ДРУГОМ конфиг-ключе** — `config('ai.fixtures.disk')`
   (`config/ai.php:499`, дефолт `estimates`), а не на `crawler.documents_disk`. Диск `estimates`
   в `config/filesystems.php:67-73` — драйвер **`local`**, и `GoldCorpusBuilder:162` вызывает
   `Storage::disk($disk)->path(...)`, которого у S3-драйвера нет. То есть перевод корпуса
   эталонов на S3 сломает построение gold независимо от адресации — это отдельный факт, который
   фаза обязана назвать, а не молча унаследовать.

### 12.4. Прямые чтения конфига диска в домене (кандидаты на перевод через резолвер)

`config('crawler.documents_disk')` читается напрямую в `ArchiveStrategy:62`, `WordStrategy:45`,
`GsfxStrategy:54,73`, `SpreadsheetStrategy:69`, `ScanStrategy:71`, `WorkingCopyProvisioner:37,73`,
`FormatBenchRunner:429`, `DocumentDownloader:133`, `RepresentationResolver:33`;
`config('crawler.raw_disk')` — в `LlmPayloadReader:31`, `LlmCallRecorder:119`,
`CsvBlockIngestor:148`, `EstimatePersister:260`, `DatasetExportCommand:65`;
`config('ai.fixtures.disk')` — в `FixtureUploader:61`, `FixturesVerifyCommand:36`,
`FixturesImportCommand:40`, `GoldBuildCommand:77`, `FixtureForm:35`, `Fixture.php:140`,
`FormatBenchRunner:424`, `WorkingCopyProvisioner:37`.

Итого 39 call-site'ов `Storage::disk` при трёх конфиг-ключах и одной таблице, где диск хранится
верно. Это и есть поверхность фазы P6.

## 13. Дополнение 2026-07-31 (design-сессия P7) — схема, рантайм БД, процессы

Дописано при детализации фазы P7. Числа сняты машинно на дереве `09e07a5` (+ незакоммиченный
чужой диф `Old/**`/`.env.example`, слоя `app/**` и `database/**` не касается); команда указана
рядом с числом. Это ОТПРАВНАЯ ТОЧКА ревизии P7.1, а не её замена: собственная сверка обязательна.

### 13.1 Инвентарь схемы

- `ls database/migrations/ | wc -l` → **64** файла; `ls database/migrations/ | grep -cE
  "create_[a-z0-9_]+_table"` → **56** создающих таблицу.
- Таблиц по префиксам: `prc_` — **43**, `ref_` — **4** (okpd2/ktru/okved2/kbk), `llm_` — **4**
  (calls/providers/routes/prompts), `org_` — **1**, системные — 4 файла (users+sessions,
  cache, jobs+failed_jobs+job_batches, pulse×3).
- Литеральный `Schema::create('…')` встречается лишь **11** раз (`grep -rhoE
  "Schema::create\('[^']+'" database/migrations/ | wc -l`) — остальные 45 создают таблицу через
  `$this->table()` = `(new Model)->getTable()`. Следствие для ревизии: инвентарь по grep DDL
  НЕПОЛОН, имена таблиц берутся из моделей/каталога БД, а не из миграций.
- 8 миграций — не-`create` (alter/setup): `100047_setup_pg_partman`, `100048_add_procurement_foreign_…`,
  `100049_add_lease_columns_to_prc_documents`, `100050_set_pg_partman_retention`,
  `100051_set_hot_partition_autovacuum`, `100054_add_fixture_to_prc_ai_dataset_examples`,
  `100056_drop_prepared_storage_path_…`, `100060_add_analysis_id_to_prc_ai_dataset_examples`.
- `find database/seeders -type f` → **6** (`DatabaseSeeder`, `PlatformSeeder`, `StatusMappingSeeder`,
  `PromptSeeder`, `AiRoutingSeeder`, `User/AdminUserSeeder`); `find database/factories -name '*.php'
  | wc -l` → **53**.

### 13.2 Сырой SQL и партиционирование

`grep -rlc "DB::statement" database/migrations/ | wc -l` → **23** файла. Категории:
`PARTITION BY RANGE` — 5, `GENERATED ALWAYS AS IDENTITY` — 5 (те же таблицы), частичные
`CREATE UNIQUE INDEX` — 9, `ADD CONSTRAINT … CHECK` — 14, один GIN по `text[]`
(`prc_procurements_okpd2_codes_gin`).

Партиционировано **5** таблиц (`config/partitioning.php`), интервал `1 month`, `premake = 3`,
`retention_keep_table = false`:

| таблица | ключ RANGE | PK | retention |
|:--|:--|:--|:--|
| `prc_events` | `occurred_at` | `(id, occurred_at)` | 6 мес, DROP |
| `prc_revisions` | `detected_at` | `(id, detected_at)` | **NULL — не дропается** |
| `llm_calls` | `called_at` | `(id, called_at)` | 12 мес, DROP |
| `prc_fetches` | `fetched_at` | `(id, fetched_at)` | 6 мес, DROP |
| `prc_operation_logs` | `created_at` | `(id, created_at)` | 6 мес, DROP |

`set_hot_partition_autovacuum.php`: горячие — `llm_calls` и `prc_fetches`; три параметра
(`autovacuum_vacuum_scale_factor`, `autovacuum_analyze_scale_factor`,
`autovacuum_vacuum_insert_scale_factor`) = `0.01`, применяются и к `partman.template_public_*`
(для будущих партиций), и ко всем существующим партициям через `pg_inherits`.
`config('partitioning.eternal')` = `prc_estimates`, `prc_ai_dataset_examples`, `prc_revisions` —
информационный список для теста-гейта (`tests/Feature/Infra/PartitionRetentionTest.php`).

### 13.3 Типы величин

`grep -rnE "\->(float|double)\(" database/migrations/*.php` → **0** (плавающей точки в схеме нет).
Распределение `decimal`: `18,2` — 17 колонок (деньги), `18,6` — 14 (коэффициенты, количества,
трудозатраты), `5,2` — 3 (проценты), `20,2` — 1 (вендорная pulse), **`15,3` — 1**.

**Находка-одиночка:** `prc_items.quantity = decimal(15,3)` против `prc_estimate_items.quantity` и
`prc_estimate_resources.quantity = decimal(18,6)` — одно доменное понятие «количество», три знака
против шести и total 15 против 18.

**Валюта выражена у 4 таблиц из ~10, где лежат деньги:** `char('currency', 3)` есть в
`prc_procurements`, `prc_lots`, `prc_awards`, `prc_estimates`; нет у `prc_bids.price`,
`prc_items.unit_price/total_cost`, `prc_financing.amount`, `prc_eis_details.*_amount`,
`prc_estimate_items.*_price_*`, `prc_estimate_resources.price_*`, `prc_estimate_totals.amount` —
валюта подразумевается унаследованной, но связь не выражена ни FK, ни констрейнтом.

`jsonb` — **37** колонок, `->json(` — 0 (консистентно). Семь из них — generic-«мешок»
`attributes` (`prc_procurements`, `prc_lots`, `prc_procurement_parties`, `prc_estimate_items`,
`org_organizations`, все четыре `ref_*`).

### 13.4 Ключи и ссылочная целостность

`->foreignId(` → **75**, `->constrained(` → **74**, `->foreign(` → 4 (реальных 3), сырых
`ADD CONSTRAINT … FOREIGN KEY` на партиционированных — **5**.

`onDelete`-раскладка: `nullOnDelete()` — **33**, `cascadeOnDelete()` — **21**,
`restrictOnDelete()`/`onDelete('restrict')` — **0**, **без явного `onDelete` — ~28** (Postgres
default `NO ACTION`); `grep "ON DELETE" database/migrations/*.php` → **0**, то есть все 5 сырых FK
тоже без явного правила.

`*_id` без FK-констрейнта (метод: `grep -rnE "unsignedBigInteger\('[a-z_]*_id'"` = 7 попаданий +
ручной осмотр heredoc-DDL пяти партиционированных таблиц против пяти `ADD CONSTRAINT`):

| колонка | причина |
|:--|:--|
| `prc_procurements.current_revision_id`, `prc_procurement_parties.first_seen_revision_id`, `prc_documents.first_seen_revision_id` | ссылка на партиционированную `prc_revisions` — FK невозможен (композитный PK) |
| `prc_ai_prompt_suggestions.llm_call_id` | ссылка на партиционированную `llm_calls` |
| `prc_events.revision_id` | сырой DDL, FK не создан |
| **`prc_revisions.crawler_run_id`** | сырой DDL, FK не создан — **хотя `prc_crawler_runs` НЕ партиционирована и FK был бы возможен** |
| **`llm_calls.analysis_run_id`** | сырой DDL, FK не создан (есть только на `analysis_id`) |
| `prc_operation_logs.entity_id` | полиморфная пара — FK невозможен по определению |

`->unique(` → **38** (13 доменных составных), `->index(` → **85**, составных `->index([` → 5,
частичных `CREATE UNIQUE INDEX` сырым SQL → 9, `->fullText(` → 0.

### 13.5 Процессы: jobs, очереди, команды

`find app/Jobs -name '*.php' | wc -l` → **5**:

| класс | очередь | уникальность |
|:--|:--|:--|
| `PipelineJob` (abstract) | — | `uniqueFor()` |
| `PrepareDocumentJob` | `doc_prepare` | `ShouldBeUnique` |
| `ScreeningJob` | `ai_screening` | `ShouldBeUnique` |
| `Analyzers/Estimate/EstimateExtractionJob` | `ai_extraction` | `ShouldBeUniqueUntilProcessing` (D39) |
| `ShadowAnalysisJob` | `ai_shadow` | только `ShouldQueue` |

`grep -rnE "\\\$tries|\\\$backoff|\\\$timeout|retryUntil" app/Jobs/` → **0 в коде**. Ретраи/таймауты
заданы только в `config/horizon.php`, где `tries: 1` во ВСЕХ четырёх supervisor'ах — то есть
ретраев на уровне очереди нет вовсе; повтор реализован своим контуром
(`HasErrorBlock` + `pipeline:retry-failed` каждые 10 минут).

**Дефект: очередь `ai_shadow` не имеет supervisor'а.** `grep -rn "ai_shadow" config/ app/` →
только `ShadowAnalysisJob.php:74` и комментарий в `ShadowDispatch.php:63`; в `config/horizon.php`
объявлены `default`, `doc_prepare`, `ai_screening`, `ai_extraction`. Задачи shadow-прогонов
попадают в Redis и не разбираются никем, пока вручную не поднят `queue:work ai_shadow`.

Supervisor'ы (`config/horizon.php`): `default` (memory 128, timeout 60), `doc_prepare` (128/120),
`ai_screening` (128/60), `ai_extraction` (256/300); инвариант из комментариев — job timeout 300 <
`queue.redis.retry_after` 360 < `stop_grace_period` 600.

`find app/Console/Commands -name '*.php' | wc -l` → **29**: `partitions:maintain` (1),
`Procurement/` — 8 (`crawler:*`, `pipeline:*`, `procurement:convert-xlsx-to-pdf`),
`Procurement/Ai/` — 7 (`ai:*`), `Procurement/Analyzers/Estimate/` — 12 (`estimate:*`),
`Reference/` — 1. Расписание (`routes/console.php`): `partitions:maintain` daily,
`crawler:run-fresh` по cron профиля (fallback `*/30`), `crawler:fetch-details` /5 мин,
`crawler:rescan-backlog` /15 мин, `pipeline:retry-failed` /10 мин `withoutOverlapping()`,
`pipeline:stuck-check` /15 мин `withoutOverlapping()`.

### 13.6 Тесты и статический анализ (вход аудита P7.6)

- `find tests -name '*Test.php' | wc -l` → **158** (Feature 128, Unit 30).
- Feature по подкаталогам: `Procurement` 49, `Ai` 36, `Crawler` 9, `Filament` 8, `Console` 5,
  `Observability` 5, `Llm` 4, `Document` 3, `Infra` 2, `Organization` 2, `Reference` 2,
  `Architecture` 1, `Support` 1.
- **`find tests -ipath '*Jobs*' -name '*Test.php' | wc -l` → 0** и
  **`find tests -ipath '*Command*' -name '*Test.php' | wc -l` → 0**: выделенных директорий нет,
  job'ы покрыты косвенно через оркестраторы (`grep -rl "…Job" tests/ | wc -l` → 16 файлов),
  команды — через `tests/Feature/Console/` (**5 из 29**: `MaintainPartitions`, `FetchDetails`,
  `RunFreshCrawler`, `RunArchiveCrawler`, `RescanBacklog`). Все 12 `estimate:*` и 7 `ai:*` команд
  собственных тестов не имеют; уникальность job'а (`uniqueId`/`uniqueFor`) и поведение при
  конкурентном диспатче машинно не проверены.
- Arch-гейтов **2**: `tests/Unit/Architecture/CoreKnowsNoDocumentTypeTest.php`,
  `tests/Feature/Architecture/TrainingUiIndependenceTest.php` (P3.4 добавит третий).
- `phpstan.neon`: `level: 5`, paths `app`, larastan + baseline;
  `grep -c "message:" phpstan-baseline.neon` → **74**.
- `pint.json` **отсутствует** — действует дефолтный пресет `laravel`.
- Целей `stan`/`pint` нет ни в `composer.json scripts` (там `setup`, `dev`, `test`), ни в
  `docker/Makefile` (**55** целей: `test`, `test-php`, `test-preflight`, `db-*`, `prod-*`).
  Статический анализ и форматирование запускаются вручную.

### 13.7 Рантайм БД и наблюдаемость (вход P7.2/P7.4)

- Postgres: `build: ./docker/postgres`, `image: agelto_postgres:17-partman`
  (`FROM postgres:17` + `postgresql-17-partman`), healthcheck `pg_isready`, volume `pgsql_data`,
  init-скрипт создаёт `agelto_test`. Блоки идентичны в `docker-compose.yml:123` и
  `docker-compose.prod.yml:136`.
- **`command:` с параметрами Postgres НЕТ** — кластер работает на дефолтах образа
  (`shared_buffers`, `work_mem`, `max_connections`, `wal_level` не тюнятся):
  `grep -rn "wal_level\|max_connections\|shared_preload" docker-compose*.yml docker/` → **0**.
- **`pg_stat_statements` отсутствует в репозитории полностью:**
  `grep -rn "pg_stat_statements" --include='*.php' --include='*.yml' --include='*.sql'
  --include='*.conf' --include='*.md' . | grep -v vendor/` → **0**.
- **Реплики/standby нет:** `grep -rn "replica\|standby" docker-compose*.yml docker/` → **0**.
- **`shm_size: '2gb'` стоит на сервисе `horizon`, а не на `postgres`**
  (`docker-compose.prod.yml:82`, внутри блока `horizon:` строк 68–90) — при том что shm нужен
  Postgres (партиционирование, параллельные планы, `run_maintenance()`).
- Наблюдаемость: есть `laravel/pulse` (+ `pulse_*` таблицы), `laravel/horizon` (metrics
  `trim_snapshots` 24/24), `sentry/sentry-laravel`, health-эндпоинт `/up` (Laravel-дефолт; тест
  `tests/Feature/HealthCheckTest.php` проверяет только 2xx, не БД/Redis/MinIO).
  `spatie/laravel-health`, Telescope, Prometheus, Nightwatch — **нет** (остались только env-флаги
  в `phpunit.xml`).
- `config/database.php`: **5** дефолтных connections Laravel, кастомных нет; у `pgsql` только
  `search_path` и `sslmode`. **`read`/`write`/`sticky` — отсутствуют**
  (`grep -rn "sticky\|'read' =>\|'write' =>" config/database.php | wc -l` → 0).
- Бэкапы: `docker/postgres/backup-and-verify.sh` (pg_dump + restore-check), цель `make db:backup`.

### 13.8 Доки, привязанные к схеме (вход P7.8)

`grep -rlc "prc_" docs/03_Dev/*.md docs/02_Workflow/*.md .ai/guidelines/*.md` → **11** файлов.
Плотность привязки: `docs/03_Dev/ocds-mapping.md` — 44 упоминания, `docs/03_Dev/database.md` — 20,
`docs/03_Dev/entity-lifecycle-map.md` — 13, `.ai/guidelines/glossary.md` — 10; остальные ≤4.
**Ни один `docs/02_Workflow/*.md` имён таблиц не упоминает** — бизнес-слой от схемы отвязан, при
ревизии его править не придётся.
