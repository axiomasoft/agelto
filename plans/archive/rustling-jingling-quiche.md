# Актуализация доменной структуры и документации после унификации `prc_`

## Context

Прошлая сессия унифицировала все таблицы под единый префикс `prc_` (кроме `ref_*`/`org_organizations`), но не довела последствия до конца:

1. **`.ai/guidelines/*.md`** (source-файлы, из которых генерируются `CLAUDE.md`/`AGENTS.md`/скиллы) и скилл `project-map` — устарели ещё СИЛЬНЕЕ, чем казалось: они описывают дособытийную архитектуру (`Modules/Platform`, `Modules/Eis`, `app/Models/Order/`), которой в живом дереве нет уже давно (осталась только в `Old/Modules`), ссылаются на несуществующие `docs/dev/*`, `docs/workflow/*`, и `architecture.md` прямо содержит устаревший префикс `plt_*`. Глоссарий (`.ai/guidelines/glossary.md`) вообще не документирует связку «короткий префикс таблицы ↔ полное доменное имя» — она есть только в удалённом уже плане прошлой сессии.
2. **Доменная структура кода не обновлена под унификацию.** Модели (и остальные слои — Services/Dto/Enums/Repositories/Console) всё ещё разложены по папкам `Ai/`, `Crawler/`, `Document/`, `Estimate/`, `Platform/`, `Sys/` — то есть по СТАРЫМ префиксам, которые перестали существовать как отдельные таблицы. Все они теперь `prc_*` и должны жить в одной папке `Procurement/` (уже существует и уже содержит часть prc_-моделей — остальные разбросаны). `Reference/` (`ref_`) и `Organization/` (`org_organizations`) — самостоятельные домены, не трогаем, кроме одной находки: `ProcurementParty` (таблица `prc_procurement_parties`) сейчас лежит в `Organization/`, хотя по правилу должна быть в `Procurement/`.
3. **`docs/specs/v2/`** — рабочие ТЗ-доки для разработки v2 «с нуля»; v2 больше не в разработке, это и есть текущая система. Часть контента (`01-database.md`…`05-admin-observability.md`) — живая техническая референс-документация для разработчиков; часть (`06-roadmap.md`, `07-carryover-from-v1.md`) — чисто историческая. Perplexity Web (`search_advanced`, web, 2024–2025) подтверждает практику: публичная документация (Diátaxis: tutorials/guides/reference/concepts, для не-инженера) и внутренняя инженерная (reference/architecture/ADR, для разработчика) — раздельно, без версионного «vN» фрейминга в живой доке.
4. **`docs/` — не свободная папка, а канал синхронизации с Obsidian-хранилищем Brain.** Проверено напрямую: `~/Vaults/Brain/.projects.json` содержит запись проекта Agelto с `"folders": ["docs", "docs-public"]` — именно эти две папки репозитория зеркалятся в vault (`brain sync`), `docs-private` в этом списке нет и не должно быть (не синхронизируется, чисто репо-локальная). Контрол-нота проекта в vault (`05-Projects/01-Products/Agelto/Agelto.md`) до сих пор ссылается на `docs/01_Business/_index`, `docs/02_Workflow/_index`, `docs/03_Dev/_index` — это структура из `Old/docs/` (`00_Ideas/`, `01_Business/`, `02_Workflow/`, `03_Dev/` + `_index.md` на каждую категорию), которую нужно взять за базовую и наполнить актуальным контентом, а не изобретать плоскую `docs/business-processes/`. Смонтированный в vault `docs/specs/v2` — это стухший результат прошлого `brain sync` (двусторонний git-merge вымыл старые категории, когда в репозитории появился только `docs/specs/v2`); восстановление канонической структуры в репо само чинит vault на следующем sync.

## Scope of work

### A. Консолидация доменных папок под `prc_` (Models + остальные слои)

