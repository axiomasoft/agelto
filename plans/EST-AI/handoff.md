# HANDOFF — 2026-07-09 — after P8.2

**Next:** `/task:plan-exec EST-AI P9.1` (фаза P9 «Обучающий контур и локальная LLM» ещё не читана
исполнителем — начинать с Phase Context `plans/EST-AI/phases/P9.md`).
**Done:** P1–P8 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6/P8 — 🟢 Done). P8.2 закрыт
🟢: `PromptResource` выведен из-под enum-RBAC короткого замыкания AzGuard
(`config/az-guard-filament.php` → `exclude.resources`), новая `App\Guards\Admin\Policies\PromptPolicy`
(`#[GuardPolicy(model: Prompt::class)]`, методы `update`/`delete` с `#[GateAbility]`) делает
канонические (`is_protected=true`) промты read-only НА УРОВНЕ POLICY (не только UI) — оператор с
коарс-грантом `PromptPermission::Update/::Delete` не может редактировать/удалять protected-запись, но
по-прежнему может операторские (`is_protected=false`); `OperatorRole`/`PromptPermission` не тронуты.
`PromptsTable`/`PromptForm` — badge/фильтр `is_protected`, hint про git+db:seed,
`DeleteBulkAction::authorizeIndividualRecords('delete')` (bulk не обходит per-record политику).
Регресс-гвард (`AdminPanelAccessTest`) вручную проверен реальным откатом конфига — без строки
`exclude.resources` тест ловит регрессию. Итого закрыто 28 item из 40.
**Remaining:** P9 (4) → P10 (4) → P11 (1) → P12 (2, только opus/Fable — Routing §3). Итого 12 items
впереди из 40.
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D44 полный контекст; Status Board — P8
теперь 🟢 2/2) · plans/EST-AI/phases/P8.md (закрыт, Phase Handoff — что именно сделано) ·
plans/EST-AI/phases/P9.md (Phase Context + P9.1–P9.4 — читать ПЕРЕД exec, план ещё не тронут
исполнителем) · `app/Guards/Admin/Policies/PromptPolicy.php` (образец для будущих Policy-item'ов —
единственная политика в проекте, канон `#[GuardPolicy]`/`#[GateAbility]` подтверждён рабочим кодом) ·
`config/az-guard-filament.php` (комментарий у `exclude.resources` объясняет механику для следующих
resource-специфичных исключений) · `resources/ai/prompts/README.md` (инструкция для модели,
наполняющей промт-тексты — актуальна, P8.1) · `database/seeders/PromptSeeder.php` (парсер
front-matter + upsert/деактивация, актуален).
**Open risks:**
- exclude одного ресурса из AzGuard-каталога снимает его из ResourceGate enum-RBAC — коарс-право
  `PromptPermission::Update/::Delete` по-прежнему грантуется (enum сам по себе не удалён,
  `OperatorRole` его несёт), но если позже кто-то запустит `guard:filament:generate --source=enum`
  заново для ВСЕХ ресурсов, стоит перепроверить, что `PromptResource` остаётся в `exclude` (иначе
  регрессия к старому конфликту) — тест `AdminPanelAccessTest` («PromptResource вне enum-RBAC
  каталога») это ловит (эмпирически подтверждено ручным откатом на P8.2), но только если кто-то его
  прогонит после будущей регенерации.
- любой БУДУЩИЙ item, добавляющий Filament-Policy поверх модели, обнаруженной AzGuard при
  `source=enum`, столкнётся с ТЕМ ЖЕ паттерном — готовое решение: exclude + `#[GuardPolicy]`/
  `#[GateAbility]`-политика под `app/Guards/Admin/**` (см. `PromptPolicy` как образец) — держать в
  голове при P9/P10 Filament-правках, если появятся.
- plan-lint: 5 pre-existing ошибок в `plan.md`/`handoff.md` (Status Board P3/P4/P5/P7 фазовый статус
  «Done with deviations» при всех items 🟢/🟠 — задокументированное сознательное расхождение
  конвенции плана с линтером, см. inline-заметки `plan-lint:` в соответствующих `phases/P{3,4,5,7}.md`)
  — НЕ новые, воспроизводятся с прошлых фаз, не чинились (без изменений).
