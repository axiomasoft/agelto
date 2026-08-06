# Отчёт A — процессная организация Old/ (v1)

Пайплайн: краулинг → скачивание вложений → AI-скрининг (Gemini) → извлечение позиций сметы. Организация: «процессы» в `app/Process/**`, оркестрация `Bus::chain` + планировщик + идемпотентные guards.

## Дерево Process/
- Crawling/ — AbstractCrawlingService (ядро), Cursor/CrawlCursor (день+ценовой стек+страница), Run/CrawlerRunManager (start/resume, heartbeat, fail, recover)
- OrderParsing/OrderParsingProcess.php — ПУСТОЙ стаб run(){}
- DocumentPrepare/ — DocumentProcessor (loadMedia + стратегии), Jobs/PrepareDocumentJob (queue platform_document_prepare), Strategies/{PreparationStrategy (canProcess/process), ExcelStrategy (xls→PDF PhpSpreadsheet+Mpdf), ArchiveStrategy (zip→дочерние DocumentAnalysis)}
- DocumentAnalysis/ — PrimaryAnalysisProcessor (run() создаёт запись + Bus::chain), Jobs/PrimaryAnalysisJob (queue platform_primary_analysis), Schema/ (DocumentType, PrimaryAnalysis, *Response DTO)
- EstimateAnalysis/ — EstimateAnalysisProcessor (Gemini context-cache + запрос позиций), Jobs/EstimateAnalysisJob (queue platform_estimate_analysis), Schema/ (~15 DTO: EstimateItem, EstimateSection, EstimatePassport…)
- Pipeline/PipelineDispatcher.php:26 dispatchPreparationForPendingLinks() — мост стадия1→2

Точки входа:
- PrimaryAnalysisProcessor.php:38 run() → DocumentAnalysis (Preparing) + Bus::chain([PrepareDocumentJob, PrimaryAnalysisJob]) (:43-46)
- PrimaryAnalysisProcessor.php:101-103 — если document_type === Estimate → EstimateAnalysisJob dispatch()->afterCommit()
- AbstractCrawlingService.php:53 start() — конкретных подклассов в Old/ НЕТ (Eis-краулер вне архива/утерян)

## Пайплайн-триггеры
1. Crawling → Link PENDING (storeOrUpdateLink), диспатча нет — подхват планировщиком
2. Мост: `app:pipeline:dispatch-prepare` everyMinute (bootstrap/app.php:31) — PENDING-ссылки → PrimaryAnalysisProcessor::run на вложение без анализа → Link PROCESSING
3. Bus::chain: PrepareDocumentJob → PrimaryAnalysisJob; по результату PrimaryIgnored (стоп) или EstimateAnalysisJob afterCommit (НЕ в chain)
4. EstimateAnalysisJob → EstimateAnalysisProcessor::run → EstimateAnalyzed
Событий (Events/Listeners) нет. Обратная связь: DocumentAnalysis::transitionStatus (:141) → Link::recomputeStatusFromAnalyses() (Link.php:79).

## Enum'ы (точные значения)
- CrawlingStatus: pending, processing, completed, failed
- AnalysisStatus: preparing, prepared, primary_analyzing, primary_analyzed, primary_ignored, primary_analysis_failed, estimate_analyzing, estimate_analyzed, estimate_analysis_failed, completed, error. Хелперы: stage() (:62) → PipelineStage; isTerminal()=[completed, primary_ignored]; isFailed()=[error, primary_analysis_failed, estimate_analysis_failed]; isInProgress(); isAwaiting()
- PipelineStage: document_prepare, primary_analysis, estimate_analysis (точка перезапуска retry)
- CrawlerRunStatus: running, paused, completed, failed, cancelled
- ProcessStage: crawling, parsing_docs, doc_analysis, parsing_full (декоративный)
- Schema/DocumentType: estimate, tech_spec, unknown — только estimate продолжает
- Dto/Prompt/Step: global, document_analysis:global, document_analysis:primary, estimate_analysis:global, estimate_analysis:preview, estimate_analysis:fetch_info, estimate_analysis:fetch_items

Очереди: platform_document_prepare, platform_primary_analysis, platform_estimate_analysis, default. Timeouts: Prepare 300, AI-jobs 180/300.
Планировщик (bootstrap/app.php:29-33): horizon:snapshot /5м; delete-old-temporary-uploads daily; dispatch-prepare everyMinute; stuck-check /15м; retry-failed-links /10м — все withoutOverlapping().

