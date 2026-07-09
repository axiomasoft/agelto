# HANDOFF — 2026-07-09 — after P8.1

**Next:** `/task:plan-exec EST-AI P8.2` (`is_protected`: миграция уже несёт колонку (P8.1) — P8.2
добавляет Policy/Filament-слой: `PromptPolicy` запрещает update/delete на `is_protected=true`,
правки ресурса `app/Filament/Resources/Procurement/Ai/Prompts/`, badge «канон», фильтр по
`is_protected`). После P8 (закроет фазу 2/2) — P9 (обучающий контур и локальная LLM, 4 items).
**Done:** P1–P7 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6 — 🟢 Done). P8.1 закрыт 🟢.
Итого закрыто 26 item из 40 (P1 2/4 + P2 3/3 + P3 4/4 + P4 3/3 + P5 2/2 + P6 4/4 + P7 7/7 + P8 1/2).
**Remaining:** P8.2 (1) → P9 (4) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3).
Итого 14 items впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D43 — полный контекст архитектурных
решений; Update Log — детали каждого закрытого item'а) · plans/EST-AI/phases/P8.md (P8.1 Completion
Notes — детали структуры/сидера) · `resources/ai/prompts/README.md` (единственная инструкция для
модели, наполняющей промт-тексты — цикл правки/version/protected) · `app/Enums/Procurement/
PromptStep.php` (8 канонических значений step) · `database/seeders/PromptSeeder.php` (парсер
front-matter + upsert/деактивация — источник истины формата файла) · `docs/specs/v2/
08-estimate-extraction.md` §6/§7 (правила промтов v1, дословно перенесённые в extraction:global/
extraction:rows/extraction:totals).
**Open risks:**
- **Новое (P8.1):** базовые тексты промтов — ПЕРВАЯ версия, не откалиброванная ни на одном реальном
  документе (`ai:eval:run` на реальном датасете ещё не прогонялся, P7.4 Pending Work) — ожидаемо
  будут править; структура/сидер/README рассчитаны именно на этот цикл правки.
- **Новое (P8.1):** директория промта на диске — slug шага через дефис (`screening-global/`), не
  буквальный `step`-value с двоеточием; front-matter `step`-поле остаётся канонично парсируемым
  источником, путь — организация. Если наполняющая модель заведёт НОВЫЙ файл в директории с
  ошибочным `step` в front-matter — сидер упадёт с `ValueError` на `PromptStep::from()` (fail fast,
  ожидаемое поведение, не тихий баг).
- **Новое (P8.1):** `PromptSeeder` вызывается из `DatabaseSeeder` БЕЗУСЛОВНО (до local/testing-гейта,
  прецедент `AiRoutingSeeder` P7.2) — на существующем dev/test окружении это уже штатно (проверено
  `migrate:fresh --seed`), но при первом прод-деплое `db:seed` перезапишет ЛЮБЫЕ protected-строки
  файлами репозитория — ожидаемо по дизайну (D5/D17), не риск, но стоит держать в голове при первом
  прод-запуске.
