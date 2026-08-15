# P9 — terminal Filament admin surface reconnaissance

Дата повторной проверки: 2026-08-13. Candidate tree до P9.1: `0e852eb6`. Scope:
terminal products P0.4/P0.6/P2.4/P3.5/P4.5/P5.5/P6.6/P7.6/P8.5,
`app/Filament/**`, app-owned authorization, AzGuard source package и runtime admin routes.
Product/UI/package файлы не изменялись.

## Terminal upstream gate

| State | Статус | Committed product, который потребляет P9 |
|:--|:--|:--|
| P0.4 | 🟢 | Laravel/Filament evidence chain; current Filament contract подтверждается отдельно runtime inventory |
| P0.6 | 🟢 | frozen release contract; APP-11/APP-12 требуют русский UX и route/auth matrix |
| P2.4 | 🟢 | `17d16fa6`, reconciled P2.7 `cdc6ae5b`; private package и Compose bootstrap доступны |
| P3.5 | 🟢 | isolated admin/browser carrier и recovery path |
| P4.5 | 🟢 | private documentation route/authorization/build carrier |
| P5.5 | 🟢 | current proxy settings/resolver surface после P5.6 reconciliation |
| P6.6 | 🟠 terminal | typed document-format contract; historical process deviations не меняют P9 surface |
| P7.6 | 🟢 | analysis/screening/prompt/dataset/estimate projection после repair `92723313` |
| P8.5 | 🟢 | platform/registry/status-mapping surface после P8.7 candidate `d1c8a1e4` |

Все девять обязательных states терминальны. Незакрытых upstream assumptions для P9.1 нет.

## Runtime boundary

Команда внутри healthy Compose application container:

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test app \
  php artisan route:list --path=admin --json
