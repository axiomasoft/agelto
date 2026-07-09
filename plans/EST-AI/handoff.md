# HANDOFF — 2026-07-09 — after P9.3

**Next:** `/task:plan-exec EST-AI P9.4` (Phase Context `plans/EST-AI/phases/P9.md` уже прочитан
многократно этой линией сессий — исполнителю P9.4 достаточно перечитать сам item + `Required
Reads`, Phase Context не устарел).
**Done:** P1–P8 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P9.1 закрыт
🟢, P9.2/P9.3 закрыты 🟠 Done with deviations (3/4 фазы P9). P9.3: `EstimateToContractMapper`
(`app/Services/Procurement/Extraction/EstimateToContractMapper.php`) — обратный маппер
`prc_estimate_*` (нормализованные `sections`/`items.resources`/`totals`) → DTO-контракт P2.2
(`PassportResponse`+`rows: EstimateRow[]`) — паспорт через `EstimatePassport::from()`, итоги —
рекурсивная сборка `TotalLine`-дерева из плоских `Total` по `parent_id`/`position`, строки — обход
`Item` по `row_index` с breadcrumb-диффом секций (синтез `section_header`/`subsection_header` на
смене ветки); `row_index` ВСЕХ строк (включая синтезированные заголовки) переприсваивается
последовательно заново — оригинальные `row_index` заголовков нигде не персистированы (D10,
докблок класса фиксирует это явно как структурное ограничение); `row_type=subtotal` не
реконструируется вовсе (нет персистентной записи). `GoldExampleRecorder`
(`app/Services/Procurement/Extraction/GoldExampleRecorder.php`) — единственный потребитель
`review_status=approved`, вызывается из `ViewAnalysis::approveAction()` (P9.2, СРАЗУ после
`AnalysisStoreRepository::update()`); no-op для `step=Screening` и для анализа без персистированных
смет; `source=corrected`, если у ПРЯМОГО `parent_analysis_id` есть хотя бы одна коррекция с
`applied_at IS NOT NULL`, иначе `llm`; `version` — инкремент максимума по
`(document_version_id, task=extraction)`; `gold` несёт `tier:1` + `estimates[]` из маппера;
`gold_ref` всегда `null` (object-storage overflow для gold — deferred). `DatasetExportCommand`
(`ai:dataset:export --format=sft|dpo`, `app/Console/Commands/Ai/DatasetExportCommand.php`) — sft:
JSONL chat-format, `system`=`PromptComposer::compose(ExtractionRows, [RowsChunkResponse])` (боевая
композиция буквально), `assistant`={rows, incomplete_items:[]}, `user`=воспроизводимая ссылка на
`document_version_id`+`storage_path` (НЕ текст документа — OCR/vision-транскрипция ещё не
реализована, явно задокументировано); dpo: пары chosen(gold)/rejected(`Analysis.result`
предшественника) через АКТИВНЫЙ анализ документа, пропускает пары где raw==gold; экспорт —
`php://temp`-поток через `cursor()`, манифест `<path>.manifest.json` рядом (format/task/examples/
generated_at/sha256). Тесты: `EstimateToContractMapperTest` (1, round-trip 2 раздела+подраздел+
ресурс+дерево итогов), `GoldExampleRecorderTest` (4 — llm/no estimates/screening no-op/
corrected+immutable v1), `DatasetExportTest` (2 — sft roles+assistant+manifest, dpo chosen≠rejected
фильтр+manifest), `--filter=EstimateToContractMapper`/`--filter=GoldExampleRecorder`/
`--filter=DatasetExport` зелёные, полный сьют 453 passed (1508 assertions, было 446/1441), pint
чист (ручная правка `Builder<DatasetExample>`-докблока — pint auto-fix упёрся в bind-mount
permission, тот же workaround, что вся сессия P9), `migrate:fresh --seed` ок.
**Remaining:** P9.4 (1) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого 8
items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board —
P9 теперь 🟡 3/4) · plans/EST-AI/phases/P9.md (Phase Context + P9.1/P9.2/P9.3 Completion Notes —
что именно сделано; P9.4 читать перед exec) ·
`app/Services/Procurement/Extraction/EstimateToContractMapper.php` (канон обратного маппинга DTO↔
таблицы, ограничение реконструкции row_index заголовков/subtotal) ·
`app/Services/Procurement/Extraction/GoldExampleRecorder.php` (канон source=corrected/llm,
версионирование immutable-снапшотов) ·
`app/Console/Commands/Ai/DatasetExportCommand.php` (канон sft/dpo JSONL-формата, манифест) ·
`app/Filament/Resources/Procurement/Ai/Analyses/Pages/ViewAnalysis.php` (точка вызова
`GoldExampleRecorder` из `approveAction()`) ·
`tests/Feature/Ai/{GoldExampleRecorderTest,DatasetExportTest}.php` +
`tests/Feature/Procurement/Extraction/EstimateToContractMapperTest.php` (образцы тестов round-trip
и петли approve→gold→export).
**Open risks:**
- (новое, P9.3) `--format=dpo` резолвит пару ТОЛЬКО через АКТИВНЫЙ анализ документа — для
  СТАРЫХ версий gold (после ≥2 re-run) исходный анализ-предшественник больше не резолвится
  (`prc_ai_dataset_examples` не хранит `analysis_id` — докблок `Analysis` закрепил «не хранится
  отдельной таблицей», связь только через `document_version_id`, которая после нескольких циклов
  теряет однозначность).
