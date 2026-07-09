# HANDOFF — 2026-07-09 — after P10.2

**Next:** `/task:plan-exec EST-AI P10.3` — фаза P10 в работе (2/4), следующий item — наполнение
`03_Dev` развёрнутым текстом (pipelines, AI-карточки, runbooks, ADR, recipes, troubleshooting)
поверх диаграмм, добавленных P10.2. Phase Context `plans/EST-AI/phases/P10.md` и текст P10.3 уже
существуют в файле — исполнителю P10.3 всё равно нужно перечитать их целиком по протоколу
plan-exec (новая сессия/линия — обязательно, повторное чтение в РАМКАХ этой же линии не требуется).
**Done:** P1–P9 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P10.1
закрыт 🟢 Done (скелет `docs/**`, 44 файла). P10.2 закрыт 🟢 Done — фаза P10 теперь 🟡 In progress
(2/4). P10.2: 6 mermaid-диаграмм по фактическому коду (P4–P9) размещены строго по H §Диаграммы:
Д1 `stateDiagram-v2` FSM документа (`DocumentStatus`, переходы подписаны
`PrepareDocumentJob.process()`/`DocumentPreparer`/`ScreeningJob`/
`ScreeningOutcomeHandler.markScreened()/ignore()`/`EstimateExtractionJob`/
`ExtractionOrchestrator.finalizeSuccess()/markUnprocessable()`) + легенда класс→файл:строка →
`docs/03_Dev/document-pipeline.md`; Д1-lite (бизнес-языком, без job'ов/error-блока) →
`docs/02_Workflow/document-and-estimate-pipeline.md`; Д2 `flowchart` стадий+очередей
(`PipelineDispatcher` → `PrepareDocumentJob`(`doc_prepare`) → `ScreeningJob`(`ai_screening`) →
`EstimateExtractionJob`(`ai_extraction`, checkpoint-requeue self-loop) + backoff-петля
`pipeline:retry-failed`, шаблонный `PipelineJob.handle()`) → `docs/03_Dev/document-pipeline.md`;
Д3 `sequenceDiagram` композиции промта (`PromptComposer.compose()` →
`PromptReadRepository.activeForSteps()` global→step-global→step priority ASC →
`PromptContextGenerator.generate()` → `ComposedPrompt`, явная граница кэшируемого префикса
rect-блоком + опциональный `CorrectionAwareComposer.correctionsBlock()` вне префикса) →
`docs/03_Dev/ai/prompts.md`; Д4 `sequenceDiagram` extraction-сессии (`ExtractionOrchestrator.run()`
→ `ExtractionPreflight.decide()` → WholeDocument(D3, `ChunkSource`+checkpoint) /
Windowed(D30, `WindowedChunkSource`→`SegmentAssembler.assemble()`→per-segment) →
`EstimatePersister.persist()` → `finalizeSuccess()`, + заметка `RerunRangeAction`) →
`docs/03_Dev/document-pipeline.md`; Д5 `flowchart` обучающего контура (Analysis→ревью→
approved/rejected+Correction→`RerunAnalysisAction`→…→`GoldExampleRecorder`→
`DatasetExportCommand` sft/dpo) → `docs/03_Dev/ai/training-loop.md`; Д6 `flowchart` LLM-слоя
(`AiRouteResolver.resolve()`→`prc_ai_routes`→`ResolvedRoute`→`PrismGeminiProvider`/
`OpenAiCompatibleProvider` implements `LlmProvider`, `SupportsFileUpload`/`SupportsContextCaching`
Gemini-only, `LlmUsage.cachedTokens` обязателен) → `docs/03_Dev/llm-providers.md`. Перекрёстные
ссылки (blockquote, без дублирования текста) добавлены в `docs/specs/v2/03-document-pipeline.md`
§2, `docs/specs/v2/04-llm-providers.md` §2.1, `docs/specs/v2/08-estimate-extraction.md` §4.
Найдено и исправлено на этапе Validation: `stateDiagram-v2` (Д1) не парсит `::` в некавычечной
edge-метке (`mermaid-cli` 11.16.0 parse error) — заменено на `Class.method()` только в Д1;
остальные 5 диаграмм `::` не содержат либо используют его внутри кавычечных меток (не ломается).
Валидация: `npx -y @mermaid-js/mermaid-cli` (v11.16.0) рендерит все 7 mermaid-блоков без ошибок;
все 21 упомянутый класс существует по указанным путям (grep/find); `git diff` — новых
`[text](path)`-ссылок не введено; `plan-lint.py plans/EST-AI` — те же 5 pre-existing ошибок, новых
нет.
**Remaining:** P10.3–P10.4 (наполнение текстом) → P11 (1, удаление Old/) → P12 (2, только
opus/Fable — Routing §3). Итого 5 items впереди из 40 (было 6 до P10.2).
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44; Status Board — P10 теперь 🟡 2/4)
· plans/EST-AI/phases/P10.md (Phase Context + P10.1/P10.2 Completion Notes; P10.3–P10.4 тексты
items уже там же) · plans/EST-AI/research/H-docs-structure.md (SSOT дерева `docs/` + §Диаграммы —
нумерация/размещение Д1–Д6, использовано P10.2) · docs/03_Dev/document-pipeline.md (Д1, Д2, Д4) ·
docs/02_Workflow/document-and-estimate-pipeline.md (Д1-lite) · docs/03_Dev/ai/prompts.md (Д3) ·
docs/03_Dev/ai/training-loop.md (Д5) · docs/03_Dev/llm-providers.md (Д6) — все шесть incl. легенды
класс→файл — канон диаграмм для P10.3 (текст пишется ВОКРУГ уже вставленных диаграмм, не дублируя
их).
**Open risks:**
- (новое, P10.2) Диаграммы вставлены, развёрнутого текста разделов пока нет (кроме легенд/
  однострочных пояснений под каждой диаграммой) — P10.3/P10.4 обязаны наполнить содержимое,
  frontmatter файлов остаётся `status: draft` до этого момента.
