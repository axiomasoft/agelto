# Отчёт J — observability, деплой с живыми очередями, конкурентность, надёжность CLI (июль 2026)

## 1. Observability
- Laravel Pulse актуален (v1.4.x, Laravel 12): slow jobs/queries, exceptions, queue throughput, cache; кастомные recorders + карточки (Pulse::record()->count()/sum()/avg()); низкий оверхед (запись после ответа, агрегаты), рычаги: sampling, Redis ingest, отдельная БД. Nightwatch — платный SaaS; Telescope — dev-only.
- Errors: GlitchTip self-hosted (MIT, ~4 контейнера, ~512 MB RAM) принимает официальный sentry/sentry-laravel SDK (меняется только DSN); self-hosted Sentry — 40+ контейнеров, 16-32 GB RAM (не наш случай); Sentry SaaS free 5k событий/мес.
- Корреляция: фасад **Context** (Laravel 11+) — контекст автоматически дегидрируется в payload job при dispatch и гидрируется в воркере → trace_id из middleware/команды сквозь все jobs и логи. Log::withContext через очередь НЕ едет. JSON-логи: monolog-канал + JsonFormatter или tap.
- Per-stage метрики пайплайна = доменные данные → своя таблица событий/переходов в PG + scheduled SQL-алерты (backlog>N, failure rate>X% за 15 мин, «нет завершений N минут»); Pulse-карточки — витрина поверх. Prometheus+Grafana (spatie/laravel-prometheus 02.2025) — over-solve на старте.
- Horizon: horizon:snapshot /5м + Horizon::waits/long-wait алерты — встроено.
МИНИМАЛЬНЫЙ СТЕК: GlitchTip self-hosted + sentry SDK; Pulse (Redis ingest) + 2-3 кастомные карточки пайплайна; Context::add('trace_id') в middleware и начале консольных команд + JSON-каналы; стадии в prc_*-таблицах + SQL-алерты в Telegram.

## 2. Zero-downtime деплой с Horizon
- horizon:terminate: «текущие jobs доделаются» = SIGTERM master-супервизорам + флаг illuminate:queue:restart в cache; --wait блокируется; fast_termination=true — вернуться сразу (старый доживает параллельно с новым).
- **stopWaitingSeconds НЕ СУЩЕСТВУЕТ** (0 вхождений в horizon/framework — миф, смешение с supervisord stopwaitsecs / Compose stop_grace_period).
- Реальные ручки: job $timeout < supervisor timeout (horizon.php) < retry_after (queue.php, минимум на несколько сек больше — иначе job дважды); снаружи **stop_grace_period** сервиса > самого длинного job (Docker default 10 c — УБЬЁТ 300+с job!).
- queue:restart — для голых queue:work; при Horizon канон horizon:terminate. Общий cache-драйвер обязателен.
- «Холодный режим» ОФИЦИАЛЬНО: horizon:pause / horizon:continue (+ pause-supervisor, status, supervisor-status). Pause = не берёт новые, текущие доделываются.
- artisan down останавливает И очереди Horizon (если у supervisor force!=true) — для паузы пайплайна использовать horizon:pause, не down.
- Миграции при живых воркерах: только аддитивные (expand/contract): аддитив → рестарт воркеров → деструктив следующим деплоем.
- Длинные jobs: SIGTERM обрабатывается МЕЖДУ jobs; идемпотентность обязательна (чекпоинты прогресса, дробление, WithoutOverlapping/unique, retryUntil+backoff); Redis block_for != 0.
КАНОН ДЕПЛОЯ: (1) compose pull → (2) [рискованный деплой] horizon:pause → (3) migrate --force one-off на новом image, только аддитивные → (4) horizon:terminate в старом контейнере + compose up -d → (5) stop_grace_period у horizon-сервиса 600–3600s, инвариант timeout'ов → (6) horizon:status; после pause — horizon:continue.

## 3. Конкурентность (PG 17)
- SELECT ... FOR UPDATE SKIP LOCKED — канон таблиц-очередей: claim в КОРОТКОЙ транзакции (CTE + UPDATE ... RETURNING), работа вне транзакции; главная ошибка — держать транзакцию на время job (vacuum/bloat). Обязателен watchdog зависших running (started_at < now()-interval).
- Atomic conditional UPDATE (WHERE status='pending' RETURNING) — guard идемпотентности, 0 rows = выход.
- Advisory locks (pg_try_advisory_lock / pg_advisory_xact_lock) — именованный мьютекс (шард краулера, per-domain politeness); риски: утечка session-локов через пулеры.
- Laravel: Cache::lock (Redis, TTL) — распределённый мьютекс; ShouldBeUnique — дедуп на enqueue (uniqueId стабильный; TTL истёк — дубль может проскочить); WithoutOverlapping — сериализация выполнения по ключу (release ЖРЁТ attempts — поднять tries!).
МАТРИЦА: claim записи → SKIP LOCKED батчем / conditional UPDATE; guard в job → conditional UPDATE по статусу; «один прогон Crawler» → Cache::lock TTL>прогона + lease-поле в БД; дедуп dispatch → ShouldBeUnique (document_version_id+step); один job на площадку/домен → WithoutOverlapping('platform:'.$id)->releaseAfter + tries↑; зависшие running → watchdog в scheduler.

## 4. Надёжность долгоживущих CLI (краулер)
- Демон = отдельный compose-сервис (restart: unless-stopped + stop_grace_period под единицу работы), НЕ exec-запуск руками; schedule:work — только для scheduler.
- Graceful restart после деплоя — калька queue:restart: cache-ключ crawler:restart_at, проверка ПОСЛЕ каждой единицы работы → чистый exit 0 → рестарт менеджером на новом коде; + SIGTERM через Symfony SignalableCommandInterface → флаг → доработать единицу → exit (спасает от exit 137 при compose down).
- Двойной запуск: Isolatable + --isolated (cache-mutex) для демонов; withoutOverlapping()+onOneServer() для scheduled. PID-файлы — устаревший антипаттерн в контейнерах.
- Heartbeat: cache + CrawlerRun.last_heartbeat_at; spatie/laravel-health (ScheduleCheck + кастомный чек «heartbeat старше X мин») → алерт.
- Логи: гибрид — детальный поток в JSON stderr/файл (с Context trace_id), в БД только тонкий operation log (строка на прогон: exit_code, счётчики, deployed_commit) — CrawlerRun уже это; полный лог в БД = write-hotspot.
- Гигиена: --max-time/--max-units self-restart против утечек памяти.

## Расхождения/оговорки
- stopWaitingSeconds — миф (проверено code search).
- horizon:terminate «дожидается» верно, НО docker stop_grace_period default 10 c убьёт контейнер раньше — главный практический нюанс для 300+с jobs.
- Цены Sentry/GlitchTip — из агрегаторов, перепроверить на официальных прайсах.
Источники: laravel.com/docs/12.x (horizon/context/logging/queues), github laravel/horizon 5.x (TerminateCommand.php), laravel/pulse, spatie/laravel-{prometheus,health}, glitchtip.com, getsentry/self-hosted.
