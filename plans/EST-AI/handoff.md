# HANDOFF — 2026-07-09 — after P11.1

**Next:** `/task:plan-exec EST-AI P12.1` (Routing §3 — ТОЛЬКО opus/Fable, effort high, D21;
sonnet'ом не исполнять). Фаза P11 закрыта ⛔ Skipped by decision (D45) — владелец явно отказал в
подтверждении на `git rm Old/` при исполнении цепочки P1→P12, `Old/` остаётся в дереве. P12 от
P11 функционально не зависит (AI-контекст: скилы/гайдлайны/корневой CLAUDE.md-хаб) — цепочка
продолжена без переисполнения P11.1.
**Done:** P1–P11 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8/P10 — 🟢 Done;
P11 — ⛔ Skipped by decision, D45). P10.4/фаза P10 — 🟢 Done (4/4), без изменений с прошлого
handoff. Итого 29 item закрыто из 40 (P11.1 не засчитан — skipped, не done).
P10.4: наполнены все Scope Included файлы. `01_Business`: `overview.md` (продукт/проблема/
аудитории/границы), `roadmap.md` (выжимка `docs/specs/v2/06-roadmap.md` — сделано/в работе/
отложено, без цен/рисков). `02_Workflow`: `glossary.md` (перенос `.ai/guidelines/glossary.md`
бизнес-языком + секция «Изменения терминов» v1→v2), `crawling-and-discovery.md`
(площадка/краулер/прогон бизнес-языком), `document-and-estimate-pipeline.md` (3 шага пайплайна +
условия отсева/borderline/windowed для гигантов + терминальные исходы вкл. `unprocessable`, вокруг
Д1-lite), `prompts-and-quality.md` (жизненный цикл промта, требования, петля качества). `04_Operators`:
`_index.md` (роутер + мини-матрица прав по фактическим ролям AzGuard — Аналитик/Оператор/Суперадмин,
уровни 10/100/1000), `reference/statuses.md` (`DocumentStatus`+`UnprocessableReason`,
`AnalysisStatus`, `EstimateValidationStatus`, `ReviewStatus` — все enum-кейсы покрыты, сверено
grep'ом), 5 SOP (`check-ai-analysis.md`, `review-estimate.md`, `restart-failed-run.md`,
НОВЫЙ `handle-unprocessable-document.md` — явно назван в Intent P10.4, не был в каркасе P10.1,
`manage-prompts.md`). `meta/editing.md` наполнен. `docs-private/`: 2 новых файла
(`ai-economics.md` — реальные тарифы/лимиты/`ai:cost-report`; `prompt-engineering-notes.md` —
наследие v1/антипаттерны/composite versioning), `local-llm-training.md` (P9.4) вычитан без правок,
связка ссылками уже была (P10.3) + 2 новые обратные ссылки добавлены из `03_Dev/llm-providers.md`
и `03_Dev/ai/prompts.md`. Validation: (1) enum-кейсы 5 статус-enum'ов — 0 пропущенных в
`statuses.md`; (2) markdown-резолвер по всем 60 файлам `docs/**` — 0 битых ссылок; (3) frontmatter
`audience:`/`type:` — есть во всех изменённых/новых файлах; (4) grep на цены/руб/api_key по
публичной части (БЕЗ `docs-private`) — 0 утечек; (5) `python3 plan-lint.py plans/EST-AI` — те же 5
pre-existing ошибок (см. ниже), новых нет; (6) docs-only изменения, тесты не запускались/не
требовались.
**Remaining:** P12 (2, только opus/Fable — Routing §3 plan.md). Итого 2 items впереди из 40.
P11.1 (удаление `Old/`) — вне remaining, но переисполним по решению владельца (Pending Work).
**Sources of truth:** `plans/EST-AI/plan.md` (Decision Log D1–D44; Status Board — P10 теперь
🟢 Done 4/4) · `plans/EST-AI/phases/P10.md` (Phase Context + P10.1–P10.4 Completion Notes, Phase
Handoff) · `plans/EST-AI/phases/P11.md` (следующий item, читать целиком) ·
`plans/EST-AI/research/I-v1-db-coverage.md` (гейты удаления `Old/`) · `docs/` (весь раздел теперь
наполнен, включая 04_Operators/01_Business/02_Workflow — канон для сверки).
**Open risks:**
- (без изменений) `03_Dev/crawling-pipeline.md` — линк-карта краулинга, не полный разбор internals
  (не требовалось Scope P10.3/P10.4) — расширить, если краулинг будет ревизован отдельным планом.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика при всех items 🟢/🟠 — задокументированное расхождение, не новое.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц — не в Scope P10.
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
- (без изменений) `ai:eval:run` НЕ прогонялся ни разу на реальных данных (ни screening, ни
  extraction) — теперь явно упомянуто и в `docs-private/prompt-engineering-notes.md` §5.
