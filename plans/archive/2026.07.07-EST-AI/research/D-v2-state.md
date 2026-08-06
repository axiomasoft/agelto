# Отчёт D — текущее состояние v2 (пайплайн документов и AI)

## Расхождения с CLAUDE.md
- docs/03_Dev/*, docs/02_Workflow/* НЕ СУЩЕСТВУЮТ. Актуальные ТЗ: docs/specs/v2/{01-database.md, 03-document-pipeline.md, 04-llm-providers.md, 06-roadmap.md}; разбор истории — docs-private/.
- ТЗ оперирует doc_*/ai_*/est_*, но реальные таблицы — prc_* (имена индексов/FK сохранили доменные префиксы, напр. doc_documents_procurement_external_id_unique).
- Estimate — НЕ DTO, а Eloquent-модели (Estimate+Section+Item+Resource+Total).
- Активного плана нет; пайплайн документов/AI = Фазы 4–7 роадмапа, не начаты.

## Реализовано (граница — ровно до статуса downloaded)
- app/Services/Procurement/Modes/DetailAnalysisProcessor.php — оркестратор детальной карточки; run() :53; downloadPendingDocuments() :98
- app/Services/Procurement/DocumentIndexer.php — DTO→prc_documents, upsert (procurement_id, external_id), removed_at + событие document_removed; index() :31
- app/Services/Procurement/DocumentDownloader.php — скачивание, sha256 контент-адресация, дедуп unique(document_id, content_hash), события added/replaced, error-блок, backoff; download() :35
- app/Services/Procurement/PipelineDispatcher.php — ЗАГЛУШКА-мост: только Log::info по downloaded (:25,:34), «статусом владеет будущий consumer Фазы 4»
- app/Repositories/Procurement/DocumentStoreRepository.php — единственная точка персистентности documents/versions
- app/Console/Commands/Procurement/FetchDetails.php :26
- app/Services/Procurement/Eis/Card/DocumentsParser.php — HTML→DocumentData[]

## Модели без бизнес-логики (схема/casts/relations/factory)
- Document (scope pendingRetry :61, uuid7), DocumentVersion (parent_version_id, prepared_storage_path в fillable — кода заполнения нет)
- Ai: Analysis (:23 fillable lease locked_by/locked_until, cache_id/cache_expires_at/rules_hash, progress, scope pendingRetry :83), LlmCall, Provider, AiRoute, Prompt, DatasetExample, EvalRun, EvalResult — НИГДЕ в сервисах не используются, только тесты
- Estimate: Estimate, Section, Item, Resource, Total

## Enums (app/Enums/Procurement/) — готовы
DocumentStatus: discovered/downloaded/prepared/screened/extracted/ignored/failed
AnalysisStep: screening/extraction · AnalysisStatus: pending/running/succeeded/failed/skipped
LlmPurpose: screening/extract_passport/extract_rows/extract_totals/eval/shadow · ResourceType

## Filament
Только scaffolds: app/Filament/Resources/Procurement/Ai/{Prompts,Providers,AiRoutes,DatasetExamples}/ (группа «AI-анализ»). Document/Estimate/Analysis ресурсов нет.

## Схема prc_* (точно)
prc_documents (100018): id, uuid(uniq), procurement_id(FK), external_id(text,null), title, kind(null), source_url(null), status(text), first_seen_revision_id, removed_at, attempts(0), last_error, last_error_at, next_retry_at, failed_permanently(false), timestamps. Partial unique (procurement_id, external_id) WHERE external_id IS NOT NULL.
prc_document_versions (100019): id, document_id(FK), content_hash(sha256), size_bytes, filename, extension, mime, storage_path, downloaded_at, parent_version_id(FK self, nullOnDelete), prepared_storage_path(null), timestamps. Unique (document_id, content_hash), index content_hash.
prc_ai_analyses (100025): id, uuid, document_version_id(FK), step, status, document_type(null), result(jsonb), provider, model, locked_by, locked_until, attempts, last_error, last_error_at, next_retry_at, failed_permanently, started_at, finished_at, cache_id, cache_expires_at, rules_hash, progress(jsonb), timestamps. UNIQUE (document_version_id, step).
prc_ai_llm_calls (100026, партиц. RANGE по called_at, premake 3 мес): id, analysis_id(FK,null), purpose, provider, model, prompt_version_set(jsonb), cache_id, request_ref, response_ref, prompt_tokens, completion_tokens, cached_tokens, total_tokens, cost_micros(bigint), success, error, duration_ms, called_at. PK (id, called_at).
prc_ai_providers (100027): code(uniq), driver, base_url, options(jsonb), is_active.
prc_ai_routes (100028): document_type, purpose, provider_id(FK), model, options(jsonb), priority(0), is_active, shadow(bool). Unique (document_type, purpose, priority).
prc_ai_prompts (100029): key, step, content, version, priority(0), is_active. Unique (key, version).
prc_ai_dataset_examples (100030): document_version_id(FK), task, gold(jsonb), gold_ref, source(llm/manual/corrected), verified_by(FK), verified_at, version(1).
prc_ai_eval_runs/results (100031/32): metrics jsonb (precision/recall/f1/row_coverage).
prc_estimates (100033): id, uuid, analysis_id(FK), document_version_id(FK), procurement_id(FK), title, estimate_number, total_amount(18,2), currency(char3), base_year(int), price_level(text), passport(jsonb), raw_result(jsonb), raw_result_ref (payload>~1МБ), rows_expected, rows_extracted, validation_status(text).
prc_estimate_sections (100034): estimate_id, parent_id(self), position, title, section_number.
prc_estimate_items (100035): estimate_id, section_id, row_index, code(шифр), name, unit, quantity(18,6), unit_price(18,2), total_price(18,2), attributes(jsonb коэфф/индексы). UNIQUE (estimate_id, row_index).
prc_estimate_resources (100036): item_id, resource_type(labor/machine/material/overhead/other), code, name, unit, quantity, price.
prc_estimate_totals (100037): estimate_id, parent_id(self), position, label, kind(НР/СП/НДС), amount.

