# План 02: Сеть, Proxyma Cutover, TLS, Doctor и Наблюдаемость

**Волна внедрения:** W0 (секреты Telegram, алерты, `PrivatePackagesArePinned`), W2 (завершение Proxyma cutover и hardening сети)  
**Затрагиваемые слои:** `app/Services/Procurement/{Network,Proxy,Proxyma,Health}/**`, `app/Providers/{ProxymaServiceProvider,InactiveProxymaServiceProvider}.php`, `app/Doctor/**`, `app/Notifications/PipelineAlert.php`, `app/Support/{SecretRedactor,Telemetry}/**`, `config/{outbound,proxyma,logging,sentry}.php`, `local/vendors/proxyma/**`.

---

## 1. Критические задачи (Proxyma Cutover)

### [CRITICAL] C1. Завершение Cutover `Proxyma`: `Reconciler`, `RetentionPruner`, миграции и CLI
- **Файлы:**
  - `composer.json:147-150` (`dont-discover`)
  - `app/Providers/InactiveProxymaServiceProvider.php:37-41, 110-113`
  - `local/vendors/proxyma/packages/core/src/Laravel/ProxymaServiceProvider.php:281-313`
  - `config/proxyma.php:9`
  - `app/Services/Procurement/Network/NetworkReconcileCategory.php:23-27`
  - `routes/console.php:20-71`
- **Проблема:**
  `InactiveProxymaServiceProvider::boot()` намеренно подавляет `parent::boot()` до завершения cutover. В результате:
  1. `Proxyma\Application\Recovery\Reconciler` нигде не запускается — любой краш воркера оставляет `pxm_permits`/`pxm_demands`/`pxm_operations` активными, навсегда «съедая» `max_inflight` (который равен `1–2` в `config/outbound.php:15-24`).
  2. `RetentionPruner` не запущен — таблицы `pxm_*` растут бесконечно.
  3. `loadMigrationsFrom()`, `OutcomePolicyRegistry::freeze()` и все `proxyma:*` CLI-команды (`reconcile`, `prune`, `doctor`, `diagnose`, `metrics`, `health-reset`, `probe`, `transport-doctor`) не зарегистрированы.
- **План исправления:**
  1. Зарегистрировать `ProxymaReconcileCategory` (вызывает `Proxyma\Application\Recovery\Reconciler::run(TenantKey::system())`) в `ReconcileDaemon`.
  2. Поставить `RetentionPruner` в расписание `routes/console.php` (или включить `proxyma.scheduler.enabled`).
  3. При переключении authority перейти на полный `Proxyma\Laravel\ProxymaServiceProvider` (или вызывать `parent::boot()` с регистрацией миграций, CLI-команд и `OutcomePolicyRegistry::freeze()`).
- **Верификация:** Интеграционный тест: крашнутый `reserved`/`authorized` permit восстанавливается через `ReconcileDaemon` и освобождает `inflight`-слот; `php artisan list proxyma` показывает все команды.

---

### [CRITICAL] C2. Контракт секрета `PROXYMA_WIRE_DIGEST_KEY`
- **Файлы:**
  - `local/vendors/proxyma/packages/core/src/Application/Execution/WireSealer.php:21-26`
  - `config/proxyma.php:1-21`
  - `.env.example`
  - `app/Doctor/Diagnostics/SecretContractIsReady.php:18-23`
- **Проблема:**
  `WireSealer::__construct()` требует `strlen($digestKey) >= 32`, иначе падает с `AdmissionRejected('wire_digest_key_required')` → `ConfigurationHold` на каждом исходящем запросе. При этом `config/proxyma.php` не задаёт `wire.digest_key`, `.env.example` не документирует переменную, а `SecretContractIsReady` её не проверяет (релиз проходит `php artisan doctor` зелёным, но не может сделать ни одного HTTP-запроса).
