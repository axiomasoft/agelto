# P9 execution design — domain-oriented Russian Filament UX

## Sources

- `findings/P9-filament-v5-rag-2026-08-09.md` — official Filament 5.7 contracts and URLs.
- `findings/P9-filament-surface-recon-2026-08-09.md` — terminal runtime surface, route/auth,
  schema/table/action and consumer matrices.
- `research/00-user-intent.md` and owner direction 2026-08-13 — Russian UX must be understandable
  by work domain and human task, not by literal translation of implementation names.
- terminal P0.4/P0.6/P2.4/P3.5/P4.5/P5.5/P6.6/P7.6/P8.5 products and
  `root/contracts/release-readiness-v1.md` APP-11/APP-12.

## Reconciled decisions

### Navigation and package boundary

- D37's stable URL/permission/plugin identity and no-installed-vendor rules remain valid.
- D37's four implementation-shaped groups are rejected after terminal inventory: «Краулинг» and
  «AI-анализ» each mix unrelated operator tasks, while most labels are generated from English
  class names.
- D82 supersedes D37 with seven ordered task domains: «Закупки», «Площадки», «Сбор данных»,
  «Качество анализа», «Настройка AI», «Доступ и безопасность», «Система».
- The AzGuard source package receives backward-compatible config-driven labels/groups with English
  defaults. Agelto owns Russian overrides and keyed display ordering. Installed vendor bytes,
  route slugs, permission keys, Policies/Gates and `shouldRegisterNavigation` remain unchanged.
- Filament Cluster для «Настройка AI» рассмотрен и отклонён в P9: официальный contract скрывает
  дочерние sidebar items, добавляет sub-navigation и новый URL prefix. Это полезная IA-механика,
  но здесь она превратит copy/regrouping item в route/auth migration. Повторно рассматривать только
  отдельным post-release decision с redirects, bookmarks, permission and browser migration proof.

### Human naming and descriptions

- A resource is named after the question or action it answers: `Crawler` → «Задание сбора»,
  `LlmRoute` → «Модель для задачи», `Prompt` → «Инструкция для AI», `Fixture` → «Эталонный
  документ». Transliteration and mechanical class-name translation are rejected.
- Every navigation subject has one plain-language purpose. Native headings/subheadings, empty
  states, Section descriptions and helper text carry that purpose where applicable; a custom
  navigation-description framework is not introduced.
- Code identifiers, logs and payload keys remain technical where they are evidence. A rendered
  operator label must explain them or keep them secondary/toggleable rather than disguise them.

### Enum, boolean, schemas and tables

- D38 remains current: direct context-independent enum values use one Russian `HasLabel`; contextual
  and provider-agnostic values stay behind an app-owned presenter.
- Boolean state uses accessible native boolean icon/entry or «Да/Нет», never raw `1/0` or an
  unexplained badge.
- Forms/infolists use task Sections; help describes units, precedence, security, read-only state and
  side effects only where non-obvious. Validation, dehydration, policy and mutation semantics stay
  byte-equivalent in behavior.
- Primary operator identity/status/action remains mobile-visible. Technical IDs and detailed
  provenance remain reachable but secondary; long text has both dense display and full-value access.

## Frozen target contract

1. Navigation order is deterministic and tested: ungrouped «Обзор», then «Закупки», «Площадки»,
   «Сбор данных», «Качество анализа», «Настройка AI», «Доступ и безопасность», «Система».
2. «Закупки» contains Закупки, Сметы and Результаты анализа: these are the operator's core object
   and its business outputs, even though Analysis is implemented under an `Ai` namespace.
3. «Площадки» contains source identity/configuration/status/health; «Сбор данных» contains collection
   jobs, their runs and network route configuration. Platform and collection are not one menu bucket.
4. «Качество анализа» separates эталонные документы and проверенные примеры from «Настройка AI»,
   which contains providers, task models, instructions, composition and improvement suggestions.
5. Access management and diagnostics are separate from system logs/documentation. AzGuard external
   identity stays stable while Agelto displays «Доступ и безопасность».
6. Every visible app/package subject and hidden FinalPrompt route has an explicit natural Russian
   label and one-sentence purpose; Dashboard is «Обзор». No visible English fallback remains.
