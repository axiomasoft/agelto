# HANDOFF — 2026-07-09 — after P9.2

**Next:** `/task:plan-exec EST-AI P9.3` (Phase Context `plans/EST-AI/phases/P9.md` уже прочитан
многократно этой линией сессий — исполнителю P9.3 достаточно перечитать сам item + `Required
Reads`, Phase Context не устарел).
**Done:** P1–P8 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P9.1 закрыт
🟢, P9.2 закрыт 🟠 Done with deviations (2/4 фазы P9). P9.2: `CorrectionAwareComposer`
(`app/Services/Llm/CorrectionAwareComposer.php`) — блок «Замечания оператора к предыдущему
разбору» из коррекций `parent_analysis_id`-предшественника + reusable-коррекций того же
`document_type` (`hint ?? content`), подключён в `RowRangeChunkSource::call()` ДОБАВЛЕНИЕМ
userPart перед вопросом (systemPart/кэшируемый префикс не тронут). `RerunAnalysisAction`
(`app/Services/Procurement/Extraction/RerunAnalysisAction.php`) — ОБЯЗАТЕЛЬНЫЙ порядок
(партиция `prc_ai_analyses_active_version_step_unique` — обычный, не `DEFERRABLE`, индекс):
(1) старый анализ → `superseded_at`, (2) новый `pending` с `parent_analysis_id`
(`AnalysisStoreRepository::createRerun()`, новый метод), (3) непогашенные коррекции старого →
`applied_at`, (4) документ откатывается к pre-stage статусу (`prepared`/`screened` —
`ScreeningJob`/`EstimateExtractionJob::statusGuardPassed()` требуют именно его), (5) штатный job
стадии диспатчится заново. `EstimatePersister::persist()` получил `?int $supersededAnalysisId` —
удаляет сметы заменённого анализа В ТОЙ ЖЕ транзакции, что персист новых
(`EstimateStoreRepository::deleteByAnalysisId()`, новый метод; каскад БД чистит sections/items/
resources/totals); `ExtractionOrchestrator::finalizeSuccess()` прокидывает
`analysis.parent_analysis_id`. Filament: `AnalysisResource`
(`app/Filament/Resources/Procurement/Ai/Analyses/**`, read-only index+view — create/edit
убраны) с действиями «Проверено» (только `status=succeeded`), «Отклонить с коррекцией»
(создаёt `Correction` + `review_status=rejected`), «Перезапустить разбор»
(`RerunAnalysisAction`); `CorrectionsRelationManager` read-only. Тесты:
`OperatorFeedbackLoopTest` (2 — домен-цикл rejected+correction→re-run→hint в USER-части второго
прогона через `FakeLlmProvider::receivedPrompts()`, systemPart БЕЗ hint, старые сметы заменены
новыми, `correction.applied_at` проставлен; авторизация `can('update', $analysis)`),
`--filter=OperatorFeedbackLoop` 2 passed, полный сьют 446 passed (1441 assertions, было
444/1411), pint чист, `migrate:fresh --seed` ок, `guard:sync-roles`/`guard:doctor`/
`guard:catalog:validate` без НОВЫХ ошибок (только pre-existing «нет `#[GateAbility]`»-шум
enum-RBAC ресурсов без policy).
**Remaining:** P9.3–P9.4 (2) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3).
Итого 9 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board —
P9 теперь 🟡 2/4) · plans/EST-AI/phases/P9.md (Phase Context + P9.1/P9.2 Completion Notes — что
именно сделано; P9.3–P9.4 читать перед exec) ·
`app/Services/Procurement/Extraction/RerunAnalysisAction.php` (канон порядка операций re-run —
partial unique не DEFERRABLE, порядок supersede→create→dispatch обязателен) ·
`app/Services/Llm/CorrectionAwareComposer.php` (канон USER-part-only коррекций, systemPart
неприкосновенен) · `app/Repositories/Procurement/{AnalysisStoreRepository,
EstimateStoreRepository}.php` (`createRerun()`/`deleteByAnalysisId()` — новые методы) ·
`app/Filament/Resources/Procurement/Ai/Analyses/**` (образец read-only ресурса с
action-триггерами домена + `->authorize('update')` через enum-RBAC) ·
`tests/Feature/Procurement/Ai/OperatorFeedbackLoopTest.php` (образец теста re-run-цикла + приём
`Queue::fake()` вокруг `->afterCommit()`-диспатча под RefreshDatabase).
**Open risks:**
- P9.3 (`GoldExampleRecorder`/`ai:dataset:export`/`EstimateToContractMapper`) — единственный
  потребитель `review_status=approved` пока не написан (P9.2 даёт кнопку «Проверено», но
  подписки на approve → gold-снапшот нет); Scope P9.2 явно исключал auto-конверсию в gold.
- `CorrectionAwareComposer` подключён ТОЛЬКО в `RowRangeChunkSource` (row-range путь, P6.1) — не
  в `ScreeningProcessor`/`WindowedChunkSource` (P6.4); если появится реальный кейс коррекции на
  screening-шаге или windowed-извлечении, hints туда не попадут без доп. проводки.
