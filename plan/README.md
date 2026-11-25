# Комплексный аудит репозитория Agelto и план улучшений

**Дата аудита:** 25 ноября 2026  
**Базовый коммит:** `3a33f3b2` (`main`)  
**Объём кодовой базы:** 1 439 PHP-файлов в `app/` (97 667 LOC), 631 тестовый файл (`tests/`), 81 миграция, 31 конфиг, 7 production-сервисов Docker Compose.  
**Базовые статические проверки (на момент аудита):**
- `vendor/bin/pint --test` — **PASS**
- `vendor/bin/phpstan analyse --level=max` — **PASS (0 errors)**
- `composer audit --locked` — **FAIL: 20 advisories в 10 пакетах** (`phpoffice/phpspreadsheet`, `symfony/*`, `livewire/livewire`, `laravel/framework`)
- `pnpm audit` — **FAIL: 23 dev-advisories** (1 critical, 13 high, 8 moderate, 1 low; `--prod`: 0)
- `git status` — 4 незакоммиченных файла (`docker/private-vendors.lock.json`, `docker/scripts/deploy-repositories.sh`, `docker/scripts/vendor_manifest.py`, `.grok/hooks/lifecycle.py`) и 2 untracked (`audits/proxyma.md`, `estimates/files.zip` 196 MB).

---

## 1. Резюме аудита

### Что уже сделано на высоком инженерном уровне (подтверждено кодом)
- **Архитектурные границы доменов:** плоская структура `app/` (`Procurement`, `Reference`, `Organization`, `Llm`, `Platforms/Eis`) строго соблюдается; утечек ЕИС в общий `Procurement/Acquisition` и `Crawler` не обнаружено.
- **Process Core & Fencing:** канонический порядок блокировок (`control → handler → lanes → storage → subjects → work → attempts`), `clock_timestamp()` для lease/permit/heartbeat, `generation + owner_token + dispatch_generation`, валидируемая на старте цепочка таймаутов `slice < job < horizon < retry_after < lease`, отсутствие HTTP/S3 I/O внутри DB-транзакций в Process Core.
- **Сетевая безопасность:** ноль вызовов `verify => false`, `withoutVerifying()` или обхода `OutboundExecutor` через прямой `Http::`; `AgeltoPhysicalHttpTransport` обеспечивает DNS-pinning и запрет редиректов/приватных IP назначения.
- **Песочница OCR:** `PageBatchOcr` корректно использует `BoundedToolProcess` + `tool_supervisor.py` (Landlock + seccomp + `PR_SET_CHILD_SUBREAPER` + rlimits).

### Ключевые системные риски (почему нельзя откладывать)
1. **Блокеры production-надёжности и биллинга (P0/P1):**
   - **LLM Ledger списывает 100% `upperBound` на каждый вызов Gemini** вместо фактических токенов и классифицирует все не-200 ответы (`429`/`5xx`/`404`) + отказы без `candidatesTokenCount` как `Unknown`, что сжигает бюджет в 10–100× быстрее и вешает `account_unknown_hold`.
   - **Незавершённый cutover `Proxyma`:** `InactiveProxymaServiceProvider::boot()` подавляет `parent::boot()` (не работают `Reconciler`, `RetentionPruner`, миграции и CLI), а `PROXYMA_WIRE_DIGEST_KEY` отсутствует в `config/proxyma.php`, `.env.example` и `SecretContractIsReady` (`WireSealer` падает fail-closed).
   - **S3 crash-recovery не работает:** `PrepareWorkspace` и `BlobStore` при аварии на не-локальном диске (`s3`) навсегда оставляют `writing`-резервации (утечка до 256 MiB / 1 GiB на каждый краш).
   - **Ложная нестабильность листинга ЕИС:** `ListingFinalizer` считает `$uninterrupted = ($attempts === 1)`, поэтому обычный pacing (`target_cooldown`/`capacity_wait`) помечает стабильные дни как `incomplete (unstable_source)`.
   - **Бесконтрольный рост БД и блокировки:** `prc_process_outbox`, `prc_request_attempts`, `prc_observations` не очищаются; `InvariantRepository::readBatch()` каждые 60s делает full-scan `prc_process_work` под `FOR SHARE` на всех lanes; `ProjectorDaemon` имеет poison-pill head-of-line blocking; `prc_process_transitions` не имеет индекса по `occurred_at`.
