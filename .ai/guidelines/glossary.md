# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию. Грань — простота ↔ техническая точность.

Полный справочник (термины, enum'ы, таблицы, исключения): `docs/02_Workflow/glossary.md`. Табличные префиксы и доменная структура: `docs/03_Dev/architecture.md`. Общие правила сущностей PHP/Laravel: skill `naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

Актуально на дату этого файла (сверено с `app/`, после переименования v1→v2 — см. `docs/02_Workflow/glossary.md#изменения-терминов`):

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»).
- `CrawlerRun` → `$run` — один прогон (не голый `Run`/`Crawl`).
- `Procurement` → `$procurement` — закупка (в v1 называлась `Link`, термин больше не используется).
- `Document` → `$document`/`$documents` — документ закупки (в v1 — `LinkAttachment`).
- `DocumentVersion` → `$documentVersion` — конкретный скачанный файл документа.
- `Ai\Analysis` → `$analysis` — сессия AI-анализа (в v1 — `DocumentAnalysis`).
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Lot` / `PurchaseItem` (`prc_items`, не путать с `Estimate\Item`/`prc_estimate_items` — позицией сметы) — лот / позиция объекта закупки.
- `ProcurementRevision` → `$revision` — снапшот редакции закупки (`prc_revisions`, партиция RANGE by `detected_at`).
- `ProcurementEvent` → `$event` — immutable типизированное событие в журнале закупки (`prc_events`, партиция RANGE by `occurred_at`, `ProcurementEventType`).
- `Proxy` → `$proxy` — узел исходящей маршрутизации (`prc_proxies`); единственная точка выдачи — резолвер `App\Services\Procurement\Proxy\ProxyResolver::resolve()`, не прямой доступ к таблице.
- `ProxyScope` → назначение исходящего запроса, не сам узел (`App\Enums\Procurement\Proxy\ProxyScope`: `CrawlListing`/`CrawlDetail`/`DocumentDownload`/`Llm`); per-target здоровье — `prc_proxy_targets` (не путать с глобальным здоровьем узла на `prc_proxies`).

### AI-подомен (`App\Models\Procurement\Ai\*`, `App\Services\Llm\*`)

- `Ai\Analysis` → `$analysis` — сессия AI-анализа версии документа (`AnalysisStep::Screening|Extraction`); повторный прогон — `parent_analysis_id`, shadow-прогон — `is_shadow` (D24).
- `Ai\Prompt` → `$prompt` — версия системной инструкции (`llm_prompts`, `key`+`version` unique). Канон — оба корня (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) → `ai:prompts:sync` (сид/upsert). `is_protected` — сидовые записи read-only в админке (D17/D44); операторские правки — только новые не-protected записи.
- `Ai\Correction` → `$correction` — операторская правка результата анализа: JSONPath + `CorrectionReason` + свободный комментарий; `is_reusable=true` — применяется ко ВСЕМ документам того же `document_type` при повторном прогоне (`CorrectionAwareComposer`, D15).
- `Ai\DatasetExample` → `$example` — immutable gold-снапшот обучающего контура (`prc_ai_dataset_examples`); единственный автор новых строк — `GoldExampleRecorder` при `review_status=approved` (D15/P9.2).
- `App\Enums\Llm\PromptStep` (интерфейс, P2.5) — шаг композиции системной инструкции (`global`/`screening:*`/`extraction:*`, включая `extraction:discovery`, D37), реализуют ядерный `CorePromptStep` (кросс-документные) и типовые enum'ы анализаторов (`App\Enums\Procurement\Analyzers\<Type>\*PromptStep`) — не путать с `AnalysisStep` (шаг АНАЛИЗА, screening/extraction) или `App\Enums\Llm\LlmPurpose` (тот же интерфейсный сейм для назначения конкретного вызова: `screening`/`discovery`/`extract_passport`/`extract_rows`/`extract_totals`/`eval`/`shadow`).
- `DocumentStatus::Unprocessable` + `UnprocessableReason` → терминальный исход «не можем обработать» (лимит провайдера/формат/пароль/zip-бомба) — константация, не сбой, не ретраится (D32/D38).
- `App\Attributes\Ai\Description` (property/parameter/class constant, P2.3) — типизированный носитель
  семантики поля контракта ответа AI, заменивший докблок-bullet; ридер атрибутов —
  `App\Services\Llm\Schema\ContractAttributeReader` (`descriptions()`/`aliases()`/`caseAliases()`) —
  единственный источник и для `description` в исходящей схеме (`JsonSchemaGenerator`), и для
  словаря синонимов (`PromptContextGenerator`, `#[Alias]`). Не путать с `#[Alias]` (синоним
  написания величины в документе, не смысл поля).
