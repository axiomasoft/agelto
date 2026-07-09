# HANDOFF — 2026-07-09 — after P10.1

**Next:** `/task:plan-exec EST-AI P10.2` — фаза P10 в работе (1/4), следующий item — диаграммы
Д1–Д6 по фактическому коду (Phase Context `plans/EST-AI/phases/P10.md` уже прочитан этой линией
сессий, P10.2 текст item'а — тоже; исполнителю P10.2 всё равно нужно перечитать сам item целиком
плюс Required Reads по протоколу, повторное чтение Phase Context не требуется в рамках той же
линии, но новая сессия должна прочитать заново по правилам plan-exec).
**Done:** P1–P9 закрыты (P1/P3/P4/P5/P7/P9 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P10.1
закрыт 🟢 Done — фаза P10 теперь 🟡 In progress (1/4). P10.1: полный скелет `docs/` по каркасу
`plans/EST-AI/research/H-docs-structure.md` (базовое дерево + §Дополнение SOVA-референса) — 44
новых файла. `docs/index.md` — реальный роутер (не заглушка): таблица «Для кого что читать»
(владелец/оператор/разработчик/DevOps/AI-агент) + «Быстрые ссылки», по образцу
`~/sibintek/projects/sova/docs/index.md`. Категории `00_Ideas/ 01_Business/ 02_Workflow/ 03_Dev/
04_Operators/ meta/` с `_index.md`-роутерами, несущими реальные ссылки на листовые файлы.
Листовые файлы — frontmatter (`audience: dev|operator|owner|shared`, `type:
howto|reference|explanation|runbook|adr`, `status: draft`) + 3–5-строчная аннотация «что здесь
будет» из выжимок H; `04_Operators/how-to/*.md` дополнительно несут `last-reviewed: 2026-07-09`.
Дельта §Дополнение учтена: `03_Dev/{getting-started,commands,recipes,troubleshooting}.md`,
`03_Dev/docker/{index,development,production,troubleshooting}.md`, `03_Dev/ai-context/{index,
scenarios}.md` (агентский контур, отдельно от `03_Dev/ai/` — LLM-пайплайн), `02_Workflow/
prompts-and-quality.md`, `meta/editing.md` — все созданы. `docs/dev/subagents-and-workflows.md` и
`docs/specs/v2/**` НЕ тронуты (Implementation Rules), вписаны ссылками в роутеры. Новый файл
`.ai/guidelines/docs-pr-checklist.md` — сквозное правило PR-чеклиста («docs обновлены?»/
«SOP обновлён при Filament-правке?»), применимо ко ВСЕМ PR (не дублирует доменный чеклист skill
`procurement-pipeline-dev`); одна строка-ссылка добавлена в `.ai/guidelines/navigation.md`
Reference Table. `CLAUDE.md`/`AGENTS.md` не тронуты (генерируемые, Scope Excluded). Валидация:
diff списка путей `docs/**` (без `specs/`/`dev/`) против явного списка H+§Дополнение — 0
расхождений; Python-скрипт резолвит markdown-ссылки во всех файлах `docs/**` — 0 битых ссылок;
`grep` `^audience:`/`^type:` по каждому новому файлу — 0 без обеих меток; `plan-lint.py plans/EST-AI`
— 5 ошибок, все pre-existing (см. Open risks), новых не добавлено.
**Remaining:** P10.2–P10.4 (диаграммы → dev-разделы → операторские SOP, наполнение скелета
контентом) → P11 (1, удаление Old/) → P12 (2, только opus/Fable — Routing §3). Итого 6 items
впереди из 40 (было 7 до P10.1).
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board —
P10 теперь 🟡 1/4) · plans/EST-AI/phases/P10.md (Phase Context + P10.1 Completion Notes — точный
список 44 созданных файлов + валидационные команды; P10.2–P10.4 тексты items уже там же) ·
plans/EST-AI/research/H-docs-structure.md (SSOT дерева `docs/`, включая §Дополнение — используется
и P10.2–P10.4) · `docs/index.md` + все `**/_index.md` (канон роутеров — образец для будущих правок
навигации) · `.ai/guidelines/docs-pr-checklist.md` (новое сквозное правило) ·
`.ai/guidelines/navigation.md` (добавлена строка-ссылка на чеклист).
**Open risks:**
- (новое, P10.1) Скелет `docs/` целиком в статусе `draft` (кроме роутеров — `living`) — P10.2–P10.4
  обязаны наполнить контент; пустой каркас без наполнения бесполезен для аудиторий, кроме
  навигации.
- (новое, P10.1) `03_Dev/crawling-pipeline.md` и `03_Dev/admin-panel.md` помечены в тексте файла как
  «наполнен вне плана/частично — линк-ревизия» — P10.3 должен явно сверить их с фактическим кодом
  краулинга/Filament (P10.1 этого не делал, только создал стаб с пояснением, раздел раньше не
  существовал физически в `docs/03_Dev/`).
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика при всех items 🟢/🟠 — задокументированное расхождение, не новое.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц (P10.2/P10.3 могут заодно поправить перекрёстные ссылки).
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
  вне Scope P10, зафиксировано для будущего item'а.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. git-историю handoff.md) два `--env-file`; composer/pint-автофикс/
  `vendor:publish`/`guard:filament:generate` — с ХОСТА; один `--filter` за прогон; dot-notation в
  `config('ai.capabilities.<модель-с-точками>...')`; `dispatch(...)->afterCommit()` под
  `RefreshDatabase` исполняется немедленно в определённых условиях; `Storage::disk('s3')` в тестах
  требует `Storage::fake('s3')`. P10.1 новых workaround'ов не добавил (документационный item, кода
  не менял).
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.4) боевой тюнинг
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
  для теневого скрининга; перенос shadow-сравнения в `prc_ai_eval_results`; **добавлено P10.1:**
  наполнение содержимого всех разделов `docs/` — P10.2 (диаграммы Д1–Д6) → P10.3 (dev-разделы) →
  P10.4 (операторские/бизнес-разделы); сверка `crawling-pipeline.md`/`admin-panel.md` с фактическим
  кодом (P10.3).
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

Context: handoff свежий — рекомендован /clear перед следующим item (P10.2 — Phase Context уже
прочитан этой линией сессий; свежая сессия обязана перечитать его заново по протоколу plan-exec).