- (без изменений) `SENTRY_LARAVEL_DSN` пуст; `json`-канал логов не default;
  `GracefullyStoppable`-трейт готов, но не подключён; `stop_grace_period` не пересчитан.
- (без изменений) `ShadowDispatch` НЕ подключён ни к одному боевому call-сайту (P9.4 Open risk).
- (без изменений) `AI_COST_REPORT_RUB_PER_USD` — статический курс, не live-котировка (теперь
  явно зафиксировано в `docs-private/ai-economics.md` §3).
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. git-историю handoff.md) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; `dispatch(...)->afterCommit()` под
  `RefreshDatabase` исполняется немедленно в определённых условиях; `Storage::disk('s3')` в тестах
  требует `Storage::fake('s3')`; `mermaid-cli` (`stateDiagram-v2`) не парсит `Class::method()` в
  некавычечной edge-метке — использовать `Class.method()` (теперь также задокументировано в
  `docs/meta/editing.md` §Диаграммы, публично, не только в handoff); каркас `docker/development.md`
  ссылался на несуществующую make-цель `deploy:frontend` — исправлено P10.3.
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.4/P10.3) боевой тюнинг
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
  для теневого скрининга; перенос shadow-сравнения в `prc_ai_eval_results`; сверка
  `crawling-pipeline.md`/`admin-panel.md` с фактическим кодом краулинга — частично сделано P10.3
  (admin-panel — линк-ревизия; crawling-pipeline — карта, не полный разбор internals); паттерн
  «коарс-право + запись-специфичный чек» AzGuard как переиспользуемый trait/base-policy при
  появлении второго случая (ADR 0005 Consequences). **добавлено P10.4:** ничего нового не отложено
  — все items Scope Included P10.4 закрыты в этом же item'е.
- open_questions: (без изменений) подтверждение владельца перед `git rm Old/` (актуально для
  СЛЕДУЮЩЕГО item'а P11.1 — проверить перед физическим удалением); хост GlitchTip/боевой DSN; дата
  переключения на 3.x-модели; исходящий прокси для Gemini; структурный reason-столбец на
  `prc_documents`/`prc_ai_analyses`; overlap-дедуп по составному ключу; источник `Estimate.title`;
  структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть промта (P7.1);
  порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод; боевой Telegram-
  токен/chat; калибровка порогов `pipeline:stuck-check`/`backlog_threshold`/
  `no_completions_minutes`; когда владелец подготовит эталоны для `ai:dataset:import`;
  `LOG_CHANNEL=json` на проде по умолчанию; `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор
  `RunFreshCrawler`/`RunArchiveCrawler` под `shouldStop()`; хост и расписание прод-бэкапов; окна
  retention/MinIO ILM `expire_days`; нужен ли `prc_ai_dataset_examples.analysis_id`; нужна ли
  `league/flysystem-aws-s3-v3` в `composer.json` явной зависимостью; нужна ли реальная проводка
  `ShadowDispatch` в этом же плане или отдельным будущим планом.

Context: handoff свежий — рекомендован /clear перед следующим item (P11.1 — новая сессия обязана
перечитать `plans/EST-AI/phases/P11.md` целиком по протоколу plan-exec).