- **Критерий непрегрессии** (`EvalRunComparator::compare()`, `--criterion=patch|no-regression|
  noise-aware`, P2.2/P2.13) — числовое условие приёмки патча промта: рост/непадение F1 и row
  coverage на `tune`/`holdout` в пределах порога; `noise-aware` берёт порог из дельты нулевой пары
  замеров, а не из константы (`docs/03_Dev/ai/training-loop.md` §6).
- `DiscoveryResponse`/`DiscoveryMarker` (`App\Dto\Procurement\Analyzers\Estimate\*`) — паспорт границ смет оконного пути извлечения: LLM размечает страницы окна маркерами (`estimate_start`/`continuation`/`estimate_end`), бэкенд ДЕТЕРМИНИРОВАННО (не LLM) склеивает их в сегменты `{estimate_ordinal, page_start, page_end}` (D30/D37).
- `ExtractionRoute` (не backed enum, `Whole`/`Windowed`) — путь извлечения, выбранный `ExtractionPreflight` до первого upload по capability-матрице маршрута (D29).
- **Критерий подтверждения величины сметной схемы** (`docs/03_Dev/database.md`) — величина попадает в схему `prc_estimate*` только при пороге `N ≥ 9` файлов корпуса `estimates/files`, несущих её, ПЛЮС хотя бы один из тестов (а) прямое подтверждение / (б1) арифметика / (б2) поле F1 / (б3) фильтр-агрегат витрины; форма носителя (колонка/jsonb-ключ/производный аксессор) — тестом (в), «ровно один носитель на величину».

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Глаголы методов

- `find*` — поиск по идентичности, **nullable**, без суффиксов `ByXAndY` (`findCrawler($id)`, `findProcurement($platformId, $sourceNumber)`).
- `get*` — вернуть определённое/вычисленное (`getPendingProcurements()`); если существительное читается ясно — без `get` (`owner()`, `systemInstructionsFor($step)`).
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса.
- `run()` — точка входа процессора (одна на класс). `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- **Избегать:** `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`; `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, **одна ответственность на файл**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`, `->primaryAnalysisFailed()`); цепочки — `->for()`/`->has()` или именованный билдер.
- **Без** tuple-возвратов и boolean-флагов в хелперах (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$procurement`, `$document`, `$analysis`, `$estimate`).
- Test doubles → `tests/Support/Doubles/` (`Tests\Support\Doubles`); общие mock-сборки — хелперы (`fakeBrowserClient()`).
- Повторяющиеся проверки — кастомные expectations, не копипаста запросов.

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).

## OCDS/оспариваемые термины

Термины из внешнего OCDS-аудита, которые не являются отдельными PHP-классами — описываются здесь
ссылкой на решение, без строки «канон → `$переменная`».

- `projection` — `prc_procurements` хранит свёрнутое ТЕКУЩЕЕ состояние закупки; `prc_revisions` +
  `prc_events` хранят историю (редакции и события), из которой это состояние проецируется. Не
  отдельная сущность и не отдельная таблица — отношение, объясняющее изоморф OCDS
  `compiledRelease`/`versionedRelease`/`releases` (см. ADR-0007, `docs/03_Dev/adr/0007-ocds-as-exchange-layer.md`).
- `provenance` — тонкий срез coarse-lineage колонок (`crawler_run_id`, `artifact_ref`,
  `provider`/`model`/`rules_hash`/`cache_id`, `source_url`), не fact-level схема на 5 уровней (см.
  ADR-0008, `docs/03_Dev/adr/0008-thin-provenance-slice.md`).
- `quality flag` → `App\Enums\Procurement\Estimate\EstimateValidationStatus` (`prc_estimates.validation_status`:
  `Valid`/`Suspicious`/`Invalid`) — детерминированный вывод пайплайна ДО оператора. **Не путать** с
  `ReviewStatus` (`prc_ai_analyses.review_status`: операторское ревью AI-анализа) — это два разных
  статуса про разные вещи, смешение запрещено.
- `notice` (извещение) — не отдельная сущность; регуляторный/OCDS-синоним `Procurement` (44-ФЗ
  «извещение о закупке»).
- `registry number` → `prc_procurements.registry_number` — реестровый номер извещения ЕИС по 44-ФЗ
  (D5, `plans/archive/DOMLANG/plan.md` §5).
- `ETP number` → `prc_procurements.etp_number` — второй номер закупки на площадке по 223-ФЗ;
  forward-provision без продюсера на 2026-07-10 (D13, `plans/archive/DOMLANG/plan.md` §5) — не баг.