**Правило (зафиксировать в `.ai/guidelines/architecture.md` и скилле `project-map`):** папка = полное доменное имя, соответствующее короткому префиксу таблицы. `prc_` → `Procurement/`, `ref_` → `Reference/`, `org_` → `Organization/`. Внутри домена подпапки — по смыслу (не по бывшему префиксу).

**Что переносится в `app/Models/Procurement/` (из отдельных папок, ставших избыточными):**
- `Ai/` (8 моделей: AiRoute, Analysis, DatasetExample, EvalResult, EvalRun, LlmCall, Prompt, Provider) — все `prc_ai_*`.
- `Crawler/` (8: Crawler, DailyStat, Fetch, IgnoredItem, IgnoreRule, ProxyCheck, Proxy, Run) — все `prc_*` (crawlers/daily_stats/fetches/ignored_items/ignore_rules/proxy_checks/proxies/crawler_runs).
- `Document/` (2: Document, DocumentVersion) — `prc_documents`, `prc_document_versions`.
- `Estimate/` (5: Estimate, Item, Resource, Section, Total) — `prc_estimates` и сателлиты.
- `Platform/` (5: DocumentType, Platform, Region, Registry, StatusMapping) — все `prc_*`.
- `Sys/` (1: OperationLog) — `prc_operation_logs`.
- `Organization/ProcurementParty.php` (таблица `prc_procurement_parties`) — единственный файл в `Organization/`, который на самом деле принадлежит закупкам, а не организациям.

**Не трогаем:** `Reference/` (Kbk/Ktru/Okpd2/Okved2 — `ref_*`), `Organization/Organization.php` (`org_organizations`), уже корректно лежащие в `Procurement/` модели (Award, Bid, Cancellation, Clarification, EisDetails, Financing, Lot, ProcurementEvent, Procurement, ProcurementRevision, Protocol, PurchaseItem), `User.php`.

**Та же консолидация — в остальных слоях** (по факту разведки, только там, где фрагментация реально есть):
- `app/Services/Crawler/**` (23 файла, включая `Eis/`, `Modes/`, `Contracts/`, `Exceptions/`) и `app/Services/Document/**` (3) → `app/Services/Procurement/**` (сохранить внутренние подпапки `Eis/Modes/Contracts/Exceptions` как есть, просто под новым корнем).
- `app/Dto/Crawler/**` (17, включая `Details/`) → `app/Dto/Procurement/**`.
- `app/Enums/Ai/`, `Enums/Crawler/`, `Enums/Document/`, `Enums/Estimate/`, `Enums/Sys/` (3+4+1+1+1 = 10 файлов) → `app/Enums/Procurement/`.
- `app/Repositories/Document/` (1 файл) → `app/Repositories/Procurement/`.
- `app/Console/Commands/Crawler/` (4 файла) → `app/Console/Commands/Procurement/`.
- `app/Services/Organization/` (1 файл) — **перед переносом проверить содержимое**: если сервис работает с `org_organizations` (общий справочник) — оставить в `Organization/`; если специфичен для закупок — перенести в `Procurement/`. Не переносить вслепую по имени папки.

Итого ~87 файлов на перенос + обновление namespace/`use`-выражений везде, где они импортируются (весь `app/**`, `tests/**`, `database/factories/**`). Модули `Modules/`, `Filament/`, `Actions/`, `Jobs/`, `Http/Controllers/` (кроме базового) — пустые или не существуют в живом дереве, переносить нечего.

**Как делать:** это namespace-рефакторинг, не переименование таблиц — используем skill `namespace-refactor-safety`, но БЕЗ фазы alias (внешних потребителей нет, продукт не в проде): `git mv` файла → обновить `namespace`-декларацию в нём → якорным поиском (по точному FQN класса, не по голому слову `Ai`/`Crawler`) обновить все `use `-выражения и статические ссылки (`Ai\Analysis::class` и т.п.) во всём дереве. Отдельно проверить фабрики (`database/factories/**`) — Laravel резолвит фабрику модели по namespace-конвенции (`App\Models\X\Y` → `Database\Factories\X\YFactory` либо через `newFactory()`), при переносе модели фабрика должна переехать туда же и/или конвенция должна продолжать резолвиться.

