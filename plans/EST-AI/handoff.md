# HANDOFF — 2026-07-09 — after P8.2

**Next:** `/task:plan-design EST-AI P8.2` — P8.2 заблокирован (🔴), НЕ `/task:plan-exec`: Scope
Included item'а («PromptPolicy запрещает update/delete на `is_protected=true`, не только UI»)
структурно конфликтует с уже подключённым в проект AzGuard RBAC (`config/az-guard-filament.php`
`source=enum`, `enforce=true`, PromptResource не исключён из discovery) — `AzGuard\Filament\
AzGuardFilamentServiceProvider::boot()` регистрирует `Gate::before` через `ResourceGate::check()`,
который для `update`/`delete` на обнаруженной Filament-модели отвечает
`(bool) $user->hasPermission(...)` БЕЗ учёта записи и коротко замыкает Laravel `Gate` ДО вызова
любой `Gate::policy()`. `OperatorRole` (`app/Guards/Admin/Roles/OperatorRole.php`) уже несёт полный
CRUD на `PromptPermission` (Update+Delete) — подтверждено tinker-прогоном на этой ветке:
`Gate::allows('update'|'delete', $protectedPrompt)` возвращает `true` для пользователя с этой
ролью НЕЗАВИСИМО от `is_protected`. Полный текст находки — blockquote `🔴 ESCALATION P8.2` в
`plans/EST-AI/phases/P8.md`. plan-design должен выбрать один из вариантов (см. blockquote): убрать
Update/Delete у `PromptPermission` из `OperatorRole`; `#[GuardPolicy]`/`#[GateAbility]`-паттерн для
Prompt (требует `source=>'policy'` в az-guard-filament.php — RBAC-декизия); кастомный порядок/
приоритет между `ResourceGate` и `PromptPolicy`; исключить PromptResource из discovery. После
решения — `/task:plan-exec EST-AI P8.2` с нуля (Required Reads пункта уже актуальны).
**Done:** P1–P7 закрыты (P1/P3/P4/P5/P7 — 🟠 Done with deviations; P2/P6 — 🟢 Done). P8.1 закрыт 🟢.
P8.2 — попытка исполнения ЗАБЛОКИРОВАНА эскалацией (черновая реализация написана, конфликт вскрыт
тестом + tinker, затем ПОЛНОСТЬЮ ОТКАЧЕНА — рабочее дерево чистое, в git ничего не закоммичено).
Итого закрыто 26 item из 40 (без изменений — P8.2 не засчитан, тот же счёт, что и в предыдущем
handoff).
**Remaining:** P8.2 (1, заблокирован — ждёт plan-design) → P9 (4) → P10 (4) → P11 (1) → P12
(2, только opus/Fable — Routing §3). Итого 14 items впереди из 40 (без изменений). Фаза P8 НЕ
терминальна (1/2, один item Blocked) — P9 не начинать до закрытия P8.2 (phase-first, Execution
Rules).
**Sources of truth:** plans/EST-AI/plan.md (Decision Log D1–D43) · plans/EST-AI/phases/P8.md
(blockquote `🔴 ESCALATION P8.2` — полный текст находки и варианты решения; P8.1 Completion Notes —
детали структуры/сидера) · `config/az-guard-filament.php` (`source`/`enforce`/`exclude` — текущая
RBAC-конфигурация, актуальна на момент эскалации) · `app/Guards/Admin/Roles/OperatorRole.php`
(источник конфликта — полный CRUD на `PromptPermission`) · `vendor/axioma-studio/azguard-filament/
src/Permissions/ResourceGate.php` (Gate::before-хук, коротко замыкающий Policy) ·
`resources/ai/prompts/README.md` (инструкция для модели, наполняющей промт-тексты) ·
`app/Enums/Procurement/PromptStep.php` (8 канонических значений step) · `database/seeders/
PromptSeeder.php` (парсер front-matter + upsert/деактивация).
**Open risks:**
- **Новое (P8.2 escalation):** `is_protected` НЕ защищён от правки/удаления через Filament UI
  штатным оператором (роль `OperatorRole`) — до решения plan-design протекция существует только
  на бумаге (миграция+модель-cast из P8.1), функционально canonical-промты можно перезаписать/
  удалить руками в админке ЛЮБЫМ пользователем с ролью, управляющей промптами. Не блокирует
  остальной пайплайн (сидер по-прежнему единственный писатель `is_protected=true`, `db:seed`
  восстановит канон при следующем прогоне), но ждёт закрытия до P11 (удаление Old/) по хорошей
  практике — не критический блокер remaining items, но открытый риск для прод-эксплуатации.
