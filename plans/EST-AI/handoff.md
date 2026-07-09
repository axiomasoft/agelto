# HANDOFF — 2026-07-09 — after P9.4

**Next:** `/task:plan-exec EST-AI P10.1` — фаза P9 закрыта (4/4), следующая фаза P10 (документация,
4 items) начинается с каркаса `docs/` (Phase Context `plans/EST-AI/phases/P10.md` ещё не прочитан
этой линией сессий — исполнителю P10.1 нужно прочитать Phase Context целиком + сам item + Required
Reads).
**Done:** P1–P9 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P9.4
закрыт 🟠 Done with deviations — фаза P9 закрыта 🟠 Done with deviations (4/4). P9.4:
`OpenAiCompatibleProvider` (`app/Services/Llm/Providers/OpenAiCompatibleProvider.php`) — stateless
OpenAI-совместимый транспорт (vLLM/Ollama) за контрактом `LlmProvider`; `LlmOptions` получил новые
nullable `$baseUrl`/`$apiKeyEnv` (Gemini-адаптер их игнорирует) — `AiRouteResolver::
toResolvedRoute()` копирует их из `prc_ai_providers.base_url`/`options.api_key_env` для ЛЮБОГО
маршрута, чтобы адаптер оставался БЕЗ параметров конструктора и `app()->instance(
OpenAiCompatibleProvider::class, $fake)` продолжал работать в тестах (тот же приём, что
`PrismGeminiProvider`). `usingStructuredMode(StructuredMode::Structured)` — ЯВНО (Prism `Auto` не
знает локальных имён моделей и упал бы на `Json`-режим без schema-guided decoding). `RawSchema`
передаётся КАК ЕСТЬ, БЕЗ `GeminiResponseSchemaAdapter` (OpenAI-совместимый JSON Schema нативно
понимает `$ref`/`$defs`). `SupportsFileUpload` НЕ реализован — `RuntimeException` на непустых
`LlmPrompt::$fileRefs` (Files API вне OpenAI-совместимого REST-протокола). Регистрация:
`driver === 'openai_compatible'` в `AiRouteResolver::providerFor()`; новый
`AiRouteReadRepository::activeShadowRouteFor()` + `AiRouteResolver::shadowRoute()` (первый активный
`shadow=true` маршрут). `ShadowDispatch` (`app/Services/Llm/ShadowDispatch.php`) — вызывается ПОСЛЕ
основного вызова с ТЕМ ЖЕ `LlmPrompt`/`JsonSchema`; no-op (с `Log::warning`) при отсутствии
теневого маршрута, ошибке резолва ИЛИ непустых `fileRefs`; диспатчит `ShadowAnalysisJob`
(`app/Jobs/Procurement/ShadowAnalysisJob.php`, очередь `ai_shadow`) — НЕ наследует `PipelineJob`
(не часть status-guard/lease боевой стадии), весь `handle()` в `try/catch` (исключение НИКОГДА не
выходит наружу — Implementation Rules), создаёт `Ai\Analysis(is_shadow=true)` через новый
`AnalysisStoreRepository::createShadow()` (вне partial unique `(document_version_id, step) WHERE
... is_shadow=false`, D24), LLM-вызов пишется через `LlmCallRecorder` с `purpose=shadow`, сравнение
через `EvalMetrics::compare()` — best-effort, только когда обе стороны несут `result.estimates[]`.
`docs-private/local-llm-training.md` — стек D16 (Qwen2.5-VL/MiniCPM-V без дообучения + Qwen3-4B/8B
QLoRA-SFT через Unsloth/LLaMA-Factory, vLLM `structured_outputs`/xgrammar), конфиг-скелет Unsloth,
объём 1–3k с оговоркой «перепроверить на момент обучения». Тесты:
`OpenAiCompatibleProviderTest` (4, `Prism::fake()`) + `ShadowDispatchTest` (5, `FakeLlmProvider`),
`--filter=OpenAiCompatible` и `--filter=ShadowDispatch` зелёные РАЗДЕЛЬНО, полный сьют 462 passed
(1532 assertions, было 453/1508), pint чист, `migrate:fresh --seed` ок (миграций в item'е нет —
Files не содержал ни одной).
**Remaining:** P10 (4 items: каркас/диаграммы/dev-разделы/операторские SOP) → P11 (1, удаление
Old/) → P12 (2, только opus/Fable — Routing §3). Итого 7 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board — P9
теперь 🟠 4/4, Update Log несёт полную сводку P9.4) · plans/EST-AI/phases/P9.md (Phase Context +
P9.1–P9.4 Completion Notes + Phase Handoff — что именно сделано за всю фазу) ·
`app/Services/Llm/Providers/OpenAiCompatibleProvider.php` (канон OpenAI-совместимого адаптера,
stateless-приём через `LlmOptions::$baseUrl`/`$apiKeyEnv`) ·
`app/Services/Llm/ShadowDispatch.php` + `app/Jobs/Procurement/ShadowAnalysisJob.php` (канон
теневого прогона, докблоки фиксируют Known Deviation по `prc_ai_eval_results`) ·
`app/Services/Llm/AiRouteResolver.php` (driver-регистрация + `shadowRoute()`) ·
`docs-private/local-llm-training.md` (инструкция обучения) ·
`tests/Unit/Llm/OpenAiCompatibleProviderTest.php` + `tests/Feature/Llm/ShadowDispatchTest.php`
(образцы тестов адаптера/shadow-цикла) · plans/EST-AI/phases/P10.md (следующая фаза, Phase Context
+ P10.1 ещё не прочитаны этой сессией).
**Open risks:**
- (новое, P9.4) `ShadowDispatch` НЕ подключён ни к одному боевому call-сайту
  (`ScreeningProcessor`/`RowRangeChunkSource`/`WindowedChunkSource` — вне Scope/Files item'а;
  единственный существующий funnel `LlmCallRecorder::record()` не несёт `LlmPrompt`/`JsonSchema`
  для повтора вызова) — механизм полностью реализован и покрыт тестами, но НЕ работает в проде без
  отдельного item'а на проводку.