## Гэпы (прикладной слой отсутствует полностью)
- Нет ни одного Job, нет app/Jobs; Horizon стоит (^5.47, config/horizon.php), очередей пайплайна нет. Ожидаемые: DownloadDocumentVersionJob, PrepareDocumentJob, ScreeningJob, EstimateExtractionJob.
- Нет LLM-слоя: app/Services/Llm/ нет; Prism/laravel/ai/gemini-SDK не установлены. Нет контрактов LlmProvider, SupportsContextCaching, DTO LlmPrompt/LlmOptions/LlmResponse/JsonSchema, адаптеров.
- Нет подготовки (§3.3 ТЗ): распаковка ZIP/RAR/7z (дочерние documents с parent_version_id), Excel→PDF, vision-ветка.
- Нет скрининга (§3.4), нет глубокого парсинга смет (§3.5: row-range оркестратор, structured output, merge, валидация rows_expected vs rows_extracted, точечный re-run).
- Нет промт-резолвера (Global→GlobalStep→step, приоритет), нет «схема как код», нет rules_hash-инвалидации в коде.
- Нет context caching/учёта стоимости в коде (cache_id/prompt_version_set/cost_micros — только колонки).
- Нет eval/shadow-раннера, нет резолвера ai_routes.
- FSM документа не двигается дальше downloaded.
- Тесты AI/смет — только schema-level: tests/Feature/Procurement/{AiAnalysisTest,EstimateTest}.php, DocumentVersioningTest.php. Рабочие: tests/Feature/Document/{DocumentIndexerTest,DocumentDownloaderTest,PipelineDispatcherTest}.php, tests/Feature/Crawler/DetailAnalysisProcessorTest.php.

## Конвенции v2 (план обязан соблюдать)
- Плоское app/**, домен Procurement, подпапки Models/Procurement/{Document,Ai,Estimate,Crawler,Platform}/, Services/Procurement/..., Dto/, Enums/, Repositories/. LLM-адаптеры → app/Services/Llm/.
- Таблицы prc_*; $table в модели; миграции берут (new Model)->getTable().
- Сервисы final readonly, конструкторная инъекция, именованные аргументы.
- Персистентность только через Repository (сервис — что, репозиторий — как).
- declare(strict_types=1), PHPDoc со ссылками на разделы ТЗ, русские комментарии.
- Единый error-блок: attempts/last_error/last_error_at/next_retry_at/failed_permanently + scope pendingRetry(); backoff now()->addMinutes($backoff*$attempts), лимиты config/crawler.php.
- Идемпотентность unique-констрейнтами + lease (locked_by/locked_until).
- UUID7 через booted() creating.
- События через ProcurementStoreRepository::storeEvent(eventType:, entityRef:).
- Object storage: диск config('crawler.documents_disk'|'raw_disk'), путь documents/{hash[0:2]}/{hash}; payload>~1МБ — *_ref.
- Партиционирование RANGE + premake (config/partitioning.php, MaintainPartitions).
- Filament v5: Resources/<Domain>/<Entity>/ c Pages/Schemas/Tables, группы по-русски.
- Тесты Pest, RefreshDatabase, pgsql agelto_test, #[UseFactory]; вложенные unique-конфликты — свой DB::transaction() (savepoint).
- Роадмап: Фаза 4 (подготовка+скрининг, Horizon/lease/retry) → 5 (извлечение смет) → 6 (провайдеры/eval/shadow) → 7 (Filament). Не прыгать в LLM-провайдеров раньше среза подготовки/скрининга.
