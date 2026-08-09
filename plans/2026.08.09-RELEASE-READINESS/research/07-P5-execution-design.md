# P5 execution design — typed default proxy precedence

## Sources

- `findings/P5-proxy-settings-recon-2026-08-09.md` — repo call-site map and official Spatie RAG.
- `research/00-user-intent.md` — owner trace for profile → default → direct and tooling separation.
- D8 — immutable owner precedence and ProxyResolver/health boundary.
- P0.5/P0.6 state/products — authoritative future RAG/frozen contract, not yet produced.

P5 is designed before P0 execution. P5.1 is therefore a hard reconcile/freeze item: it requires
terminal P0.5/P0.6 and compares their named products with D28/D29 before any Composer, migration or
application mutation. Contradiction produces a superseding decision through plan-design; the executor
does not improvise. RAG:— (repo-grounded: P0.5/P0.6 current states and plan phase order).

## Alternatives and decisions

### Settings carrier

- A: env/config URL. Simple and deploy-owned, but not Filament-editable, duplicates proxy credentials
  outside `prc_proxies`, and cannot preserve existing health identity.
- B: bespoke singleton `prc_crawler_settings` model. Small dependency surface, but recreates typed
  migrations/cache/refresh and becomes a second local settings framework.
- C (D28): `spatie/laravel-settings ^3.9`, dedicated `prc_settings` repository and typed nullable
  `CrawlerProxySettings::defaultProxyId`. The payload is only an existing Proxy ID; credentials stay
  in `prc_proxies`. Official package compatibility and capabilities are captured in P5 findings.

### Profile semantics

- A: keep bool `use_proxy`; false inherits default. Minimal schema change, but silently changes every
  existing intentional-direct profile and cannot express explicit opt-out once default exists.
- B: nullable binding presence alone. No extra enum, but empty relation conflates inherit and direct.
- C (D29): `CrawlerProxyMode` = `profile|default|direct`; migrate current true→profile and false→direct,
  while new profiles default to `default`. This preserves old intent and makes inheritance explicit.

### Availability fallback

- A: if a configured profile/default node is unhealthy, continue down the precedence chain. More
  availability, but silently changes egress and erases breaker meaning.
- B (D29): precedence selects configuration, not health fallback. A configured source is passed as an
  exact ID set to ProxyResolver; empty/unhealthy/unscoped candidates fail visibly. Only absent default
  produces direct, and explicit direct is always visible.

### Observability

- A: structured logs only. Cheap, but fetch history cannot distinguish profile/default after rotation.
- B (D29): typed `ProxyRouteSource` on `ProxyLease`, structured resolution log, and nullable/string
  `proxy_source` on partitioned `prc_fetches`. Global/per-target health schemas remain unchanged.

## Frozen runtime contract

1. `profile`: listing uses that crawler's exact bindings/country; derived detail/document union exact
   bindings of active profile-mode crawlers for the procurement registry. Empty configured set is a
   validation error, not an unrestricted pool request.
2. `default`: resolver refreshes `CrawlerProxySettings`; an ID selects exactly that Proxy. Null means
   direct. Selected Proxy must be active and carry all three crawler scopes at save; scope/health are
   rechecked by the resolver on every target.
3. `direct`: ProxyResolver itself returns a typed direct lease. Call sites no longer construct direct
   leases or choose fallback independently.
4. For derived detail/document, aggregate precedence is: any profile-mode bindings → their union;
   else any default-mode profile → global default/direct; else explicit direct. Mixed profiles are
   deterministic and covered by a dataset.
5. An unavailable configured profile/default throws/logs `NoProxyAvailableException`; it never falls
   through to another source. `reportOutcome()` continues to update the same global/per-target carriers.
6. LLM keeps `PoolOutboundProxy` + `AI_GEMINI_PROXY`; Playwright MCP keeps its launcher/env. Neither
   consumes `CrawlerProxySettings`.
7. Settings save is an Action mutation behind `ProxyPermission::Update`; Filament shows only safe
   Proxy identity/service/host/country/scopes and never dehydrates credentials into settings state.
8. Horizon freshness is explicit: a read-side settings resolver calls official `refresh()` before
   selection; production cache is enabled only if cross-process edit/read proof stays green.

RAG:— (repo-grounded synthesis: D8/D28/D29 and P5 findings).

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P5.1 | terminal P0.5/P0.6 | reconciled findings/contracts and stable D28/D29 or explicit superseding chain | P5.2 may change dependencies/schema only after freeze |
| P5.2 | terminal P5.1 | locked package, dedicated settings repository/class and refresh-aware reader | P5.3 consumes one typed carrier |
| P5.3 | terminal P5.2 | typed modes/source, migrations and centralized resolver precedence at every call site | P5.4 exposes only sealed semantics |
| P5.4 | terminal P5.3 | authorized secret-safe settings/profile UX | P5.5 tests real UI/runtime carrier |
| P5.5 | terminal P5.4 | full precedence/health/no-proxy/tooling proof and operator docs | P10/P12 consume evidence |

The items form a strict reconcile→carrier→runtime→UI→acceptance chain and share Composer/schema,
resolver, Filament and test hotspots. The N≥3 independent criterion is false and no autonomous
external-state chain spans sessions; orchestration is not declared. RAG:— (repo-grounded: dependency
matrix above).

## Validation strategy

1. Contract gate: terminal P0.5/P0.6, named products, no contradiction hidden as implementation.
2. Storage: clean migration, nullable default, dedicated table, Horizon-like refresh after another
   process saves, rollback without data/schema ambiguity.
3. Runtime: dataset for profile/default/direct; listing/detail/document/OKPD2; configured-unavailable
   fail-closed; mixed derived profiles; source persisted in fetch evidence.
4. Health: same proxy/global target rows and breaker behavior for profile and default; direct no-op;
   no second health repository/table.
5. UI/security: ProxyPermission access matrix, scope validation, masked/no raw credentials, Russian
   labels/helper text, existing profile migration and create/edit flows.
6. Separation: exact-name search proves Playwright env only in tooling; Gemini pool/env tests remain
   unchanged; full no-TIA acceptance is green on isolated test DB.

## Verification status

- RAG: official GitHub/Spatie ×3 fact classes OK; repo inspection OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0.5/P0.6 terminal products; P5.1 is the exact reconcile carrier.
- Обходки/[POSSIBLE-DEFECT]: none.
