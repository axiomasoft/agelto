# HANDOFF — 2026-07-09 — after P9.1

**Next:** `/task:plan-exec EST-AI P9.2` (Phase Context `plans/EST-AI/phases/P9.md` уже прочитан этой
сессией — исполнителю P9.2 достаточно перечитать сам item + `Required Reads`, Phase Context не
устарел).
**Done:** P1–P8 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P9.1 закрыт
🟢 (1/4 фазы P9): миграция `2026_07_04_100025` (env=local, гейт подтверждён `php artisan env`)
получила 7 feedback-колонок на `prc_ai_analyses` (`review_status`/`reviewed_by`/`reviewed_at`/
`operator_comment`/`parent_analysis_id`/`superseded_at`/`is_shadow`, D24), старый
`unique(document_version_id, step)` заменён raw-DDL partial unique
`prc_ai_analyses_active_version_step_unique` (`WHERE superseded_at IS NULL AND is_shadow = false`);
новая миграция `2026_07_09_100052_create_prc_ai_corrections_table` (`prc_ai_corrections`,
`analysis_id` cascadeOnDelete, `estimate_id` nullOnDelete, `created_by` FK users); enum'ы
`App\Enums\Procurement\{CorrectionReason,ReviewStatus}`; `Analysis` — fillable/casts/связи
(`reviewedBy()`/`parentAnalysis()`/`corrections()`) + scope `active()` + докблок «DPO-пары
выводятся запросом, отдельной таблицы нет»; новая модель `Ai\Correction` (uuid7,
`analysis()`/`estimate()`/`createdBy()`); ретрофит `AnalysisStoreRepository::firstOrCreate()` —
поиск через `active()`-скоуп вместо `Model::firstOrCreate()` (единственный потребитель
уникальности — `ScreeningJob`+`EstimateExtractionJob`, оба используют один репозиторий).
Тесты: `AiCorrectionTest` (9), `--filter=AiCorrection` 9 passed, регрессия `--filter=Screening`
33 passed, полный сьют 444 passed (1411 assertions, было 434/1384), pint чист. Известных
отклонений нет.
**Remaining:** P9.2–P9.4 (3) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого
11 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board — P9
теперь 🟡 1/4) · plans/EST-AI/phases/P9.md (Phase Context + P9.1 Completion Notes — что именно
сделано; P9.2–P9.4 читать перед exec) · `app/Models/Procurement/Ai/{Analysis,Correction}.php`
(канон feedback-схемы: scope `active()`, DPO-докблок) ·
`app/Repositories/Procurement/AnalysisStoreRepository.php` (канон поиска активного анализа —
образец для будущих repository-методов, тронутых D24) · `database/migrations/2026_07_04_100025_*`
(правка allowed — данных не было, тот же гейт, что P1.4/P8.1) ·
`database/migrations/2026_07_09_100052_*` (новая таблица `prc_ai_corrections`) ·
`tests/Feature/Procurement/AiCorrectionTest.php` (партиция активный/superseded/shadow — образец
для тестов P9.2 re-run-цикла).
**Open risks:**
- P9.2 (`CorrectionAwareComposer`/`RerunAnalysisAction`/Filament-действия) — единственный
  потребитель `Ai\Correction`/`ReviewStatus` пока не написан; поля `hint`/`is_reusable`/
  `applied_at` не проверены на реальном use-case подмешивания в промт (ожидаемо, Scope P9.1
  явно исключал UI/композицию).
- `ExtractionOrchestrator`/`EstimateExtractionJob` (P6.1) — ретрофит
  `AnalysisStoreRepository::firstOrCreate()` покрыт только полным сьютом (444 passed, включая
  `ExtractionOrchestratorTest`/`EstimatePersisterTest`/`RerunRangeTest`), НЕ отдельным
  таргетным regression-прогоном именно extraction-веток P9.1-изменения — риск низкий (общий
  код с screening, который прогонялся явно), но не подтверждён изолированно.
- partial unique `prc_ai_analyses_active_version_step_unique` — имя длиннее 63 байт лимита
  Postgres НЕ проверялось явно (индекс создан успешно на `migrate:fresh`, значит уложился;
  байт-длина не пересчитывалась вручную).
- `CorrectionReason`/`ReviewStatus` без Filament `#[Label]`-атрибутов (enum-attributes канон
  проекта) — добавит P9.2 при построении форм действий «Отклонить с коррекцией».