```

дала exit 0 и 58 routes: 50 resource routes, 5 panel page routes, 2 auth routes и отдельный
`admin.documentation.show`. Runtime panel зарегистрировал 19 resources: 17 app-owned и 2
AzGuard-owned. App tree содержит 45 resource page classes, 21 schema carriers, 17 table carriers
и 5 relation managers. Три panel widgets (`AccountWidget`, `FilamentInfoWidget`,
`LlmSpendChartWidget`) не создают navigation subjects.

## Почему D37 superseded

Снимок 2026-08-09 группировал поверхность по внутренним слоям реализации:
«Краулинг» → «Закупки» → «AI-анализ» → «Доступ». Terminal surface показывает, что эта схема
не соответствует задачам оператора:

- «Краулинг» смешивает площадки, реестры, преобразование статусов, задания и запуски сбора,
  прокси, проверки площадок и общий журнал процессов.
- «AI-анализ» смешивает результаты обработки закупок, AI-инфраструктуру, инструкции модели,
  предложения по их улучшению и эталонный контур качества.
- почти все resource labels выводятся из английских class names; буквальный перевод таких имён
  не объяснит человеку назначение экрана.
- owner direction 2026-08-13 требует доменную, понятную человеку группировку: площадки,
  закупки и дополнительные процессы должны быть различимы по рабочему смыслу, а названия и
  описания — объяснять действие, не транслитерировать код.

Это evidence-backed contradiction именно navigation/copy части D37. Новый D82 сохраняет его
stable identity/package-boundary ограничения, но заменяет IA и naming canon. D38 не противоречит
terminal evidence и остаётся current.

## Целевая domain/task IA

Порядок отражает частоту и смысл работы, а не порядок pipeline implementation.

| Порядок | Группа | Простое назначение | Subjects |
|:--|:--|:--|:--|
| 1 | Закупки | Найти закупку и увидеть результат её обработки | Закупки · Сметы · Результаты анализа |
| 2 | Площадки | Настроить источники закупок и понять их состояние | Площадки · Реестры закупок · Соответствие статусов · Проверки площадок |
| 3 | Сбор данных | Управлять получением данных с площадок | Задания сбора · Запуски сбора · Прокси-серверы · Прокси по умолчанию |
| 4 | Качество анализа | Проверять и улучшать качество разбора документов | Эталонные документы · Проверенные примеры |
| 5 | Настройка AI | Настроить модели и инструкции, по которым работает анализ | AI-провайдеры · Модели для задач · Инструкции для AI · Сборка инструкции · Предложения по улучшению |
| 6 | Доступ и безопасность | Управлять правами и проверять контур доступа | Роли пользователей · Персональные права · Проверка доступа |
| 7 | Система | Найти техническую причину и открыть справку | Журнал процессов · Документация |

Subjects внутри каждой группы идут строго слева направо из таблицы с `navigationSort`
`10, 20, 30, ...`; у «Обзор» ungrouped sort `-100`. Эти числа — часть P9.2 regression contract,
а не подсказка исполнителю.

Dashboard остаётся до групп и получает имя «Обзор». Скрытый `FinalPromptPage` не добавляется в
меню и получает человекочитаемый title «Итоговый запрос к AI» только на своём authorized route.
Group/item purpose из этой таблицы — copy contract: экран использует его как heading/subheading,
empty-state или описание первой Section там, где соответствующий native Filament carrier есть.
В меню не изобретается unsupported custom description UI.

## Navigation, route and authorization matrix

`Auth owner` описывает текущую границу; P9 меняет presentation, но не grants, policies или URLs.

| Subject | Runtime route/pages | Current → target group | Target label | Auth owner | Consumer |
|:--|:--|:--|:--|:--|:--|
| Dashboard | `/admin` | ungrouped | Обзор | panel access | P9.2/P9.6 |
| ProcurementResource | `/admin/procurement/procurements` + view | Закупки → Закупки | Закупки | AzGuard ResourceGate | P9.2–P9.6 |
| EstimateResource | `/admin/procurement/estimates` + view | Закупки → Закупки | Сметы | AzGuard ResourceGate | P9.2–P9.6 |
| AnalysisResource | `/admin/procurement/ai/analyses` + view | AI-анализ → Закупки | Результаты анализа | `AnalysisPolicy` | P9.2–P9.6 |
| PlatformResource | `/admin/procurement/platform/platforms` + create/edit | Краулинг → Площадки | Площадки | AzGuard ResourceGate | P9.2–P9.6 |
| RegistryResource | `/admin/procurement/platform/registries` + create/edit | Краулинг → Площадки | Реестры закупок | AzGuard ResourceGate | P9.2–P9.6 |
| StatusMappingResource | `/admin/procurement/platform/status-mappings` + create/edit | Краулинг → Площадки | Соответствие статусов | AzGuard ResourceGate | P9.2–P9.6 |
| PlatformCheckResource | `/admin/procurement/health/platform-checks` + view | Краулинг → Площадки | Проверки площадок | AzGuard ResourceGate | P9.2–P9.6 |
| CrawlerResource | `/admin/procurement/crawler/crawlers` + create/edit | Краулинг → Сбор данных | Задания сбора | AzGuard ResourceGate | P9.2–P9.6 |
| RunResource | `/admin/procurement/crawler/runs` + view | Краулинг → Сбор данных | Запуски сбора | AzGuard ResourceGate | P9.2–P9.6 |
| ProxyResource | `/admin/procurement/crawler/proxies` + create/edit | Краулинг → Сбор данных | Прокси-серверы | AzGuard ResourceGate | P9.2–P9.6 |
| CrawlerProxySettingsPage | `/admin/crawler-proxy-settings` | Краулинг → Сбор данных | Прокси по умолчанию | `ProxyPermission::Update` | P9.2/P9.3/P9.6 |
| FixtureResource | `/admin/procurement/ai/fixtures` + create/view/gold/edit | AI-анализ → Качество анализа | Эталонные документы | AzGuard ResourceGate | P9.2–P9.6 |
| DatasetExampleResource | `/admin/procurement/ai/dataset-examples` + view | AI-анализ → Качество анализа | Проверенные примеры | AzGuard ResourceGate | P9.2–P9.6 |
| ProviderResource | `/admin/procurement/ai/providers` + create/edit | AI-анализ → Настройка AI | AI-провайдеры | AzGuard ResourceGate | P9.2–P9.6 |
| LlmRouteResource | `/admin/procurement/ai/llm-routes` + create/edit | AI-анализ → Настройка AI | Модели для задач | AzGuard ResourceGate | P9.2–P9.6 |
| PromptResource | `/admin/procurement/ai/prompts` + create/view/edit | AI-анализ → Настройка AI | Инструкции для AI | `PromptPolicy` | P9.2–P9.6 |
| PromptCompositionPage | `/admin/prompt-composition` | AI-анализ → Настройка AI | Сборка инструкции | `PromptResource::canViewAny()` | P9.2/P9.3/P9.6 |
| PromptSuggestionResource | `/admin/procurement/ai/prompt-suggestions` + view | AI-анализ → Настройка AI | Предложения по улучшению | AzGuard ResourceGate | P9.2–P9.6 |
| RoleResource | `/admin/roles` + create/edit | AzGuard → Доступ и безопасность | Роли пользователей | AzGuard ResourceGate | P9.2–P9.6 |
| DirectGrantResource | `/admin/direct-grants` + create | AzGuard → Доступ и безопасность | Персональные права | AzGuard ResourceGate | P9.2–P9.6 |
| DoctorPage | `/admin/doctor-page` | AzGuard → Доступ и безопасность | Проверка доступа | current panel access; page permission catalog is not an enforcement substitute | P9.2/P9.5/P9.6/P10 |
| OperationLogResource | `/admin/procurement/operation-logs` | Краулинг → Система | Журнал процессов | AzGuard ResourceGate | P9.2–P9.6 |
| Documentation item | `/admin/documentation/{path?}` | Доступ → Система | Документация | `DocumentationPermission::View` + controller check | P9.2/P9.6 |
| FinalPromptPage, hidden | `/admin/final-prompt/{run}` | hidden → hidden | Итоговый запрос к AI | `HasAzGuardPage`; no navigation registration | P9.2/P9.4/P9.6 |

### Exact runtime route baseline

Ниже — canonical route-list-order projection всех runtime routes по `(method, uri, name, action)`,
а не только navigation subjects. SHA-256 этой `jq -S` JSON projection:
`d81ccf3c63fd30961718a3aa775cd3d9b05e078fc8e08c75258a5f0fbfe94464` на candidate
`0e852eb6`. Для resource pages suffix `index/create/view/edit` разрешается соответственно через
`canViewAny/canCreate/canView/canEdit`; named custom actions сохраняют собственные predicates,
перечисленные в action carrier matrix ниже.

| Method | URI | Route name | Page/controller | Authorization owner |
|:--|:--|:--|:--|:--|
| `GET/HEAD` | `/admin` | `filament.admin.pages.dashboard` | `Filament\Pages\Dashboard` | panel access |
| `GET/HEAD` | `/admin/crawler-proxy-settings` | `filament.admin.pages.crawler-proxy-settings` | `App\Filament\Pages\CrawlerProxySettingsPage` | `ProxyPermission::Update` |
| `GET/HEAD` | `/admin/direct-grants` | `filament.admin.resources.direct-grants.index` | `AzGuard\Filament\Resources\DirectGrantResource\Pages\ListDirectGrants` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/direct-grants/create` | `filament.admin.resources.direct-grants.create` | `AzGuard\Filament\Resources\DirectGrantResource\Pages\CreateDirectGrant` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/doctor-page` | `filament.admin.pages.doctor-page` | `AzGuard\Filament\Pages\DoctorPage` | current panel access; P10 audits the gap |
| `GET/HEAD` | `/admin/documentation/{path?}` | `admin.documentation.show` | `App\Http\Controllers\Documentation\PrivateDocumentationController` | `DocumentationPermission::View` + controller |
| `GET/HEAD` | `/admin/final-prompt/{run}` | `filament.admin.pages.final-prompt.{run}` | `App\Filament\Pages\FinalPromptPage` | `HasAzGuardPage` + bound `Run` |
| `GET/HEAD` | `/admin/login` | `filament.admin.auth.login` | `Filament\Auth\Pages\Login` | panel guest/login boundary |
| `POST` | `/admin/logout` | `filament.admin.auth.logout` | `Filament\Auth\Http\Controllers\LogoutController` | authenticated panel session |
| `GET/HEAD` | `/admin/procurement/ai/analyses` | `filament.admin.resources.procurement.ai.analyses.index` | `App\Filament\Resources\Procurement\Ai\Analyses\Pages\ListAnalyses` | `AnalysisPolicy` → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/analyses/{record}` | `filament.admin.resources.procurement.ai.analyses.view` | `App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis` | `AnalysisPolicy` → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/dataset-examples` | `filament.admin.resources.procurement.ai.dataset-examples.index` | `App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ListDatasetExamples` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/dataset-examples/{record}` | `filament.admin.resources.procurement.ai.dataset-examples.view` | `App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ViewDatasetExample` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/fixtures` | `filament.admin.resources.procurement.ai.fixtures.index` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ListFixtures` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/fixtures/create` | `filament.admin.resources.procurement.ai.fixtures.create` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/ai/fixtures/{record}` | `filament.admin.resources.procurement.ai.fixtures.view` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/fixtures/{record}/edit` | `filament.admin.resources.procurement.ai.fixtures.edit` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\EditFixture` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/ai/fixtures/{record}/gold` | `filament.admin.resources.procurement.ai.fixtures.gold` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixtureGold` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/llm-routes` | `filament.admin.resources.procurement.ai.llm-routes.index` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\ListAiRoutes` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/llm-routes/create` | `filament.admin.resources.procurement.ai.llm-routes.create` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\CreateAiRoute` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/ai/llm-routes/{record}/edit` | `filament.admin.resources.procurement.ai.llm-routes.edit` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\EditAiRoute` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/ai/prompt-suggestions` | `filament.admin.resources.procurement.ai.prompt-suggestions.index` | `App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ListPromptSuggestions` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/prompt-suggestions/{record}` | `filament.admin.resources.procurement.ai.prompt-suggestions.view` | `App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ViewPromptSuggestion` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/prompts` | `filament.admin.resources.procurement.ai.prompts.index` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\ListPrompts` | `PromptPolicy` → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/prompts/create` | `filament.admin.resources.procurement.ai.prompts.create` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\CreatePrompt` | `PromptPolicy` → `canCreate` |
| `GET/HEAD` | `/admin/procurement/ai/prompts/{record}` | `filament.admin.resources.procurement.ai.prompts.view` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\ViewPrompt` | `PromptPolicy` → `canView` |
| `GET/HEAD` | `/admin/procurement/ai/prompts/{record}/edit` | `filament.admin.resources.procurement.ai.prompts.edit` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\EditPrompt` | `PromptPolicy` → `canEdit` |
| `GET/HEAD` | `/admin/procurement/ai/providers` | `filament.admin.resources.procurement.ai.providers.index` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\ListProviders` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/ai/providers/create` | `filament.admin.resources.procurement.ai.providers.create` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\CreateProvider` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/ai/providers/{record}/edit` | `filament.admin.resources.procurement.ai.providers.edit` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\EditProvider` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/crawler/crawlers` | `filament.admin.resources.procurement.crawler.crawlers.index` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/crawler/crawlers/create` | `filament.admin.resources.procurement.crawler.crawlers.create` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/crawler/crawlers/{record}/edit` | `filament.admin.resources.procurement.crawler.crawlers.edit` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/crawler/proxies` | `filament.admin.resources.procurement.crawler.proxies.index` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\ListProxies` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/crawler/proxies/create` | `filament.admin.resources.procurement.crawler.proxies.create` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\CreateProxy` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/crawler/proxies/{record}/edit` | `filament.admin.resources.procurement.crawler.proxies.edit` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\EditProxy` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/crawler/runs` | `filament.admin.resources.procurement.crawler.runs.index` | `App\Filament\Resources\Procurement\Crawler\Runs\Pages\ListRuns` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/crawler/runs/{record}` | `filament.admin.resources.procurement.crawler.runs.view` | `App\Filament\Resources\Procurement\Crawler\Runs\Pages\ViewRun` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/estimates` | `filament.admin.resources.procurement.estimates.index` | `App\Filament\Resources\Procurement\Estimates\Pages\ListEstimates` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/estimates/{record}` | `filament.admin.resources.procurement.estimates.view` | `App\Filament\Resources\Procurement\Estimates\Pages\ViewEstimate` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/health/platform-checks` | `filament.admin.resources.procurement.health.platform-checks.index` | `App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ListPlatformChecks` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/health/platform-checks/{record}` | `filament.admin.resources.procurement.health.platform-checks.view` | `App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ViewPlatformCheck` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/procurement/operation-logs` | `filament.admin.resources.procurement.operation-logs.index` | `App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/platform/platforms` | `filament.admin.resources.procurement.platform.platforms.index` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\ListPlatforms` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/platform/platforms/create` | `filament.admin.resources.procurement.platform.platforms.create` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\CreatePlatform` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/platform/platforms/{record}/edit` | `filament.admin.resources.procurement.platform.platforms.edit` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\EditPlatform` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/platform/registries` | `filament.admin.resources.procurement.platform.registries.index` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\ListRegistries` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/platform/registries/create` | `filament.admin.resources.procurement.platform.registries.create` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\CreateRegistry` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/platform/registries/{record}/edit` | `filament.admin.resources.procurement.platform.registries.edit` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\EditRegistry` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/platform/status-mappings` | `filament.admin.resources.procurement.platform.status-mappings.index` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\ListStatusMappings` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/platform/status-mappings/create` | `filament.admin.resources.procurement.platform.status-mappings.create` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\CreateStatusMapping` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/procurement/platform/status-mappings/{record}/edit` | `filament.admin.resources.procurement.platform.status-mappings.edit` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\EditStatusMapping` | AzGuard ResourceGate → `canEdit` |
| `GET/HEAD` | `/admin/procurement/procurements` | `filament.admin.resources.procurement.procurements.index` | `App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/procurement/procurements/{record}` | `filament.admin.resources.procurement.procurements.view` | `App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement` | AzGuard ResourceGate → `canView` |
| `GET/HEAD` | `/admin/prompt-composition` | `filament.admin.pages.prompt-composition` | `App\Filament\Pages\PromptCompositionPage` | `PromptResource::canViewAny()` |
| `GET/HEAD` | `/admin/roles` | `filament.admin.resources.roles.index` | `AzGuard\Filament\Resources\RoleResource\Pages\ListRoles` | AzGuard ResourceGate → `canViewAny` |
| `GET/HEAD` | `/admin/roles/create` | `filament.admin.resources.roles.create` | `AzGuard\Filament\Resources\RoleResource\Pages\CreateRole` | AzGuard ResourceGate → `canCreate` |
| `GET/HEAD` | `/admin/roles/{record}/edit` | `filament.admin.resources.roles.edit` | `AzGuard\Filament\Resources\RoleResource\Pages\EditRole` | AzGuard ResourceGate → `canEdit` |

