# P9 — current Filament admin surface reconnaissance

Дата снимка: 2026-08-09. Scope: `app/Filament/**`, `app/Providers/Filament/**`, relevant
`app/Enums/**`, `config/az-guard-filament.php`, installed Filament/AzGuard sources and current
Filament tests. Product files не изменялись.

## Surface map

- 17 `*Resource.php` classes, 17 resource table builders, 9 forms, 9 infolists and 46 resource
  page classes. Команды воспроизведения: `rg --files app/Filament/Resources` с фильтрами
  `Resource.php`, `/Tables/`, `/Schemas/.*Form.php`, `/Schemas/.*Infolist.php`, `/Pages/`.
- Две custom pages: navigation-visible `PromptCompositionPage` и hidden
  `FinalPromptPage`. Первая уже имеет русский navigation label и прямой `canAccess()` gate;
  вторая использует `HasAzGuardPage` и намеренно не регистрируется в navigation.
- `AdminPanelProvider` объявляет порядок `Краулинг → Закупки → AI-анализ → Доступ`, но plugin
  resources фактически публикуют group identity `AzGuard`. Filament 5.7.6
  `NavigationManager` умеет сопоставить associative key группы с другим display label, поэтому
  `['AzGuard' => NavigationGroup::make('Доступ')]` закрывает русификацию/порядок без vendor fork.
  RAG:— (repo-grounded: `app/Providers/Filament/AdminPanelProvider.php`,
  `vendor/axioma-studio/azguard-filament/src/**`,
  `vendor/filament/filament/src/Navigation/NavigationManager.php`).
- Plugin menu items сами жёстко заданы по-английски (`Role(s)`, `Direct Grant(s)`, `Doctor`) в
  source package `/home/vostrikov/projects/packages/azguard/packages/filament/src/**`; application
  override отсутствует. Полный brief «всё меню по-русски» требует backward-compatible config hook
  в source package с English defaults и Russian overrides в Agelto. Правка installed `vendor/`
  запрещена; package tree уже несёт unrelated dirty generated-skill changes, которые item не должен
  включать в commit.
- Только `PromptSuggestionResource` задаёт явные `$modelLabel`/`$pluralModelLabel`; остальные
  resource labels выводятся из English class names. Группы и sorts заданы строками на каждом
  resource; повторяющиеся group identities являются coupling hotspot.

## Labels and schemas

- В `app/Enums/**` нет реализации Filament `HasLabel`. Filament forms передают enum class напрямую
  для `ProcurementStatus`, `CrawlerMode`, `RunStopPolicy`, `SourceMethod`, `ListingSortField` и
  `ProxyScope`; `PromptCompositionPage` вручную отдаёт raw values для document types/steps.
- Boolean table state уже часто использует `IconColumn::boolean()`, но infolist entries и custom
  page values местами остаются badge/raw-state. Общий контракт «Да/Нет либо однозначная иконка с
  accessible label» не проверяется тестом.
- 13 из 18 form/infolist schema files не имеют собственного top-level `Section::make`; часть
  использует `Fieldset`/вложенные section factories, но единая operator-task hierarchy отсутствует.
- `helperText()` встречается точечно (главным образом `CrawlerForm` и `PlatformForm`), а поля
  `ProxyForm` вроде service/host/port/scopes/health не имеют полного русского label/help contract.
  Запрошенная поверхность `/admin/procurement/crawler/proxies/create` воспроизводит этот класс.
- `StatusMappingForm` показывает disabled raw `registry_id` как «ID реестра»; planned P8 изменит
  applicability model, поэтому P9 не должна закреплять текущую форму до terminal P8.5.

## Tables and routes

- В current `app/Filament/**` нет `visibleFrom()`/`hiddenFrom()` и нет `lineClamp()`; `wrap()`
  используется 9 раз. 26 secondary columns уже toggleable-hidden-by-default.
- UUID видим по умолчанию в Platforms, Registries и Procurements tables. Дополнительные technical
  IDs (`parent_analysis_id`, `entity_id`) требуют явной per-column классификации, а не blanket hide.
- Long text policies неоднородны: встречаются `limit(60|80)` и `wrap()`, но нет единого tooltip/full
  value rule. Record actions должны сохранять существующие authorization predicates при grouping.
- `php artisan route:list --path=admin --json` в host-сессии остановился до inventory: `.env`
  указывает DB host `postgres`, который вне Compose не резолвится. Это environment limitation,
  не finding приложения; P9.5 обязан строить runtime route matrix внутри изолированного test stack.

## Existing proof seams

- `tests/Feature/Filament/AdminPanelAccessTest.php` проверяет panel/role/resource abilities и
  Prompt policy exception.
- `tests/Feature/Filament/LayerNavigationTest.php` проверяет переходы procurement/analysis/
  estimate/fixture, но не полный navigation order/labels/route reachability.
- Точечные resource tests существуют для Crawler, Proxy, Platform, StatusMapping, Prompt,
  Analysis-adjacent and training surfaces; общего schema/table contract test и responsive browser
  sweep пока нет.
- Playwright carrier отсутствует сейчас, но запечатан как продукт P3.4 (D24); P9.6 должен расширять
  terminal P3.4 stack, а не создавать второй E2E framework.

## Verdict

P9 остаётся шестью последовательными items: сначала terminal-upstream reconcile и freeze IA, затем
labels (включая backward-compatible AzGuard source-package localization hook) → schemas → tables →
полный route/local mismatch pass → Livewire/browser/auth acceptance. Общего product redesign или
installed-vendor edit для выполнения owner brief не требуется.