2. **Безопасность (P0/P1):**
   - **20 Composer CVE** (вкл. `phpoffice/phpspreadsheet` XXE/DoS/SSRF, `symfony/http-foundation` ReDoS/host-bypass, `livewire/livewire` auth bypass).
   - **`XlsxResourceGuard`** молча пропускает XML > 128 MiB как «безопасный» и отдаёт битые `.xlsx` в LibreOffice; внешние конвертеры (`soffice`, `7z`, `unar`, `ocrmypdf`) обходят `tool_supervisor.py` (нет сетевой/FS песочницы).
   - **Утечка Telegram Bot Token** в URL `/bot<id>:<token>/sendMessage` не редактируется `SecretRedactor` / `TelemetrySanitizer`.
   - **AzGuard Filament `enforce => false`**, сломанный дефолт `config/session.php` (`driver=database` + `connection=session`), нет HTTP security headers / `trustProxies`.
3. **CI/CD и эксплуатация (P0/P1):**
   - **CI сломан на чистом runner:** `quality` поднимает dev-стек и вызывает `rig.py` (требует `agelto_core_test`), `clean-install-e2e.sh` падает на v2 `private-vendors.lock.json` + MinIO, `tests`/`coverage`/`release-quality` не передают `ENV_FILE=.env.example` и не мигрируют `agelto_test`, `tia-baseline.yml` не чекаутит `proxyma`, отсутствует gitignored 6.2 MB GSFX-фикстура.
   - **PR CI гоняет полный PHP-набор 15 раз последовательно** (неизбежный таймаут 35 мин).
   - **7 production-скриптов (`backup`/`restore`/`release`/`rollback`/`first-deploy`/`rotate-secrets`) являются fixture-заглушками**, а единственный рабочий бэкап локальный (`offhost=0`).

---

## 2. Сводка находок по доменам и критичности

| Домен / план | Critical | High | Medium | Low | Всего |
|---|---:|---:|---:|---:|---:|
| [01. Process Core, демоны и БД](./01-process-core-and-db.md) | 1 | 6 | 7 | 5 | **19** |
| [02. Сеть, Proxyma, TLS и наблюдаемость](./02-network-proxyma-observability.md) | 2 | 4 | 4 | 2 | **12** |
| [03. Acquisition, ЕИС, Organization и Reference](./03-acquisition-platforms-org-ref.md) | 1 | 10 | 7 | 3 | **21** |
| [04. Документы, S3-хранилище и Prepare](./04-documents-storage-prepare.md) | 3 | 6 | 5 | 2 | **16** |
| [05. AI-пайплайн, Eval/Gold и LLM Ledger](./05-ai-pipeline-llm-ledger.md) | 0 | 5 | 6 | 4 | **15** |
| [06. Filament UI, RBAC, безопасность и зависимости](./06-filament-security-http.md) | 1 | 5 | 5 | 4 | **15** |
| [07. CI/CD, Docker, Prod Ops, тесты и гигиена](./07-cicd-docker-ops-testing-hygiene.md) | 5 | 6 | 8 | 0 | **19** |
| **Итого** | **13** | **42** | **42** | **20** | **117** |

*(Примечание: пересекающиеся находки между доменами — например, `prc_process_outbox` retention, `ProjectionRetention` и `ProcessDiagnosticsInspector::now()` — сведены в единых владельцев без дублирования задач.)*

---

## 3. Дорожная карта внедрения (волны W0 → W5)

