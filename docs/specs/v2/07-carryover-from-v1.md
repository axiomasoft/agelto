# ТЗ v2 · 07 — Карта переноса из v1 (что забрать, что в Old)

> Цель: дать возможность спокойно переместить текущий код в `Old/` и строить v2 с нуля, зная, какие удачные решения и данные из v1 переносятся и где их искать. Основано на аудите реального кода — `research/tz-v2/v1-columns.md`, `v1-solutions.md`, `v1-domain.md`, `v1-gap.md`.

## 0. Как использовать

1. Текущий код переезжает в `Old/` (справочник, не удаляется).
2. v2 строится по `01`–`06`; при реализации сверяться с этим файлом — он указывает конкретные v1-файлы-ориентиры для каждого решения.
3. Ссылки на v1-файлы даны от корня проекта.

## 1. ТОП идей v1 — переносим (с пометкой)

| Идея | v1-файл | Перенос |
|---|---|---|
| **CrawlerRunManager** — жизненный цикл прогона: partial-unique «один running на crawler» (в БД, не в коде), heartbeat, recovery оборванных, иерархический курсор возобновления | `app/Process/Crawling/Run/CrawlerRunManager.php`, `Cursor/CrawlCursor.php` | **как есть** → `crw_runs` |
| **Иерархический курсор** — `price_from/to` + `price_stack` (стек диапазонов бинарного дробления) + `day_from/to` + `cursor_offset` | `plt_crawler_runs` миграция | **как есть** → `crw_runs.cursor jsonb` (`02` §4.2) |
| **Partial-unique инвариант в БД** (`WHERE status='running'`) через сырой `DB::statement` | миграция `plt_crawler_runs` | **как есть** |
| **AbstractCrawlingService** — Template Method: `start()` с try/finally, специфика площадки только в хуках; парсинг вложений только при изменении `platform_updated_at` | `app/Process/Crawling/AbstractCrawlingService.php` | **архитектуру как есть**, парсинг вёрстки переписать под текущую разметку |
| **Идемпотентная джоба** — guard по терминальному статусу → `acquireLease()` → try/finally `releaseLease()` → `failed()` = `recordFailure()` + `PipelineAlert` + `tags()` для Horizon | `app/Process/*/Jobs/*Job.php` | **как эталон** для всех queued-джоб v2 (`03` §4) |
| **PipelineDispatcher** — явный мост между стадиями (выстрадан на инциденте рассинхрона) | `app/Process/Pipeline/PipelineDispatcher.php` | **как есть** (`02` §8, `03` §4) |
| **Промпты из БД + версии** — `PromptRule` (key/step/priority/version/is_active), редактор в админке | `app/Repositories/Prompt/PromptRuleRepository.php`, Filament `PromptRule/*` | **как есть** → `ai_prompts` (`04` §3.2) |
| **«Схема как код»** — DTO-схема чистится regex и идёт в system instruction | `app/Services/PromptService.php::getCleanSchema()` | **как есть** |
| **Gemini context caching + инвалидация** по `rules_hash`/`file_fingerprint`/`expires_at`, учёт токенов | `app/Services/GeminiService.php` | **идею как есть** → `ai_analyses.cache_*`/`rules_hash` (`04` §2.1) |
| **Прокси Strategy** — Factory + Driver interface (Config/Database), health-tracking (`fails_count`, авто-деактивация при MAX_FAILS) | `app/Services/Http/Proxy/*`, `Modules/Sys/.../ProxyRepository` | **как есть** → `crw_proxies` |
| **Статус-enum с поведением** — `stage()` (retry с нужной точки), `isTerminal/isFailed/isInProgress/isAwaiting` | `app/Enums/AnalysisStatus.php`, `Crawler/CrawlerRunStatus.php` | **как есть** (канон статус-enum'ов) |
| **Репозиторий по канону** — `findLink/getPendingLinks/storeOrUpdateLink`, read/store split | `app/Repositories/Crawling/CrawlingRepository.php` | **как есть** |
| **Целевая схема сметы** — дерево `spatie/laravel-data` DTO (Passport/Type/Target/Header+Column с алиасами/Section/Item/Resource/Modifier/Footer) с русскими докблоками | `app/Process/EstimateAnalysis/Schema/*` | **DTO забрать как есть** → `est_*` таблицы; extraction-код писать заново |
| **Якорный поиск шапки таблицы** (по тексту в HTML, не по номерам ячеек) — как fallback без LLM | `app/Services/SmetaParserService.php` (сам класс отбросить) | **только идею** |
| **Filament UX** — комбинированный фильтр расширений (Select+свободный ввод), `poll('30s')` + индикация устаревшего heartbeat + человекочитаемый курсор, лог прогона как RelationManager | `app/Filament/Resources/{LinkAttachment,CrawlerRun,PromptRule}/*` | **паттерны** → под новые ресурсы v2 (`05`) |

## 2. Данные — потерянные колонки уже добавлены в 01

Из gap-анализа (`v1-gap.md`) в `01-database.md` внесены:
- `ai_analyses`: `cache_id/cache_expires_at/rules_hash` (TTL-проверка кэша без API) + `progress jsonb` (резюм многошаговой экстракции);
- `prc_procurements`: `okved_code`/`okved_additional` (ОКВЭД основной+доп) + error-блок стадии детального слоя (`detail_attempts/…/detail_failed`);
- `est_totals.parent_id` (иерархия итогов «в т.ч. НДС»); `est_resources.resource_type += overhead` (НР/СП на ресурсе);
- `plt_regions` (справочник регионов площадки); `crw_ignored_items` (denylist конкретных закупок с `reason_category`); `crw_daily_stats` (посуточная аналитика по стадиям — бывш. `plt_crawler_analytics`);
- сценарии в чек-листе §11: инвалидация кэша по `rules_hash`, резюм прерванной экстракции, ретрай упавшей детальной карточки.

Прочие полезные v1-паттерны, уже покрытые v2: `content_hash` дедуп (`doc_versions`), `dirty_data`/`attributes jsonb` (сырое неразобранное), `submission_end_at` (→ `bidding_ends_at`, приоритизация очереди), error-блок `next_retry_at/failed_permanently` (канон), lease `locked_by/until`.

## 3. Что осознанно НЕ переносим (в Old остаётся как история)

**Мёртвый/дублирующий код:**
- `app/Services/SmetaParserService.php` — legacy-парсер (голый Guzzle, hardcoded ключ в query, `verify:false`), дублирует `EstimateAnalysisProcessor`, нигде не вызывается. Забрать только идею якорей (§1).
- `app/Dto/Estimates/*` + `app/Enums/Estimates/ItemType.php` — ранняя упрощённая версия схемы сметы (`row_index`+`fields:array`), вытеснена `Schema/*`.
- `app/Process/OrderParsing/OrderParsingProcess.php` — пустой стаб (`run(){}`).
- Битый `App\Process\DocumentAnalysis\Schema\Responses\EstimateFetchItemsResponse` (ссылается на несуществующий namespace).
- Неиспользуемые приватные методы `DocumentProcessor::findExistingBy*`; закомментированный блок в `AbstractCrawlingService::pageProcessing()`.

**Заменено осознанно:**
- `geo_countries`/`geo_locations` (ФИАС/КЛАДР + geometry) — тяжёлый геосправочник; v2 = денормализованный `region_code` + отложенный ГАР (`01` §12).
- `plt_document_analysis_logs` (построчный лог сессии) — консолидирован в `ai_llm_calls` (тех-след вызова) + `sys_operation_logs` (общий журнал).
- `plt_platform_proxies` pivot — избыточен, привязка через `crw_proxies.targets jsonb`.
- `ord_lot_infos` со строковыми числами — техдолг не наследуем (`est_items` типизирован).

**Переработать (не 1:1):**
- `EstimateAnalysisProcessor::run()` — заглушка с комментарием `// ... твой код запросов ...`; реальную многошаговую логику писать заново (`03` §3.5, `04` §4).
- `Link::recomputeStatusFromAnalyses()` — N+1 при пачке вложений; в v2 батчить.
- `EisCrawlingService::isSplitProcess` (mutable-флаг на инстансе) — вынести в явный параметр/Runner.
- Модели `ord_*` — добавить FK на закупку, enum статуса `Order`, решить `PurchaseObject` (из извещения) vs `EstimateItem` (из сметы) — в v1 не связаны.

## 4. Домен заказов/смет — важное предупреждение

Весь домен `Order/Lot/PurchaseObject/Customer/LotInfo` (`ord_*`) в v1 **не заполняется** (пустой `OrderParsingProcess`, нет фабрик/тестов/связи с crawling). Из него переносим **не код, а**: (1) целевую схему сметы `Schema/*` → `est_*`; (2) поля-ориентиры `ord_*` → уже разложены по `prc_procurements`/`prc_items`/`org_organizations` в v2 (заказчик по ИНН, `edition_number`→`source_edition`, даты жизненного цикла). Реализацию извлечения и связывания писать с нуля.

## 5. Процедура перехода

1. `git mv` текущего кода приложения в `Old/` (или отдельная ветка-архив) — как read-only справочник.
2. Развернуть v2-каркас (`06` фаза 0), применить схему (`01`).
3. При реализации каждой стадии открывать соответствующий v1-файл из §1 как образец, переносить паттерн, а не копировать вёрстку/заглушки.
4. Мёртвый код из §3 в v2 не воспроизводить.

## 6. Файлы-ориентиры v1 (быстрый переход)

`app/Process/Crawling/Run/CrawlerRunManager.php` · `Cursor/CrawlCursor.php` · `AbstractCrawlingService.php` · `app/Process/Pipeline/PipelineDispatcher.php` · `app/Process/*/Jobs/*Job.php` · `app/Models/DocumentAnalysis/DocumentAnalysis.php` (lease+transitionStatus+recordFailure) · `app/Repositories/Crawling/CrawlingRepository.php` · `Prompt/PromptRuleRepository.php` · `app/Services/GeminiService.php` · `app/Services/Http/Proxy/*` · `app/Process/EstimateAnalysis/Schema/*` · `app/Enums/AnalysisStatus.php` · `Modules/Eis/app/Crawling/EisCrawlingService.php` (режимы/дробление — паттерны, не разметку) · Filament `{LinkAttachment,CrawlerRun,PromptRule}/*`.
