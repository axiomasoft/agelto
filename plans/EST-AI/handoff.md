# HANDOFF — 2026-07-09 — after P12.2 (EST-AI plan closed)

**Next:** Плана EST-AI больше не имеет open items — все 40 items (P1–P12) закрыты, 39 фактически
исполнены (30 предыдущими сессиями + P12.1/P12.2 в этой и предыдущей), P11.1 — ⛔ Skipped by decision
(D45, владелец не подтвердил `git rm Old/`). Следующий шаг — на усмотрение владельца: (а) новый план
на боевой тюнинг промтов силами другой модели (см. plan.md §1 «Следующий шаг владельца»); (б)
переисполнение P11.1, если владелец даст подтверждение на удаление `Old/`/`tmp/agelto*.md`; (в) любой
из открытых Pending Work/open_questions ниже как отдельный follow-up план. Никакого plan-exec
продолжения внутри EST-AI не требуется.
**Done:** P1–P10, P12 — закрыты (P1/P3/P4/P5/P7/P9/P12 — 🟠 Done with deviations; P2/P6/P8/P10 —
🟢 Done). P11 — ⛔ Skipped by decision (D45). Plan Meta Status → 🟠 Done with deviations
(2026-07-09). P12.2 (эта сессия, opus/Fable, effort high — Routing §3 plan.md): корневой `CLAUDE.md`
пересобран вручную из трёх правленных P12.1 источников (`.ai/guidelines/{architecture,glossary,
navigation}.md`); managed-хаб-блок `<!-- swissknifeman:hub:start/end -->` не тронут. Domain Hub
получил абзац про AI-подомен (пути `Services/{Llm,Procurement/{Prepare,Screening,Extraction}}`,
`Models/Procurement/Ai`, указатель на 3 скила P12.1); Glossary — компактную секцию «AI-подомен» (7
сущностей: `Ai\Analysis`, `Ai\Prompt`, `Ai\Correction`/`Ai\DatasetExample`, `PromptStep`≠
`AnalysisStep`≠`LlmPurpose`, `DocumentStatus::Unprocessable`+`UnprocessableReason`,
`ExtractionRoute`) с пометкой «полностью: `.ai/guidelines/glossary.md`»; Navigation Guide — Quick
Start п.4, 3 строки Fast Term Routing, 6 новых строк Reference Table (3 скила P12.1 + PR-чеклист,
ранее отсутствовавший в CLAUDE.md, + `runbooks/_index.md` + `04_Operators/_index.md` +
`ai-context/index.md` из каркаса P10/research H). Transaction & Jobs Policy п.4 дополнен LLM-
инвариантами (lease+status-guard, prefix-stable кэш, checkpoint-requeue). Синхронно поправлены
источники `.ai/guidelines/architecture.md`/`navigation.md` теми же формулировками (Fast Entry Rules,
Agent File Edit Safety, Transaction & Jobs Policy п.4, Docker-секция, Reference Table). Line budget:
189→185 строк (149 ручных + 36 managed), уложились в ≤200 за счёт компрессии «Глаголы методов»/
«Нейминг тестов» и снятия мёртвых ссылок на `AGENTS.md`/`GEMINI.md`/`.ai/skills/*`/`.cursor/rules/`
(см. Known Deviations P12.2 в `phases/P12.md`). **Валидация:** линк-чек — все 18 файловых + 19 skill-
ссылок в CLAUDE.md разрешены (0 расхождений); canary (3 задачи по Code Guidance пункта) —
«добавь поле в контракт сметы»→`estimate-extraction-dev`, «добавь провайдера Anthropic»→
`llm-layer-dev`, «оператор жалуется на зависший анализ»→`runbooks/_index.md`/`04_Operators/
_index.md` — все верно; `wc -l CLAUDE.md` = 185 ≤ 200; `python3 plan-lint.py plans/EST-AI` — те же 5
pre-existing ошибок (P3/P4/P5/P7/P9), новых нет; `skiller status` — 1 pre-existing warning
(`session-observer`/`shared-memory` + дубль marketplace/vendor каналов), не связан с этим item'ом.
**Remaining:** 0 items. P11.1 — вне remaining (Skipped, не Done), переисполнение — по решению
владельца (см. Next).
**Sources of truth:** `plans/EST-AI/plan.md` (Meta Status 🟠 Done with deviations; Decision Log
D1–D45; Status Board — все фазы терминальны) · `plans/EST-AI/phases/P12.md` (P12.1/P12.2 Completion
Notes/Known Deviations целиком) · `CLAUDE.md` (корень, 185 строк — актуальный хаб) ·
`.ai/guidelines/{architecture,glossary,navigation,docs-pr-checklist}.md` (источники хаба) ·
`.claude/skills/{procurement-pipeline-dev,estimate-extraction-dev,llm-layer-dev,
prompt-engineering-agelto,testing-rules,ai-context-workflow}/SKILL.md` ·
`.claude/rules/agelto-reviewer-overrides.md` · `docs/` (полное дерево P10, каркас research/H).
**Open risks:**
- (новое P12.2) `AGENTS.md`/`GEMINI.md` НЕ пересобраны — удалены отдельным осознанным решением
  владельца ДО этого плана (коммит `7b9cd004`, 2026-07-06, «single AI provider: Claude Code»); план
  унаследовал устаревшую формулировку «CLAUDE.md/AGENTS.md» в Intent/Files P12.2 (написано
  2026-07-07, на день позже коммита) — если владелец захочет второго провайдера обратно, это отдельное
  решение, не часть EST-AI.