- **Новое (P8.2 escalation):** любой БУДУЩИЙ item, добавляющий Filament-Policy поверх модели,
  обнаруженной AzGuard (`exclude` пуст) при `source=enum`, столкнётся с ТЕМ ЖЕ конфликтом
  (`Gate::before` коротко замыкает `Gate::policy()`) — стоит держать в голове при P9/P10 Filament-
  правках, если появятся (в P9/P10 Scope сейчас Filament-слоя не заявлено, но риск системный).
- **Новое (P8.2 escalation):** plan.md Scope Included/Scope Excluded этого item'а взаимно
  противоречивы В КОНТЕКСТЕ уже подключённого AzGuard (Included требует Policy-enforcement,
  Excluded запрещает RBAC-правки, а RBAC уже структурно душит Policy-enforcement) — сам факт того,
  что план написан раньше AzGuard-интеграции (или без учёта её `source=enum`), стоит проверить и
  на других ещё не исполненных items с Filament-слоем (P9.2 упомянут в Scope Excluded P8.2 как
  «UI корректирующих hints» — не проверялся на этот же паттерн, вне блока данного пункта).
- plan-lint: 5 pre-existing ошибок в `plan.md`/`handoff.md` (Status Board P3/P4/P5/P7 фазовый статус
  «Done with deviations» при всех items 🟢/🟠, P7 Items-счётчик читается линтером как 0/7) — НЕ от
  P8.2, воспроизводятся на `HEAD` до этого item'а; вне Scope, не чинились (без изменений).
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
  `app` не может ЗАПИСАТЬ автофикс/`vendor:publish` в bind-mount (Permission denied) — автофикс
  делать с ХОСТА.
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
  изменений).
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
**Workarounds/Deferred/Open questions:**
- workarounds: (без изменений от предыдущего handoff) — `docker compose exec` два `--env-file`;
  composer/pint-автофикс/`vendor:publish` — с ХОСТА; `.env.example` слепой append; `pint --dirty`
  не видит untracked — явный список путей; один `--filter` за прогон; `config('ai.capabilities.
  <модель-с-точками>...')` — dot-notation не работает; Symfony Console `--version` зарезервирован;
  Pulse-тесты — явный `Pulse::startRecording()`; `mc ilm rule add` не идемпотентен сам по себе.
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
  plan-lint ошибки Status Board P3/P4/P5/P7; **новое: архитектурное решение AzGuard RBAC vs
  `PromptPolicy`/`is_protected` (P8.2 escalation, см. Next) — блокирует закрытие P8.2, а значит
  и старт P9**.
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
  расписание прод-бэкапов (P7.7); реальные окна retention/MinIO ILM `expire_days` (P7.7); **новое:
  какой из вариантов совмещения RBAC+is_protected выбрать (P8.2 escalation — см. blockquote в
  phases/P8.md для полного списка вариантов)**.

Context: P8.2 попытка исполнения ЗАБЛОКИРОВАНА (🔴) конфликтом Scope с уже подключённым AzGuard
RBAC — детали в `plans/EST-AI/phases/P8.md` blockquote `🔴 ESCALATION P8.2`. Рабочее дерево чистое
(черновая реализация полностью откачена), в git ничего не закоммичено. Следующий вход —
`/task:plan-design EST-AI P8.2` для архитектурного решения, НЕ `/task:plan-exec`. Можно `/clear`.
