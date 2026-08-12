# P8 execution design — exact adapter identity and status applicability

## Sources and terminal reconcile

- `findings/P8-platform-status-extension-recon-2026-08-09.md` — reconciled DB/runtime/UI/test
  seams and the frozen capability/collision/status matrices.
- `findings/P0-rag-domain-security.md` plus terminal P0.5 state/commits — document taxonomy stays
  independent of source/format identity; no competing platform adapter or status contract appears.
- `root/contracts/release-readiness-v1.md` plus terminal P0.6 state/commits — EXT-14 confirms typed
  platform/status seams and forbids a hard-coded procurement-law switch.
- terminal P7.6 state and `92723313` products — table-driven reason/outcome matrix and architecture
  enumeration are the accepted proof shape for closed typed contracts.
- D35/D36 — accepted platform identity and status applicability decisions.

P8 does not implement a real new procurement source. It makes the existing boundary honest: data
rows register identity, while typed code capabilities register crawl behavior. All new conclusions
below are repository-grounded. RAG:—.

## Reconciliation verdict

P0.5, P0.6 and P7.6 are terminal and their committed products were inspected. P0.5 reinforces the
separation between procurement taxonomy and format/source behavior; P0.6 freezes typed extension as
a release invariant; P7.6 supplies the test pattern for a finite typed matrix and invalid
combinations. None changes the exact platform key or adds another status applicability.

D35 and D36 therefore remain byte-stable. No superseding decision is created. The expanded
row-mapper and pagination rows in the findings are consequences of making the selected source
method executable under D35: current generic consumers still use a global EIS mapper and EIS block
size, so P8.2 must route those sub-capabilities through the same exact platform selection. This
does not introduce another adapter identity, fallback or universal platform API.

## Alternatives and frozen decisions

### Runtime platform extension carrier

- A: keep global `source_methods`, registry-code-only `filters`, global EIS row mapper and EIS block
  size in generic processors. Rejected: a second CSV platform or same-named registry silently
  receives EIS behavior.
- B: store handler FQCNs in `prc_platforms`/`prc_registries`. Rejected: deployable code identity
  becomes mutable DB data, breaks config cache/static analysis and enables unsafe class injection.
- C (D35, frozen): one typed `PlatformAdapterDefinition` per exact `Platform.code`; it owns
  supported source-method capabilities and nested registry filter capabilities.
  `PlatformAdapterRegistry` is the sole `crawler.platforms` reader and every missing capability
  fails closed.

A source-method capability is minimal but executable: method implementation, the matching raw-row
mapper and its listing cursor/page-size semantics. The registry capability supplies its typed
filter and mandatory-scope/form behavior. These are concrete producer/consumer pairs observed in
the current tree, not a speculative `PlatformAdapter` interface with listing/detail/status methods.
New capability kinds are added only when another implementation proves a shared contract.

### Status applicability

- A: exact registry only. Safe and preserved as backfill identity, but duplicates platform-wide
  vocabularies and cannot express requested defaults.
- B: platform only. Rejected because one registry exception would fork or overwrite the platform
  mapping.
- C (D36, frozen): exactly one scope per rule — platform default or exact registry; exact registry
  wins and unknown discovery creates only an exact-registry `unknown` rule.
- D: global spelling, JSON predicates or numeric priority. Rejected: ambiguous overlap, incomplete
  FK identity and an operator-programmable rule engine before a third scope exists.

The schema uses nullable `platform_id`/`registry_id`, an exactly-one check and two partial unique
indexes over `(scope_id, source_stage)`. Existing rows remain exact-registry rules. Rollback refuses
before mutation while platform-scope rows exist; it never deletes or guesses those rules.

## Frozen target contract

1. `Platform.code` is the runtime definition key; `Registry.code` is interpreted only below that
   platform. Missing platform, method, mapper, cursor or registry/filter capability fails closed
   with exact identity and never falls back to EIS/global behavior.
2. `PlatformAdapterRegistry` is the sole reader of `config('crawler.platforms')`.
   `SourceMethodFactory`, `CrawlerFilterFactory`, `Crawler`, generic listing processors and
   `CrawlerForm` consume typed resolved capabilities; legacy global maps/binds disappear only after
   all consumers migrate.
3. DB rows alone may represent inactive/reference/training sources. A crawlable platform means data
   identity plus a valid typed definition and the exact capabilities used by the selected profile.
4. Source-specific code remains under its producer ownership. EIS filter fields, preview URL,
   mapper, registry flags and seed literals do not move into provider-agnostic factories, models,
   forms, repositories or processors.
5. A source-method capability owns its implementation, raw-row mapper and cursor/page-size
   semantics. Two platforms using the same transport enum cannot share those implicitly.
6. `ProcurementStatus` remains the global canonical enum. Every raw-status rule has exactly one
   applicability: `platform_id` or `registry_id`, never both/neither.
7. Resolution order is exact registry → owning platform default → race-safe materialization of an
   exact-registry `unknown`. There is no global spelling match or numeric priority.
8. Existing `(registry_id, source_stage)` rows backfill without semantic change. Migration rollback
   succeeds only if no platform-scope rows exist and refuses before mutation otherwise.
9. Read/store repositories own lookup and unique-index-safe creation; the service evaluates
   precedence and returns typed status plus matched applicability. Repositories open no transaction.
10. Filament create/edit surfaces show human platform/registry/applicability labels. Rule identity
    is immutable on edit; canonical status is mutable. Filters and direct actions retain authz.
11. EIS raw labels and fz44 procedure semantics stay inside EIS configuration/seed/adapter code.
    Explicit EIS readiness checks remain named release consumers, never generic fallback behavior.
