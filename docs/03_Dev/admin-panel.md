---
audience: dev
type: reference
status: draft
---

# Admin Panel (Filament) — для разработчика

## Ресурсы

`app/Filament/Resources/Procurement/` — по подпапкам вплотную к домену:

| Подпапка | Ресурсы |
|:--|:--|
| `Procurements/` | Закупки (карточка закупки, документы, сметы) |
| `Crawler/` | `Runs`, `Proxies`, `IgnoreRules`, `Crawlers` — управление обходом |
| `Platform/` | `Platforms`, `Registries` — площадки-источники |
| `Ai/` | `Providers`, `AiRoutes`, `Prompts`, `Analyses`, `DatasetExamples`, `Fixtures` — AI-пайплайн |
| `Estimates/` | `EstimateResource` (P8.5) — read-only витрина смет: список со счётчиком «помечено N из M» и просмотр позиций с пометкой неопределённости |

`Crawler/Proxies` — форма узла: страна и скоупы (`ProxyScope`, мультиселект) — закрытые списки
(`ProxyForm::COUNTRIES`), не свободный текст; здоровье breaker'а — read-only `Fieldset`
(`disabled()->dehydrated(false)`, правка не долетает до записи — автомат обновляет эти поля сам).
`CrawlerForm` несёт `use_proxy`/`proxy_country` профиля (тот же закрытый список стран).

`Ai/Fixtures` (P5) — рабочее место для прогонки эталонных документов через штатный пайплайн вручную:
карточка эталона (файл/код/оси/`type_profile`) → relation-менеджер прогонов (`RunsRelationManager`,
токены/цена) → модальное сравнение результата анализатора с эталоном (`RunResultSchema`). Соседние
экраны того же контура — финальный склеенный промт прогона и очередь предложений по промтам
(approve/reject/audit) — см. Completion Notes P5.5/P5.6 в `plans/archive/2026.07.28-EST-ARCH/phases/P5.md`.

`Estimates/EstimateResource` (P8.5) — read-only на уровне ресурса (`canCreate`/`canEdit`/`canDelete`
запрещены явно, D137: правки позиции оператором в этой фазе нет, OQ-10). Счётчик «помечено N из M»
на списке — агрегат в запросе (`withCount`), не выборка позиций в PHP: число запросов списка не
растёт с числом позиций сметы. Просмотр несёт `ItemsRelationManager` (не секция инфолиста —
`RepeatableEntry` не даёт интерактивный `->filters()`, нужный для «только помеченные») с колонкой
пометки: причина по-русски (`RowQualityReason::label()` — единственный носитель перевода) и
провенанс («Не прочитано моделью» / «Нашла проверка сметы»); имена полей — подписи колонок сметы,
не машинные ключи (`ItemsRelationManager::FIELD_LABELS` — единственный носитель).

Каждый ресурс — Filament v5 `Schemas`/`Tables`/`Pages` подпапки (канон skill `filament`,
scaffold только через `make:filament-*`).

## AzGuard RBAC-гейт

`ResourceGate::check()` коротко замыкает `Gate::before` по карте `FilamentDiscovery::subjects()` —
для большинства Procurement-ресурсов авторизация решается ГРУБО (per-resource permission из
`AdminGuardPanelProvider::discoverPermissionEnums()`), без записи-специфичных проверок.

**Исключение — `PromptResource` (D44).** `config('az-guard-filament.exclude.resources')` исключает
`PromptResource` из discovery-каталога плагина: `ResourceGate::check()` для `Prompt` возвращает
`null` (карта его не знает), enum-RBAC `Gate::before` НЕ срабатывает, штатный `Gate::policy()`
резолвится в `App\Guards\Admin\Policies\PromptPolicy` (`app/Guards/Admin/Policies/PromptPolicy.php`,
`#[GuardPolicy(model: Prompt::class)]` + `#[GateAbility]`). Политика перепроверяет И грубое право
(`hasPermission()`), И запись-специфичное условие (`!is_protected` — сидовые промты из обоих корней
канона (`resources/ai/prompts/**` + `resources/<домен>/ai/prompts/**`, D32) read-only в админке, D17).
Это единственный официально задокументированный
паттерн композиции «коарс-право + запись-специфичный чек» в AzGuard (`packages/azguard/docs/
best-practices/policies-and-gates.md`) — не RBAC-роль, `PromptPermission`/`OperatorRole` не менялись.

## Смежное

- Как именно read-only работает для оператора — `docs/04_Operators/how-to/manage-prompts.md` (P10.4).
- Промт-система целиком — [`ai/prompts.md`](ai/prompts.md).
- Общий канон AzGuard — skill `laravel-permissions`/`attribute-authorization`.