- (новое, P9.4) даже после будущей проводки в `ScreeningProcessor` shadow для screening останется
  no-op — скрининг ВСЕГДА использует `fileRefs` (Gemini Files API), а `ShadowDispatch` пропускает
  вызовы с непустыми `fileRefs`; нужна инлайн-передача изображений теневой модели, не реализована.
- (новое, P9.4) сравнение shadow-метрик хранится в `result.comparison` теневого `Ai\Analysis`, НЕ в
  `prc_ai_eval_results` (Known Deviations P9.4: таблица требует NOT NULL `dataset_example_id`, нет
  связи с `Ai\Analysis`) — P7.2-отчёт по стоимости НЕ агрегирует shadow-сравнения без доп. работы.
- (без изменений, P9.3) `--format=dpo` резолвит пару ТОЛЬКО через АКТИВНЫЙ анализ документа — для
  старых версий gold (после ≥2 re-run) исходный анализ-предшественник не резолвится
  (`prc_ai_dataset_examples` не хранит `analysis_id`).
- (без изменений, P9.3) `sft`-экспорт использует ТОЛЬКО шаг `extraction:rows` — паспорт/итоги-шаги
  не получают собственных SFT-примеров.
- (без изменений, P9.3) `GoldExampleRecorder` покрывает только `step=Extraction` — скрининг не
  подписан на gold-петлю.
- (без изменений, P9.3) tier-gate в экспорте не применяется — единственный producer и так пишет
  `tier:1`.
- (без изменений, P9.2) `ScreeningJob`-путь `RerunAnalysisAction` не покрыт отдельным тестом.
- (без изменений, P9.2) `CorrectionAwareComposer` подключён только в `RowRangeChunkSource` — не в
  `ScreeningProcessor`/`WindowedChunkSource`.
- (без изменений, P9.2) тонкая RBAC-грануляция действий карточки анализа (approve/reject/rerun
  отдельными правами) не разведена.
- (без изменений, унаследовано из P9.1) partial unique
  `prc_ai_analyses_active_version_step_unique` — имя длиннее 63 байт лимита Postgres не
  проверялось явно вручную (индекс успешно создаётся, значит уложился).
- (без изменений) exclude одного ресурса (`PromptResource`) из AzGuard-каталога снимает его из
  ResourceGate enum-RBAC — перепроверить при будущей `guard:filament:generate --source=enum`.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7 «Done with
  deviations» при всех items 🟢/🟠 — задокументированное расхождение, не новые (теперь и P9 в этом
  списке — тот же паттерн, не новая проблема).
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами
  используют устаревшие имена таблиц.