- (без изменений, унаследовано из P8.2) exclude одного ресурса из AzGuard-каталога снимает его
  из ResourceGate enum-RBAC — при будущей `guard:filament:generate --source=enum` перепроверить
  `PromptResource` остаётся в `exclude`.
- (без изменений) любой будущий item с Filament-Policy поверх AzGuard-enum-модели — паттерн
  `PromptPolicy` как образец.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7 «Done with
  deviations» при всех items 🟢/🟠 — задокументированное расхождение, не новые.
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами
  используют устаревшие имена таблиц.
- (без изменений) `docker compose exec` требует ДВА `--env-file`; `pint --dirty` не видит
  untracked — явный список путей.
- (без изменений) `composer require`/`composer update --lock` — только с ХОСТА.
- (без изменений) `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- (без изменений) `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется.
- (без изменений) explicit context cache НЕ подключён к `ExtractionOrchestrator`/
  `RowRangeChunkSource`; `LlmCacheManager` — CRUD без production-потребителя.
- (без изменений) `EstimatePersister` пропускает сметы документов без `procurement_id`.
- (без изменений) `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию целиком.
- (без изменений) `mergedIncompleteItems` не персистятся в `prc_estimate_items`.
- (без изменений) Пороги `pipeline:stuck-check` НЕ откалиброваны на живом трафике.
- (без изменений) `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env`.
- (без изменений) `ai:eval:run` НЕ прогонялся ни разу на реальных данных.
- (без изменений) `SENTRY_LARAVEL_DSN` пуст; `json`-канал логов не default;
  `GracefullyStoppable`-трейт готов, но не подключён; `stop_grace_period` не пересчитан.
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений, см. предыдущий handoff) два `--env-file`; composer/pint-автофикс/
  `vendor:publish` — с ХОСТА; `.env.example` слепой append; `pint --dirty` — явный список путей;
  один `--filter` за прогон; dot-notation в `config('ai.capabilities.<модель-с-точками>...')`;
  Symfony Console `--version` зарезервирован; `mc ilm rule add` не идемпотентен сам по себе;
  регресс-тест на exclude-строку AzGuard — ручной temporary-revert.
- deferred: (без изменений полный список — см. git-историю handoff.md до P9.1) боевой тюнинг
  промт-текстов; Batch API; RAR/7z; OCR; LibreOffice-конвертер xlsx; Ghostscript-даунскейл;
  generic ai_extractions; контакты-сущность CRM; 9-префиксный ренейм (отклонён); Prometheus/
  Grafana; прод GlitchTip/Sentry; shadow CI-гейт; калибровка порогов; 3.x-модели; Langfuse;
  устаревшие имена таблиц 03/04; Gemini-прокси; калибровка rate_limit; тариф >200k; ренейм
  `Ai\AiRoute`; per-purpose роутинг; runtime-обнаружение лимита после preflight; полный
  пересчёт `validation_status` после `RerunRangeAction`; путь `mergedIncompleteItems` в UI/
  датасет; `image/*` в windowed-пути; explicit context cache; `ai:caches prune` в расписание;
  live FX-курс; маршруты `eval`/`shadow` в `AiRoutingSeeder`; v1-carry-over проверки;
  `EstimatePersister` под manual-документы; эвристика сопоставления многосметных gold;
  `EvalRun.prompt_version_set`; `gold_ref`/object storage; Pulse `sample_rate`/retention;
  `PULSE_ENABLED`/`PULSE_PATH` в прод `.env`; `db:backup` расписание/off-host; `ai:storage:
  lifecycle` вне scheduler; **добавлено P9.1:** Filament `#[Label]`-атрибуты для
  `CorrectionReason`/`ReviewStatus` (P9.2); таргетный regression-прогон extraction-веток
  `AnalysisStoreRepository`-ретрофита, если появится изолированный extraction-фильтр теста.
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; guard-фикс `ScreeningJob::
  statusGuardPassed()`; overlap-дедуп по составному ключу; источник `Estimate.title`;
  структурный `is_merged_from_split`; как развести кэшируемую/per-purpose часть промта (P7.1);
  порог explicit vs implicit cache; курс `AI_COST_REPORT_RUB_PER_USD` в прод; боевой Telegram-
  токен/chat; калибровка порогов `pipeline:stuck-check`; когда владелец подготовит эталоны для
  `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию; `HORIZON_STOP_GRACE_PERIOD`
  точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler` под `shouldStop()`; хост и
  расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`.

Context: handoff свежий — рекомендован /clear перед следующим item (P9.2 — Phase Context P9.md
уже прочитан этой сессией, начинать с самого item + Required Reads).