## Сильные стороны
- Идемпотентность: firstOrCreate по естественным ключам (PrimaryAnalysisProcessor:146,154; ArchiveStrategy:39), updateOrCreate вложений; status-guard в начале каждого Job (PrimaryAnalysisJob:43, PrepareDocumentJob:43, EstimateAnalysisJob:43)
- Distributed lease: DocumentAnalysis::acquireLease/releaseLease (:180-208) — атомарный UPDATE WHERE locked_until IS NULL OR < now(), TTL 600с, try/finally — защита от дубль-запросов к Gemini
- Гранулярный retry по стадиям: AnalysisStatus::stage() + PipelineRetryFailedLinksCommand (:52); атомарный claim UPDATE WHERE status=failedStatus (:66)
- Backoff+dead-letter: recordFailure (DocumentAnalysis:158): attempts++, exp-backoff min(2**n,240) мин, failed_permanently при attempts>=5
- CrawlerRunManager: heartbeat, персистентный курсор, startOrResume/recoverOrphan, «1 running на профиль» (частичный уникальный индекс + QueryException-гонка :71)
- Мониторинг: PipelineStuckCheckCommand (зависшие анализы/ссылки/краулеры, backlog моста, PostgreSQL/Redis/queue-depth//dev/shm, Telegram-алерты), PipelineStatusCommand
- Стратегии подготовки через config/platform.php → document_prepare.strategies

## Слабые стороны
- Смешанная оркестрация (chain + dispatch + поллер), 3→4 вне chain
- Мост 1→2 только поллер everyMinute (латентность)
- Незавершённость: OrderParsingProcess пуст; EstimateAnalysisProcessor::run (:28-34) — заглушка с хардкод-промптом; PrimaryAnalysisProcessor::analysis (:74) пустая ветка if (!success)
- Дублирование retry/backoff в 3 местах (DocumentAnalysis::recordFailure; CrawlingRepository::markFailed :138 pow(2,attempts) БЕЗ потолка; CrawlerRunManager::backoffMinutes)
- Мёртвый код: DocumentProcessor::findExistingByUrl/findExistingByMedia (:105,134) не вызываются
- Нет retry для FAILED-ссылок краулинга (markFailed ставит next_retry_at, исполнителя нет)
- transitionStatus без state-machine guard

## Легко потерять (guards/edge-cases/доменные знания)
- Lease-механизм (TTL 600с)
- storeOrUpdateLink: сброс статуса ТОЛЬКО при isStale (CrawlingRepository:44, сравнение platform_updated_at->timestamp) — иначе повторный обход затирает attempts/last_error и зацикливает
- syncAttachments: удаляет только пропавшие вложения БЕЗ анализа (:178 whereDoesntHave('documentAnalyses'))
- Финализация стадии сметы обязательна (EstimateAnalysisProcessor:48) — иначе вечный EstimateAnalyzing
- Статус EstimateAnalyzing ставится в воркере, не до commit (PrimaryAnalysisProcessor:100, EstimateAnalysisJob:56)
- getPendingLinks предзагружает только вложения без анализа (CrawlingRepository:94)
- Excel: landscape + fitToWidth(1)/fitToHeight(0) (ExcelStrategy:32-34) — иначе смета режется по столбцам; guard hasMedia(PREPARED) (:21)
- Archive: пропуск директорий/dot-файлов (:32), медиа только wasRecentlyCreated (:51)
- /dev/shm <32MB → Chromium падает; HEARTBEAT_STALE_MINUTES=15; queue-depth-limit 5000; краулер запускается вручную → stuck-check сигналит о мёртвом прогоне
- checkVpnConstraint (AbstractCrawlingService:185) — отключена, точка сохранена
- needsDocuments (:222): перепарс вложений только если новая или platform_updated_at изменился (toDateString())
- keep_state_on_crash (:167) — resume с state['current_url']
- Антибот usleep(3–5с) (:136), рандом-прокси, retries:3, rotate_proxy_on_fail
- parseExtensionFromName: строка >12 символов ≠ расширение (CrawlingRepository:191)
- Токены Gemini в DocumentAnalysisResponse: prompt/completion/total_tokens, rules_hash=sha1(instructions), cache_file_id; DocumentAnalysis::isCacheValid (:102)
- Link::recomputeStatusFromAnalyses (:100): hasActive→PROCESSING, allTerminal→COMPLETED, hasPermanentFail→FAILED, default→PROCESSING
- Interfaces/Crawling/{BrowserClient,PlatformCrawling} — готовые DI-швы
