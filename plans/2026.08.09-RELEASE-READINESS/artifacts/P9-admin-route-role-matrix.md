# P9 — матрица admin routes и ролей

Снимок получен из изолированного Compose runtime командой
`docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test app php artisan route:list --path=admin --json`.
`AdminRouteInventoryTest` сравнивает весь снимок с runtime: добавленный, удалённый, дублированный
или переназначенный route не может остаться вне матрицы.

## Ролевой контракт

Все application и AzGuard routes проходят собственный page/resource gate: пользователь без роли
получает 403 по прямому URL. `SuperAdmin` проходит wildcard. `Analyst` имеет только read surfaces:
dashboard, Doctor, analyses, fixtures, prompt suggestions, runs, estimates и procurements.
`Operator` дополнительно ведёт configuration surfaces, но не создаёт status mappings, роли,
direct grants, LLM routes или operation logs. Record routes повторно проверяют `view`/`update`
соответствующего owner; скрытый FinalPrompt и documentation route не получают исключения из этого
правила. Login/logout — единственные auth routes, не являющиеся operator navigation subjects.

| Метод | URI | Route | Action |
|:--|:--|:--|:--|
| `GET|HEAD` | `admin` | `filament.admin.pages.dashboard` | `Filament\Pages\Dashboard` |
| `GET|HEAD` | `admin/crawler-proxy-settings` | `filament.admin.pages.crawler-proxy-settings` | `App\Filament\Pages\CrawlerProxySettingsPage` |
| `GET|HEAD` | `admin/direct-grants` | `filament.admin.resources.direct-grants.index` | `AzGuard\Filament\Resources\DirectGrantResource\Pages\ListDirectGrants` |
| `GET|HEAD` | `admin/direct-grants/create` | `filament.admin.resources.direct-grants.create` | `AzGuard\Filament\Resources\DirectGrantResource\Pages\CreateDirectGrant` |
| `GET|HEAD` | `admin/doctor-page` | `filament.admin.pages.doctor-page` | `AzGuard\Filament\Pages\DoctorPage` |
| `GET|HEAD` | `admin/documentation/{path?}` | `admin.documentation.show` | `App\Http\Controllers\Documentation\PrivateDocumentationController` |
| `GET|HEAD` | `admin/final-prompt/{run}` | `filament.admin.pages.final-prompt.{run}` | `App\Filament\Pages\FinalPromptPage` |
| `GET|HEAD` | `admin/login` | `filament.admin.auth.login` | `Filament\Auth\Pages\Login` |
| `POST` | `admin/logout` | `filament.admin.auth.logout` | `Filament\Auth\Http\Controllers\LogoutController` |
| `GET|HEAD` | `admin/procurement/ai/analyses` | `filament.admin.resources.procurement.ai.analyses.index` | `App\Filament\Resources\Procurement\Ai\Analyses\Pages\ListAnalyses` |
| `GET|HEAD` | `admin/procurement/ai/analyses/{record}` | `filament.admin.resources.procurement.ai.analyses.view` | `App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis` |
| `GET|HEAD` | `admin/procurement/ai/dataset-examples` | `filament.admin.resources.procurement.ai.dataset-examples.index` | `App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ListDatasetExamples` |
| `GET|HEAD` | `admin/procurement/ai/dataset-examples/{record}` | `filament.admin.resources.procurement.ai.dataset-examples.view` | `App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages\ViewDatasetExample` |
| `GET|HEAD` | `admin/procurement/ai/fixtures` | `filament.admin.resources.procurement.ai.fixtures.index` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ListFixtures` |
| `GET|HEAD` | `admin/procurement/ai/fixtures/create` | `filament.admin.resources.procurement.ai.fixtures.create` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture` |
| `GET|HEAD` | `admin/procurement/ai/fixtures/{record}` | `filament.admin.resources.procurement.ai.fixtures.view` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture` |
| `GET|HEAD` | `admin/procurement/ai/fixtures/{record}/edit` | `filament.admin.resources.procurement.ai.fixtures.edit` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\EditFixture` |
| `GET|HEAD` | `admin/procurement/ai/fixtures/{record}/gold` | `filament.admin.resources.procurement.ai.fixtures.gold` | `App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixtureGold` |
| `GET|HEAD` | `admin/procurement/ai/llm-routes` | `filament.admin.resources.procurement.ai.llm-routes.index` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\ListAiRoutes` |
| `GET|HEAD` | `admin/procurement/ai/llm-routes/create` | `filament.admin.resources.procurement.ai.llm-routes.create` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\CreateAiRoute` |
| `GET|HEAD` | `admin/procurement/ai/llm-routes/{record}/edit` | `filament.admin.resources.procurement.ai.llm-routes.edit` | `App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\EditAiRoute` |
| `GET|HEAD` | `admin/procurement/ai/prompt-suggestions` | `filament.admin.resources.procurement.ai.prompt-suggestions.index` | `App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ListPromptSuggestions` |
| `GET|HEAD` | `admin/procurement/ai/prompt-suggestions/{record}` | `filament.admin.resources.procurement.ai.prompt-suggestions.view` | `App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages\ViewPromptSuggestion` |
| `GET|HEAD` | `admin/procurement/ai/prompts` | `filament.admin.resources.procurement.ai.prompts.index` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\ListPrompts` |
| `GET|HEAD` | `admin/procurement/ai/prompts/create` | `filament.admin.resources.procurement.ai.prompts.create` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\CreatePrompt` |
| `GET|HEAD` | `admin/procurement/ai/prompts/{record}` | `filament.admin.resources.procurement.ai.prompts.view` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\ViewPrompt` |
| `GET|HEAD` | `admin/procurement/ai/prompts/{record}/edit` | `filament.admin.resources.procurement.ai.prompts.edit` | `App\Filament\Resources\Procurement\Ai\Prompts\Pages\EditPrompt` |
| `GET|HEAD` | `admin/procurement/ai/providers` | `filament.admin.resources.procurement.ai.providers.index` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\ListProviders` |
| `GET|HEAD` | `admin/procurement/ai/providers/create` | `filament.admin.resources.procurement.ai.providers.create` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\CreateProvider` |
| `GET|HEAD` | `admin/procurement/ai/providers/{record}/edit` | `filament.admin.resources.procurement.ai.providers.edit` | `App\Filament\Resources\Procurement\Ai\Providers\Pages\EditProvider` |
| `GET|HEAD` | `admin/procurement/crawler/crawlers` | `filament.admin.resources.procurement.crawler.crawlers.index` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers` |
| `GET|HEAD` | `admin/procurement/crawler/crawlers/create` | `filament.admin.resources.procurement.crawler.crawlers.create` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler` |
| `GET|HEAD` | `admin/procurement/crawler/crawlers/{record}/edit` | `filament.admin.resources.procurement.crawler.crawlers.edit` | `App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler` |
| `GET|HEAD` | `admin/procurement/crawler/proxies` | `filament.admin.resources.procurement.crawler.proxies.index` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\ListProxies` |
| `GET|HEAD` | `admin/procurement/crawler/proxies/create` | `filament.admin.resources.procurement.crawler.proxies.create` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\CreateProxy` |
| `GET|HEAD` | `admin/procurement/crawler/proxies/{record}/edit` | `filament.admin.resources.procurement.crawler.proxies.edit` | `App\Filament\Resources\Procurement\Crawler\Proxies\Pages\EditProxy` |
| `GET|HEAD` | `admin/procurement/crawler/runs` | `filament.admin.resources.procurement.crawler.runs.index` | `App\Filament\Resources\Procurement\Crawler\Runs\Pages\ListRuns` |
| `GET|HEAD` | `admin/procurement/crawler/runs/{record}` | `filament.admin.resources.procurement.crawler.runs.view` | `App\Filament\Resources\Procurement\Crawler\Runs\Pages\ViewRun` |
| `GET|HEAD` | `admin/procurement/estimates` | `filament.admin.resources.procurement.estimates.index` | `App\Filament\Resources\Procurement\Estimates\Pages\ListEstimates` |
| `GET|HEAD` | `admin/procurement/estimates/{record}` | `filament.admin.resources.procurement.estimates.view` | `App\Filament\Resources\Procurement\Estimates\Pages\ViewEstimate` |
| `GET|HEAD` | `admin/procurement/health/platform-checks` | `filament.admin.resources.procurement.health.platform-checks.index` | `App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ListPlatformChecks` |
| `GET|HEAD` | `admin/procurement/health/platform-checks/{record}` | `filament.admin.resources.procurement.health.platform-checks.view` | `App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ViewPlatformCheck` |
| `GET|HEAD` | `admin/procurement/operation-logs` | `filament.admin.resources.procurement.operation-logs.index` | `App\Filament\Resources\Procurement\OperationLogs\Pages\ListOperationLogs` |
| `GET|HEAD` | `admin/procurement/platform/platforms` | `filament.admin.resources.procurement.platform.platforms.index` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\ListPlatforms` |
| `GET|HEAD` | `admin/procurement/platform/platforms/create` | `filament.admin.resources.procurement.platform.platforms.create` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\CreatePlatform` |
| `GET|HEAD` | `admin/procurement/platform/platforms/{record}/edit` | `filament.admin.resources.procurement.platform.platforms.edit` | `App\Filament\Resources\Procurement\Platform\Platforms\Pages\EditPlatform` |
| `GET|HEAD` | `admin/procurement/platform/registries` | `filament.admin.resources.procurement.platform.registries.index` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\ListRegistries` |
| `GET|HEAD` | `admin/procurement/platform/registries/create` | `filament.admin.resources.procurement.platform.registries.create` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\CreateRegistry` |
| `GET|HEAD` | `admin/procurement/platform/registries/{record}/edit` | `filament.admin.resources.procurement.platform.registries.edit` | `App\Filament\Resources\Procurement\Platform\Registries\Pages\EditRegistry` |
| `GET|HEAD` | `admin/procurement/platform/status-mappings` | `filament.admin.resources.procurement.platform.status-mappings.index` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\ListStatusMappings` |
| `GET|HEAD` | `admin/procurement/platform/status-mappings/create` | `filament.admin.resources.procurement.platform.status-mappings.create` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\CreateStatusMapping` |
| `GET|HEAD` | `admin/procurement/platform/status-mappings/{record}/edit` | `filament.admin.resources.procurement.platform.status-mappings.edit` | `App\Filament\Resources\Procurement\Platform\StatusMappings\Pages\EditStatusMapping` |
| `GET|HEAD` | `admin/procurement/procurements` | `filament.admin.resources.procurement.procurements.index` | `App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements` |
| `GET|HEAD` | `admin/procurement/procurements/{record}` | `filament.admin.resources.procurement.procurements.view` | `App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement` |
| `GET|HEAD` | `admin/prompt-composition` | `filament.admin.pages.prompt-composition` | `App\Filament\Pages\PromptCompositionPage` |
| `GET|HEAD` | `admin/roles` | `filament.admin.resources.roles.index` | `AzGuard\Filament\Resources\RoleResource\Pages\ListRoles` |
| `GET|HEAD` | `admin/roles/create` | `filament.admin.resources.roles.create` | `AzGuard\Filament\Resources\RoleResource\Pages\CreateRole` |
| `GET|HEAD` | `admin/roles/{record}/edit` | `filament.admin.resources.roles.edit` | `AzGuard\Filament\Resources\RoleResource\Pages\EditRole` |