AzGuard group identity remains `AzGuard`; application keyed display mapping renders it as
«Доступ и безопасность». Source-package hooks must preserve English defaults for other consumers.

## Schema and description matrix

Every field stays owned by its existing schema carrier. P9.3 groups by operator task and adds
purposeful copy only where meaning, units, precedence, security or side effects are not obvious.

| Surface family | Current carriers | Required human contract | Consumer |
|:--|:--|:--|:--|
| Закупки | `ProcurementInfolist`, `EstimateInfolist`, `AnalysisInfolist` | separate identity/result/timeline/errors; machine IDs secondary; screening terms explained | P9.3 |
| Площадки | `PlatformForm`, `RegistryForm`, `StatusMappingForm`, `PlatformCheckInfolist` | explain source vs registry, unified status, applicability, throttle units and check verdict | P9.3 |
| Сбор данных | `CrawlerForm`, `EisCrawlerPlatformForm`, `ProxyForm`, `RunInfolist`, settings page form | explain task/run distinction, schedule, fallback, proxy precedence, breaker health and read-only fields | P9.3 |
| Качество анализа | `FixtureForm`, `FixtureInfolist`, `GoldContentSchema`, `RunResultSchema`, `DatasetExampleInfolist` | use «эталон», «проверенный пример», source/version/provenance wording; technical storage data separated | P9.3 |
| Настройка AI | `ProviderForm`, `AiRouteForm`, `PromptForm`, `PromptInfolist`, `PromptSuggestionInfolist`, composition page | explain provider vs model-for-task vs instruction, priority/shadow/protected semantics and side effects | P9.3 |
| Доступ и безопасность | AzGuard Role/DirectGrant forms, tables, relation managers and Doctor | P9.2 package hook localizes headings, role, personal permission, expiry, code-managed role, diagnostics and revoke/sync actions while keeping evidence-grade keys/classes technical | P9.2 |