- **План исправления:**
  1. Добавить `'wire' => ['digest_key' => SecretValue::fromEnv('PROXYMA_WIRE_DIGEST_KEY')]` в `config/proxyma.php`.
  2. Добавить `PROXYMA_WIRE_DIGEST_KEY` в `.env.example`, `docker/secrets.contract` и `make secrets:init`.
  3. Добавить проверку `is_string($key) && strlen($key) >= 32` в `SecretContractIsReady::check()`.
- **Верификация:** Unit-тест `SecretContractIsReady` (отказ при пустом/`< 32` байт, успех при `>= 32` байт).

---

## 2. Высокоприоритетные задачи

### [HIGH] H1. Починка Doctor-проверки `PrivatePackagesArePinned` (v2 lockfile)
- **Файлы:** `app/Doctor/Diagnostics/PrivatePackagesArePinned.php:36-42, 53-84`, `docker/private-vendors.lock.json:1-45`
- **Проблема:** `hasClosedManifest()` жёстко требует `"version": 1` и ключи `['version', 'repository', 'commit', 'packages']`, тогда как `private-vendors.lock.json` уже v2 (`["version", "repositories"]` с `azguard` и `proxyma`). Проверка **всегда падает**, не проверяет `vendor/axiomasoft/proxyma*` и добавляет `safe.directory` только для `azguard`.
- **План исправления:** Перевести валидацию на схему v2 (согласованную с `docker/scripts/vendor_manifest.py`), проверять все объявленные пакеты в `vendor/` и `safe.directory` для каждого репозитория.
- **Верификация:** Feature-тест против реального `docker/private-vendors.lock.json` и мутированных невалидных копий.

---

### [HIGH] H2. Исправление Recovery Probing в `CallerExecutionPort`
- **Файлы:** `app/Services/Procurement/Proxyma/CallerExecutionPort.php:139-147, 216`, `CallerSendFacts.php:89-102`
- **Проблема:**
  1. `$purpose` переключается на `DemandPurpose::Probe`, только если `RouteSelector::select()` вернул `null` — если маршрут #0 `closed`, а маршрут #1 `open` (пора проверять), маршрут #1 никогда не получает probe.
  2. `prepareProbe()` (строка 216) всегда берёт `$routes[0]`. Если маршрут #0 в `credential_hold` или на длинном backoff, `ProbeCoordinator::claim()` отклоняет каждый тик и блокирует восстановление всех остальных маршрутов и `target_health`.
- **План исправления:** При `$probe === true` выбирать конкретный маршрут, у которого `route_health` или `route_target_health` реально due (`(state = 'open' AND open_until <= clock_timestamp()) OR (state = 'half_open' AND probe_until <= clock_timestamp())`), исключая `credential_hold`, и явно передавать его в `prepareProbe()`.
- **Верификация:** Тест с профилем из 2 маршрутов: (a) #0 `closed`, #1 due-`open`; (b) #0 `credential_hold`, #1 due-`open` → #1 успешно пробируется и переходит в `closed`.

---

### [HIGH] H3. Редактирование Telegram Bot Token в секрет-редакторах
- **Файлы:**
  - `app/Notifications/PipelineAlert.php:94-95`
  - `app/Support/Telemetry/TelemetrySanitizer.php:68-91`
  - `app/Support/SecretRedactor.php:11-22`
  - `local/vendors/proxyma/packages/core/src/Infrastructure/Security/SecretRedactor.php:56-61`
- **Проблема:** `PipelineAlert` формирует URL `/bot{$token}/sendMessage`. `TelemetrySanitizer::url()` сохраняет `$parts['path']` без редактирования, а `SecretRedactor` не имеет паттерна для `/bot\d+:[A-Za-z0-9_-]+/`. Любой лог/исключение/breadcrumb Sentry с этим URL утекает live-токен бота в открытом виде.
- **План исправления:** Добавить замену `~/bot\d+:[A-Za-z0-9_-]+~i` → `/bot[redacted]` во все три класса (`App\Support\SecretRedactor`, `TelemetrySanitizer::url()`, `Proxyma\...\SecretRedactor`).
- **Верификация:** Unit-тесты на редактирование `https://api.telegram.org/bot123456:AAH_secret_token/sendMessage`.

