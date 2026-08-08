# ТЗ v2 · 07 — Карта переноса из v1 (что забрать, что в Old)

> Цель: дать возможность спокойно переместить текущий код в `Old/` и строить v2 с нуля, зная, какие удачные решения и данные из v1 переносятся и где их искать. Основано на аудите реального кода — `research/tz-v2/v1-columns.md`, `v1-solutions.md`, `v1-domain.md`, `v1-gap.md`.

## 0. Как использовать

1. Текущий код переезжает в `Old/` (справочник, не удаляется).
2. v2 строится по `01`–`06`; при реализации сверяться с этим файлом — он указывает конкретные v1-файлы-ориентиры для каждого решения.
3. Ссылки на v1-файлы даны от корня проекта.

## 1. ТОП идей v1 — переносим (с пометкой)

| Идея | v1-файл | Перенос |
|---|---|---|
| **CrawlerRunManager** — жизненный цикл прогона: partial-unique «один running на crawler» (в БД, не в коде), heartbeat, recovery оборванных, иерархический курсор возобновления | `app/Process/Crawling/Run/CrawlerRunManager.php`, `Cursor/CrawlCursor.php` | **как есть** → `prc_crawler_runs` |
| **Иерархический курсор** — `price_from/to` + `price_stack` (стек диапазонов бинарного дробления) + `day_from/to` + `cursor_offset` | `plt_crawler_runs` миграция | **как есть** → `prc_crawler_runs.cursor jsonb` (`02` §4.2) |
| **Partial-unique инвариант в БД** (`WHERE status='running'`) через сырой `DB::statement` | миграция `plt_crawler_runs` | **как есть** |
| **AbstractCrawlingService** — Template Method: `start()` с try/finally, специфика площадки только в хуках; парсинг вложений только при изменении `platform_updated_at` | `app/Process/Crawling/AbstractCrawlingService.php` | **архитектуру как есть**, парсинг вёрстки переписать под текущую разметку |
| **Идемпотентная джоба** — guard по терминальному статусу → `acquireLease()` → try/finally `releaseLease()` → `failed()` = `recordFailure()` + `PipelineAlert` + `tags()` для Horizon | `app/Process/*/Jobs/*Job.php` | **как эталон** для всех queued-джоб v2 (`03` §4) |
| **PipelineDispatcher** — явный мост между стадиями (выстрадан на инциденте рассинхрона) | `app/Process/Pipeline/PipelineDispatcher.php` | **как есть** (`02` §8, `03` §4) |
| **Промпты из БД + версии** — `PromptRule` (key/step/priority/version/is_active), редактор в админке | `app/Repositories/Prompt/PromptRuleRepository.php`, Filament `PromptRule/*` | **как есть** → `llm_prompts` (`04` §3.2) |
| **«Схема как код»** — DTO-схема чистится regex и идёт в system instruction | `app/Services/PromptService.php::getCleanSchema()` | **как есть** |
| **Gemini context caching + инвалидация** по `rules_hash`/`file_fingerprint`/`expires_at`, учёт токенов | `app/Services/GeminiService.php` | **идею как есть** → `prc_ai_analyses.cache_*`/`rules_hash` (`04` §2.1) |
| **Прокси Strategy** — Factory + Driver interface (Config/Database), health-tracking (`fails_count`, авто-деактивация при MAX_FAILS) | `app/Services/Http/Proxy/*`, `Modules/Sys/.../ProxyRepository` | **как есть** → `prc_proxies` |
| **Статус-enum с поведением** — `stage()` (retry с нужной точки), `isTerminal/isFailed/isInProgress/isAwaiting` | `app/Enums/AnalysisStatus.php`, `Crawler/CrawlerRunStatus.php` | **как есть** (канон статус-enum'ов) |
| **Репозиторий по канону** — `findLink/getPendingLinks/storeOrUpdateLink`, read/store split | `app/Repositories/Crawling/CrawlingRepository.php` | **как есть** |
| **Целевая схема сметы** — дерево `spatie/laravel-data` DTO (Passport/Type/Target/Header+Column с алиасами/Section/Item/Resource/Modifier/Footer) с русскими докблоками | `app/Process/EstimateAnalysis/Schema/*` | **DTO забрать как есть** → `est_*` таблицы; extraction-код писать заново |
| **Якорный поиск шапки таблицы** (по тексту в HTML, не по номерам ячеек) — как fallback без LLM | `app/Services/SmetaParserService.php` (сам класс отбросить) | **только идею** |
| **Filament UX** — комбинированный фильтр расширений (Select+свободный ввод), `poll('30s')` + индикация устаревшего heartbeat + человекочитаемый курсор, лог прогона как RelationManager | `app/Filament/Resources/{LinkAttachment,CrawlerRun,PromptRule}/*` | **паттерны** → под новые ресурсы v2 (`05`) |

## 2. Данные — потерянные колонки уже добавлены в 01

Из gap-анализа (`v1-gap.md`) в `01-database.md` внесены:
- `prc_ai_analyses`: `cache_id/cache_expires_at/rules_hash` (TTL-проверка кэша без API) + `progress jsonb` (резюм многошаговой экстракции);
- `prc_procurements`: `okved_code`/`okved_additional` (ОКВЭД основной+доп) + error-блок стадии детального слоя (`detail_attempts/…/detail_failed`);
- `prc_estimate_totals.parent_id` (иерархия итогов «в т.ч. НДС»); `prc_estimate_resources.resource_type += overhead` (НР/СП на ресурсе);
- `prc_regions` (справочник регионов площадки); `prc_ignored_items` (denylist конкретных закупок с `reason_category`); `prc_daily_stats` (посуточная аналитика по стадиям — бывш. `plt_crawler_analytics`);
- сценарии в чек-листе §11: инвалидация кэша по `rules_hash`, резюм прерванной экстракции, ретрай упавшей детальной карточки.

> Историческая строка сохранена. `prc_regions` и `prc_daily_stats` отменены 2026-08-08 по
> [D82](../../../plans/2026.08.06-CRAWL-HARDEN/decisions/D82-legacy.md); `prc_ignored_items`
> остаётся действующим denylist. Тем же решением отменена `prc_ignore_rules`.

Прочие полезные v1-паттерны, уже покрытые v2: `content_hash` дедуп (`prc_document_versions`), `dirty_data`/`attributes jsonb` (сырое неразобранное), `submission_end_at` (→ `bidding_ends_at`, приоритизация очереди), error-блок `next_retry_at/failed_permanently` (канон), lease `locked_by/until`.

## 3. Что осознанно НЕ переносим (в Old остаётся как история)

**Мёртвый/дублирующий код:**
- `app/Services/SmetaParserService.php` — legacy-парсер (голый Guzzle, hardcoded ключ в query, `verify:false`), дублирует `EstimateAnalysisProcessor`, нигде не вызывается. Забрать только идею якорей (§1).
- `app/Dto/Estimates/*` + `app/Enums/Estimates/ItemType.php` — ранняя упрощённая версия схемы сметы (`row_index`+`fields:array`), вытеснена `Schema/*`.
- `app/Process/OrderParsing/OrderParsingProcess.php` — пустой стаб (`run(){}`).
- Битый `App\Process\DocumentAnalysis\Schema\Responses\EstimateFetchItemsResponse` (ссылается на несуществующий namespace).
- Неиспользуемые приватные методы `DocumentProcessor::findExistingBy*`; закомментированный блок в `AbstractCrawlingService::pageProcessing()`.

**Заменено осознанно:**
- `geo_countries`/`geo_locations` (ФИАС/КЛАДР + geometry) — тяжёлый геосправочник; v2 = денормализованный `region_code` + отложенный ГАР (`01` §12).
- `plt_document_analysis_logs` (построчный лог сессии) — консолидирован в `llm_calls` (тех-след вызова) + `prc_operation_logs` (общий журнал).
- `plt_platform_proxies` pivot — избыточен, привязка через `prc_proxies.targets jsonb`.
- `ord_lot_infos` со строковыми числами — техдолг не наследуем (`prc_estimate_items` типизирован).

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

## 7. EST-AI: чек-лист удаления Old/

Формальный список-предусловие для P11.1. Вердикт по каждому артефакту: **перенесено** (уже в v2-коде, до/вне EST-AI, обычно V2-CRAWL) / **переносится: Pn.m** (этим планом) / **выброшено** (осознанно не наследуем) / **НЕ ПОКРЫТО** (флаг владельцу — ни один item плана артефакт не адресует). Источники вердиктов: research/A (процессы), research/B (промты/Gemini), research/C (поля смет), research/I (диф БД).

### 7.1 Old/app/** по разделам

| Артефакт | Вердикт | Куда/почему |
|---|---|---|
| `Process/Crawling/**` (AbstractCrawlingService, Cursor/CrawlCursor, Run/CrawlerRunManager) | перенесено | v2-краулинг реализован до статуса `downloaded` (`app/Services/Procurement/*`, plan.md §1) — вне EST-AI-скоупа |
| `Process/OrderParsing/OrderParsingProcess.php` | выброшено | пустой стаб `run(){}` (07 §3), не заполнялся никогда |
| `Process/DocumentPrepare/DocumentProcessor.php` | переносится: P4.1/P4.2 | паттерн выбора media (`loadMedia`+стратегии), guard `hasMedia(PREPARED)`; мёртвые `findExistingByUrl/findExistingByMedia` (§3) не наследуем |
| `Process/DocumentPrepare/Jobs/PrepareDocumentJob.php` | переносится: P4.1 | эталон идемпотентной джобы (status-guard→lease→try/finally) — уже канон в 07 §1, конкретизация в P4.1 |
| `Process/DocumentPrepare/Strategies/PreparationStrategy.php` (интерфейс) | переносится: P4.2 | `canProcess/process` — контракт стратегии подготовки |
| `Process/DocumentPrepare/Strategies/ExcelStrategy.php` | переносится: P4.2 | landscape+`fitToWidth(1)/fitToHeight(0)` (иначе смета режется по столбцам), size/rows-гейт добавляется по D35 |
| `Process/DocumentPrepare/Strategies/ArchiveStrategy.php` | переносится: P4.2 | идемпотентность: пропуск directories/dot-файлов, `firstOrCreate(parent_id,name)`, медиа только `wasRecentlyCreated` |
| `Process/DocumentAnalysis/PrimaryAnalysisProcessor.php` | переработать не 1:1: P4.3/P5.1/P5.2 | точка входа `run()`+`Bus::chain`, ветка `document_type!==Estimate→Ignored`; заглушка `if(!success)` (§3) не наследуется |
| `Process/DocumentAnalysis/Jobs/PrimaryAnalysisJob.php` | переносится: P5.1 | status-guard в начале job, timeout/tries |
| `Process/DocumentAnalysis/Schema/**` (DocumentType, PrimaryAnalysis, Responses) | зафиксировано: P1.2 | контракт уже перенесён в спеку `08-estimate-extraction.md` (screening) |
| `Process/EstimateAnalysis/EstimateAnalysisProcessor.php` | переработать не 1:1: P6.1–P6.4 | заглушка — реализует только `fetch_items`, `preview`/`fetch_info` не вызывались (research/A,B); замысел (чанки, дерево запросов, поле `state`) фиксирован в спеке 08 §4, код пишется заново |
| `Process/EstimateAnalysis/Jobs/EstimateAnalysisJob.php` | переносится: P6.1 | status-guard, dispatch `afterCommit()` вне chain |
| `Process/EstimateAnalysis/Schema/*` (~15 DTO: Passport/Header/Item/Resource/Modifier/Footer, `#[Alias]`) | зафиксировано: P1.2 | дословно перенесено в спеку 08 §1–§2 (инвентарь полей + Alias-словари); код-эквивалент DTO — P2.2 |
| `Process/Pipeline/PipelineDispatcher.php` | переносится: P4.3 | явный мост стадия1→2 (07 §1: «выстрадан на инциденте рассинхрона»); в v1 мост был только поллером `everyMinute` (латентность, research/A слабые стороны) — v2 обязан не наследовать эту задержку |
| `Repositories/Prompt/PromptRuleRepository.php` (`systemInstructionsFor`) | переносится: P3.3 | композиция `[global, step-global, step]`, `priority ASC`, схемы+Responses в конец |
| `Repositories/Crawling/CrawlingRepository.php` | перенесено | канон read/store уже применён в v2-репозиториях краулинга, вне EST-AI |
| `Models/DocumentAnalysis/DocumentAnalysis.php` (`acquireLease/releaseLease`, `transitionStatus`, `recordFailure`) | переносится: P4.1 | lease TTL 600с (атомарный `UPDATE WHERE locked_until IS NULL OR < now()`, try/finally); backoff `min(2**n,240)` мин + `failed_permanently` при `attempts>=5` — общий канон error-блока уже есть в v2 (07 §2), P4.1 применяет его к `Document`/`Analysis` |
| `Models/DocumentAnalysis/DocumentAnalysis.php` (`isCacheValid`) | переносится: P3.2 | `fingerprint && rules_hash=sha1(...) && TTL` — инвалидация кэша |
| `Models/Prompt/PromptRule.php` | переносится: P8.1 | поля `key/step/priority/version/is_active` уже в `llm_prompts` (D17); сидирование — P8.1 |
| `Models/Link.php` (`recomputeStatusFromAnalyses`) | переработать не 1:1: P4.3 | N+1 (research/A слабые стороны) — идея агрегации статуса документа по дочерним анализам переносится батчево, не построчно |
| `Dto/Estimates/**` + `Enums/Estimates/ItemType.php` (legacy динамическая схема) | выброшено (осознанно) | вытеснена structured-схемой (07 §3), решение уже зафиксировано в спеке 08 §1 (P1.2) с обоснованием отказа |
| `Dto/Prompt/Step.php` | переносится с расширением: P3.3 | `PromptStep` — 8 значений (+`extraction:discovery`, D37) |
| `Enums/AnalysisStatus.php` (`stage()`, `isTerminal/isFailed/isInProgress/isAwaiting`) | переносится идея: P4.1 | «статус-enum с поведением» — канон уже зафиксирован в 07 §1; конкретный `DocumentStatus`/`PipelineStage` v2 — P4.1, включая новый терминал `Unprocessable` (D32/D38) |
| `Enums/CrawlerRunStatus.php` | перенесено | краулинг вне EST-AI |
| `Enums/ProcessStage.php` | выброшено | декоративный enum (research/A), дублирует `AnalysisStatus::stage()` без добавленной ценности |
| `Enums/EstimateType.php`/`EstimateTarget.php`/`EstimateResourceType.php` | зафиксировано: P1.2 | значения дословно в спеке 08 §2–§3; код-эквивалент — P2.2/P2.3 |
| `Attributes/Alias.php` (`#[Alias(aliases:[...])]`) | зафиксировано идея: P1.2, код: P2.2 | матчинг русских заголовков колонок/типов ресурсов — словари дословно в спеке 08 §2; атрибут не обязателен 1:1 (можно константой/enum-мапой), решает P2.2 |
| `Services/GeminiService.php` | переработано не 1:1: P3.2 | транспорт заменён на Prism/`LlmProvider` (D8/D14); идея кэша (ttl=6h, `isCacheValid`, `deleteCache()` вручную), `file-ID=last(explode('/',name))`, `usageFromResponse` null-safe, `uploadFile mimeType` по умолчанию `application/pdf` без реального mime (баг, research/C — исправляется в P3.2, а не наследуется) |
| `Services/GeminiService.php` (кэш на `gemini-1.5-flash`, генерация на `gemini-2.0-flash`) | выброшено (D4) | баг рассинхрона моделей v1 (research/B) — v2 роутит явно через `config/ai.php`/`llm_routes`, единая модель на шаг |
| `Services/PromptService.php::getCleanSchema()` | переносится: P3.3 | «схема как код»: regex-очистка PHP-исходника класса в system instruction — приём сохраняется (D2) |
| `Services/SmetaParserService.php` | выброшено | мёртвый legacy-парсер (голый Guzzle, hardcoded ключ, `verify:false`), нигде не вызывается (07 §3); только идея якорного поиска шапки таблицы (fallback без LLM) — LLM-first в EST-AI, идея не в скоупе плана, зафиксирована здесь как возможный будущий рычаг |
| `Services/Http/Proxy/*` | перенесено | `prc_proxies`, вне EST-AI |
| `Services/Http/Proxy/*` (обязательный прокси для Gemini-вызовов из РФ, timeout 300с, research/B §5) | **НЕ ПОКРЫТО** | ни один item EST-AI не адресует исходящий прокси для LLM-вызовов (`LlmProvider`/Prism→Gemini); см. Pending Work этого item |
| `MediaLibrary/Support/PathGenerator.php`, `MediaLibrary/Traits/WithMediaHelpers.php` | перенесено | media/storage-слой v2 уже своя реализация (`storage_path`, 07 §2), custom PathGenerator v1 не нужен под новую структуру каталогов |
| `MediaLibrary/DownloadHelper.php` | перенесено | краулинг-скачивание вне EST-AI |
| `Filament/Resources/{LinkAttachment,CrawlerRun,PromptRule}/*` | перенесено (паттерны) | UX-паттерны (комбо-фильтр расширений, `poll('30s')`+heartbeat, лог прогона как RelationManager) — целевые Filament-ресурсы v2 вне EST-AI (за исключением `PromptRule`→P8.1 use как референс) |
| `Console/Commands/PipelineDispatchPrepareCommand.php` | переработано не 1:1: P4.3 | v1-мост поллером `everyMinute` — v2 не обязан наследовать латентность (см. PipelineDispatcher выше) |
| `Console/Commands/PipelineRetryFailedLinksCommand.php` | перенесено | краулинг-retry вне EST-AI; паттерн атомарного claim `UPDATE WHERE status=failedStatus` — общий канон, уже в 07 §1 |
| `Console/Commands/PipelineStatusCommand.php` | переносится идея: P7.3 | пайплайн-обзор статусов — аналог для EST-AI-стадий |
| `Console/Commands/PipelineStuckCheckCommand.php` | переносится: P7.3 | зависшие анализы/ссылки/краулеры, backlog моста, PostgreSQL/Redis/queue-depth/`/dev/shm`, алерты; `HEARTBEAT_STALE_MINUTES=15`, `queue-depth-limit=5000`, `/dev/shm<32MB`→Chromium падает — конкретные пороги переносятся как знание в P7.3 |
| `Console/Commands/CheckEnvironment.php`, `EnsureAdminUserCommand.php` | перенесено | инфраструктурные команды, вне EST-AI-домена |
| `Actions/Fortify/*`, `Concerns/{PasswordValidationRules,ProfileValidationRules}.php` | перенесено | auth-слой, не EST-AI |
| `Notifications/{PipelineAlert,PipelineAlertNotification,PipelineFailedNotification}.php` | переносится идея: P7.3/P7.5 | алертинг воспроизводится через GlitchTip+Pulse+scheduled SQL-алерты (D25), не 1:1 Telegram-канал |
| `Interfaces/Crawling/{BrowserClient,PlatformCrawling}.php` | перенесено | готовые DI-швы, вне EST-AI |

### 7.2 Guards/edge-cases (research/A §5, B §5, C §5) → item плана

| Guard/знание | Источник | Куда |
|---|---|---|
| Lease-механизм TTL 600с, атомарный UPDATE, try/finally | A§5, C§5 | P4.1 |
| Композиция промтов `[global→step-global→step]`, priority ASC, схемы+Responses в конец | B (поток) | P3.3 |
| `#[Alias]`-словари (заголовки колонок EstimateHeader, типы EstimateResourceType) | B§5, C§5 | P2.2 (текст дословно уже в спеке 08 §2, P1.2) |
| Excel: landscape+`fitToWidth(1)/fitToHeight(0)` | A§5 | P4.2 |
| Archive: пропуск directories/dot-файлов, `wasRecentlyCreated`, `firstOrCreate(parent_id,name)` | A§5 | P4.2 |
| Буфер `incomplete_items` (split_by_page_end / orphan_numbers_at_page_start) | tmp/agelto2.md, уже в спеке 08 §4б (P1.2) | P6.2 |
| `ignore_rules` «Поставка продуктов питания» (`config('platform.estimate.ignore_rules')`) | C§5 | P5.2 |
| Учёт токенов (prompt/completion/total/cached), `rules_hash=sha1(instructions)`, `cache_file_id`, `isCacheValid` | A§5, B§5 | P3.2 |
| `file_fingerprint`-инвалидация кэша при изменении файла | C§5 | P7.1 |
| `PipelineStuckCheck`-знания: `/dev/shm<32MB`, `HEARTBEAT_STALE_MINUTES=15`, `queue-depth-limit=5000` | A§5 | P7.3 |
| Баг v1 «кэш на `gemini-1.5-flash`, генерация на `gemini-2.0-flash`» | B§5 | выброшено (D4) |
| `uploadFile()` mimeType=`application/pdf` для всех файлов без реального mime | C§5 | исправляется в P3.2 |
| Финализация стадии сметы обязательна (иначе вечный `EstimateAnalyzing`); статус ставится в воркере, не до commit | A§5 | P6.1/P6.4 (терминальный финиш + self-requeue, D31/D39) |
| Дедуп `findExistingByFileSignature` (имя+размер) | B§5 | P4.1/P4.2 (идемпотентность подготовки) |
| `getDocumentForAnalysis()`: prepared-версия приоритетнее source | C§5 | P4.2/P4.3 (мост выбирает подготовленную версию) |
| `generator` (сметная программа, напр. Гранд-смета) в паспорте сметы | C§5 | зафиксировано: P1.2 (спека 08, поле паспорта) |
| Порядок промта детерминирован (БД→PHP-схемы→Responses последними) | B§5 | P3.3 |
| Прокси для Gemini-вызовов из РФ (timeout 300с) | B§5 | **НЕ ПОКРЫТО** — см. §7.1 и Pending Work |
| Остальные guard'ы краулинга (`isStale`, `syncAttachments`, `checkVpnConstraint`, `needsDocuments`, `keep_state_on_crash`, антибот `usleep`, `parseExtensionFromName`) | A§5 | перенесено ранее (V2-CRAWL), вне EST-AI |

### 7.3 Диф БД v1→v2 (транскрипция вердиктов research/I)

Колоночная сверка Old-миграций (24+2) против v2 (47) построчно против research/tz-v2/v1-columns.md — **доменных потерь нет**. Группы: (1) заказчики `ord_customers→org_organizations` — полный перенос, v2 богаче; (2) закупки `ord_orders+plt_crawler_links→prc_procurements(+registries/revisions/fetches)` — полный перенос; (3) лоты/позиции `ord_lots/ord_purchase_objects/ord_lot_infos→prc_lots/prc_items` — перенос, строковые числа `lot_infos` осознанно не наследуются (техдолг); (4) **сметы — в v1-БД смет не было** (только DTO-схема в коде), v2 материализует и превосходит (`passport`/`raw_result`/`validation_status`/`rows_expected`); (5) документы/AI `attachments+document_analysis→documents+versions+prc_ai_analyses`, `plt_prompt_rules→llm_prompts` — точный перенос; (6) краулинг — перенесено ранее (partial-unique `WHERE status='running'` воспроизведён); (7) гео/media/users — осознанно не переносится (region_code вместо ФИАС/КЛАДР).

**Потери (c) — все инфраструктурные, не доменные:** `sys_logs.ip_address` (мусор); `plt_document_analysis.options` json (почти не заполнялось); `plt_crawlers.state` json (ушло в `runs.cursor`); `sys_proxies.last_error` снимок (покрыт `prc_proxy_checks`). Ни один пункт не требует item плана — фиксируются здесь как закрытые (low, без переноса).

**Гейты перед `git rm Old/` (обязательны в P11.1, НЕ закрываются этим item'ом):**
- `Old/database/database.sqlite` (86 KB, НЕ в git) — может содержать реально собранные dev-данные; ПРОВЕРИТЬ содержимое перед удалением.
- `Old/database/seeders/data/locations.zip` (3.6 MB, в git) — датасет ФИАС/КЛАДР; гео отброшено, но сохранить копию (имена регионов по `region_code`).
- `Old/database/seeders/data/countries.zip` — тривиально, копия не обязательна.

### 7.4 Old/docs/**

| Раздел | Вердикт | Куда |
|---|---|---|
| `03_Dev/LLM_Strategy.md` §10 (тексты промтов, единственный источник) | зафиксировано: P1.1 (EMPTY.md + решение владельца), P1.2 (спека 08 §6) | наполнение боевых текстов — отдельный будущий шаг владельца (P8) |
| `03_Dev/{Architecture_Agelto,Database_Schema,Procurement_Lifecycle,Dev_Status}.md`, `dev/*`, `server/*`, `ai/*` | выброшено (устарело) | описывают v1-архитектуру/схему; актуальная документация — `docs/03_Dev/*` v2, новая структура каркаса — P10 (D19/D20) |
| `00_Ideas/*`, `01_Business/*` (монетизация, конкурентный анализ, финмодель, BRD) | выброшено из EST-AI-скоупа | бизнес-контент вне доменных знаний пайплайна; актуализация/перенос в новый каркас доков (если нужен) — решение владельца вне EST-AI, не блокирует P11.1 |
| `02_Workflow/{Glossary,Lead_Verification,Onboarding}.md`, `workflow/parsing-process.md`, `business-processes/parsing-process.md` | частично зафиксировано | процессная часть (parsing-process) покрыта research/A + спекой 08; глоссарий/лид-верификация/онбординг — бизнес-домен вне EST-AI, не блокирует удаление |

### 7.5 Уже перенесено ранее (не в скоупе EST-AI)

Краулинг-ядро (`CrawlerRunManager`, `CrawlCursor`, `AbstractCrawlingService`, `PipelineDispatcher`-мост стадии 0→1, proxy-стратегия, статус-enum'ы краулинга, репозиторий-канон, `Interfaces/Crawling/*`) и Filament-панель для краулинг-доменов — реализованы в v2 до статуса документа `downloaded` (plan.md §1, `app/Services/Procurement/DocumentDownloader.php`), предшествующими V2-CORE/V2-CRAWL планами. EST-AI начинает с подготовки документа и далее.