Current Sections already exist in the most mature read-only screens, but forms for providers,
AI routes, prompts, fixtures, platforms, registries and status mappings still expose a flat or
Fieldset-only structure. Literal Russian labels without the purpose copy above do not satisfy D82.

## Table, relation and action matrix

| Surface | Primary mobile identity/status | Secondary/technical content | Existing actions that must retain auth | Consumer |
|:--|:--|:--|:--|:--|
| Закупки | procurement title/registry/price/status; estimate title/status; analysis document/status/review | UUID, analysis/parent IDs, timestamps, screening details | view; approve/reject/rerun/final request; revision compare | P9.4/P9.6 |
| Площадки | platform/registry/status mapping/check verdict | UUID, codes, timestamps, check windows/baseline | create/edit/delete, view check | P9.4/P9.6 |
| Сбор данных | task registry/mode/active; run task/status/duration; proxy endpoint/country/active | counters, retry/cursor/breaker details, timestamps | start/restart/resume, pause/cancel, reactivate, CRUD | P9.4/P9.6 |
| Качество анализа | fixture code/title/type/verified; example fixture/document/task/source | manifest/storage/hash/token/cost/version fields | run/view result/final request, fixture CRUD | P9.4/P9.6 |
| Настройка AI | provider active; task model/priority/active; instruction type/key/version/active; suggestion status | provider IDs, raw keys, timestamps, origin/base version | CRUD, view, approve/reject | P9.4/P9.6 |
| Доступ и безопасность | role name/type/level; grantee/permission/expiry | numeric IDs, PHP class, raw permission key, timestamps | create/edit/revoke/sync/attach/detach | P9.2–P9.6 |
| Система | time/level/stage/message | entity type/ID | read-only | P9.4/P9.6 |