- Модели EOL (D27): линейка 2.5 умирает 2026-10-16 — `pipeline:stuck-check` (P7.3) алертит при
  приближении (`config('ai.model_eol_warn_days', 30)`), само переключение НЕ делает.
  `AiRoutingSeeder::routes()` уже несёт закомментированные 3.x-строки-преемники.
- Прокси для Gemini/LLM-вызовов из РФ не покрыт решением владельца (без изменений).
- Троттлинг Gemini не откалиброван против реальной квоты провайдера (без изменений).
- `docs/specs/v2/03-document-pipeline.md`/`04-llm-providers.md` местами используют устаревшие имена
  таблиц (`doc_*`/`est_*`/`ai_*` вместо `prc_*`) — расхождение не устранено.
- `docker compose exec` требует ДВА `--env-file` (`--env-file .env --env-file .env.docker`);
  `pint --dirty` внутри контейнера НЕ подхватывает untracked-файлы (только `git diff
  --name-only`) — на untracked-heavy item'ах гонять `pint --test <явный список путей>` (P8.2 — с
  ХОСТА, контейнер `app` не может ЗАПИСАТЬ автофикс/`vendor:publish` в bind-mount).
- `composer require`/`composer update --lock` — тоже только с ХОСТА, не из контейнера (без
  изменений).
- `.env`/`.env.example` закрыты от чтения — правки только append вслепую (без изменений).
- `php artisan test --filter=X --filter=Y` — второй `--filter` игнорируется, гонять раздельными
  командами.
- Symfony Console резервирует имя опции `--version` (без изменений).
- `phpunit.xml` несёт `PULSE_ENABLED=false` (без изменений).
- `dispatchSync()`/`sync`-очередь не проверяют реальную дегидрацию/гидрацию `Context` (без
  изменений).
- `ReaskPolicy` НЕ используется оркестраторами (без изменений).
- explicit context cache НЕ подключён к `ExtractionOrchestrator`/`RowRangeChunkSource` (без
  изменений); `LlmCacheManager` (P7.1) — CRUD без production-потребителя (Pending Work P7.1).
- `EstimatePersister` пропускает сметы документов без `procurement_id` (без изменений).
- `RerunRangeAction` НЕ пересчитывает `validation_status`/иерархию секций целиком (без изменений).
- `mergedIncompleteItems` не персистятся в `prc_estimate_items` (без изменений).
- Пороги `pipeline:stuck-check` НЕ откалиброваны на реальном трафике (без изменений).
- `TELEGRAM_BOT_TOKEN`/`TELEGRAM_CHAT_ID` не заведены в прод `.env` (без изменений).
- `ai:caches prune` НЕ добавлен в расписание (без изменений).
- `ai:eval:run` НЕ прогонялся ни разу на реальных данных (без изменений).
- Сопоставление многосметных gold-документов — чисто позиционное (без изменений).
- `SENTRY_LARAVEL_DSN` пуст (без изменений).
- `json`-канал логов НЕ default (без изменений).
- `GracefullyStoppable`-трейт готов, но не подключён (без изменений).
- `stop_grace_period` (600s) не пересчитан (без изменений).
- `docs/03_Dev/process/deploy.md` — заготовка, ждёт P10.3 (без изменений).
- `AiRoutingSeeder` может затереть операторские правки маршрутов при ручном `db:seed` (P7.2,
  documented MED, без изменений).
- `StorageLifecycleTest` (P7.7) мутирует реальный dev-бакет MinIO `agelto` (documented MED, без
  изменений).