- (новое, P9.3) `sft`-экспорт использует ТОЛЬКО шаг `extraction:rows` (`RowsChunkResponse`) для
  system-композиции — паспорт/итоги-шаги (`extract_passport`/`extract_totals`) не получают
  собственных SFT-примеров; design-решение exec-сессии, не разведено отдельно design-сессией.
- (новое, P9.3) `GoldExampleRecorder` покрывает только `step=Extraction` — скрининг не подписан
  на gold-петлю (Required Reads/Inputs P9.3 целиком про сметы).
- (новое, P9.3) tier-gate в экспорте не применяется — `DatasetImportCommand` (P7.4) не пишет
  `tier` в gold, `GoldExampleRecorder` пишет `tier:1`; оба сейчас экспортируются без разбора,
  различие проявится только когда появится tier≠1 producer.
- (без изменений, P9.2) `ScreeningJob`-путь `RerunAnalysisAction` не покрыт отдельным тестом.
- (без изменений, P9.2) `CorrectionAwareComposer` подключён только в `RowRangeChunkSource` — не
  в `ScreeningProcessor`/`WindowedChunkSource`.
- (без изменений, P9.2) RBAC-плюмбинг сверх заявленного Scope Excluded «RBAC» —
  `AnalysisPermission`/`AnalystRole`/`OperatorRole` минимальная схема, тонкая грануляция
  (approve/reject/rerun отдельными правами) не разведена.
- (без изменений, P9.2) `ScreeningJob`/`EstimateExtractionJob::statusGuardPassed()` — правка
  `active()`-скоупа затрагивает КАЖДЫЙ повторный диспатч этих jobs, покрыта только полным сьютом.
- (без изменений, унаследовано из P9.1) partial unique
  `prc_ai_analyses_active_version_step_unique` — имя длиннее 63 байт лимита Postgres НЕ
  проверялось явно вручную (индекс успешно создаётся, значит уложился).
- (без изменений) exclude одного ресурса (`PromptResource`) из AzGuard-каталога снимает его из
  ResourceGate enum-RBAC — перепроверить при будущей `guard:filament:generate --source=enum`.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7 «Done with
  deviations» при всех items 🟢/🟠 — задокументированное расхождение, не новые.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами
  используют устаревшие имена таблиц.