### Exact table/relation carriers

`Primary mobile` — обязательные target keys на малом viewport. Остальные перечисленные current
keys остаются достижимыми через breakpoint/toggle/view; ничего из списка молча не исчезает.

| Carrier | Primary mobile | Current secondary/technical keys | Current actions/auth carrier |
|:--|:--|:--|:--|
| `AnalysesTable` | `documentVersion.document.title`, `status`, `review_status` | `step`, `document_type`, `screening_cardinality`, `screening_confidence`, `screening_disposition`, `screening_reason`, `screening_summary`, `screening_tags`, `screening_lifecycle`, `parent_analysis_id`, `created_at` | `ViewAction`; `AnalysisResource`/`AnalysisPolicy` |
| `DatasetExamplesTable` | `fixture.code`, `documentVersion.document.title`, `task`, `source` | `document_type`, `version`, `verifiedBy.name`, `verified_at`, `created_at` | `ViewAction`; resource gate |
| `FixturesTable` | `code`, `title`, `document_type`, `is_verified` | `owner_document_type`, `source_format`, `in_manifest`, `gold`, `created_at` | `ViewAction`, `EditAction`, `DeleteBulkAction`; resource gate |
| `AiRoutesTable` | `provider.id`, `priority`, `is_active` | `shadow`, `created_at`, `updated_at` | `EditAction`, `DeleteBulkAction`; resource gate |
| `PromptSuggestionsTable` | `status`, `kind`, `prompt_key` | `base_version`, `document_type`, `analysis_step`, `origin`, `decidedBy.name`, `applied_version`, `created_at` | `ViewAction`; resource gate; page decisions below |
| `PromptsTable` | `document_type`, `key`, `version`, `is_active` | `step`, `priority`, `is_protected`, `created_at`, `updated_at` | `ViewAction`, `EditAction`, `DeleteBulkAction`; `PromptPolicy` |
| `ProvidersTable` | target adds existing model keys `code`, `driver`, keeps `is_active` | current table exposes only `is_active`, `created_at`, `updated_at`; this is an explicit P9.4 identity gap | `EditAction`, `DeleteBulkAction`; resource gate |
| `CrawlersTable` | `registry.name`, `mode`, `is_active` | `schedule`, `proxies_count`, `created_at`, `updated_at` | `start`, `restart`, `resume`, `EditAction`, `DeleteBulkAction`; custom predicates below |
| `ProxiesTable` | target adds endpoint identity (`service`/`host` as rendered by the model), keeps `port`, `country`, `is_active` | `scopes`, `fails_count`, `disabled_reason`, `disabled_at`, `open_until`, `created_at`, `updated_at` | `EditAction`, `reactivate`, `DeleteBulkAction`; custom predicate below |
| `RunsTable` | `crawler.name`, `status`, `duration` | `started_at`, `finished_at`, `fetches_count`, `fetches_rows_new_sum`, `fetches_rows_updated_sum`, `stop_reason`, `cap_hit`, `attempts`, `failed_permanently`, `created_at`, `updated_at` | `ViewAction`; page actions below |
| `EstimatesTable` | `title`, `procurement.title`, `validation_status` | `documentVersion.document.title`, `analysis.id`, `marked_items_count`, `created_at` | `ViewAction`; resource gate |
| `PlatformChecksTable` | `platform.name`, `check_kind`, `verdict` | `sample_size`, `requests_used`, `window_from`, `window_to`, `checked_at` | `ViewAction`; resource gate |
| `OperationLogsTable` | `created_at`, `level`, `stage`, `message` | `entity_type`, `entity_id` | read-only; resource gate |
| `PlatformsTable` | `name`, `code`, `is_active` | `uuid`, `base_url`, `created_at`, `updated_at` | `EditAction`, `DeleteBulkAction`; resource gate |
| `RegistriesTable` | `name`, `platform.name`, `code` | `uuid`, `created_at`, `updated_at` | `EditAction`, `DeleteBulkAction`; resource gate |
| `StatusMappingsTable` | `platform.name`, `registry.code`, `canonical_status` | `applicability`, `source_stage` | `EditAction`; resource gate |
| `ProcurementsTable` | target adds existing model title/status, keeps `registry.name`, `initial_price` | `uuid`, `published_at`, `bidding_ends_at`, `revision_count`, `detail_failed`, `last_seen_at`, `created_at` | `ViewAction`; resource gate |
| `CorrectionsRelationManager` | `reason`, `field_path`, `content` | `hint`, `is_reusable`, `applied_at`, `createdBy.name`, `created_at` | read-only under owning Analysis |
| `RunsRelationManager` | `status`, `mode`, `duration` | `started_at`, `finished_at`, `prompt_tokens`, `cached_tokens`, `completion_tokens`, `total_tokens`, `cost_micros` | `viewAnalysis`, `finalPrompt`, `result`; owning Fixture access + row-state predicates |
| `ItemsRelationManager` | `row_index`, `name`, `quantity`, `unit_price_curr`, `total_price_curr` | `section.title`, `unit`, `quality` | read-only under owning Estimate |
| `EventsRelationManager` | `occurred_at`, `event_type`, `field` | `old_value`, `new_value`, `revision.revision_number` | read-only under owning Procurement |
| `RevisionsRelationManager` | `revision_number`, `detected_at`, `change_reason` | `source_updated_at` | `comparePrevious`; visible only when `revision_number > 1` |
| `FinalPromptPage` table | `purpose`, `provider`, `model`, `success` | `called_at` | `select`; authorized hidden page |
| AzGuard `RoleResource` table | `name`, `is_code_role`, `level` | `id`, `dbPermissions_count`, `created_at` | `EditAction`, `DeleteAction`, `DeleteBulkAction`; AzGuard ResourceGate |
| AzGuard `DirectGrantResource` table | `grantable_id`, `permission_key`, `expires_at` | `panel_id`, `created_at` | `DeleteAction`, `DeleteBulkAction` («отозвать»); AzGuard ResourceGate |
| AzGuard `RolePermissionsRelationManager` | `permission_key`, `panel_id` | — | `sync_permissions`, `DeleteAction`, `DeleteBulkAction`; owning Role access |
| AzGuard `RoleUsersRelationManager` | user label/email | numeric `id` | `AttachAction`, `DetachAction`, `DetachBulkAction`; owning Role access |