- (без изменений) `03_Dev/crawling-pipeline.md` и `03_Dev/admin-panel.md` требуют явной сверки с
  фактическим кодом краулинга/Filament — P10.3.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика при всех items 🟢/🟠 — задокументированное расхождение, не новое.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц (P10.3 может заодно поправить перекрёстные ссылки таблиц, НЕ диаграмм —
  те актуализированы P10.2).
- (без изменений) `docker compose exec` требует ДВА `--env-file`; `pint --dirty` не видит
  untracked — явный список путей; pint auto-fix может упереться в bind-mount permission.
- (без изменений) `composer require`/`composer update --lock` — только с ХОСТА.
- (без изменений) `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- (без изменений) `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется.
- (без изменений) explicit context cache НЕ подключён к `ExtractionOrchestrator`/
  `RowRangeChunkSource`; `LlmCacheManager` — CRUD без production-потребителя.
- (без изменений) `EstimatePersister` пропускает сметы документов без `procurement_id`.
- (без изменений) `RerunRangeAction` (P6.3) НЕ пересчитывает `validation_status`/иерархию целиком.
- (без изменений) `mergedIncompleteItems` не персистятся в `prc_estimate_items`.
- (без изменений) Пороги `pipeline:stuck-check` НЕ откалиброваны на живом трафике.
- (без изменений) `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env`.
- (без изменений) `ai:eval:run` НЕ прогонялся ни разу на реальных данных.
- (без изменений) `SENTRY_LARAVEL_DSN` пуст; `json`-канал логов не default;
  `GracefullyStoppable`-трейт готов, но не подключён; `stop_grace_period` не пересчитан.