12. Contract tests use the P7.6 pattern: table-driven success/reason vectors plus architecture
    enumeration and explicit invalid/collision cases.

RAG:— (repo-grounded synthesis: terminal products, current schema/services/config/forms and
D35–D36).

## Item topology and exact consumer contract

| Item / consumer | Input dependency | Product and exact obligation | Downstream gate |
|:--|:--|:--|:--|
| P8.1 | terminal P0.5/P0.6/P7.6 + current repo | reconciled identity/capability/status/UI/test matrices; stable D35/D36 | no runtime/schema mutation before freeze |
| P8.2 | terminal P8.1 | typed platform registry; nested registry filter; platform-scoped method/mapper/cursor capability; migrated config, factories, generic model/processors/form; no EIS/global fallback | P8.3 may rely on exact platform+registry identity only after synthetic collision/missing-capability tests pass |
| P8.3 | terminal P8.2 | nullable two-scope schema, exactly-one check, partial uniqueness, existing-row backfill and lossless rollback refusal | P8.4 may switch resolver/UI only after migration reapply and refusal paths pass |
| P8.4 | terminal P8.3 | read/store repositories, exact-over-platform resolver, race-safe exact unknown, exact EIS seed and authorized Filament CRUD/filter surface | P8.5 tests the complete persistence/operator boundary |
| P8.5 | terminal P8.4 | synthetic same-registry-code and same-transport platforms, different mapper/page-size paths, full status matrix, architecture guard and developer guide | P9/P10/P12 consume a stable extension contract |
| P9 | terminal P8.5 where declared | Russian labels/navigation/responsiveness without semantic or authz drift | presentation cannot redefine applicability or adapter identity |
| P10 | terminal P8.5 where declared | audit for source literals in generic namespaces, DB FQCN/global fallback, race/rollback and spelling leakage | findings map to committed fixes, not narrative waivers |
| P12 | terminal P8.5 where declared | clean seed/bootstrap and named EIS capability/readiness proof | data-only platform rows never satisfy runnable readiness |

## P8.2 migration map

| Current consumer | Required target behavior | Required failure proof |
|:--|:--|:--|
| `config/crawler.php` | one exact platform definition graph | duplicate/malformed definition rejected during registry construction |
| `SourceMethodFactory` | resolve platform then method, including fallback within the same definition | unknown platform/method/fallback stops with exact identity |
| `CrawlerFilterFactory` | resolve owning platform then nested registry filter | two platforms with `registry.code=main` resolve different filters; missing mapping stops |
| `Crawler::filter/assertScope` | hydrate and describe scope through the resolved typed capability | no hard-coded EIS/OKPD2 message in provider-agnostic model |
| `AppServiceProvider` / `CsvBlockIngestor` | mapper comes from the selected source-method capability | two CSV platforms do not cross-use row mappers |
| `FreshRunProcessor` / `ArchiveWindowScanner` | cursor/page size comes from selected capability | different synthetic sizes advance and stop independently |
| `CrawlerForm` | fields/preview come from resolved capability | no EIS import/config read; missing preview/filter produces explicit unavailable state |

`CrawlerProfileSeeder` already demonstrates the correct source identity by resolving `eis` plus
`fz44`; `StatusMappingSeeder` must adopt that exact pair. EIS-specific mapper/builder/seed classes
remain valid inside EIS ownership.

## P8.3–P8.5 status proof matrix

| Proof | Expected observable result |
|:--|:--|
| Existing row migration | every old row becomes exact-registry with unchanged raw/canonical values |
| Exactly-one scope | both-null and both-set writes fail; one platform or one registry succeeds |
| Partial uniqueness | duplicate `(platform, stage)` and `(registry, stage)` fail independently |
| Precedence | exact registry beats owning platform default; another platform with same spelling is ignored |
| Unknown | missing value creates one exact-registry `unknown`; repeated/concurrent calls return that row |
| Rollback | succeeds with registry-only rows; refuses before mutation with any platform-scope row |
| Seeder | only the exact EIS/fz44 registry receives proven seed values |
| Filament | human scope labels, create/edit identity rules, filters and guest/roleless/authorized cases |
| Architecture | provider-agnostic factories/forms/repositories/processors contain no EIS/fz44 dispatch branch |

## Validation strategy

1. Reconcile: terminal upstream states/products, exact collision inventory and explicit stable D
   verdict.
2. Adapter identity: synthetic platforms with the same registry code and transport resolve
   different filter/method/mapper/cursor capabilities; all missing entries fail closed.
3. Migration: existing-row backfill, exactly-one check, partial uniqueness, clean rollback/reapply
   and refusal with platform-scope data.
4. Resolution: exact override, platform default, exact unknown creation, repeated/concurrent unknown,
   no cross-platform spelling leak and canonical enum rejection.
5. Operator: create both scope kinds, immutable identity on edit, dependent labels/selects, filters,
   duplicate errors and guest/roleless/authorized cases.
6. Architecture/docs: no provider-specific dispatch outside declared adapter/config/seed/readiness
   ownership; developer checklist distinguishes data-only from crawlable onboarding.

## Verification status

- Terminal P0.5/P0.6/P7.6 evidence: reconciled; all named states are green and products exist in
  the named commits.
- Repo extension/status carriers: inventoried through DB, runtime, config, form, seed and tests.
- Adapter capability/collision and status applicability/preference/backfill/UI matrices: frozen and
  routed to exact downstream owners.
- Decision chain: D35/D36 remain current; no superseding record is required.
- External claim or open owner question: none. A real second platform, a global raw-stage mapping
  and a generic predicate engine remain out of scope.
