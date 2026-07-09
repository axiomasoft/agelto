# HANDOFF — 2026-07-09 — after P10.3

**Next:** `/task:plan-exec EST-AI P10.4` — фаза P10 в работе (3/4), последний item фазы — наполнение
`04_Operators`/`01_Business`/`02_Workflow` (менеджерская основная часть доки, D20) + остаточный
`docs-private`. Phase Context и текст P10.4 уже существуют в `plans/EST-AI/phases/P10.md` —
исполнителю всё равно нужно перечитать их целиком по протоколу plan-exec (новая сессия/линия —
обязательно, повторное чтение в РАМКАХ этой же линии не требуется).
**Done:** P1–P9 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P10.1/
P10.2 закрыты 🟢 Done. P10.3 закрыт 🟢 Done — фаза P10 теперь 🟡 In progress (3/4). P10.3: наполнены
развёрнутым текстом ВСЕ 22 файла `docs/03_Dev/**` из Scope Included (кроме `_index.md` — уже
`status: living` по факту P10.1): `getting-started.md`, `architecture.md`, `database.md`,
`crawling-pipeline.md` (был фактически пуст несмотря на пометку P10.1 «наполнен вне плана» —
заполнен линк-картой краулинга), `document-pipeline.md` (§Инварианты идемпотентности, §Статусы вкл.
`Unprocessable`, §Failure modes → runbooks — текст вокруг уже вставленных P10.2 диаграмм Д1/Д2/Д4,
без их переписывания), `llm-providers.md` (§Роутинг, §Транспорт, §Capability-матрица, §Учёт
стоимости — вокруг Д6), `admin-panel.md` (ресурсы Filament, AzGuard-исключение `PromptResource`
D44), `commands.md` (полная шпаргалка make/artisan по факту сигнатур), `ai/prompts.md` (§Файлы→сид→
админка, §Composite versioning — вокруг Д3, ссылка на `resources/ai/prompts/README.md` как источник
«как писать промт»), `ai/feature-card-screening.md` и `ai/feature-card-estimate-extraction.md`
(модель/промты/схема/why-решения/failure modes/eval), `ai/training-loop.md` (§Раздельное хранение
raw/gold, §Корректирующие промты, §Экспорт — вокруг Д5), `ai-context/index.md` (новый mermaid
source→generation→consumers) и `ai-context/scenarios.md` (маршруты скилов по задачам), 3 runbook'а
(Symptoms→Triage→Fix→Rollback→Escalation с реальными `config('ai.pipeline.*')`-ключами), `adr/0001`
дописан по MADR-формату + 4 НОВЫХ ADR (`0002` Prism-фасад D8/D14, `0003` row-range+windowed
D3/D30/D40/D42, `0004` jsonb для не-смет D13, `0005` protected-промты D17/D44), `process/{deploy,
testing}.md` (ссылки на `docker/Makefile`/skill `testing-rules`, без копий), `recipes.md` (3
рецепта), `troubleshooting.md` (8 известных workaround'ов плана), `docker/{index,development,
production,troubleshooting}.md` (по факту `docker-compose*.yml`/`docker/Makefile`). Обнаружена и
исправлена мелкая неточность исходного каркаса P10.1: стаб `docker/development.md` ссылался на
несуществующую цель `make prod:deploy:frontend` — заменено на фактическое описание (не расхождение
плана с кодом, эскалации не требовало). Валидация: python-скрипт резолвит все markdown-ссылки
`docs/**` — 0 битых; `grep` frontmatter (`audience:`/`type:`) — 0 файлов без обеих меток; выборочный
grep 16 классов/сидеров и ключевых `config('ai.*')`-ключей — 0 расхождений с кодом;
`python3 plan-lint.py plans/EST-AI` — те же 5 pre-existing ошибок (Status Board P3/P4/P5/P7/P9),
новых нет; изменения docs-only, `docker compose exec app php artisan test` не запускался (не
требовался).
**Remaining:** P10.4 (1, операторские SOP + бизнес-разделы) → P11 (1, удаление Old/) → P12 (2,
только opus/Fable — Routing §3). Итого 4 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44; Status Board — P10 теперь 🟡 3/4) ·
plans/EST-AI/phases/P10.md (Phase Context + P10.1–P10.3 Completion Notes; текст P10.4 уже там же) ·
plans/EST-AI/research/H-docs-structure.md (SSOT дерева `docs/` + §Диаграммы + §Дополнение) ·
docs/03_Dev/** (весь раздел теперь наполнен — канон для сверки при P10.4, если понадобятся
перекрёстные ссылки) · resources/ai/prompts/README.md (единственный источник «как писать промт»,
не дублируется нигде в docs/).
**Open risks:**
- (без изменений) `03_Dev/crawling-pipeline.md` теперь содержит линк-карту краулинга, но НЕ полный
  разбор internals (Scope P10.3 не требовал переписывания краулинга с нуля) — если краулинг
  когда-нибудь будет ревизован отдельным планом, этот файл стоит расширить.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика при всех items 🟢/🟠 — задокументированное расхождение, не новое.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц — не тронуто P10.3 (Scope Included ограничивал перекрёстные ссылки P10.2).
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
  extraction).
- (без изменений) `SENTRY_LARAVEL_DSN` пуст; `json`-канал логов не default;
  `GracefullyStoppable`-трейт готов, но не подключён; `stop_grace_period` не пересчитан.
- (без изменений) `ShadowDispatch` НЕ подключён ни к одному боевому call-сайту (P9.4 Open risk).
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. git-историю handoff.md) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; `dispatch(...)->afterCommit()` под
  `RefreshDatabase` исполняется немедленно в определённых условиях; `Storage::disk('s3')` в тестах
  требует `Storage::fake('s3')`; `mermaid-cli` (`stateDiagram-v2`) не парсит `Class::method()` в
  некавычечной edge-метке — использовать `Class.method()`. **добавлено P10.3:** каркас `docker/
  development.md` ссылался на несуществующую make-цель `deploy:frontend` — фронтенд-сборка (yarn)
  идёт вне `make`, целей под неё в `docker/Makefile` нет.
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.4/P10.2) боевой тюнинг
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
  операторских/бизнес-разделов `docs/` — P10.4; сверка `crawling-pipeline.md`/`admin-panel.md` с
  фактическим кодом краулинга — частично сделано P10.3 (admin-panel — линк-ревизия по факту P8.2/
  P9.2; crawling-pipeline — карта, не полный разбор internals); паттерн «коарс-право +
  запись-специфичный чек» AzGuard как переиспользуемый trait/base-policy при появлении второго
  случая (ADR 0005 Consequences).
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; overlap-дедуп по составному ключу; источник
  `Estimate.title`; структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть
  промта (P7.1); порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод;
  боевой Telegram-токен/chat; калибровка порогов `pipeline:stuck-check`/`backlog_threshold`/
  `no_completions_minutes`; когда владелец подготовит эталоны для `ai:dataset:import`;
  `LOG_CHANNEL=json` на проде по умолчанию; `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор
  `RunFreshCrawler`/`RunArchiveCrawler` под `shouldStop()`; хост и расписание прод-бэкапов; окна
  retention/MinIO ILM `expire_days`; нужен ли `prc_ai_dataset_examples.analysis_id`; нужна ли
  `league/flysystem-aws-s3-v3` в `composer.json` явной зависимостью; нужна ли реальная проводка
  `ShadowDispatch` в этом же плане или отдельным будущим планом.

Context: handoff свежий — рекомендован /clear перед следующим item (P10.4 — Phase Context уже
прочитан этой линией сессий; свежая сессия обязана перечитать его заново по протоколу plan-exec).
