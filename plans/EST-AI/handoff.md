# HANDOFF — 2026-07-09 — after P12.1

**Next:** `/task:plan-exec EST-AI P12.2` (Routing §3 plan.md — ТОЛЬКО opus/Fable, effort high,
D21; sonnet'ом не исполнять). P12.2 — пересобрать корневой `CLAUDE.md`/`AGENTS.md` из
`.ai/guidelines/**`, правленных этим item'ом (Domain Hub — AI-подомен, Glossary — 7 новых
сущностей, Navigation — 3 новых скила), и свести туда 3 новых + 2 обновлённых скила P12.1.
**Done:** P1–P11 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8/P10 — 🟢 Done;
P11 — ⛔ Skipped by decision, D45). P12.1 — 🟠 Done with deviations (см. Known Deviations ниже).
Итого 30 item закрыто из 40 (P11.1 не засчитан — skipped, не done).
P12.1: прочитан весь финальный код AI-подомена (`app/Services/{Llm,Procurement}/**`,
`app/Enums/Procurement/*`, `app/Dto/Procurement/Ai/**`, `app/Models/Procurement/Ai/*`, ключевые
докблоки оркестратора/чанк-сорсов/stitcher/validator/persister/composer/провайдеров,
`config/ai.php`, `resources/ai/prompts/README.md`, `PromptSeeder`, `PromptPolicy`,
`FakeLlmProvider`). **Гайдлайны** (`.ai/guidelines/**`) актуализированы под финальный код:
`architecture.md` — Domain Hub получил абзац про AI-подомен (пути `Services/Llm`,
`Services/Procurement/{Prepare,Screening,Extraction}`, `Models/Procurement/Ai`) с указателем на
новые скилы; `glossary.md` — секция «AI-подомен» (`Ai\Correction`, `Ai\DatasetExample`,
`Ai\Prompt`/`is_protected`, `PromptStep` разведён с `AnalysisStep`/`LlmPurpose`,
`DocumentStatus::Unprocessable`+`UnprocessableReason`, `DiscoveryResponse`/`DiscoveryMarker`,
`ExtractionRoute`); `navigation.md` — Quick Start п.4 + Fast Term Routing + 3 строки Reference
Table на новые скилы. **Скилы:** `procurement-pipeline-dev` актуализирован (таблица стадий 4→5,
колонка `DocumentStatus`-переходов вкл. `unprocessable`, dependency map переадресует на глубокие
скилы, PR checklist «1–5»). Созданы 3 новых локальных скила в `.claude/skills/`:
`estimate-extraction-dev` (контракты P2 как SSOT, чек-лист «поменял DTO», таблица
`Whole`/`Windowed`, preflight, checkpoint-requeue+`ShouldBeUniqueUntilProcessing`, unprocessable-
таксономия, границы Stitcher/Validator/Persister, калибруемые `config('ai.extraction.*')`),
`llm-layer-dev` (контракт `LlmProvider`+опциональные интерфейсы, чек-лист адаптера, кэш-
инварианты, capability-матрица+EOL/successor, стоимость, запреты дословно вкл. indirect prompt
injection — research L finding 14), `prompt-engineering-agelto` (канон файл→сид→`is_protected`,
композиция/приоритеты, `CorrectionAwareComposer`-hints, eval-цикл, 5 антипаттернов). Ревизия
`testing-rules` (секция про `FakeLlmProvider`) и `.claude/rules/agelto-reviewer-overrides.md` (6
новых блокирующих осей ревью LLM-слоя/промтов/лимитов провайдера). Validation: `skiller status`
— 0 новых ⚠; grep путей/классов 3 новых скилов против `app/` — 0 расхождений; `python3
plan-lint.py plans/EST-AI` — те же 5 pre-existing ошибок, новых нет.
**Remaining:** P12.2 (1 item, только opus/Fable — Routing §3 plan.md). Итого 1 item впереди из 40.
P11.1 (удаление `Old/`) — вне remaining, но переисполним по решению владельца (Pending Work).
**Sources of truth:** `plans/EST-AI/plan.md` (Decision Log D1–D45; Status Board — P12 теперь
🟡 In progress 0/2, P12.1 закрыт с девиацией) · `plans/EST-AI/phases/P12.md` (Phase Context +
P12.1 Completion Notes целиком, P12.2 — следующий item, читать целиком) ·
`.ai/guidelines/{architecture,glossary,navigation}.md` (правленные источники) ·
`.claude/skills/{procurement-pipeline-dev,estimate-extraction-dev,llm-layer-dev,
prompt-engineering-agelto,testing-rules}/SKILL.md` · `.claude/rules/agelto-reviewer-overrides.md`
· skill `ai-context-workflow` (фактический канон: `.ai/guidelines/**` — единственный источник
ПРАВИЛ; локальные скилы живут напрямую в `.claude/skills/`, `.ai/skills/` не существует).
**Open risks:**
- (новое P12.1) `.cursor/skills/` mirror для локальных скилов НЕ существует в репозитории вообще
  (не заведён ни одной прошлой сессией) — 3 новых + 2 обновлённых скила P12.1 в нём не отражены;
  решение, заводить ли вручную или ждать `skiller sync`-подобный механизм для локальных скилов, —
  открытый вопрос вне P12.1 (Scope Excluded: доработка swissknifeman-реестра).