7. Generated route slugs, permission keys, Policies/Gates, panel access and hidden-page semantics do
   not change for translation or regrouping.
   Domain groups may be collapsible, но ни одна current surface не переводится в Cluster в P9.
8. P9.2 implements label/group/copy identity; P9.3 implements schema descriptions; P9.4 implements
   table density/full-value/action presentation; none may change domain persistence.
9. P9.5 reproduces the terminal 58-route baseline inside the Compose application stack and binds
   every visible/hidden subject to its exact auth owner and destination.
10. P9.6 combines focused Pest/Livewire behavior tests with the terminal P3.4 Playwright carrier for
    role visibility, direct URL denial, navigation order/copy, desktop/mobile tables/actions and clean
    console/network/Livewire state.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P9.1 | terminal upstream states and booted admin surface | D82 plus complete domain/navigation/copy/schema/table/auth/test matrices | no UI mutation before freeze |
| P9.2 | terminal P9.1 D82/D38 | package hook + Russian domain groups, labels and purpose copy | schemas/tables consume one naming contract |
| P9.3 | terminal P9.2 | task-sectioned forms/infolists with useful descriptions and unchanged validation/auth | tables and route audit see final schemas |
| P9.4 | terminal P9.3 | responsive table/ID/long-text/action contract across resources/relation managers | route sweep tests final list UX |
| P9.5 | terminal P9.4 | full runtime route/auth inventory and bounded presentation remediation | browser acceptance receives stable route matrix |
| P9.6 | terminal P9.5 + terminal P3.4 | Livewire/Playwright/auth/responsive proof and synchronized docs | P10/P11/P12 consume validated admin surface |

All items mutate or validate the same navigation/label/schema/table/test/doc hotspots and consume the
preceding product. The independent-scope criterion is false; orchestration remains undeclared.

## Validation strategy

1. Static identity: every discovered app/package resource/page has expected domain group, natural
   label, sort and purpose; hidden subjects remain hidden; English package defaults still pass.
2. Enum/boolean: dataset-driven label assertions prove Russian values across form/table/infolist
   contexts without adding Filament dependencies to Llm transport contracts.
3. Schemas: component tests prove task Sections and purposeful descriptions/help on the matrix's
   ambiguous fields while retaining rules, dehydration, visibility and authorization.
4. Tables: per-builder matrix proves primary mobile columns/actions, hidden technical IDs,
   full-value access and existing authorization/query predicates.
5. Runtime routes: Compose `route:list` plus role matrix covers the exact destination/auth owner for
   all discovered subjects and rejects orphan, duplicate or unauthorized-visible navigation items.
6. Browser: reuse P3.4 setup/storageState with desktop/mobile viewports, stable role/label/test-id
   locators, no sleeps and clean console/network/Livewire failure artifacts.

## Risk controls

| Failure | Detection | Action |
|:--|:--|:--|
| Menu mirrors PHP namespaces | target label/group differs only by literal translation | fail P9.2 contract; use D82 purpose and task vocabulary |
| Core result hidden under AI settings | Analysis remains grouped with providers/prompts | fail navigation matrix; keep «Результаты анализа» in «Закупки» |
| Platform and crawler stay conflated | platform identity and collection execution share one group | fail group membership test |
| Friendly copy changes semantics | route/permission/policy/validation/dehydration diff | revert product drift; P9 presentation only |
| Technical details disappear | no toggle/full-value/route to evidence | fail P9.4 contract; secondary is not removed |
| Package update loses Russian copy | override depends on installed-vendor patch | fail P9.2 package test/lock boundary |
| Direct URL remains untested | navigation link is the only proof | fail P9.5/P9.6 auth matrix |

## Verification status

- RAG: official Filament 5.x docs captured in the companion finding; cluster contract отдельно
  проверен 2026-08-13; installed v5.7.6 source cross-check OK.
- Terminal runtime: nine upstream states terminal; booted panel and isolated admin route inventory
  succeeded on candidate `0e852eb6`.
- Decision chain: D82 supersedes only D37; D38 remains current; D19/D24 and APP-11/APP-12 stay
  compatible.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: product implementation and browser proof belong to P9.2–P9.6.
- Обходки/[POSSIBLE-DEFECT]: none.