Каждая волна имеет чёткий периметр, зависимости и обязательные приёмочные ворота (`make core-quality`, профильные `make core-test-*`, `composer audit`, `EXPLAIN`).

### Волна W0 — Экстренная безопасность, рабочие ворота CI и чистый репозиторий (1–2 спринта)
**Цель:** закрыть уязвимости безопасности, починить CI и Doctor, убрать риск утечки секретов и поломки релизов.
1. **Зависимости (CVE):** точечный апдейт `phpoffice/phpspreadsheet`, `symfony/*`, `livewire/livewire`, `laravel/framework`, пересмотр override `vite` в `package.json` → `composer audit --locked` = 0, `pnpm audit --audit-level=high` = 0 ([06](./06-filament-security-http.md#1-обновление-зависимостей-с-security-advisories)).
2. **Утечка секретов и алерты:** редактирование `/bot<id>:<token>` в `SecretRedactor` и `TelemetrySanitizer`; расписание `process:diagnostics` + исправление HTML-экранирования, 4096-лимита и `Cache::add` в `PipelineAlert` ([02](./02-network-proxyma-observability.md#h3-редактирование-telegram-bot-token-в-секрет-редакторах)).
3. **Песочница и XXE в `prepare`:** исправить `XlsxResourceGuard::entryContainsProhibitedXml()` и отказ при битом ZIP; закрыть обход `BoundedToolProcess` для `soffice`, `7z`, `unar`, `ocrmypdf` ([04](./04-documents-storage-prepare.md#c2-обход-xxe-и-лимита-размера-в-xlsxresourceguard)).
4. **Восстановление CI и Doctor:**
   - Исправить `PrivatePackagesArePinned` под v2 `docker/private-vendors.lock.json` ([02](./02-network-proxyma-observability.md#h1-починка-doctor-проверки-privatepackagesarepinned-v2)).
   - Перевести `ci.yml` (`quality`, `tests`, `coverage`, `release-quality`, `tia-baseline.yml`) на `agelto_core_test`, исправить `clean-install-e2e.sh` (v2 lockfile + `prod:storage:init`), обеспечить герметичную поставку GSFX-фикстуры, убрать 15× последовательный прогон PR CI ([07](./07-cicd-docker-ops-testing-hygiene.md#1-cicd-и-унификация-с-agelto_core_test)).
   - Исправить сломанный дефолт `config/session.php` ([06](./06-filament-security-http.md#h2-исправление-дефолтной-конфигурации-сессий)).
   - Убрать stray `estimates/files.zip` (196 MB) и добавить `/estimates/*.zip` в `.gitignore` ([07](./07-cicd-docker-ops-testing-hygiene.md#4-гигиена-репозитория-и-ai-контекста)).

**Ворота W0:** `composer audit --locked` (0), `make core-quality` (PASS), зелёный CI на чистом runner, `php artisan doctor` (PASS).

---

### Волна W1 — Надёжность Process Core, БД-индексы, Retention и S3 Crash-Recovery (2 спринта)
**Цель:** исключить деградацию под нагрузкой, зависания демонов и утечки квот хранилища.
1. **Process Core (демоны и блокировки):**
   - Изоляция poison-pill в `ProjectorDaemon` ([01](./01-process-core-and-db.md#h1-изоляция-poison-pill-в-projectordaemon)).
   - Убрать full-scan `prc_process_work` / `prc_admission_groups` под `FOR SHARE` в `InvariantRepository::readBatch()` ([01](./01-process-core-and-db.md#h2-устранение-full-table-scan-в-invariantrepositoryreadbatch)).
   - Исправить неиндексируемый `WHERE id::text = ?` в `IntentRepository::activateNextLocked()` ([01](./01-process-core-and-db.md#h4-устранение-idtext--в-intentrepositoryactivatenextlocked)).
   - Heartbeat внутри длинных тиков `ReconcileDaemon` / `ProjectorDaemon` ([01](./01-process-core-and-db.md#m1-heartbeat-внутри-длинных-тиков-демонов)), убрать двойной `settleLocked()` в `RecoveryRepository` ([01](./01-process-core-and-db.md#m2-устранение-двойного-вызова-settlelocked)), оптимизировать N+1 в `AdmissionRepository::admit()` ([01](./01-process-core-and-db.md#m3-оптимизация-n1-и-времени-блокировки-lanes-в-admissionrepository)).
2. **БД-индексы и Retention:**
   - Retention + частичный индекс для `prc_process_outbox`, батчевый `ProjectionRetention::prune()` (4 SQL вместо 2000) и многобатчевый `process:prune-history` ([01](./01-process-core-and-db.md#h3-retention-для-prc_process_outbox-и-высокообъёмных-таблиц)).
   - Индексы на `prc_process_transitions(occurred_at)`, `llm_provider_calls(coalesce(sent_at, send_claimed_at))`, `prc_analysis_llm_requests(analysis_run_id)`, `prc_analysis_runs(document_version_id)`, `prc_observations`, `prc_request_attempts`, `prc_process_work(updated_at)`, `prc_process_runs(requested_at)` ([01](./01-process-core-and-db.md#db-индексы-и-партиционирование)).
3. **S3 & Workspace Crash-Recovery:**
   - Lease-based восстановление `writing`-резерваций и repair-блобов на S3 в `PrepareWorkspace`/`BlobStore`/`DocumentStorageReconciler` ([04](./04-documents-storage-prepare.md#c1-восстановление-s3-writing-резерваций-после-краша)).
   - Исправить утечку 1 GiB и `JsonException` в `PrepareWorkspace::open()/cleanup()` ([04](./04-documents-storage-prepare.md#h8-утечка-1-gib-в-prepareworkspaceopen-и-неперехваченный-jsonexception)).
   - Убрать буферизацию в память и no-op верификацию в `ExpandArchiveEntryAction` и `ArchiveExtractor` ([04](./04-documents-storage-prepare.md#h4-expandarchiveentryaction-память-резервации-и-верификация)).

**Ворота W1:** `make core-test-execution`, `make core-test-concurrency`, `make core-test-documents`, `EXPLAIN (ANALYZE, BUFFERS)` без Seq Scan на ключевых таблицах.

---

### Волна W2 — Завершение Cutover `Proxyma` и стабилизация краулинга ЕИС (2 спринта)
**Цель:** обеспечить безотказный сетевой контур и достоверную 2-проходную сборку закупок ЕИС.
1. **Proxyma Cutover:**
   - Контракт секрета `PROXYMA_WIRE_DIGEST_KEY` в `config/proxyma.php`, `.env.example`, `secrets:init` и `SecretContractIsReady` ([02](./02-network-proxyma-observability.md#c2-контракт-секрета-proxyma_wire_digest_key)).
   - Включить `Proxyma\Application\Recovery\Reconciler` (в `ReconcileDaemon`), `RetentionPruner` (в `routes/console.php`), миграции и `proxyma:*` команды ([02](./02-network-proxyma-observability.md#c1-завершение-cutover-proxyma-reconciler-retention-миграции-cli)).
   - Исправить выбор маршрута для probe в `CallerExecutionPort`, утечку памяти `AdapterSendGuard::$plans`, SSRF-проверку прокси в `ProxyResolver`, флаги `DnsResolver` и PEM-валидацию в `TlsTrustAdapter` ([02](./02-network-proxyma-observability.md#h2-исправление-recovery-probing-в-callerexecutionport)).
2. **Acquisition & ЕИС:**
   - Исправить `$uninterrupted` в `ListingFinalizer` (не считать pre-send `deferred` прерыванием) ([03](./03-acquisition-platforms-org-ref.md#c1-исправление-2-проходного-доказательства-стабильности-в-listingfinalizer)).
   - Исправить ось дат для `fresh` + `updated` и приоритет явного `--from` в `EisProfilePreparer` ([03](./03-acquisition-platforms-org-ref.md#h1-ось-дат-для-fresh-и-явный---from-в-eisprofilepreparer)).
   - Заменить sentinel `UNBOUNDED_HIGH_CENTS` на nullable `highCents` и непересекающийся split `[low, mid] / [mid+1, high]` ([03](./03-acquisition-platforms-org-ref.md#h2-замена-sentinel-unbounded_high_cents-и-перекрытия-split)).
   - Вынести DDL партиций `prc_events` из транзакции `DetailFinalizer` и покрыть исторические даты в `SupplierResultsProcessor` ([03](./03-acquisition-platforms-org-ref.md#h3-ddl-партиций-prc_events-вне-транзакции-и-покрытие-supplierresultsprocessor)).
   - Исправить парсеры ЕИС: пустой НМЦК в CSV, multi-lot `supplier-results`, статусы `Rejected`/`Withdrawn` + decimal-цены, пагинированные позиции КТРУ, смягчение `PartiesParser` + сверка фиксов предпродакшена, `DocumentsParser`, `EventJournalParser`, `EisCsvParser` ([03](./03-acquisition-platforms-org-ref.md#2-парсеры-еис-и-адаптер-площадки)).
   - Исправить `OrganizationResolver` для филиалов `(INN, OGRN, разные KPP)` и добавить защиту от перезаписи свежих атрибутов архивным прогоном ([03](./03-acquisition-platforms-org-ref.md#3-домен-organization)).
   - Валидация и транзакционность `ClassifierImporter` / `LoadClassifiers` ([03](./03-acquisition-platforms-org-ref.md#4-домен-reference)).

**Ворота W2:** `make core-test-network`, `make core-test-platforms`, `make core-test-acquisition`, репетиция 1–3 итераций ЕИС.

---

### Волна W3 — AI-пайплайн, точный биллинг LLM Ledger и целостность Eval/Gold (2 спринта)
**Цель:** сделать экономику и ретраи Gemini пригодными к продакшену, исключить утечку holdout и порчу gold-датасета.
1. **LLM Ledger & Gemini ReceiptPolicy:**
   - Внедрить расчёт фактической стоимости по `PricingSnapshot + ProviderUsage` вместо списания 100% `upperBound` ([05](./05-ai-pipeline-llm-ledger.md#h1-расчёт-фактической-стоимости-gemini-вместо-100-upperbound)).
   - Классифицировать не-200 ответы (`404` lookup/cache-miss, `429`/`503`, client errors) и отказы без `candidatesTokenCount` в `GeminiAssetReceiptPolicy` ([05](./05-ai-pipeline-llm-ledger.md#h2-классификация-не-200-ответов-и-отказов-в-geminiassetreceiptpolicy)).
   - Безопасный settlement при превышении `upperBound` (overdraft audit вместо бесконечного цикла recovery) ([05](./05-ai-pipeline-llm-ledger.md#m1-обработка-фактического-превышения-upperbound-в-settlelocked)).
   - Согласовать `abandoned_unknown` с `LlmCallReadRepository` и `assertSendEligible`, добавить `thinking_tokens` в отчёты, автоматизировать очистку `llm_file_assets` / `llm_context_caches` ([05](./05-ai-pipeline-llm-ledger.md#h5-согласование-abandoned_unknown-в-отчётах-и-лимитах)).
2. **Extraction, Publication, Review & Eval:**
   - Закрыть утечку holdout в `DatasetExporter` при отсутствии `eval-splits.json` ([05](./05-ai-pipeline-llm-ledger.md#h3-устранение-утечки-holdout-в-datasetexporter)).
   - Инвалидировать `verified_at` при `Approve → Reject` в `ReviewAnalysisAction` ([05](./05-ai-pipeline-llm-ledger.md#h4-инвалидация-gold-при-aba-переходе-approve--reject)).
   - Исправить допуск арифметики строк в `ExtractionSemanticValidator`, убрать запись числа страниц в `rows_expected` (`PublicationRepository`), исправить `DiscoveryStep` (передавать `range` вместо всего документа), транзакционность `PromptCanonSynchronizer`, скоупинг `EvalRunComparator::profileFor()`, `EvalRowMatcher` и `DatasetImporter --verified` ([05](./05-ai-pipeline-llm-ledger.md#2-средний-и-низкий-приоритет)).
3. **Prepare-стратегии для не-PDF форматов:**
   - Единый `DOCUMENTS_DISK`, CP866/backslash в русских архивах, стриминг + проверка PDF в `WordStrategy`/`SpreadsheetStrategy`, изоляция `TextGridRenderer` в subprocess, исправление `GsfxStrategy::renderText()` и UTF-8 в `GsfxReader` ([04](./04-documents-storage-prepare.md#h6-поддержка-cp866-и-windows-путей-в-архивах-еис)).

**Ворота W3:** `make core-test-ai`, `make core-test-documents`, `make core-test-regression`.

---

### Волна W4 — Безопасность Filament UI, RBAC и производительность админки (1–2 спринта)
**Цель:** включить fail-closed RBAC в Filament, убрать N+1/сломанные операторские сценарии и укрепить HTTP-периметр.
1. **AzGuard Filament `enforce => true`:** внедрить `AuthorizesResource` / `AuthorizesRelationManager` / `AuthorizesPage`, закрыть `deleteAny` и `PromptCompositionPage`, включить `'enforce' => true` ([06](./06-filament-security-http.md#h1-включение-fail-closed-rbac-в-azguard-filament)).
2. **Контуры и HTTP:** фильтрация `->publicPlatform()` в `AnalysisResource` (или явный переключатель контура), HTTP security headers middleware + `trustProxies`, исправление 500→404 в `PrivateDocumentationLocator`, валидация `base_url` в `ProviderForm` ([06](./06-filament-security-http.md#m1-изоляция-контуров-в-analysisresource)).
3. **Производительность и UX операторов:**
   - Убрать N+1 и устаревающий `static $cache` в `ProcessRunsTable::runState()` ([06](./06-filament-security-http.md#h3-устранение-n1-и-stale-cache-в-processrunstable)).
   - Починить редирект «Перезапустить разбор» (`ViewAnalysis::rerunAction` → поиск по `id` в `WorkItemsTable`) ([06](./06-filament-security-http.md#h4-починка-поиска-по-uuid-после-перезапуска-разбора)).
   - Заменить 4 коррелированных подзапроса в `ProcurementResource` на `LEFT JOIN LATERAL` + индекс; заменить `static $durations` в `ProcurementInfolist` на `WeakMap` ([06](./06-filament-security-http.md#m3-оптимизация-подзапросов-в-procurementresource)).

**Ворота W4:** `AdminPanelAccessTest`, `AdminRouteInventoryTest`, `ContourTest`, `OperatorNavigationE2eTest`, query-count тесты.

---

### Волна W5 — Production Operations, Docker/Nginx Hardening, Тестовая эргономика и Архитектурный рефакторинг (2 спринта)
**Цель:** довести эксплуатационный контур, контейнеры и DX команды до зрелого production-уровня.
1. **Production Ops & Backups:** реализовать реальный `apply` (или заменить заглушки) для backup/restore/release/rollback, добавить off-host бэкапы с аутентифицированным шифрованием (`age`/`restic`), убрать `--user root` в `backup-local-enabled.sh`, добавить `--wait` и `IMAGE_REGISTRY` в `release-deploy.sh`, установить systemd-бинарники в `host-provision.sh` ([07](./07-cicd-docker-ops-testing-hygiene.md#c5-production-скрипты-заглушки-и-локальные-бэкапы)).
2. **Docker & Nginx Hardening:** выровнять `client_max_body_size` (200M) и `proxy_read_timeout` (300s) между host/container Nginx, исправить сброс `add_header` в `location`-блоках и дубль `X-Frame-Options`, добавить HSTS/`server_tokens off`; запинить все базовые образы по `@sha256:`, разделить `runtime-base` на лёгкий `app-base` и `worker-prepare` (с LibreOffice/Tesseract/OCR) ([07](./07-cicd-docker-ops-testing-hygiene.md#h3-рассогласование-host-и-container-nginx-и-hardening)).
3. **Тестовая стратегия и рефакторинг:**
   - Сократить SHA-256 пиннинг `manifest.json` до иммутабельных фикстур (`contract_fixtures` / `estimates/gold`) + `make core-manifest-sync` ([07](./07-cicd-docker-ops-testing-hygiene.md#h5-снижение-накладных-расходов-manifestjson)).
   - Заменить regex-проверки в `FilamentSchemaContractTest` / `FilamentTableContractTest` на поведенческие тесты, выровнять версию Playwright (1.62.1) и добавить E2E-сценарии оператора, подключить `core-test-faults` / `core-test-load` и `rector --dry-run` в CI ([07](./07-cicd-docker-ops-testing-hygiene.md#3-качество-тестового-набора-и-ворота)).
   - Структурный рефакторинг: разбить `ProcessDiagnosticsInspector` (810 LOC), заменить magic-key `$ids` массивы и 10–12 позиционных аргументов в `WorkStoreRepository`/`TransitionRepository` на readonly DTO, удалить неиспользуемый `prism-php/prism`, консолидировать `Audits/` vs `audits/`, удалить устаревшие `.claude/workflows/estai-*.js`, синхронизировать документацию (`deploy.md`, `testing.md`, `commands.md`, `cheatsheet.md`).

**Ворота W5:** полный прогон `make core-test-regression`, `core-test-faults`, `core-test-load`, `DocsIntegrityTest`, `ingress-backup-contract.sh`, staging restore drill.

---

## 4. Навигация по детальным планам

- [plan/01-process-core-and-db.md](./01-process-core-and-db.md) — Process Core, 4 демона, очереди Horizon, блокировки, индексы и retention PostgreSQL
- [plan/02-network-proxyma-observability.md](./02-network-proxyma-observability.md) — Outbound-сеть, Proxyma cutover, TLS, Doctor, логирование и Telegram/Sentry-наблюдаемость
- [plan/03-acquisition-platforms-org-ref.md](./03-acquisition-platforms-org-ref.md) — Краулинг, 2-проходный листинг, адаптер и парсеры ЕИС, домены `Organization` и `Reference`
- [plan/04-documents-storage-prepare.md](./04-documents-storage-prepare.md) — Загрузка документов, S3 crash-recovery, песочница конвертеров, архивы, Excel/Word/GSFX
- [plan/05-ai-pipeline-llm-ledger.md](./05-ai-pipeline-llm-ledger.md) — LLM Ledger, биллинг Gemini, `screening`/`extraction`/`publication`, `Eval`/Gold и ревью
- [plan/06-filament-security-http.md](./06-filament-security-http.md) — Зависимости (CVE), AzGuard 2.0 RBAC, сессии/HTTP-безопасность, производительность и UX Filament
- [plan/07-cicd-docker-ops-testing-hygiene.md](./07-cicd-docker-ops-testing-hygiene.md) — CI/CD на `agelto_core_test`, Docker/Nginx/бэкапы, тестовые контракты и гигиена репозитория
- [plan/08-skm-orchestration.md](./08-skm-orchestration.md) — Оркестрация через SKM (`PLAN2`: 7 фаз, 32 пункта, матрица исполнителей и ревьюэров CODEX GPT-6.2 ↔ AGY, команды `orch conduct`)