**Гейт:** `vendor/bin/phpstan analyse` (если настроен) + `php artisan test --compact` полностью зелёные после переноса; `grep -rn 'App\\\\Models\\\\Ai\\\\\|App\\\\Models\\\\Crawler\\\\\|App\\\\Models\\\\Document\\\\\|App\\\\Models\\\\Estimate\\\\\|App\\\\Models\\\\Platform\\\\\|App\\\\Models\\\\Sys\\\\'` (и то же самое для Services/Dto/Enums/Repositories/Commands неймспейсов) по всему `app/`, `tests/`, `database/` — ноль совпадений вне `Old/`.

### B. `docs/` по канонической Brain-структуре + роспуск `docs/specs/v2/`

Базовая структура — как в `Old/docs/` (подтверждено содержимым, `_index.md` на каждую категорию, именно на неё ссылается vault-контрол-нота):

```
docs/
  index.md                    — корневой обзор (было docs/business-processes/00-overview.md)
  01_Business/
    _index.md
    concept-and-monetization.md   (было business-processes/05-monetization-and-market.md, разбить по темам как в Old)
    competitive-analysis.md
    financial-model.md
  02_Workflow/
    _index.md
    glossary.md                  (business-processes/04-glossary.md)
    procurement-lifecycle.md     (business-processes/01-procurement-lifecycle.md)
    crawling-and-discovery.md    (business-processes/02-crawling-and-discovery.md)
    document-and-estimate-pipeline.md (business-processes/03-document-and-estimate-pipeline.md)
  03_Dev/
    _index.md
    architecture.md              (актуализация .ai/guidelines/architecture.md + техстек из specs/v2/00-overview.md §3)
    database.md                  (specs/v2/01-database.md)
    crawling-pipeline.md          (specs/v2/02-crawling.md)
    document-pipeline.md          (specs/v2/03-document-pipeline.md)
    llm-providers.md              (specs/v2/04-llm-providers.md)
    admin-panel.md                 (specs/v2/05-admin-observability.md)
```

`00_Ideas/`, `02_Workflow/Lead_Verification.md`, `Onboarding.md` из Old — не переносим (нерелевантны текущей системе, как и раньше решили). `docs/business-processes/` — расформировать в `01_Business`/`02_Workflow` по таблице выше, старую плоскую папку удалить. `docs/specs/v2/06-roadmap.md` и `07-carryover-from-v1.md` — чисто историческое, НЕ в `docs/` (не должно синхроваться в Brain) → `docs-private/history/roadmap.md` и `carryover-from-v1.md`.

`docs-private/` (не синхронизируется с Brain, только в репо) остаётся: `crawler-gotchas.md`, `open-follow-ups.md`, `risk-register.md` (без изменений) + новая `history/` с двумя файлами выше.

**Обновить перекрёстные ссылки** (найдены разведкой) на новые пути:
- `.env.example:57`, `research/tz-v2/v1-gap.md:1`, `plans/tz-v2-crawling-system.md`, `plans/ACTIVE.md`
- `docs-private/crawler-gotchas.md`, `open-follow-ups.md`, `risk-register.md` (уже ссылаются на старые `specs/v2/*` пути)
- PHPDoc `@see`-комментарии в коде: `app/Services/.../EventJournalParser.php:15`, `SupplierResultsParser.php:21`, `AiRoute.php:16` (путь + возможно namespace-класс, если модель уже переехала по части A)

После переноса — удалить пустую `docs/specs/` и `docs/business-processes/`. По завершении — упомянуть в отчёте, что `brain sync`/`maind` (если доступен в среде исполнения) стоит прогнать отдельно, чтобы обновить зеркало в vault; сам вызов `brain sync` — не READ-ONLY действие на внешнее хранилище, выполнять только по запросу пользователя, не автоматически.