### Exact custom action carriers

| Carrier | Actions | Frozen authorization/state predicate |
|:--|:--|:--|
| `CrawlersTable` | `start`, `restart`, `resume` | `RunPermission::Update`; active/paused run guards remain in visibility and mutation action |
| `ViewRun` | `pause`, `cancel` | `RunPermission::Update` and status in `Running|Paused` |
| `ProxiesTable` | `reactivate` | resource `update` plus `! is_active` |
| `ViewAnalysis` | `finalPrompt`, `approve`, `rejectWithCorrection`, `rerun` | page `view`; mutations require `AnalysisPolicy::update` plus their current success/document-type/terminal-state guards |
| `ViewFixture` | `run` | `AnalysisPermission::Create` and no active run |
| fixture `RunsRelationManager` | `viewAnalysis`, `finalPrompt`, `result` | owning Fixture access; `viewAnalysis` requires related analysis; destination page gates remain authoritative |
| `ViewPromptSuggestion` | `approve`, `reject` | `PromptSuggestionPermission::Update` and `Pending` status |
| `RevisionsRelationManager` | `comparePrevious` | owning Procurement access and `revision_number > 1` |
| `FinalPromptPage` | `select` | hidden page access; record selection is local read-only state |
| AzGuard role/grant carriers | `sync_permissions`, attach/detach/revoke CRUD | owning Role/DirectGrant resource gate; permission catalog and code-role restrictions remain unchanged |