- UI корректирующих hints обучающего контура (P9.2) — вне Scope P8.2, ещё не сделано (ожидаемо,
  следующая фаза).
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений) `docker compose exec` два `--env-file`; composer/pint-автофикс/
  `vendor:publish` — с ХОСТА; `.env.example` слепой append; `pint --dirty` не видит untracked —
  явный список путей; один `--filter` за прогон; `config('ai.capabilities.<модель-с-точками>...')`
  — dot-notation не работает; Symfony Console `--version` зарезервирован; Pulse-тесты — явный
  `Pulse::startRecording()`; `mc ilm rule add` не идемпотентен сам по себе; регресс-тест на
  exclude-строку AzGuard-конфига проверяется ручным temporary-revert + `config:clear` (не
  автоматизирован в CI сам по себе — тест ловит регрессию логически, ручная проверка была
  разовой на P8.2).
- deferred: боевой тюнинг промт-текстов (P8.1 дал ПЕРВУЮ версию); Batch API (D7); RAR/7z в
  ArchiveStrategy; OCR/предобработка сканов; LibreOffice-конвертер xlsx (D35); Ghostscript-
  даунскейл скан-PDF; generic ai_extractions (D13); контакты-сущность под CRM; полный 9-префиксный
  ренейм (отклонён D11); Prometheus/Grafana (D25); прод-развёртывание GlitchTip и боевой
  SENTRY_LARAVEL_DSN (P7.5); shadow CI-гейт; калибровка порогов P6.4/P5.2/P6.2/P7.3/P7.5/P7.7;
  переключение сидов на 3.x-модели (D27); Langfuse/registry промтов; правка устаревших имён таблиц
  в 03/04; Gemini-прокси-для-РФ; калибровка `ai.gemini.rate_limit.*`; тариф gemini-2.5-pro
  >200k токенов; ренейм `Ai\AiRoute` (P1.4); калибровка `temperature`/`maxTokens` по purpose
  (P3.3); второй driver для `AiRouteResolver::providerFor()` (P3.3); `LlmCallRecorder`-интеграция
  ВНУТРИ `ReaskPolicy` (P3.4); per-purpose роутинг в `ExtractionOrchestrator`; runtime-обнаружение
  провайдерского лимита ПОСЛЕ preflight; полный пересчёт `validation_status`/иерархии после
  `RerunRangeAction`; путь для `mergedIncompleteItems` в UI/датасет через `raw_result` (P9/Filament-
  витрина); `image/*` в windowed-пути (P6.4); `max_estimates_per_document`-аналог для
  `runWindowed()`; живое подключение explicit context cache (P7.1); `ai:caches prune` в
  расписание; live FX-курс вместо статического `ai.cost_report.rub_per_usd` (P7.2); маршруты
  `eval`/`shadow` в `AiRoutingSeeder` (D15); v1-инфра-проверки — carry-over; `EstimatePersister`
  под manual-документы (P7.4); эвристика сопоставления многосметных gold-эталонов (P7.4);
  `EvalRun.prompt_version_set` не прокинут (P7.4); `gold_ref`/object storage для крупных эталонов
  (P7.4); Pulse `sample_rate`/retention (P7.5); `PULSE_ENABLED`/`PULSE_PATH` не заведены в прод
  `.env` (P7.5); `GracefullyStoppable`-трейт не подключён (P7.6); `docs/03_Dev/process/deploy.md`
  runbook-заготовка (P10.3); `db:backup` на прод-расписание, off-host копирование, MinIO bucket
  versioning/`mc mirror` (P7.7); `ai:storage:lifecycle` вне scheduler (P7.7); pre-existing
  plan-lint расхождения Status Board P3/P4/P5/P7 (задокументированы inline); UI корректирующих
  hints обучающего контура (P9.2, явный next-scope).
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
  запустит `ai:eval:run` без `--dry-run` (P7.4); включать ли `LOG_CHANNEL=json` на проде по
  умолчанию (P7.5); точное числовое значение `HORIZON_STOP_GRACE_PERIOD` (P7.6); когда/нужно ли
  рефакторить `RunFreshCrawler`/`RunArchiveCrawler` под цикл единиц работы (P7.6); хост и
  расписание прод-бэкапов (P7.7); реальные окна retention/MinIO ILM `expire_days` (P7.7).

Context: handoff свежий — рекомендован /clear перед следующим item (P9.1, новая фаза — начать с
чтения `plans/EST-AI/phases/P9.md` Phase Context).
