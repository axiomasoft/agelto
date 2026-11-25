# План 06: Зависимости (CVE), Filament Admin UI, AzGuard 2.0 RBAC и HTTP-безопасность

**Волна внедрения:** W0 (зависимости CVE, `config/session.php`), W4 (AzGuard `enforce => true`, HTTP headers, производительность и UX Filament)  
**Затрагиваемые слои:** `composer.json`/`composer.lock`, `package.json`/`pnpm-lock.yaml`, `app/Filament/**`, `app/Guards/**`, `app/Services/Documentation/**`, `bootstrap/app.php`, `config/{azguard-filament,session,documentation}.php`.

---

## 1. Обновление зависимостей с Security Advisories (W0)

### [CRITICAL] C1. 20 Composer advisories (10 пакетов) и 23 dev-advisories в `pnpm`
- **Источник:** живой прогон `composer audit --locked` и `pnpm audit` (25.11.2026).
- **Ключевые уязвимости Composer:**
  - `phpoffice/phpspreadsheet <= 5.9.0` (4 advisories, 3 High + 1 Medium): `CVE-2026-108357`, `CVE-2026-108358` (обход `XmlScanner` → XXE/DoS), `CVE-2026-108359` (ODS DoS), `CVE-2026-108360` (SSRF redirect bypass). **Критично для Agelto**, т.к. парсит недоверенные сметы с ЕИС.
  - `symfony/http-foundation < 8.1.11` (5 advisories, вкл. High `CVE-2026-108082` ReDoS в `Cache-Control`, `CVE-2026-135801/135802`, `CVE-2026-121619`, `CVE-2026-118514`).
  - `symfony/yaml < 8.1.11` (2 advisories, вкл. High `CVE-2026-109413`), `symfony/html-sanitizer`, `symfony/filesystem`, `symfony/http-kernel`, `symfony/mime`, `symfony/routing`.
  - `livewire/livewire < 4.4.8` (`GHSA-mhf7-xp6r-6qrc`: auth bypass в `#[Authorize]`).
  - `laravel/framework < 13.40.0` (`CVE-2026-128083`).
- **Ключевые уязвимости `pnpm` (dev-контур):** 23 уязвимости (1 critical, 13 high), включая принудительный pin `vite@7.3.6` в `package.json` (исправлено в `>= 7.3.7`) и `shell-quote@1.9.0`.
- **План исправления:**
  1. Выполнить точечное обновление `composer update --with-all-dependencies phpoffice/phpspreadsheet livewire/livewire laravel/framework symfony/*`.
  2. Снять устаревший override `vite 7.3.6` в `package.json` и обновить `pnpm-lock.yaml`.
  3. Добавить `composer audit --locked` в `core-quality`.
- **Верификация:** `composer audit --locked` = 0, `pnpm audit --audit-level=high` = 0, `make core-quality`, `make core-test-regression`, `pnpm build` и сборка VitePress-документации.

---

## 2. Безопасность RBAC, сессий и HTTP

### [HIGH] H1. Включение fail-closed RBAC в AzGuard Filament (`enforce => true`)
- **Файлы:**
  - `config/azguard-filament.php:17` (`'enforce' => false`)
  - `app/Filament/Resources/**` (0 из 20 Resources используют `AuthorizesResource`; 0 из 5 RelationManagers используют `AuthorizesRelationManager`)
  - `app/Filament/Pages/PromptCompositionPage.php:46-49`
  - `app/Guards/Admin/Permissions/*Permission.php`
- **Проблема:**
  При `'enforce' => false` ключевые fail-closed механизмы AzGuard Filament (`FilamentGate::before/visible`, `AuthorizesActions`, `RefusesSurfaceCalls`, `RefusesEditableColumns`) работают как no-op. Поскольку Policies есть только у 4 моделей, непокрытые abilities (`deleteAny`, `forceDeleteAny`, `restoreAny`, RelationManagers) возвращают `null` из `GateBridge` и **разрешаются по умолчанию** Filament'ом (в частности, триггер `DeleteBulkAction` доступен viewer-пользователям без права `delete`). `PromptCompositionPage` обходит `ProcessControlPermission::PromptCompositionPageView` через `PromptResource::canViewAny()`.
- **План исправления:**
  1. Подключить `AuthorizesResource` / `AuthorizesRelationManager` / `AuthorizesPage` ко всем ресурсам, страницам и RelationManager'ам.
  2. Добавить явные права или запреты для `delete_any`/`force_delete_any`/`restore_any`; выровнять `PromptCompositionPage::canAccess()` с `ProcessControlPermission::PromptCompositionPageView`.
  3. Включить `'enforce' => true` в `config/azguard-filament.php`.
- **Верификация:** Feature-тесты на `403` для viewer при попытке `deleteAny`, вызове действий RelationManager и прямых Livewire-вызовах; `AdminPanelAccessTest`, `AdminRouteInventoryTest`.

---

### [HIGH] H2. Исправление дефолтной конфигурации сессий
- **Файлы:** `config/session.php:23, 52, 78, 174`, `app/Providers/AppServiceProvider.php:121-128`
- **Проблема:** По умолчанию `'driver' => env('SESSION_DRIVER', 'database')` в паре с `'connection' => env('SESSION_CONNECTION', 'session')`, при этом SQL-соединения `session` в `config/database.php` нет (оно только в `redis.session`) → `InvalidArgumentException` при любом запуске без явного `SESSION_DRIVER=redis`. Также `SESSION_SECURE_COOKIE` не проверяется в `AppServiceProvider::boot()` для production HTTPS.
- **План исправления:** Сменить дефолт `'driver'` на `'redis'` (как в `config/cache.php:25`) или убирать `'connection' => 'session'` для `database`; добавить проверку `session.secure === true` при `production` + `https://` в `AppServiceProvider::boot()`.
- **Верификация:** Config/unit-тест на дефолтную загрузку session-драйвера и production HTTPS guard.

