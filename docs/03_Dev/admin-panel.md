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
| `Ai/` | `Providers`, `AiRoutes`, `Prompts`, `Analyses`, `DatasetExamples` — AI-пайплайн |

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