- plan-lint: 5 pre-existing ошибок в `plan.md`/`handoff.md` (Status Board P3/P4/P5/P7 фазовый статус
  «Done with deviations» при всех items 🟢/🟠, P7 Items-счётчик читается линтером как 0/7) — НЕ от
  P8.1, воспроизводятся на `HEAD` до этого item'а (проверено `git stash`); вне Scope P8.1, не
  чинились.
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16 — `pipeline:stuck-check` (P7.3) алертит при
  приближении (`config('ai.model_eol_warn_days', 30)`), само переключение НЕ делает.
  `AiRoutingSeeder::routes()` уже несёт закомментированные 3.x-строки-преемники.
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие имена
  таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>` (P8.1 —
  очередное подтверждение); контейнер `app` не может ЗАПИСАТЬ автофикс/`vendor:publish` в
  bind-mount (Permission denied) — автофикс делать с ХОСТА.
- `composer require`/`composer update --lock` — тоже только с ХОСТА, не из контейнера (без
  изменений, P8.1 не трогал composer).
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую (P8.1 не трогал .env).
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- Symfony Console резервирует имя опции `--version` (без изменений, P8.1 не заводил такую опцию).
- `phpunit.xml` несёт `PULSE_ENABLED=false` (без изменений, P8.1 не касался Pulse).
- `dispatchSync()`/`sync`-очередь не проверяют реальную дегидрацию/гидрацию `Context` (без
  изменений).
- `ReaskPolicy` НЕ используется оркестраторами (без изменений).
- explicit context cache НЕ подключён к `ExtractionOrchestrator`/`RowRangeChunkSource` (без
  изменений).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (без изменений).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений).
- Пороги `pipeline:stuck-check` НЕ откалиброваны на реальном трафике (без изменений).
- `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env` (без изменений).
- `ai:caches prune` НЕ добавлен в расписание (без изменений).
- `ai:eval:run` НЕ прогонялся ни разу на реальных данных (без изменений — теперь актуальнее: P8.1
  дал реальные промт-тексты, реального золотого датасета для сравнения по-прежнему нет).
- Сопоставление многосметных gold-документов — чисто позиционное (без изменений).
- `SENTRY_LARAVEL_DSN` пуст (без изменений).
- `json`-канал логов НЕ default (без изменений).
- `GracefullyStoppable`-трейт готов, но не подключён (без изменений).
- `stop_grace_period` (600s) не пересчитан (без изменений).
- `docs/03_Dev/process/deploy.md` — заготовка, ждёт P10.3 (без изменений).
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений от предыдущего handoff, см. историю в Update Log P1–P7) — `docker
  compose exec` два `--env-file`; composer/pint-автофикс/`vendor:publish` — с ХОСТА; `.env.example`
  слепой append; `pint --dirty` не видит untracked — явный список путей (P8.1 — очередное
  подтверждение); один `--filter` за прогон; `config('ai.capabilities.<модель-с-точками>...')` —
  dot-notation не работает; Symfony Console `--version` зарезервирован; Pulse-тесты — явный
  `Pulse::startRecording()`; `mc ilm rule add` не идемпотентен сам по себе.
- deferred: боевой тюнинг промт-текстов (P8.1 дал ПЕРВУЮ версию — следующий шаг владельца по папке
  эталонных смет через `ai:eval:run`); Batch API (D7); RAR/7z в ArchiveStrategy; OCR/предобработка
  сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-даунскейл скан-PDF; generic ai_extractions
  (D13); контакты-сущность под CRM; полный 9-префиксный ренейм (отклонён D11); Prometheus/Grafana
  (D25); прод-развёртывание GlitchTip и боевой SENTRY_LARAVEL_DSN (P7.5); shadow CI-гейт; калибровка
  порогов P6.4/P5.2/P6.2/P7.3/P7.5/P7.7; переключение сидов на 3.x-модели (D27); Langfuse/registry
  промтов; правка устаревших имён таблиц в 03/04; Gemini-прокси-для-РФ; калибровка
  `ai.gemini.rate_limit.*`; тариф gemini-2.5-pro >200k токенов; ренейм `Ai\AiRoute` (P1.4);
  калибровка `temperature`/`maxTokens` по purpose (P3.3); второй driver для
  `AiRouteResolver::providerFor()` (P3.3); `LlmCallRecorder`-интеграция ВНУТРИ `ReaskPolicy` (P3.4);
  per-purpose роутинг в `ExtractionOrchestrator`; runtime-обнаружение провайдерского лимита ПОСЛЕ
  preflight; полный пересчёт `validation_status`/иерархии после `RerunRangeAction`; путь для
  `mergedIncompleteItems` в UI/датасет через `raw_result` (P9/Filament-витрина); `image/*` в
  windowed-пути (P6.4, теоретический разрыв); `max_estimates_per_document`-аналог для
  `runWindowed()`; живое подключение explicit context cache в `ExtractionOrchestrator`/
  `RowRangeChunkSource` (P7.1); `ai:caches prune` в расписание; live FX-курс вместо статического
  `ai.cost_report.rub_per_usd` (P7.2); маршруты `eval`/`shadow` в `AiRoutingSeeder` (D15);
  v1-инфра-проверки (Redis-backlog/queue-depth) — carry-over на будущие работы по краулинг-стеку;
  `EstimatePersister` под manual-документы (P7.4); эвристика сопоставления многосметных
  gold-эталонов по номеру/заголовку (P7.4); `EvalRun.prompt_version_set` не прокинут из
  `PromptComposer` (P7.4); `gold_ref`/object storage для крупных эталонов (P7.4); Pulse
  `sample_rate`/retention (P7.5); `PULSE_ENABLED`/`PULSE_PATH` не заведены в прод `.env` (P7.5);
  `GracefullyStoppable`-трейт не подключён ни к одной реальной команде (P7.6);
  `docs/03_Dev/process/deploy.md` runbook-заготовка (P10.3); `db:backup` на прод-расписание,
  off-host копирование, MinIO bucket versioning/`mc mirror` (P7.7, инфра-решение владельца);
  `ai:storage:lifecycle` вне scheduler (P7.7); pre-existing plan-lint ошибки Status Board P3/P4/P5/P7
  (вне Scope любого конкретного item'а — накопленная неточность формата таблицы, требует
  отдельного точечного прохода по plan.md, не блокирует исполнение).
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
  для `PipelineAlert` (P7.3); реальная калибровка ВСЕХ порогов `pipeline:stuck-check` на живом
  трафике; когда владелец подготовит папку реальных эталонных смет для `ai:dataset:import` и
  запустит `ai:eval:run` без `--dry-run` (P7.4, теперь ключевой блокер для тюнинга P8.1-текстов);
  включать ли `LOG_CHANNEL=json` на проде по умолчанию (P7.5); точное числовое значение
  `HORIZON_STOP_GRACE_PERIOD` (P7.6); когда/нужно ли рефакторить `RunFreshCrawler`/
  `RunArchiveCrawler` под цикл единиц работы (P7.6); хост и расписание прод-бэкапов (P7.7); реальные
  окна retention/MinIO ILM `expire_days` после наблюдения объёма (P7.7).

Context: P8.1 закрыт (файловые промты — структура/сидер/sync-команда/README, базовый комплект всех
8 значений `PromptStep`, `is_protected` в схеме) — фаза P8 в процессе (1/2). Следующий вход — P8.2
(`plans/EST-AI/phases/P8.md`, Filament read-only на protected-промты, admin-слой независим от
контекста извлечения/пайплайна) — можно `/clear`.