- (без изменений) `docker compose exec` требует ДВА `--env-file`; `pint --dirty` не видит
  untracked — явный список путей; pint auto-fix может упереться в bind-mount permission (ручная
  правка вместо автофикса — подтверждено ещё раз в P9.4, `ShadowAnalysisJob.php` docblock-выравнивание).
- (без изменений) `composer require`/`composer update --lock` — только с ХОСТА.
- (без изменений) `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- (без изменений) `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется
  (подтверждено ещё раз в P9.4).
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
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. предыдущий handoff) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА (bind-mount permission denied для
  www-data — файлы правились через `setfacl`/`chown --user root` внутри контейнера); один
  `--filter` за прогон; dot-notation в `config('ai.capabilities.<модель-с-точками>...')`;
  `dispatch(...)->afterCommit()` под `RefreshDatabase` исполняется НЕМЕДЛЕННО, если на момент
  диспатча активна только обёртывающая транзакция теста; **добавлено P9.4:** `Storage::disk('s3')`
  в тестах ТРЕБУЕТ предварительного `Storage::fake('s3')` — `league/flysystem-aws-s3-v3` НЕ
  установлен в vendor (только `league/flysystem-local`), реальный `s3`-диск падает с `Class
  "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found` при ЛЮБОЙ попытке резолва без
  fake (проверено напрямую: изолированный тест без `Storage::fake('s3')` стабильно падает; все
  существующие тесты, пишущие в `config('crawler.raw_disk')`='s3' (`LlmCallRecorder` и т.п.), уже
  вызывают `Storage::fake('s3')` в начале — паттерн НЕ новый, просто не был явно
  задокументирован здесь до сих пор).
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.1–P9.3) боевой тюнинг
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
  грануляция действий карточки анализа; `Estimate` как самостоятельный Filament-ресурс (Фаза 7
  роадмапа); screening-gold; SFT-примеры для `extract_passport`/`extract_totals` шагов; tier-gate
  в экспорте; OCR/vision-транскрипция для `user`-части SFT; `analysis_id` на
  `prc_ai_dataset_examples`; **добавлено P9.4:** производственная проводка `ShadowDispatch` в
  `ScreeningProcessor`/`RowRangeChunkSource`/`WindowedChunkSource` (отдельный item, вне Scope/Files
  P9.4); расширение `LlmCallRecorder::record()` (или альтернативный funnel) для передачи
  `LlmPrompt`/`JsonSchema` дальше в shadow-механику без per-call-сайта; инлайн-кодирование
  скана/PDF для теневого скрининга (vision-сообщение вместо Files API); перенос shadow-сравнения
  в `prc_ai_eval_results` новой миграцией (nullable `dataset_example_id` + `analysis_id`/
  `shadow_analysis_id`).
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; overlap-дедуп по составному ключу; источник
  `Estimate.title`; структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть
  промта (P7.1); порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод;
  боевой Telegram-токен/chat; калибровка порогов `pipeline:stuck-check`; когда владелец подготовит
  эталоны для `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию;
  `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler` под
  `shouldStop()`; хост и расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`; нужен ли
  `prc_ai_dataset_examples.analysis_id` для точного DPO-резолва по НЕ-последней версии gold;
  **добавлено P9.4:** нужна ли `league/flysystem-aws-s3-v3` в `composer.json` явной зависимостью
  (сейчас работает только благодаря повсеместному `Storage::fake('s3')` в тестах — реальный прод
  MinIO-диск использует ТОТ ЖЕ `driver: s3`, риск идентичного отказа вне тестов не проверялся этой
  сессией, вне Scope P9.4); нужна ли реальная проводка `ShadowDispatch` в этом же плане (P9.5?) или
  отдельным будущим планом, когда появится реальный локальный LLM-сервер для сравнения.

Context: handoff свежий — рекомендован /clear перед следующим item (P10.1 — Phase Context
`plans/EST-AI/phases/P10.md` этой линией сессий ЕЩЁ НЕ прочитан, исполнителю нужно прочитать его
целиком перед самим item'ом).