- (новое P12.1) `docs/03_Dev/ai-context/scenarios.md`/`index.md` (P10.3) НЕ обновлены под 3 новых
  скила P12.1 — Scope Excluded этого item'а («переписывание docs/» — P10 закрыта); если владелец
  захочет видеть новые скилы в этой доке, нужен отдельный docs-PR.
- (без изменений) `03_Dev/crawling-pipeline.md` — линк-карта краулинга, не полный разбор internals.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика — задокументированное расхождение, не новое.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц — не в Scope P10/P12.
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
- (без изменений) Пороги `pipeline:stuck-check`/`backlog_threshold`/`no_completions_minutes` НЕ
  откалиброваны на живом трафике.
- (без изменений) `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env`.
- (без изменений) `ai:eval:run` НЕ прогонялся ни разу на реальных данных.
- (без изменений) `SENTRY_LARAVEL_DSN` пуст; `json`-канал логов не default;
  `GracefullyStoppable`-трейт готов, но не подключён; `stop_grace_period` не пересчитан.
- (без изменений) `ShadowDispatch` НЕ подключён ни к одному боевому call-сайту.
- (без изменений) `AI_COST_REPORT_RUB_PER_USD` — статический курс, не live-котировка.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. git-историю handoff.md) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; `dispatch(...)->afterCommit()` под
  `RefreshDatabase` исполняется немедленно в определённых условиях; `Storage::disk('s3')` в тестах
  требует `Storage::fake('s3')`; `mermaid-cli` (`stateDiagram-v2`) не парсит `Class::method()` в
  некавычечной edge-метке — использовать `Class.method()`; каркас `docker/development.md` ссылался
  на несуществующую make-цель `deploy:frontend` — исправлено P10.3.
- deferred: (без изменений полный список — см. git-историю handoff.md до P10.4) боевой тюнинг
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
  extraction-веток; hints в `ScreeningProcessor`/`WindowedChunkSource`; отдельный тест
  screening-ветки `RerunAnalysisAction`; тонкая RBAC-грануляция действий карточки анализа;
  `Estimate` как самостоятельный Filament-ресурс; screening-gold; SFT-примеры для
  `extract_passport`/`extract_totals`; tier-gate в экспорте; OCR/vision-транскрипция для
  `user`-части SFT; `analysis_id` на `prc_ai_dataset_examples`; производственная проводка
  `ShadowDispatch`; расширение `LlmCallRecorder::record()`; инлайн-кодирование скана/PDF для
  теневого скрининга; перенос shadow-сравнения в `prc_ai_eval_results`; сверка
  `crawling-pipeline.md`/`admin-panel.md` с фактическим кодом краулинга; паттерн «коарс-право +
  запись-специфичный чек» AzGuard как переиспользуемый trait/base-policy при появлении второго
  случая. **добавлено P12.1:** `.cursor/skills/` mirror локальных скилов (см. Open risks);
  обновление `docs/03_Dev/ai-context/scenarios.md`/`index.md` под 3 новых скила (Scope Excluded).
- open_questions: (без изменений) подтверждение владельца перед `git rm Old/` (актуально, если
  P11.1 будет переисполнен по Pending Work); хост GlitchTip/боевой DSN; дата переключения на
  3.x-модели; исходящий прокси для Gemini; структурный reason-столбец на
  `prc_documents`/`prc_ai_analyses`; overlap-дедуп по составному ключу; источник `Estimate.title`;
  структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть промта; порог
  explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод; боевой Telegram-токен/чат;
  калибровка порогов `pipeline:stuck-check`/`backlog_threshold`/`no_completions_minutes`; когда
  владелец подготовит эталоны для `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию;
  `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler` под
  `shouldStop()`; хост и расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`; нужен ли
  `prc_ai_dataset_examples.analysis_id`; нужна ли `league/flysystem-aws-s3-v3` явной зависимостью;
  нужна ли реальная проводка `ShadowDispatch` в этом же плане или отдельным будущим планом;
  **добавлено P12.1:** заводить ли `.cursor/skills/` вручную для локальных скилов, или это
  функция будущей доработки `skiller`/vendor-канала (вне Scope этого плана).

Context: handoff свежий — рекомендован /clear перед следующим item (P12.2 — новая сессия ОБЯЗАНА
перечитать `plans/EST-AI/phases/P12.md` целиком по протоколу plan-exec; Routing §3 plan.md —
только opus/Fable, effort high).