- (без изменений P12.1) `.cursor/skills/` mirror локальных скилов НЕ существует в репозитории вообще
  (согласуется с удалением `.cursor/` тем же коммитом `7b9cd004` — вероятно, механизм зеркалирования
  для Cursor больше не актуален при single-provider Claude Code; решение, заводить ли вручную или
  считать окончательно отменённым, — открытый вопрос вне этого плана).
- (без изменений) `docs/03_Dev/ai-context/scenarios.md`/`index.md` (P10.3) не проверялись повторно на
  актуальность под 3 новых скила P12.1 (Scope Excluded обоих P12-items); если владелец захочет —
  отдельный docs-PR.
- (без изменений) `03_Dev/crawling-pipeline.md` — линк-карта краулинга, не полный разбор internals.
- (без изменений) plan-lint: 5 pre-existing ошибок Status Board P3/P4/P5/P7/P9 «Done with
  deviations»/расхождение счётчика — задокументированное расхождение, не новое, не исправлялось
  повторно (вне Scope P12.2 — правка чужих Status Board записей не по этому item'у).
- (без изменений) Модели EOL (D27): линейка 2.5 умирает 2026-10-16.
- (без изменений) Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца.
- (без изменений) Троттлинг Gemini не откалиброван против реальной квоты провайдера.
- (без изменений) `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют
  устаревшие имена таблиц.
- (без изменений) `docker compose exec` требует ДВА `--env-file`; `pint --dirty` не видит untracked;
  pint auto-fix может упереться в bind-mount permission.
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
- deferred: (без изменений полный список — см. git-историю handoff.md до P10.4/P12.1) боевой тюнинг
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
  случая; `.cursor/skills/` mirror локальных скилов (вероятно отменён вместе с `.cursor/` —
  решение владельца); обновление `docs/03_Dev/ai-context/scenarios.md`/`index.md` под 3 новых
  скила. **добавлено P12.2:** возврат `AGENTS.md`/`GEMINI.md`, если владелец захочет второго
  AI-провайдера — отдельное решение вне EST-AI.
- open_questions: (без изменений) подтверждение владельца перед `git rm Old/` (актуально, если
  P11.1 будет переисполнен); хост GlitchTip/боевой DSN; дата переключения на 3.x-модели; исходящий
  прокси для Gemini; структурный reason-столбец на `prc_documents`/`prc_ai_analyses`; overlap-дедуп
  по составному ключу; источник `Estimate.title`; структурный `is_merged_from_split`; как развести
  кэшируемую/per-purpose часть промта; порог explicit vs implicit cache; курс
  `AI_COST_REPORT_RUB_PER_USD` в прод; боевой Telegram-токен/чат; калибровка порогов
  `pipeline:stuck-check`/`backlog_threshold`/`no_completions_minutes`; когда владелец подготовит
  эталоны для `ai:dataset:import`; `LOG_CHANNEL=json` на проде по умолчанию;
  `HORIZON_STOP_GRACE_PERIOD` точное значение; рефактор `RunFreshCrawler`/`RunArchiveCrawler` под
  `shouldStop()`; хост и расписание прод-бэкапов; окна retention/MinIO ILM `expire_days`; нужен ли
  `prc_ai_dataset_examples.analysis_id`; нужна ли `league/flysystem-aws-s3-v3` явной зависимостью;
  нужна ли реальная проводка `ShadowDispatch` в этом же плане или отдельным будущим планом; заводить
  ли `.cursor/skills/` вручную (или считать отменённым вместе с `.cursor/`); **добавлено P12.2:**
  нужен ли `AGENTS.md`/`GEMINI.md` обратно (второй AI-провайдер) — если да, отдельный план/задача
  пересобрать их из тех же `.ai/guidelines/**`-источников по образцу CLAUDE.md.

Context: EST-AI закрыт (Meta Status 🟠 Done with deviations, 2026-07-09). Рекомендован /clear —
следующая работа в этом репозитории начинается с чтения актуального `CLAUDE.md` (роутинг-хаб, 185
строк) и, при необходимости нового плана, `.ai/guidelines/**` источников напрямую.
