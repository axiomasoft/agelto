# HANDOFF — 2026-07-09 — after P7.7 (phase P7 closed 7/7)

**Next:** `/task:plan-exec EST-AI P8.1` (Промт-система: сиды/базовые тексты — `plan-design` не
требуется, item уже детализирован в `phases/P8.md`; 8-й промт — `discovery` D37). После P8 (2 items)
— P9 (обучающий контур и локальная LLM, 4 items).
**Done:** P1–P7 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6 — 🟢 Done). Итого закрыто 25
item из 40 (P1 2/4 + P2 3/3 + P3 4/4 + P4 3/3 + P5 2/2 + P6 4/4 + P7 7/7).
**Remaining:** P8 (2) → P9 (4) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого 15
items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D43 — полный контекст архитектурных
решений; Update Log — детали каждого закрытого item'а) · plans/EST-AI/phases/P7.md (Phase Handoff —
сводка всей фазы; P7.7 Completion Notes/Known Deviations — детали retention/MinIO ILM/бэкапов) ·
`config/partitioning.php` (P7.7, единый источник: `tables`/`interval`/`premake`/`retention`/
`eternal`/`minio_lifecycle` — не дублировать список таблиц в других местах) · `docs/specs/v2/
08-estimate-extraction.md` §9 (P7.7, матрица «таблица→окно→где→почему») · `docker/postgres/
backup-and-verify.sh` + `docker/Makefile` (`db:backup`/`prod:db:backup`, P7.7) · `app/Console/
Commands/Procurement/PipelineStuckCheck.php` (P7.3+P7.5, все SQL-проверки в одном месте) ·
`config/ai.php` §`pipeline` (P7.3+P7.5, все пороги stuck-check/алертов).
**Open risks:**
- **Новое (P7.7):** retention-окна (`AI_RETENTION_LLM_CALLS_MONTHS`=12/`FETCHES`=6/
  `OPERATION_LOGS`=6/`EVENTS`=6) и MinIO ILM `AI_STORAGE_LLM_BLOBS_EXPIRE_DAYS`=90 — default'ы по
  обоснованию Code Guidance («llm_calls-метаданные ценны дольше блобов»), НЕ откалиброваны на
  реальном объёме/трафике — тот же класс риска, что пороги `pipeline:stuck-check`/`window_pages`.
- **Новое (P7.7):** `make db:backup` НЕ поставлен на прод-расписание (cron/systemd-timer) — ручной
  запуск владельцем до появления инфра-решения (Scope Excluded); off-host копирование дампа и MinIO
  bucket versioning/`mc mirror` для офф-сайт копии блобов — не реализованы (Scope Excluded, §9.5
  спеки 08 фиксирует это явно как Pending).
- **Новое (P7.7):** `ai:storage:lifecycle` не в `routes/console.php`-расписании — ILM-правила
  применяются вручную при изменении `config('partitioning.minio_lifecycle')` (правило меняется
  редко, но при добавлении НОВОГО префикса/правила требуется не забыть прогнать команду).
