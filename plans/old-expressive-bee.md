# План: Filament admin-панель agelto v2 на Azguard (фаза 1)

## Context

v2-код (`feat/v2-scaffold`) — доменное ядро: 44 модели в 3 доменах (`prc_*`, `ref_*`, `org_*`), **Filament не установлен, RBAC нет**, `User` голый, единственный `DatabaseSeeder`. Папка `Old/` — снапшот v1: рабочая Filament-панель с 9 ресурсами (v1-нейминг: Link/LinkAttachment/DocumentAnalysis/CrawlerRun/Service). Нужно перенести админку на v2-модели с каноном имён и завязать доступ на пакет **Azguard** (`/home/vostrikov/projects/packages/azguard`, монорепо: `core`/`filament`/`context`).

Ключевой факт: **у Azguard уже есть полноценная Filament-интеграция** (плагин `AzGuardPlugin`, `ResourceGate` через `Gate::before`, генератор permission-enum'ов `guard:filament:generate`, `RoleResource`/`DirectGrantResource`, `guard:sync-roles`, `SuperAdminRole` с wildcard). Свой Shield-мост писать **не нужно** — только правильно подключить.

Целевое дерево ресурсов описано в `docs/03_Dev/admin-panel.md`. Эта задача — **фаза 1**: каркас панели + Azguard + операторская конфигурация + read-only реестры. Сметы/eval/виджеты-метрики/spatie-settings/health — последующие фазы.

Решения (подтверждены владельцем):
- **Scope**: фазированно — каркас + операторская конфигурация (не весь admin-panel.md разом).
- **Azguard**: в этой задаче интегрируем как есть; DX-шероховатости **фиксируем списком** и делаем отдельным PR в `packages/azguard` (не смешиваем два репо в одном ревью).
- **Роли**: `super-admin` + `operator` + `analyst`.
- Всё проходит через **код-ревью** (agelto-reviewer / agelto-security-review), задача исполняется как task после утверждения.

---

## Editable vs View-only (канон разделения)

Правило: **operator-authored конфиг → CRUD; продукт пайплайна (краулинг/парсинг/LLM) → read-only** (оператор ревизует, не авторит). Из `admin-panel.md` + инвентаря моделей.

**Фаза 1 — CRUD (операторская конфигурация):**
| Ресурс | Модель | Old-референс формы/таблицы |
|---|---|---|
| Platform | `Procurement\Platform\Platform` (`prc_platforms`) | `Old/app/Filament/Resources/Platform/*` |
| Registry | `Procurement\Platform\Registry` (`prc_registries`) | нет — с нуля по схеме |
| Crawler | `Procurement\Crawler\Crawler` (`prc_crawlers`) | `Old/app/Filament/Resources/Crawler/*` (+ `Modules/Eis/.../EisCrawlerForm.php`) |
| Proxy | `Procurement\Crawler\Proxy` (`prc_proxies`) | `Old/Modules/Sys/app/Filament/Resources/Proxy/*` |
| IgnoreRule | `Procurement\Crawler\IgnoreRule` (`prc_ignore_rules`) | нет — с нуля |
| Ai\Provider | `Procurement\Ai\Provider` (`prc_ai_providers`) | нет — с нуля |
| Ai\AiRoute | `Procurement\Ai\AiRoute` (`prc_ai_routes`) | нет — с нуля |
| Ai\Prompt | `Procurement\Ai\Prompt` (`prc_ai_prompts`) | `Old/app/Filament/Resources/PromptRule/*` |
| Ai\DatasetExample | `Procurement\Ai\DatasetExample` (`prc_ai_dataset_examples`) | нет — с нуля |

**Фаза 1 — View-only (наблюдаемость):**
| Ресурс | Модель | Old-референс | Особенности |
|---|---|---|---|
| Run | `Procurement\Crawler\Run` (`prc_crawler_runs`) | `Old/.../CrawlerRun/*` | index+view, `LogsRelationManager` read-only; экшены «возобновить/сброс курсора» — как отдельная фаза |
| Procurement (реестр) | `Procurement\Procurement` (`prc_procurements`) | `Old/.../Link/*` | таблица+фильтры+ссылка на источник; **карточка с таб-ами (events/revisions/items/...) — фаза 2** |

**Отложено (фаза 2+):** карточка закупки с relation managers, Document/DocumentVersion, Estimate-дерево + overlay «проверено оператором», Analysis + ручной перезапуск стадии, Eval/dataset-runs, классификаторы `ref_*`, Organization, виджеты-метрики из партиций, spatie-settings, health-checks, Horizon-встройка.

Канон view-only из Old воспроизвести дословно: `CrawlerRunResource` — pages только index+view, `canCreate/canEdit/canDelete/canDeleteAny` → под Azguard это **не хардкод `false`**, а отсутствие CRUD-страниц + отсутствие соответствующих permission в ролях (гейт сам закроет).

---

## Как исполнять (после утверждения плана)

Задача — многослайсовая backend-фича, гоним через **адаптивный dev-loop с review-gate**, а не одним заходом:

- Запуск: **`/task:dev`** (по этому плану) — планирует срезы на Opus/high, реализует срез (`agelto-slice-builder`), тесты до зелёного (`agelto-test-writer` → `agelto-test-verifier`), затем **risk-адаптивное ревью каждого среза** (`agelto-reviewer`; на access-layer/авторизации ещё `agelto-security-review`). Ревью-гейт: `.claude/hooks/review-gate.sh`.
- Срезы по этому плану: (1) установка+панель+плагин, (2) User+роли+permission-enum'ы+сиды, (3) CRUD-ресурсы конфигурации, (4) view-only Run+Procurement.
- Финал: **`/code-review`** по всему diff ветки против `main` (блокирующие находки с file:line), затем — при необходимости — `/security-check`.
- Azguard follow-ups (раздел 6) — **не** в этой ветке: отдельный PR в `packages/azguard` со своим `/code-review`.

## Шаги реализации

### 1. Установка зависимостей
- В `composer.json` agelto добавить `repositories` типа `path` на три пакета azguard:
  `/home/vostrikov/projects/packages/azguard/packages/core`, `.../filament`, `.../context` (context — опционально, фаза 1 без мульти-воркспейсов; ставим только core+filament).
- `composer require filament/filament:^5 axioma-studio/azguard-core axioma-studio/azguard-filament`.
- Проверить совместимость Filament 5 ↔ Laravel 12 ↔ PHP 8.4 (Old уже жил на Filament v4/v5 + L12).
- Опубликовать конфиги: `az-guard.php`, `az-guard-filament.php`; выставить `AZ_GUARD_MORPH_TYPE` под тип PK `users` (int).
- Прогнать миграции azguard (`roles`, `model_has_roles`, `az_guard_role_permissions`, `az_direct_grants`, `model_has_scopes`).

### 2. Панель + плагин
- `php artisan filament:install --panels` → `app/Providers/Filament/AdminPanelProvider.php` (`id('admin')`, `path('admin')`, `->login()`), зарегистрировать в `bootstrap/providers.php`. Референс: `Old/app/Providers/Filament/AdminPanelProvider.php`.
- Подключить плагин: `->plugin(AzGuardPlugin::make()->forPanel('admin'))` — он сам регистрирует `RoleResource`/`DirectGrantResource`/`DoctorPage` и делает `checkPolicyExistence(false)`, чтобы гейт Azguard решал доступ без authorization-кода в ресурсах.
- **Явная регистрация и группировка — как в Old**, не полагаться на автопорядок discovery. Реплицировать кастомный `NavigationBuilder` (референс `Old/.../AdminPanelProvider.php:71-90`): каждый ресурс явно кладётся в свою группу. Группы фазы 1 (по `admin-panel.md`): «Краулинг» (Platform, Registry, Crawler, Proxy, IgnoreRule, Run), «Закупки» (Procurement), «AI-анализ» (Ai\Provider, Ai\AiRoute, Ai\Prompt, Ai\DatasetExample), «Доступ» (Role, DirectGrant — от плагина).
- Discovery: `discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')` — **рекурсивно по доменным подпапкам** (v2 не модульный, Modules нет).

### 3. Azguard на User + роли
- `app/Models/User.php`: подключить трейт `AzGuard\Concerns\HasAzGuard`, реализовать контракт `AzGuard\Contracts\AzGuardUser`; для Filament — `FilamentUser::canAccessPanel()` (пустить только с ролью на панель `admin`).
- Роли — PHP-классы `App\Auth\Roles\*` наследуют `AzGuard\Roles\BaseRole`:
  - `SuperAdminRole` — использовать готовый `AzGuard\Roles\SuperAdminRole` (`['*']`, level 1000) либо тонкий проектный сабкласс.
  - `OperatorRole` — `view_any/create/update/delete` на `platforms, registries, crawlers, proxies, ignore_rules, ai_providers, ai_routes, ai_prompts, ai_dataset_examples`; `view_any/view` + `update` на `runs`.
  - `AnalystRole` — только `view_any/view` на read-only реестры (`procurements`, позже estimates/analyses/ref_*).
- Permission-enum'ы на ресурс генерировать `php artisan guard:filament:generate --source=enum --panel=admin` → `App\Auth\Permissions\*` (по одному enum на ресурс, ключ `admin.{resource}.{ability}`). Роли ссылаются на case'ы enum'ов (refactor-safe), не на строки.

### 4. Filament-ресурсы (фаза 1)
- **Генерация только консольными командами**, не руками: скелет — `php artisan make:filament-resource <Name> --generate --view` (`--generate` тянет поля формы/колонки из схемы модели, `--view` даёт view-страницу для read-only). Relation-менеджеры (например `Run` → логи) — `php artisan make:filament-relation-manager`. Не писать boilerplate вручную — генерим, затем правим.
- **Доменная раскладка по папкам моделей** (как модели в `app/Models/<Домен>/...`): ресурсы кладём в зеркальные подпапки с вложенным namespace —
  - `app/Filament/Resources/Procurement/Platform/PlatformResource.php` (`App\Filament\Resources\Procurement\Platform`),
  - `.../Procurement/Crawler/{Crawler,Proxy,IgnoreRule,Run}Resource.php`,
  - `.../Procurement/Ai/{Provider,AiRoute,Prompt,DatasetExample}Resource.php`,
  - `.../Procurement/ProcurementResource.php`.
  `make:filament-resource` принимает вложенный путь (`Procurement/Crawler/Crawler`) — namespace/папка совпадут с моделью; discovery подхватывает рекурсивно. Структура внутри ресурса — вынесенные `Schemas/<Name>Form.php` + `Tables/<Name>Table.php`, `final`, `declare(strict_types=1)`, как в Old.
- CRUD-ресурсы из таблицы выше: где есть Old-референс — перенести поля формы/колонки/фильтры, переименовав модель и поля под v2-схему (сверять с миграциями `database/migrations` и моделями); где нет — генерить `--generate` и допиливать.
- View-only: `Run` (index+view, `LogsRelationManager` без экшенов, polling), `Procurement` (index-таблица + фильтры + ссылка на источник, без create/edit-страниц).
- **Никакого authorization-кода в ресурсах** — доступ enforced гейтом Azguard по permission-каталогу.

### 5. Сиды и провижининг
- `guard:sync-roles --panel=admin` — зеркалит PHP-роли в `roles` (name/level/class_name).
- Сид `database/seeders/`: создать роли (через sync или прямой вызов), назначить `super-admin` bootstrap-пользователю (`guard:super-admin --user=` или в сиде `assignRole`). Обновить `DatabaseSeeder` (dev-пользователь получает роль).
- Проверка каталога: `guard:doctor`, `guard:catalog-validate`.

### 6. Azguard — обкатка пакета как реальным пользователем (правки внесены В ПАКЕТ)

Пакет обкатывается как open-source: непонятные/ломающие моменты чиним в самом `packages/azguard`
(правильно, не костыльно), чтобы следующая интеграция шла гладко; доковые пробелы отмечаем.

**СДЕЛАНО в packages/azguard (ветка refactor/plan-remainder, свои тесты/pint/phpstan зелёные, требует своего PR+ревью и коммита):**

1. **FIX (BUG) — `guard:doctor` крашился на enum-пермишенах ролей.** `AzGuardDiagnostics::checkRoles` кастовал backed-enum в строку/использовал как ключ массива → фатал, хотя `permissions(): list<UnitEnum>` — документированная preferred-форма. Теперь enum-кейсы скоупятся через панель (как в `ClassRoleGrantSource`), чужие панели — скип. Регресс-тест: `tests/Feature/DoctorCommandTest.php` (стаб-роль с enum-cases; раньше падал — в тест-панели просто не было `Roles/`-директории, потому баг не ловился).

2. **FIX (архитектура) — ложный `DuplicatePermissionKeyException` при overlap enum- и Filament-каталога.** `CompositePermissionCatalog` бросал на одинаковом ключе с разной `group()`. Но ключ = идентичность пермишена, group/label — только отображение. Теперь идентичный ключ дедуплицируется идемпотентно (при пустой группе первого источника берётся непустая). Убрало необходимость консюмерского no-op-override `registerCatalogBuilders()` (в agelto override удалён — панель просто отдаёт `permissionEnums`, каталог сходится). Тест переписан: `tests/Unit/Registry/CompositePermissionCatalogTest.php`.

3. **FIX — `guard:doctor` ложно репортил роли Filament-панели как «unknown permission».** `checkRoles.knownAbilities` собирался только из policy-#[GateAbility] + `#[RoleOnly]`-enum, без Filament-дискавери/обычных enum-кейсов. Теперь в known входит **полный каталог пермишенов панели** (`collectCatalogAbilities`).

4. **FIX — `checkEnumsAgainstPolicies` требовал #[GateAbility]-policy-метод на КАЖДЫЙ enum-кейс.** Несовместимо с gate/ResourceGate-моделью Filament (policy нет by design). Теперь чек идёт только если у панели есть policy-классы (policy-модель); без policy — пропускается.

Итог: `guard:doctor: all checks passed` на agelto-панели; enforcement и каталог работают без консюмерских костылей.

**ОСТАЛОСЬ (заметки для пакета, не блокеры):**

- **DX — нет авто-дискавери `permissionEnums` панели.** Каждый `guard:filament:generate` добавляет enum, который надо внести в `permissionEnums()`. В agelto обошли глоб-дискавери из `Permissions/` (`AdminGuardPanelProvider::discoverPermissionEnums`, как роли из `Roles/`). Просится встроенный `permissionEnumsFromDirectory()`/авто-скан, симметрично role-discovery.
- **ДОК-ПРОБЕЛ — `basic-usage/filament.md` не объясняет главный сценарий:** для PHP class-ролей на Filament-панели нужна ОТДЕЛЬНАЯ AzGuard-`PanelProvider` (в `config/az-guard.php` `panels`) с `permissionEnums`, помимо `AzGuardPlugin` (Filament-плагин регистрирует только catalog-builder+ResourceGate, но НЕ панель в manager → `guard:sync-roles` без неё видит `panels=[]`). Добавить раздел «PHP roles on a Filament panel» (+ RU-зеркало ради docs-parity-гейта).
- **DX — `guard:catalog:validate`** шлёт warnings на Filament pages/widgets (`admin.dashboard.view`, `admin.account_widget.view`) без policy — та же policy-ассумпшн, что в чеке #4 (но это warnings, команда проходит).
- **OK (не баг) — вложенные namespace-модели** мапятся по short-name (`Ai\Provider`→`ProviderPermission`) без коллизий на фазе 1; риск при совпадении short-name в разных поддоменах — опция скоупа ключа поддоменом.
- Стаб/док `make:guard-role`: пресет `readOnly()` (view_any/view) для аналитических ролей.
- **Предсуществующий (НЕ мой)** фейл `CliReferenceDriftTest`: `guard:install` отсутствует в `docs/basic-usage/artisan-commands.md` (файл уже был `M` в git до правок) — почин отдельно.

Всё по packages/azguard — **отдельный PR + своё код-ревью + коммит** в его репозитории, не смешивая с agelto.

---

## Критические файлы
- Создаём: `app/Providers/Filament/AdminPanelProvider.php`, `app/Auth/Roles/{SuperAdmin,Operator,Analyst}Role.php`, `app/Auth/Permissions/*` (генерятся), `app/Filament/Resources/**`, сид ролей.
- Меняем: `composer.json` (repositories+require), `bootstrap/providers.php`, `app/Models/User.php`, `database/seeders/DatabaseSeeder.php`, `config/*` (публикуемые azguard-конфиги).
- Референс (не меняем, читаем): `Old/app/Filament/**`, `Old/Modules/Sys/app/Filament/**`, `Old/Modules/Eis/app/Filament/Components/EisCrawlerForm.php`, `docs/03_Dev/admin-panel.md`, `docs/03_Dev/architecture.md`, `docs/03_Dev/database.md`.

## Verification (end-to-end)
1. `docker compose exec app php artisan filament:check` / панель поднимается, `/admin` отдаёт логин.
2. `guard:sync-roles`, `guard:catalog-validate`, `guard:doctor` — зелёные; каталог содержит `admin.{resource}.{ability}` по всем ресурсам фазы 1.
3. Ручной прогон под тремя пользователями:
   - super-admin — видит все ресурсы, все экшены;
   - operator — видит/редактирует конфиг-ресурсы, реестр закупок только чтение, ролей/грантов не видит;
   - analyst — только read-only реестры; на CRUD-экшенах 403.
4. Pest: тест доступа на панель (`canAccessPanel`), тест что operator не может `create` Platform (гейт), тест что view-only `Run`/`Procurement` не имеют create/edit-страниц. Прогон через `docker compose exec app php` (изоляция БД — гейт `TestEnvironmentGuard`).
5. `pint --test`, целевые тесты — через `agelto-reviewer` / `agelto-test-verifier`.
6. Финальное **код-ревью** (`agelto-reviewer` + `agelto-security-review`: реально ли enforced авторизация, нет mass-assignment, нет секретов/кредов прокси в логах/таблицах — маскирование как в `admin-panel.md §2.1`).
```
```
