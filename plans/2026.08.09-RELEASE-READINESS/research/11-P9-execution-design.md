# P9 execution design — Russian Filament IA and responsive operator UX

## Sources

- `findings/P9-filament-v5-rag-2026-08-09.md` — official Filament 5.7 contracts and URLs.
- `findings/P9-filament-surface-recon-2026-08-09.md` — current Agelto resources/pages/schemas/
  tables/auth tests and AzGuard/Filament locked-source behavior.
- `research/00-user-intent.md` — owner trace for Russian navigation, Sections/help, hidden UUID,
  enum/boolean labels and full-route coverage.
- terminal P0.4/P0.6 and P3–P8 products — future frozen runtime/domain surfaces; P9.1 reconciles
  them before UI mutation.

## Alternatives and decisions

### Navigation and package boundary

- A: patch `vendor/axioma-studio/azguard-filament` labels/group. Rejected: generated/private
  dependency bytes are not the application SSOT and update would overwrite the patch.
- B: replace Filament navigation with a hand-built `NavigationBuilder`. Rejected: duplicates
  discovery, visibility and authorization behavior for every resource.
- C (D37): keep stable resource/plugin identities, add a backward-compatible config-driven label
  hook with English defaults to the AzGuard source package, register an ordered keyed display map
  in `AdminPanelProvider`, and make every Agelto resource/page label explicit in Russian. Agelto
  overrides plugin labels in config; external `AzGuard` identity maps to «Доступ» and is last.

### Enum and boolean presentation

- A: per-screen option arrays/format callbacks everywhere. Rejected: repeated translations drift
  across form/table/filter/infolist contexts.
- B: force every domain enum to depend on Filament. Rejected: context-specific prompt steps and
  provider-agnostic LLM contracts would acquire an unnecessary UI dependency.
- C (D38): context-independent enums already consumed directly by Filament may implement
  `Filament\Support\Contracts\HasLabel`; context-specific/interface-driven values stay translated
  in an app-owned Filament presenter. Boolean state uses `IconColumn`/`IconEntry::boolean()` or
  explicit «Да/Нет», never raw `1/0` or ambiguous badge text.

### Forms and tables

- Forms/infolists group fields by operator task with named `Section` and descriptions/helper text
  only where meaning, unit, precedence, security or side effect is non-obvious. Validation,
  dehydration, policy and mutation semantics remain unchanged.
- Technical identifiers remain reachable but hidden by default when not primary operator identity.
  Essential columns stay mobile-visible; secondary columns use breakpoint visibility/toggleability;
  long text uses wrap+line clamp+full-value tooltip. Custom CSS/JS and global width hacks are
  rejected while native Filament contracts suffice.

RAG:✅ 2026-08-09 official Filament navigation/enums/schemas/tables docs; application boundary is
repo-grounded in the two P9 findings.

## Frozen target contract

1. Navigation order is deterministic and tested: Dashboard/ungrouped first, then «Краулинг»,
   «Закупки», «AI-анализ», and «Доступ» last; hidden pages remain hidden. Vendor group identity
   stays `AzGuard`; the package exposes config-driven labels with English backward-compatible
   defaults, while Russian values and ordered display mapping stay application-owned.
2. Every navigation-visible Agelto resource/page has explicit Russian singular/plural/navigation
   labels and deterministic sort. Generated route slugs and permission keys do not change merely
   for translation.
3. Only context-independent enums that are already direct Filament values receive `HasLabel`;
   PromptStep/Llm transport interfaces remain Filament-free. One test enumerates every enum class
   passed to `options(Enum::class)` and proves non-raw Russian labels.
4. Boolean fields/entries/columns render an unambiguous semantic state and keep accessible labels.
   Data and validation types remain boolean; display fixes never rewrite stored values.
5. Every create/edit/view schema has named operator-task Sections. Helper text explains units,
   inheritance, precedence, secret/readonly behavior and side effects; obvious fields do not get
   filler text. Disabled/dehydrated/security semantics are preserved exactly.
6. UUID/internal IDs are classified, not blanket-removed: primary operator identity may stay;
   secondary technical IDs are toggleable hidden by default. Long content preserves full value via
   tooltip/view while using wrap/line clamp in list density.
7. Responsive tables retain essential identity/status/action access at mobile viewport. Grouping
   actions must reuse existing action objects/authorization predicates; no CSS-class selectors or
   authorization bypasses are introduced.
8. P9.5 materializes a complete runtime route/resource matrix inside the isolated stack and fixes
   only mismatches covered by D37/D38. A domain/schema/permission contradiction escalates to
   plan-design instead of being disguised as copy or validation cleanup.
9. P9.6 combines focused Pest/Livewire behavior tests with the terminal P3.4 Playwright carrier:
   Analyst/Operator/SuperAdmin visibility, direct-URL denial, navigation order/labels, target routes,
   mobile tables/actions, no console/page/Livewire errors and clean isolated state.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P9.1 | terminal P0.4/P0.6/P3.5/P4.5/P5.5/P6.6/P7.6/P8.5 | reconciled route/resource/schema/table matrix and stable D37/D38 | no UI mutation before freeze |
| P9.2 | terminal P9.1 | AzGuard localization hook + explicit Russian resource/page/enum/boolean labels and navigation map | schemas/tables consume one label contract |
| P9.3 | terminal P9.2 | sectioned forms/infolists with purposeful help and unchanged validation/auth | tables and route audit see final schemas |
| P9.4 | terminal P9.3 | responsive table/ID/long-text/action contract across resources/relation managers | route sweep tests final list UX |
| P9.5 | terminal P9.4 | full runtime route inventory and bounded local UX/validation remediation | browser acceptance receives stable route matrix |
| P9.6 | terminal P9.5 + terminal P3.4 | Livewire/Playwright/auth/responsive proof and synchronized docs | P10/P11/P12 consume validated admin surface |

All items mutate or validate the same Filament/navigation/label/schema/table/test/doc hotspots and
each consumes the preceding product. The N≥3 independent criterion is false; there is no long
external-state chain. Orchestration is not declared. RAG:— (repo-grounded: dependency matrix).

## Validation strategy

1. Static inventory: all discovered resources/pages/groups have expected label/group/sort and no
   raw English fallback; AzGuard display mapping remains app-owned.
2. Enum/boolean: dataset-driven label assertions and Livewire schema/table state prove Russian
   labels and non-raw booleans without touching persistence semantics.
3. Schemas: component-existence tests prove named Sections, helper text on identified ambiguous
   fields, unchanged rules/dehydration and role access.
4. Tables: per-builder contract matrix proves hidden technical IDs, full-value access, breakpoints,
   action reachability and no N+1/query regression where existing tests cover it.
5. Runtime routes: isolated `route:list` plus role matrix and exact owner target URLs; zero orphan,
   duplicate or unauthorized-visible navigation entries.
6. Browser: extend P3.4 Playwright setup/storageState; fixed desktop and mobile viewports, no sleeps,
   stable role/label/test-id locators, clean console/network/Livewire and failure artifacts.

## Verification status

- RAG: official Filament 5.x docs via WebSearch ×8 queries; installed v5.7.6 source cross-check OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: terminal P0/P3–P8 products are future hard inputs to P9.1,
  not guessed current facts.
- Обходки/[POSSIBLE-DEFECT]: host `route:list` unavailable because DB host `postgres` is outside
  Compose; P9.5 uses the isolated stack, no application workaround accepted.