---

### [MEDIUM] M1. Изоляция контуров в `AnalysisResource`
- **Файлы:** `app/Filament/Resources/Procurement/Ai/Analyses/AnalysisResource.php:31, 52-55`, `app/Models/Procurement/Ai/Analysis.php:160`
- **Проблема:** В отличие от `ProcurementResource` и `EstimateResource`, `AnalysisResource::getEloquentQuery()` не вызывает существующий `->publicPlatform()`, поэтому обучающие (`is_public=false`) фикстурные анализы смешиваются с публичными закупками в списке «Закупки → Результаты анализа».
- **План исправления:** Применить `->publicPlatform()` в `ListAnalyses` по умолчанию (сохранив возможность перехода из `RunsRelationManager` по прямой ссылке или явного переключения контура).
- **Верификация:** Feature-тест в `ContourTest` на `ListAnalyses` с публичным и обучающим `Analysis`.

---

### [MEDIUM] M2. HTTP Security Headers middleware и `trustProxies`
- **Файлы:** `bootstrap/app.php:18-21`, `app/Providers/Filament/AdminPanelProvider.php:77-87`
- **Проблема:** Заголовки безопасности заданы только для `/documentation/*`, но отсутствуют на `/admin/*` и `/`; `$middleware->trustProxies(...)` не настроен (риск неверного определения HTTPS, `Secure`-кук и подписанных URL за reverse proxy).
- **План исправления:** Добавить глобальный middleware заголовков безопасности (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Strict-Transport-Security` без перезаписи специальных заголовков `/documentation/*`) и `$middleware->trustProxies(at: ...)` из `TRUSTED_PROXIES`.

---

## 3. Производительность и UX админки Filament

### [HIGH] H3. Устранение N+1 и stale-cache в `ProcessRunsTable`
- **Файлы:** `app/Filament/Resources/Procurement/Process/Runs/Tables/ProcessRunsTable.php:36-51, 69, 99-120`
- **Проблема:**
  - Для каждой строки таблицы `self::runState($record)` вызывает `RunProjector::summary()` → отдельный SQL-запрос на строку (N+1).
  - Кэш `static $cache = []` не включает в ключ счётчики `failed`/`cancelled`/`superseded`/`succeeded` и `discovery_outcome` (возвращает устаревший статус!) и протекает между запросами в долгоживущих воркерах/тестах.
- **План исправления:** Вычислять агрегаты состояний работ в `modifyQueryUsing()` одним `LEFT JOIN LATERAL` / условным `withCount` и рассчитывать `ProcessRunState` в памяти через `RunStateReducer` без `static $cache`.
- **Верификация:** Тест `DB::enableQueryLog()` на `ListProcessRuns` с 10 и 30 запусками подтверждает константное число запросов `O(1)`.

---

### [HIGH] H4. Починка поиска по UUID после «Перезапустить разбор»
- **Файлы:** `app/Filament/Resources/Procurement/Ai/Analyses/Pages/ViewAnalysis.php:164`, `app/Filament/Resources/Procurement/Process/Work/Tables/WorkItemsTable.php:37-54`
- **Проблема:** `rerunAction()` редиректит оператора в `WorkItemResource` с `tableSearch=<work_uuid>`, но в `WorkItemsTable` единственная `->searchable()` колонка — `stage`. Оператор всегда видит пустую таблицу.
- **План исправления:** Добавить скрываемую по умолчанию колонку `TextColumn::make('id')->label('ID')->searchable()` (с поддержкой `CAST(id AS text)`) в `WorkItemsTable`.
- **Верификация:** Feature-тест: вызов `ViewAnalysis::rerun` + переход по редиректу показывает созданный `WorkItem`.

---

### [MEDIUM] M3. Оптимизация подзапросов в `ProcurementResource` и `ProcurementInfolist`
- **Файлы:** `app/Filament/Resources/Procurement/Procurements/ProcurementResource.php:60-69`, `ProcurementInfolist.php:190-212`
- **Проблема:**
  - `ProcurementResource::getEloquentQuery()` вешает 4 коррелированных подзапроса на строку, включая неиндексируемый `LIKE '%:published'` и 3 раздельных скана `prc_process_work`.
  - `ProcurementInfolist::durations()` использует `static $durations = []`, который устаревает при добавлении `ProcurementEvent` или с течением времени (`until: now()`).
- **План исправления:** Заменить 3 подзапроса `prc_process_work` на один `LEFT JOIN LATERAL` + частичный индекс; добавить частичный индекс для `prc_detail_snapshots` (`WHERE completeness = 'complete' AND part_key LIKE '%:published'`); заменить `static $durations` на `WeakMap<Procurement, ProcurementDurations>`.

---

### [LOW] Прочие улучшения UI/HTTP
- **L1 (`PrivateDocumentationLocator.php:17-18, 60-64`):** возвращать 404 вместо 500 (`Undefined array key "index.html"`), если в `manifest.json` нет `index.html`; кэшировать разобранный manifest по `(filemtime, filesize)`.
- **L2 (`ProviderForm.php:30-33`):** заменить `Textarea::make('base_url')` на `TextInput::make('base_url')->url()->rules(['starts_with:https://,http://'])`.
- **L3 (`PromptInfolist.php:41-46` & `CorrectionsRelationManager.php:22-59`):** добавить `->limit(100)` на историю версий и явный `->with('createdBy')`.