P9.4 may group actions only by reusing these action objects and predicates; any missing row or
predicate drift fails the dynamic table contract.

## Label and value matrix

- Navigation/model/page/action labels follow the target terms above; plural/singular forms must be
  natural Russian («площадка / площадки», «задание сбора / задания сбора»), never title-cased
  transliterations of `Resource` class names.
- Direct Filament enum options currently include `CrawlerMode`, `RunStopPolicy`, `SourceMethod`,
  `ListingSortField`, `ProxyScope`, `StatusMappingApplicability` and `ProcurementStatus`; P9.2 owns
  their context-independent Russian labels.
- Context-specific document types, prompt steps, statuses/reasons and transport values use an
  app-owned presenter; P9 does not add Filament dependencies to provider-agnostic Llm contracts.
- Boolean columns already using `boolean()` remain semantic icons with accessible labels; raw or
  badge-like booleans in infolists/custom pages become «Да/Нет» or native boolean entries.

## Existing and required proof matrix

| Contract | Existing seam | Missing proof owner |
|:--|:--|:--|
| Panel/role/resource auth | `AdminPanelAccessTest` and focused resource tests | P9.5 full route-role matrix; P9.6 direct URLs |
| Domain transitions | `LayerNavigationTest` | P9.5 exact target routes after final labels/tables |
| Proxy settings | `CrawlerProxySettingsPageTest`, `CrawlerProxySettingsStorageTest` | P9.6 navigation/direct URL by role |
| Analysis/quality | Analysis, fixture, dataset, estimate, prompt/suggestion focused tests | P9.2–P9.4 shared label/schema/table contracts; P9.6 browser |
| Platform extension | Platform/StatusMapping/PlatformCheck focused tests | P9.2 navigation and P9.6 responsive access |
| AzGuard package UI | package Filament unit/feature tests | P9.2 English-default + arbitrary override tests, then Agelto Russian override test |
| Browser | terminal P3.4 Playwright carrier | P9.6 desktop/mobile IA and action reachability |

Exact current app proof carriers (the downstream dynamic suites augment, not replace, this list):