### C. Актуализация `.ai/guidelines/*.md` + скилла `project-map`

Каждый файл переписать под фактическую текущую структуру (после A и B):

- **`architecture.md`**: убрать `Modules/Platform`/`Modules/Eis`, заменить на плоский `app/**` с доменами `Procurement/Reference/Organization`; заменить `plt_*` на `prc_*`/`ref_*`/`org_*`; ссылки на доки — на новые пути (`docs/02_Workflow/*` — бизнес-процессы, `docs/03_Dev/*` — тех. референс).
- **`navigation.md`**: заменить все ссылки на несуществующие `docs/workflow/*`, `docs/dev/*`, `docs/ai/*` — на актуальные `docs/02_Workflow/*` (бизнес-термины/глоссарий) и `docs/03_Dev/*` (тех. детали, архитектура, схема БД).
- **`glossary.md`**: добавить таблицу «короткий префикс ↔ полное доменное имя» (`prc_` → Procurement/закупки, `ref_` → Reference/справочники, `org_` → Organization/организации) — сейчас такой связки нет вообще нигде в живых guideline-файлах.
- **`project-development.md`**: то же самое — убрать `Modules/*`, поправить пути на несуществующие доки.
- **`agent-task-templates.md`**: только точечно поправить единственную битую ссылку (`docs/workflow/parsing-process.md` → актуальный путь); шаблоны сами по себе не устарели.
- **Скилл `project-map`** (`.claude/skills/project-map/SKILL.md`): переписать под факт — плоский `app/Models/{Procurement,Reference,Organization}/` и т.д. вместо `Modules/Platform`/`Modules/Eis`/`app/Models/Order/`.

После правки source-файлов — прогнать синхронизацию в `CLAUDE.md`/`AGENTS.md`/скиллы (`composer ai:sync` упоминается в `architecture.md`, но сам механизм не найден локально в `composer.json` — на исполнении проверить, чем именно синхронизируется; если скрипта нет в этом репо — сообщить и синхронизировать вручную по той же структуре, что уже в текущем `CLAUDE.md`).

## Execution order

1. **A (доменные папки)** — первым, остальное на него ссылается. Делегировать одному `agelto-slice-builder`-агенту с полным списком переносов (как в прошлой сессии с переименованием таблиц), гейт — phpstan+тесты+grep на старые namespace.
2. **B (роспуск specs/v2)** — после A (чтобы новый `architecture.md`-референс уже описывал финальную структуру кода).
3. **C (.ai/guidelines + project-map skill)** — последним, документирует итоговое состояние после A и B; затем `composer ai:sync` (или ручной эквивалент).

## Verification

- `grep -rn` по старым namespace-фрагментам (`Models\Ai\`, `Models\Crawler\` и т.д.) и по старым путям доков (`docs/specs/v2`, `docs/business-processes`, `docs/dev/`, `docs/workflow/`, `Modules/Platform`, `Modules/Eis`) по всему репо вне `Old/` — ноль совпадений.
- `php artisan test --compact` и (если настроен) `vendor/bin/phpstan analyse` — зелёные после части A.
- Ручная сверка: `docs/01_Business|02_Workflow|03_Dev/_index.md` и все файлы внутри открываются, не содержат битых внутренних ссылок; `docs-private/history/*` аналогично.
- `.ai/guidelines/glossary.md` содержит таблицу префикс↔домен; `CLAUDE.md`/`AGENTS.md` пересобраны и не противоречат новому `.ai/guidelines/*`.
- (по запросу пользователя, не автоматически) `brain status`/`brain sync` — подтвердить, что vault-зеркало (`05-Projects/01-Products/Agelto/docs/`) снова видит `01_Business/02_Workflow/03_Dev` вместо стухшего `specs/v2`.