---

### [HIGH] H4. Расписание `process:diagnostics` и hardening `PipelineAlert`
- **Файлы:** `routes/console.php:68-71`, `app/Console/Commands/Process/ProcessDiagnosticsCommand.php:20-48`, `app/Notifications/PipelineAlert.php:39-103`
- **Проблема:**
  1. В `routes/console.php` запланирован только `process:health-report` (печатает в stdout и не шлёт алерты), а `process:diagnostics` (который вызывает `PipelineAlert::notify()`) **не запланирован нигде**.
  2. `PipelineAlert::send()` использует `parse_mode => 'HTML'`, но `buildMessage()` не экранирует `<`, `>`, `&` → HTTP 400 `can't parse entities`.
  3. Нет обрезки сообщений > 4096 символов → HTTP 400.
  4. Гонка TOCTOU: `if (! Cache::has(...))` + `Cache::put(...)` вместо атомарного `Cache::add(...)` (вопреки docblock).
  5. `catch (\Throwable)` глотает класс и причину исключения.
- **План исправления:** Поставить `process:diagnostics` на `everyFiveMinutes()->withoutOverlapping()` в `routes/console.php`; экранировать находки `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`, безопасно обрезать до 4096 UTF-8 символов, использовать атомарный `Cache::add()` (с `Cache::forget()` при `send() === false`), логировать `exception_class`.
- **Верификация:** Unit-тесты на HTML-символы, >4096 символов, атомарный антиспам и логирование ошибок.

---

## 3. Средний и низкий приоритет

- **[MEDIUM] M1 (`ProxyResolver::validatedAddress()` SSRF-защита прокси):** `local/vendors/proxyma/.../ProxyResolver.php:22-37` принимает loopback (`127.0.0.1`, `::1`), cloud metadata (`169.254.169.254`) и приватные IP без явного allowlist, не нормализует IPv6 через `inet_ntop(inet_pton(...))` и игнорирует вторичные A/AAAA записи. Добавить проверку `isPublic()` с опциональным allowlist для корпоративных прокси.
- **[MEDIUM] M2 (Утечка памяти `AdapterSendGuard::$plans`):** `app/Services/Procurement/Proxyma/AdapterSendGuard.php:29,47-54` накапливает все `OutboundRequestPlan` в `private array $plans = []` без очистки внутри долгоживущего CLI-демона `process:daemon reconcile`. Освобождать запись в `finally` или использовать `WeakMap`.
- **[MEDIUM] M3 (`DnsResolver` subprocess):** `local/vendors/proxyma/.../DnsResolver.php:30-48` запускает `[PHP_BINARY, '-r', $script, $host]` без `-d display_errors=0 -d display_startup_errors=0` и `--` — любой PHP warning в stdout ломает `json_decode(..., JSON_THROW_ON_ERROR)`. Добавить флаги и оборачивать `JsonException`.
- **[MEDIUM] M4 (`TlsTrustAdapter` + Sentry Logs):** делегировать проверку читаемости и PEM/X.509 валидацию CA-бандла в `TlsTrustAdapter.php:29-38` для fail-fast; добавить `'before_send_log'` в `config/sentry.php:59-61` на случай включения `SENTRY_ENABLE_LOGS=true`.
- **[LOW] L1 (`config/logging.php:275-277`):** добавить `SafeLogChannelTap` на канал `emergency`.
- **[LOW] L2 (`ApplicationReadiness::runWithPgTimeouts()` `377-391`):** сохранять и восстанавливать исходные `lock_timeout`/`statement_timeout` вместо безусловного сброса в `0` в `finally`.