- `tests/Feature/Filament/AdminPanelAccessTest.php`
- `tests/Feature/Filament/AnalysisResourceTest.php`
- `tests/Feature/Filament/CrawlerFormTest.php`
- `tests/Feature/Filament/CrawlerProxySettingsPageTest.php`
- `tests/Feature/Filament/CrawlerRunActionsTest.php`
- `tests/Feature/Filament/CrawlerRunHistoryTest.php`
- `tests/Feature/Filament/DatasetExampleResourceTest.php`
- `tests/Feature/Filament/EstimateResourceTest.php`
- `tests/Feature/Filament/FinalPromptPageTest.php`
- `tests/Feature/Filament/FixtureGoldPageTest.php`
- `tests/Feature/Filament/FixtureResourceTest.php`
- `tests/Feature/Filament/FixtureRunHistoryTest.php`
- `tests/Feature/Filament/FixtureRunResultTest.php`
- `tests/Feature/Filament/FixtureRunScreenTest.php`
- `tests/Feature/Filament/LayerNavigationTest.php`
- `tests/Feature/Filament/LlmSpendChartWidgetTest.php`
- `tests/Feature/Filament/PlatformCheckResourceTest.php`
- `tests/Feature/Filament/PlatformFormTest.php`
- `tests/Feature/Filament/ProcurementHistoryTest.php`
- `tests/Feature/Filament/PromptCompositionPageTest.php`
- `tests/Feature/Filament/PromptResourceTest.php`
- `tests/Feature/Filament/PromptSuggestionResourceTest.php`
- `tests/Feature/Filament/ProxyReactivateActionTest.php`
- `tests/Feature/Filament/ProxyResourceTest.php`
- `tests/Feature/Filament/StatusMappingResourceTest.php`
- `tests/Feature/Crawler/CrawlerProxySettingsStorageTest.php`

Exact current AzGuard package proof carriers:

- `local/vendors/azguard/tests/Feature/Filament/DirectGrantResourceEagerLoadTest.php`
- `local/vendors/azguard/tests/Feature/Filament/DirectGrantResourceLikeEscapeTest.php`
- `local/vendors/azguard/tests/Feature/Filament/DoctorPageMemoizationTest.php`
- `local/vendors/azguard/tests/Feature/Filament/PageWidgetEnforcementTest.php`
- `local/vendors/azguard/tests/Feature/Filament/ResourceEnforcementTest.php`
- `local/vendors/azguard/tests/Feature/Filament/UserLabelColumnConfigTest.php`
- `local/vendors/azguard/tests/Unit/Filament/AzGuardFilamentServiceProviderTest.php`
- `local/vendors/azguard/tests/Unit/Filament/AzGuardPluginTest.php`
- `local/vendors/azguard/tests/Unit/Filament/FilamentArchTest.php`
- `local/vendors/azguard/tests/Unit/Filament/FilamentPermissionCatalogBuilderTest.php`
- `local/vendors/azguard/tests/Unit/Filament/PermissionEnumGeneratorTest.php`
- `local/vendors/azguard/tests/Unit/Filament/PermissionSchemaTest.php`
- `local/vendors/azguard/tests/Unit/Filament/PolicyGeneratorTest.php`
- `local/vendors/azguard/tests/Unit/Filament/ResourceGateTest.php`

## Downstream consumer map

| Item | Exact product from P9.1 |
|:--|:--|
| P9.2 | D82 groups/order, target labels/purpose copy, AzGuard config hook and app override |
| P9.3 | schema family matrix and non-obvious description/help obligations |
| P9.4 | primary/secondary/mobile column classification and existing action list |
| P9.5 | 58-route runtime baseline, exact auth owners and hidden-route classification |
| P9.6 | role/direct-URL/navigation/responsive/browser acceptance matrix |
| P10 | Doctor panel-only behavior and all auth owners remain explicit audit inputs |
| P11 | focused P9 suites and P3.4 browser carrier become CI consumers without a second stack |
| P12 | APP-11/APP-12 release check consumes final Russian IA and direct-route proof |

## Verification status

- RAG: official Filament 5.x navigation/enums/schemas/tables sources in the companion finding;
  installed v5.7.6 source and runtime panel cross-check OK.
- Repo/runtime: Task views green before work; nine upstream states terminal; Compose services
  healthy; isolated `route:list --path=admin --json` exit 0; runtime resources/pages/widgets
  enumerated through the booted panel.
- Contradiction: D37 grouping/copy superseded by D82; route, permission, plugin identity and
  installed-vendor boundaries remain unchanged. D38 remains current.
- Обходки/[POSSIBLE-DEFECT]: none. Host route-list failure is no longer used; runtime evidence came
  from the upstream Compose application container.