- (без изменений) `docker compose exec` требует ДВА `--env-file`; `pint --dirty` не видит
  untracked — явный список путей; pint auto-fix может упереться в bind-mount permission (ручная
  правка вместо автофикса — см. P9.3).
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
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. предыдущий handoff) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА (bind-mount permission denied для
  www-data — файлы правились через `setfacl`/`chown --user root` внутри контейнера, см.
  git-историю; **добавлено P9.3:** аналогичный upstream-докблок с полным FQCN правился вручную
  напрямую с хоста, когда pint отказал писать в файл внутри контейнера); `.env.example` слепой
  append; `pint --dirty` — явный список путей; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; Symfony Console `--version` зарезервирован;
  `mc ilm rule add` не идемпотентен сам по себе; регресс-тест на exclude-строку AzGuard — ручной
  temporary-revert; `dispatch(...)->afterCommit()` под `RefreshDatabase` исполняется НЕМЕДЛЕННО,
  если на момент диспатча активна только обёртывающая транзакция теста — тесты промежуточного
  состояния ОБЯЗАНЫ `Queue::fake()`.
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.1/P9.2) боевой
  тюнинг промт-текстов; Batch API; RAR/7z; OCR; LibreOffice-конвертер xlsx; Ghostscript-
  даунскейл; generic ai_extractions; контакты-сущность CRM; 9-префиксный ренейм (отклонён);
  Prometheus/Grafana; прод GlitchTip/Sentry; shadow CI-гейт; калибровка порогов; 3.x-модели;
  Langfuse; устаревшие имена таблиц 03/04; Gemini-прокси; калибровка rate_limit; тариф >200k;
  ренейм `Ai\AiRoute`; per-purpose роутинг; runtime-обнаружение лимита после preflight; полный
  пересчёт `validation_status` после `RerunRangeAction`; путь `mergedIncompleteItems` в UI/
  датасет; `image/*` в windowed-пути; explicit context cache; `ai:caches prune` в расписание;
  live FX-курс; маршруты `eval`/`shadow` в `AiRoutingSeeder`; v1-carry-over проверки;
  `EstimatePersister` под manual-документы; эвристика сопоставления многосметных gold;
  `EvalRun.prompt_version_set`; `gold_ref`/object storage; Pulse `sample_rate`/retention;
  `PULSE_ENABLED`/`PULSE_PATH` в прод `.env`; `db:backup` расписание/off-host; `ai:storage:
  lifecycle` вне scheduler; Filament `#[Label]`-атрибуты для `CorrectionReason`/`ReviewStatus`;
  таргетный regression-прогон extraction-веток `AnalysisStoreRepository`-ретрофита; hints в
  `ScreeningProcessor`/`WindowedChunkSource`; отдельный тест screening-ветки
  `RerunAnalysisAction`; тонкая RBAC-грануляция действий карточки анализа; `Estimate` как
  самостоятельный Filament-ресурс (Фаза 7 роадмапа); **добавлено P9.3:** screening-gold (recorder
  покрывает только extraction); SFT-примеры для `extract_passport`/`extract_totals` шагов;
  tier-gate в экспорте (нет tier≠1 producer пока); OCR/vision-транскрипция для реального текста
  документа в `user`-части SFT (сейчас — воспроизводимая ссылка-плейсхолдер); `analysis_id` на
  `prc_ai_dataset_examples` для точного DPO-резолва старых версий gold.
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; guard-фикс `ScreeningJob::
  statusGuardPassed()` (ЧАСТИЧНО закрыт P9.2); overlap-дедуп по составному ключу; источник
  `Estimate.title`; структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть
  промта (P7.1); порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод;
  боевой Telegram-токен/chat; калибровка порогов `pipeline:stuck-check`; когда владелец
  подготовит эталоны для `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию;
  `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler`
  под `shouldStop()`; хост и расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`;
  нужна ли P9.3-подписка на `review_status=approved` (РЕШЕНО P9.3: прямой вызов из Filament-
  действия, не событийная подписка — см. Known Deviations P9.3); **добавлено P9.3:** нужен ли
  `prc_ai_dataset_examples.analysis_id` (или аналог) для точного DPO-резолва по НЕ-последней
  версии gold — сейчас работает только для активного анализа документа.

Context: handoff свежий — рекомендован /clear перед следующим item (P9.4 — Phase Context P9.md
актуален этой сессией; исполнителю достаточно перечитать сам item + Required Reads).