- (без изменений) `ShadowDispatch` НЕ подключён ни к одному боевому call-сайту (P9.4 Open risk) —
  вне Scope P10.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. git-историю handoff.md) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; `dispatch(...)->afterCommit()` под
  `RefreshDatabase` исполняется немедленно в определённых условиях; `Storage::disk('s3')` в тестах
  требует `Storage::fake('s3')`. **добавлено P10.2:** `mermaid-cli` (`stateDiagram-v2`) не парсит
  `Class::method()` в некавычечной edge-метке — использовать `Class.method()` в state-диаграммах;
  в flowchart/sequence с кавычечными метками `::` безопасен.
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.4/P10.1) боевой тюнинг
  промт-текстов; Batch API; RAR/7z; OCR; LibreOffice-конвертер xlsx; Ghostscript-даунскейл; generic
  ai_extractions; контакты-сущность CRM; 9-префиксный ренейм (отклонён); Prometheus/Grafana; прод
  GlitchTip/Sentry; shadow CI-гейт; калибровка порогов; 3.x-модели; Langfuse; устаревшие имена
  таблиц 03/04; Gemini-прокси; калибровка rate_limit; тариф >200k; ренейм `Ai\AiRoute`;
  per-purpose роутинг; runtime-обнаружение лимита после preflight; полный пересчёт
  `validation_status` после `RerunRangeAction`; путь `mergedIncompleteItems` в UI/датасет;
  `image/*` в windowed-пути; explicit context cache; `ai:caches prune` в расписание; live FX-курс;
  маршруты `eval`/`shadow` в `AiRoutingSeeder`; v1-carry-over проверки; `EstimatePersister` под
  manual-документы; эвристика сопоставления многосметных gold; `EvalRun.prompt_version_set`;
  `gold_ref`/object storage; Pulse `sample_rate`/retention; `PULSE_ENABLED`/`PULSE_PATH` в прод
  `.env`; `db:backup` расписание/off-host; `ai:storage:lifecycle` вне scheduler; Filament
  `#[Label]`-атрибуты для `CorrectionReason`/`ReviewStatus`; таргетный regression-прогон
  extraction-веток `AnalysisStoreRepository`-ретрофита; hints в `ScreeningProcessor`/
  `WindowedChunkSource`; отдельный тест screening-ветки `RerunAnalysisAction`; тонкая RBAC-
  грануляция действий карточки анализа; `Estimate` как самостоятельный Filament-ресурс; screening-
  gold; SFT-примеры для `extract_passport`/`extract_totals` шагов; tier-gate в экспорте; OCR/vision-
  транскрипция для `user`-части SFT; `analysis_id` на `prc_ai_dataset_examples`; производственная
  проводка `ShadowDispatch`; расширение `LlmCallRecorder::record()`; инлайн-кодирование скана/PDF
  для теневого скрининга; перенос shadow-сравнения в `prc_ai_eval_results`; наполнение содержимого
  всех разделов `docs/` — P10.3 (dev-разделы) → P10.4 (операторские/бизнес-разделы); сверка
  `crawling-pipeline.md`/`admin-panel.md` с фактическим кодом (P10.3).
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; overlap-дедуп по составному ключу; источник
  `Estimate.title`; структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть
  промта (P7.1); порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод;
  боевой Telegram-токен/chat; калибровка порогов `pipeline:stuck-check`; когда владелец подготовит
  эталоны для `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию;
  `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler` под
  `shouldStop()`; хост и расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`; нужен ли
  `prc_ai_dataset_examples.analysis_id`; нужна ли `league/flysystem-aws-s3-v3` в `composer.json`
  явной зависимостью; нужна ли реальная проводка `ShadowDispatch` в этом же плане или отдельным
  будущим планом.

Context: handoff свежий — рекомендован /clear перед следующим item (P10.3 — Phase Context уже
прочитан этой линией сессий; свежая сессия обязана перечитать его заново по протоколу plan-exec).