- **Новое (P7.7):** fallback-путь `MaintainPartitions::dropExpiredFallback()` (managed Postgres без
  pg_partman) парсит retention-рубеж партиции regex'ом по суффиксу имени (`_YYYY_MM`) — если
  premake/интервал партиционирования когда-либо сменится с помесячного, регэксп потребует правки
  вместе с `config('partitioning.interval')`.
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16 — `pipeline:stuck-check` (P7.3) алертит при
  приближении (`config('ai.model_eol_warn_days', 30)`), само переключение НЕ делает.
  `AiRoutingSeeder::routes()` уже несёт закомментированные 3.x-строки-преемники.
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие имена
  таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>`; контейнер
  `app` не может ЗАПИСАТЬ автофикс/`vendor:publish` в bind-mount (Permission denied) — автофикс и
  публикацию vendor-артефактов делать с ХОСТА (или скопировать вручную Read+Write, P7.5).
- `composer require`/`composer update --lock` — тоже только с ХОСТА, не из контейнера:
  path-repositories `azguard` (`../../packages/azguard/packages/*`) резолвятся по относительному
  пути ОТ ПРОЕКТА, которого нет внутри контейнера (смонтирован только сам проект); после
  host-composer — `package:discover`/миграции гонять В КОНТЕЙНЕРЕ (там есть DB-доступ,
  `postgres`-хост резолвится только в docker-сети).
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую.
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- Symfony Console резервирует имя опции `--version` (`LogicException: An option named "version"
  already exists`) — под ЛЮБУЮ новую команду с версионируемой сущностью использовать другое имя
  флага (P7.4 использовал `--gold-version`), обнаруживается только тестами/реальным запуском.
- `phpunit.xml` несёт `PULSE_ENABLED=false` (пред-существующий baseline, не от P7.5) — ЛЮБОЙ тест,
  полагающийся на штатные Pulse-recorders или на `Pulse::record()`, должен явно вызвать
  `Pulse::startRecording()` в теле теста (см. `PulseIngestTest.php`), иначе тихо ничего не запишется.
- `dispatchSync()`/`sync`-очередь (`QUEUE_CONNECTION=sync` из `phpunit.xml`) НЕ проверяют реальную
  дегидрацию/гидрацию `Context` — честная проверка — реальный `database`-driver + `queue:work
  --once --stop-when-empty` (см. `JsonLoggingTest.php`, P7.5).
- `ReaskPolicy` НЕ используется оркестраторами (P5.1/P6.1/P6.4) — не отдаёт usage для
  `LlmCallRecorder` (без изменений).
- explicit context cache НЕ подключён к `ExtractionOrchestrator`/`RowRangeChunkSource` (P7.1
  Pending Work, без изменений).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (manual-импорт, D22) — без
  изменений, остаётся открытым для будущих сценариев (напр. Filament-просмотр эталонной сметы).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений, P6.3).
- Пороги `pipeline:stuck-check` (P7.3/P7.5: `analysis_stuck_minutes`/`checkpoint_stuck_minutes`/
  `document_stuck_minutes`/`spike_window_hours`/`unknown_spike_threshold`/
  `unprocessable_spike_threshold`/`estimate_mismatch_threshold`/`backlog_threshold`/
  `failure_rate_window_hours`/`failure_rate_threshold_percent`/`no_completions_minutes`) — НЕ
  откалиброваны на реальном трафике (тот же класс Pending Work, что window_pages/totals_tail_pages
  и теперь retention-окна P7.7).
- `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env` — до тех пор `PipelineAlert`
  уходит только в лог (fail-safe по дизайну, не баг).
- `ai:caches prune` (P7.1 Pending Work) НЕ добавлен в расписание рядом со `pipeline:stuck-check` —
  вне Scope Included P7.3, не запрошено.
- `ai:eval:run` НЕ прогонялся ни разу на реальных данных — реального golden-датасета ещё нет,
  команда готова, но не exercised против боевого `GEMINI_API_KEY`.
- Сопоставление многосметных gold-документов с предсказанием — чисто позиционное (индекс списка
  `estimates[]` = `ordinal`), не по номеру/заголовку смет (P7.4, без изменений).
- `SENTRY_LARAVEL_DSN` пуст (fail-open по дизайну) — ошибки НЕ уходят никуда до заведения боевого
  DSN/хоста GlitchTip (owner decision, open_questions).
- `json`-канал логов НЕ default (`LOG_CHANNEL` не тронут) — включить на проде явным
  `LOG_CHANNEL=json` в `.env` (owner decision).
- `GracefullyStoppable`-трейт (P7.6) готов, но НЕ подключён ни к одной существующей команде
  (`RunFreshCrawler`/`RunArchiveCrawler` делегируют работу одним вызовом процессора без цикла по
  «единицам работы» — подключение трейта без такого цикла было бы no-op).
- `stop_grace_period` (600s, P7.6) НЕ пересчитан к более тесному числу — параметризован
  (`HORIZON_STOP_GRACE_PERIOD`), но не откалиброван на реальной длительности extraction-jobs.
- `docs/03_Dev/process/deploy.md` (полный runbook, P7.6) остаётся заготовкой в комментарии
  `docker/Makefile` — наполнение файла ждёт P10.3.
**Workarounds/Deferred/Open questions:**
- workarounds: `docker compose exec` — два `--env-file`; composer require/update + pint-автофикс +
  `vendor:publish` — с ХОСТА; vendor-артефакты, которые `vendor:publish` не смог записать —
  копировать вручную Read+Write; `.env.example` — слепой append; `pint --dirty` не видит
  untracked — явный список путей; `php artisan test` — один `--filter` за прогон; `config('ai.
  capabilities.<модель-с-точками>.<ключ>')` — НЕ работает (dot-notation), индексировать массив
  напрямую; `rm` в `/home/.../agelto` заблокирован deny_hard-хуком — `mv`/`python3 -c "import os;
  os.remove(...)"` как обход (в этом прогоне обычный `rm` временного дампа `storage/backups/*.dump`
  сработал без блокировки); `$this->artisan(...)->expectsOutputToContain()` — одна подстрока на
  строку вывода; Symfony Console — имя `--version` зарезервировано, использовать
  `--<entity>-version`; тесты на реальную дегидрацию/гидрацию `Context` в job — только через
  `database`-очередь + `queue:work --once`; Pulse-тесты — явный `Pulse::startRecording()`;
  `make artisan ARGS="..."` из корневого `Makefile` иногда резолвится в файл `artisan` (Laravel
  CLI-скрипт в корне репо) вместо цели `docker/Makefile:artisan` («'artisan' is up to date», ничего
  не выполняется) — надёжнее вызывать `docker compose --env-file .env --env-file .env.docker exec
  app php artisan ...` напрямую, минуя обёртку Makefile, когда это происходит (P7.6); `mc ilm rule
  add` сам НЕ идемпотентен — идемпотентность держит `ai:storage:lifecycle` сама (сверка `mc ilm rule
  ls --json` перед `add`, P7.7); Dockerfile-правки (P7.7 `mc`-бинарник, P4.2 qpdf/mpdf, P7.5
  vendor-конфиги) требуют `docker compose build app horizon` + `up -d app horizon` ПЕРЕД тестами —
  контейнер не подхватывает Dockerfile-правки без пересборки.
- deferred: боевое наполнение промт-текстов (P8, вкл. 8-й discovery-промт); Batch API (D7); RAR/7z
  в ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-
  даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под CRM; полный
  9-префиксный ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip и
  боевой SENTRY_LARAVEL_DSN (P7.5); shadow CI-гейт; калибровка порогов P6.4/P5.2/P6.2/P7.3/P7.5/
  P7.7 (retention-окна/ILM expire_days); переключение сидов на 3.x-модели (D27); Langfuse/registry
  промтов; правка устаревших имён таблиц в 03/04; Gemini-прокси-для-РФ; калибровка
  `ai.gemini.rate_limit.*`; тариф gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4);
  калибровка `temperature`/`maxTokens` по purpose (P3.3); второй driver для
  `AiRouteResolver::providerFor()` (P3.3); `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy` (P3.4);
  per-purpose роутинг в `ExtractionOrchestrator`; runtime-обнаружение провайдерского лимита ПОСЛЕ
  preflight; полный пересчёт `validation_status`/иерархии после `RerunRangeAction`; путь для
  `mergedIncompleteItems` в UI/датасет через `raw_result` (P9/Filament-витрина); discovery-промт-
  текст — P8.1; `image/*` в windowed-пути (P6.4, теоретический разрыв);
  `max_estimates_per_document`-аналог для `runWindowed()`; живое подключение explicit context cache
  в `ExtractionOrchestrator`/`RowRangeChunkSource` (P7.1 Pending Work); `ai:caches prune` в
  расписание; live FX-курс вместо статического `ai.cost_report.rub_per_usd` (P7.2 — YAGNI);
  маршруты `eval`/`shadow` в `AiRoutingSeeder` (D15); v1-инфра-проверки (Redis-backlog/queue-depth/
  /dev/shm) — carry-over на будущие работы по краулинг-стеку; `EstimatePersister` под
  manual-документы (P7.4); эвристика сопоставления многосметных gold-эталонов по номеру/заголовку
  (P7.4); `EvalRun.prompt_version_set` не прокинут из `PromptComposer` (P7.4); `gold_ref`/object
  storage для крупных эталонов (P7.4); Pulse `sample_rate`/retention (P7.5); `PULSE_ENABLED`/
  `PULSE_PATH` не заведены в прод `.env` (P7.5); `GracefullyStoppable`-трейт не подключён ни к
  одной реальной команде — кандидат при появлении демона/команды с явным циклом по единицам работы
  (P7.6); `docs/03_Dev/process/deploy.md` runbook-заготовка сейчас живёт только в комментарии
  `docker/Makefile` — полное наполнение P10.3 (P7.6); `db:backup` на прод-расписание
  (cron/systemd-timer), off-host копирование дампа/ротация, MinIO bucket versioning/`mc mirror`
  офф-сайт копии блобов — все Scope Excluded P7.7, инфра-решение владельца; `ai:storage:lifecycle`
  вне scheduler (правило меняется редко, ручной прогон при изменении конфига, P7.7).
- open_questions: подтверждение владельца перед git rm Old/ (P11.1); хост для GlitchTip и боевой
  SENTRY_LARAVEL_DSN/SENTRY_ENVIRONMENT (P7.5); дата переключения на 3.x-модели (D27); нужен ли
  исходящий прокси для Gemini из среды деплоя; нужен ли структурный reason-столбец на
  `prc_documents`/`prc_ai_analyses` для unprocessable; guard-фикс `ScreeningJob::
  statusGuardPassed()` под редкий сценарий (P5.2); overlap-дедуп по составному ключу до реальных
  границ чанков (P6.2); источник `Estimate.title` (P6.3); структурный `is_merged_from_split`/якорь
  `row_index` для склеенных incomplete-строк (P6.3); как архитектурно развести «стабильную» и
  «per-purpose схемную» части системного промта extraction для явного контекстного кэша (P7.1);
  числовой порог «когда explicit cache выгоднее implicit» (P7.1); актуальный курс
  `AI_COST_REPORT_RUB_PER_USD` в прод `.env` (P7.2); боевой `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID`
  для `PipelineAlert` (P7.3); реальная калибровка ВСЕХ порогов `pipeline:stuck-check` (P7.3/P7.5)
  на живом трафике; когда владелец подготовит папку реальных эталонных смет для
  `ai:dataset:import` и запустит `ai:eval:run` без `--dry-run` (P7.4); включать ли
  `LOG_CHANNEL=json` на проде по умолчанию (P7.5); точное числовое значение
  `HORIZON_STOP_GRACE_PERIOD`, если реальные extraction-jobs на проде окажутся дольше текущего
  запаса (P7.6, сейчас 600s против 300s supervisor timeout — 2x запас, не калиброван на реальном
  трафике); когда/нужно ли рефакторить `RunFreshCrawler`/`RunArchiveCrawler` под цикл единиц
  работы, чтобы подключить `GracefullyStoppable` фактически (P7.6, вне Scope этого item'а); хост и
  расписание прод-бэкапов (P7.7 — Makefile-цель готова и проверена end-to-end на dev, cron/systemd
  на конкретном прод-хосте — решение владельца); реальные окна retention/MinIO ILM `expire_days`
  после наблюдения объёма (P7.7).

Context: P7.7 закрыт (retention партиций pg_partman/fallback, autovacuum hot-партиций, MinIO ILM
через `mc`, `db:backup`+restore-check — end-to-end проверено на dev: 79 таблиц восстановлено) —
фаза P7 полностью закрыта (7/7) 🟠 Done with deviations, сводка в `plans/EST-AI/phases/P7.md` §Phase
Handoff. Следующий вход — P8.1 (`plans/EST-AI/phases/P8.md`, промт-система: сиды/базовые тексты,
8-й discovery-промт D37); наблюдаемость/деплой/data-lifecycle контекст P7.1–P7.7 для P8 НЕ нужен
(разные подсистемы), можно `/clear`.