- `RerunAnalysisAction` покрыт тестом только для extraction-шага (основной сценарий владельца
  «перезапустить парсинг сметы»); screening-ветка (`Prepared`-откат) не имеет отдельного теста —
  код симметричен extraction-ветке, но не подтверждён изолированно.
- (новое, P9.2) RBAC-плюмбинг сверх заявленного Scope Excluded «RBAC»: `AnalysisPermission`
  (enum-RBAC catalog) + `AnalystRole`/`OperatorRole` получили минимальный набор (ViewAny/View/
  Update) — тонкая грануляция прав (кто approve vs reject vs rerun отдельно) не разведена; при
  ревью RBAC-политики проекта учесть, что это добавление сделано exec-сессией, не design-сессией.
- (новое, P9.2) `ScreeningJob`/`EstimateExtractionJob::statusGuardPassed()` — правка `active()`
  скоупа (review-фикс латентного P9.1-дефекта) затрагивает КАЖДЫЙ повторный диспатч этих jobs
  (backlog-поллер, ручной ретрай, не только re-run) — регрессия покрыта только полным сьютом
  (446 passed), не изолированным regression-фильтром именно этой правки.
- (без изменений, унаследовано из P9.1) partial unique `prc_ai_analyses_active_version_step_unique`
  — имя длиннее 63 байт лимита Postgres НЕ проверялось явно вручную (индекс успешно создаётся на
  `migrate:fresh`, значит уложился).
- (без изменений) exclude одного ресурса (`PromptResource`) из AzGuard-каталога снимает его из
  ResourceGate enum-RBAC — при будущей `guard:filament:generate --source=enum` перепроверить,
  что `PromptResource` остаётся в `exclude`.
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
- (без изменений) `RerunRangeAction` (P6.3, точечный re-run диапазона строк — НЕ путать с
  P9.2 `RerunAnalysisAction`, повторный прогон анализа целиком) НЕ пересчитывает
  `validation_status`/иерархию целиком.
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
  git-историю); `.env.example` слепой append; `pint --dirty` — явный список путей; один
  `--filter` за прогон; dot-notation в `config('ai.capabilities.<модель-с-точками>...')`;
  Symfony Console `--version` зарезервирован; `mc ilm rule add` не идемпотентен сам по себе;
  регресс-тест на exclude-строку AzGuard — ручной temporary-revert; **добавлено P9.2:**
  `dispatch(...)->afterCommit()` под `RefreshDatabase` исполняется НЕМЕДЛЕННО, если на момент
  диспатча активна только обёртывающая транзакция теста
  (`Illuminate\Foundation\Testing\DatabaseTransactionsManager::addCallback()`) — тесты
  промежуточного состояния перед реальным async-прогоном ОБЯЗАНЫ `Queue::fake()` (приём уже был
  в `ExtractionOrchestratorTest`, P9.2 подтвердил и задокументировал причину).
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
  lifecycle` вне scheduler; Filament `#[Label]`-атрибуты для `CorrectionReason`/`ReviewStatus`
  (P9.1-хвост, всё ещё не сделано); таргетный regression-прогон extraction-веток
  `AnalysisStoreRepository`-ретрофита; **добавлено P9.2:** hints в `ScreeningProcessor`/
  `WindowedChunkSource`; отдельный тест screening-ветки `RerunAnalysisAction`; тонкая RBAC-
  грануляция действий карточки анализа (approve/reject/rerun отдельными правами); `Estimate`
  как самостоятельный Filament-ресурс (Фаза 7 роадмапа, Scope Included P9.2 явно отложил).
- open_questions: (без изменений) подтверждение владельца перед git rm Old/; хост GlitchTip/
  боевой DSN; дата переключения на 3.x-модели; исходящий прокси для Gemini; структурный
  reason-столбец на `prc_documents`/`prc_ai_analyses`; guard-фикс `ScreeningJob::
  statusGuardPassed()` (ЧАСТИЧНО закрыт P9.2 — `active()`-скоуп добавлен, но исходный вопрос
  «нужен ли структурный флаг вместо статус-угадывания» открыт); overlap-дедуп по составному
  ключу; источник `Estimate.title`; структурный `is_merged_from_split`; как развести
  кэшируемую/per-purpose часть промта (P7.1); порог explicit vs implicit cache; курс
  `AI_COST_REPORT_RUB_PER_USD` в прод; боевой Telegram-токен/chat; калибровка порогов
  `pipeline:stuck-check`; когда владелец подготовит эталоны для `ai:dataset:import`;
  `LOG_CHANNEL=json` на проде по умолчанию; `HORIZON_STOP_GRACE_PERIOD` точное значение;
  рефактор `RunFreshCrawler`/`RunArchiveCrawler` под `shouldStop()`; хост и расписание
  прод-бэкапов; окна retention/MinIO ILM `expire_days`; **добавлено P9.2:** нужна ли
  P9.3-подписка на `review_status=approved` автоматической (event-driven) или командной
  (`ai:dataset:record-gold`)?

Context: handoff свежий — рекомендован /clear перед следующим item (P9.3 — Phase Context P9.md
актуален этой сессией; исполнителю достаточно перечитать сам item + Required Reads).
