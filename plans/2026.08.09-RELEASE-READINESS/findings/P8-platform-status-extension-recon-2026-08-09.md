# P8 repo recon — platform adapters and status applicability

## Scope

Read-only reconciliation of terminal P0.5/P0.6/P7.6 products with the current platform/registry
identity, crawler adapter lookup, listing normalization, status persistence, Filament surfaces and
tests. The inventory freezes the minimum executable capability boundary before P8.2 changes runtime
code and before P8.3 changes schema. A real second procurement platform remains out of scope.

RAG:— (repo-grounded: terminal item states and commits named below, current application tree,
D35–D36 and `research/10-P8-execution-design.md`).

## Terminal upstream reconciliation

| Upstream | Terminal evidence | Committed product inspected | P8 consequence |
|:--|:--|:--|:--|
| P0.5 | `phases/P0/P0.5.state.md` = `🟢 Done` | `f1cb83dd` adds `findings/P0-rag-domain-security.md`; `4c8ec806` closes the item | DOM-05 preserves procurement-document taxonomy above estimate formats. It does not introduce another platform runtime identity, status scope or fallback, so it does not contradict D35/D36. |
| P0.6 | `phases/P0/P0.6.state.md` = `🟢 Done` | `52fb69c6` freezes `root/contracts/release-readiness-v1.md`; `bf234d23` records the green downstream anchor | EXT-14 explicitly requires typed platform/status seams and configuration/domain-data mappings. This confirms D35/D36; no DB-stored FQCN or source-law switch is allowed. |
| P7.6 | `phases/P7/P7.6.state.md` = `🟢 Done` | `92723313` adds `ScreeningContractMatrixTest` and `ScreeningContractTest` with docs/operational consumers | The reusable lesson is a table-driven reason/outcome matrix plus an architecture test that enumerates the closed type set and rejects invalid combinations. P8 adopts that proof shape for adapter collisions and status applicability; P7 changes no platform/status identity. |

All upstream states are terminal and name existing committed products. No terminal product changes
the exact `Platform.code` runtime key, the nested `(platform_id, Registry.code)` identity, or the
two-scope precedence selected by D35/D36.

## Data identity and current schema

| Data carrier | Current invariant | Evidence | Collision or lifecycle consequence |
|:--|:--|:--|:--|
| `prc_platforms` | `Platform.code` is globally unique; a row may exist without crawl capability | `2026_07_04_100000_create_prc_platforms_table.php`, `Platform.php` | A data-only/reference/training platform is valid. Crawlability must be an explicit typed runtime capability, never inferred from row presence. |
| `prc_registries` | Registry identity is unique only as `(platform_id, code)` | `2026_07_04_100001_create_prc_registries_table.php`, `Registry.php` | Two platforms may both own `code=main` or `code=fz44`; any registry-code-only runtime/config lookup is ambiguous. |
| `prc_status_mappings` | Current natural key is `(registry_id, source_stage)` | `2026_07_04_100002_create_prc_status_mappings_table.php`, `StatusMapping.php` | Existing rows are exact-registry evidence and backfill losslessly to registry scope. They do not prove a platform-wide vocabulary. |

## Frozen adapter capability and collision matrix

The minimum is a typed definition/capability graph, not a universal platform interface. Each row
below has an observed producer, consumer and failure boundary; an interface or method name alone is
not accepted as capability evidence.

| Data identity | Runtime key | Minimum capability | Producer / owner | Config or DB carrier | Current consumer → P8 owner | Fail-closed behavior and collision proof |
|:--|:--|:--|:--|:--|:--|:--|
| `Platform.code` | exact platform code | select one `PlatformAdapterDefinition` | P8.2 `PlatformAdapterRegistry` | `crawler.platforms.<platform>`; no FQCN in DB | `SourceMethodFactory`, `CrawlerFilterFactory`, `CrawlerForm`, `Crawler` → P8.2 | Missing platform definition names the platform and stops. Synthetic platforms with the same nested registry code must resolve different capabilities. |
| `(platform_id, Registry.code)` | platform code + nested registry code | select the typed filter DTO and registry-owned behavior | platform definition, P8.2 | nested registry map under the platform definition | `CrawlerFilterFactory`, `Crawler::filter/assertScope`, `CrawlerForm` → P8.2 | Missing nested registry/filter mapping stops without trying a same-named registry from another platform. |
| platform + `SourceMethod` enum | exact platform + transport method | select the source-method implementation | platform definition, P8.2 | typed source-method capability in `crawler.platforms.<platform>` | `SourceMethodFactory` and run/detail processors → P8.2 | Missing method on a configured platform stops; fallback may use only another method declared by that same platform definition. No global CSV→EIS fallback. |
| platform + source method | selected method capability | normalize raw listing rows with the matching `ListingRowMapper` | selected source-method capability, P8.2 | typed class carrier beside the source-method class | current global `AppServiceProvider` bind and `CsvBlockIngestor` → P8.2 | A second CSV platform must not receive `EisCsvRowMapper`. Missing mapper stops before ingest; synthetic colliding CSV adapters prove isolation. |
| platform + source method | selected method capability | advance listing cursor with the producer's page/block size | selected source-method capability, P8.2 | typed scalar/cursor policy owned by the capability, not `crawler.eis.*` in generic processors | `FreshRunProcessor`, `ArchiveWindowScanner`, `CsvSourceMethod` → P8.2 | Generic processors cannot assume EIS block size. A synthetic capability with a different size proves cursor and short-page behavior. |
| filter DTO class + registry | exact resolved filter class | validate/hydrate provider filter and describe any mandatory scope | filter DTO/contracts, P8.2 | class and typed contracts in the nested registry capability | `Crawler::filter/assertScope` and `CrawlerForm` → P8.2 | Unknown filter fails closed. Scope failure text comes from the typed capability/contract rather than hard-coded OKPD2 wording in a provider-agnostic model. |
| selected platform/registry capability | exact resolved capability | render provider fields and preview without a generic source branch | P8.2 form consumer over the resolved definition | typed field/preview producer or existing class-dispatch owned by the resolved capability | `CrawlerForm` → P8.2 | Generic form code must not import `EisSearchFilter`/`EisCsvUrlBuilder` or read `crawler.eis.csv_endpoint`; unavailable preview is explicit and never borrows EIS. |

The row-mapper and pagination rows do not replace D35's identity choice. They are execution
consequences of selecting a source-method implementation: today raw `ListingBatch` rows are mapped
later by a global EIS bind and generic processors advance with `crawler.eis.block_size`. Leaving
either global would violate D35's exact-platform fail-closed rule even if both factories were
migrated. The target still has one platform definition, no DB class registry, no generic predicate
engine and no speculative all-purpose adapter interface.

## Exact hard-coded branch and consumer inventory

| Surface | Current source-specific branch | Classification | Required owner/action |
|:--|:--|:--|:--|
| `config/crawler.php` | global `source_methods[csv] = CsvSourceMethod` and `filters[fz44] = EisSearchFilter` | identity collision | P8.2 replaces both maps with exact platform definitions. |
| `SourceMethodFactory` | resolves only by transport enum | identity collision | P8.2 resolves platform first, then method; fallback remains inside the same definition. |
| `CrawlerFilterFactory` | resolves only by `Registry.code` | identity collision | P8.2 loads owning platform and resolves nested registry capability. |
| `Crawler::filter/assertScope` | consumes the old factory and hard-codes the OKPD2 failure copy | provider leak in generic model | P8.2 consumes the exact capability and moves provider wording to its typed producer. |
| `CrawlerForm` | imports `EisSearchFilter`/`EisCsvUrlBuilder`, matches EIS DTO, reads EIS endpoint | provider leak in generic UI | P8.2 makes the form consume only the exact platform/registry capability. |
| `AppServiceProvider` + `CsvBlockIngestor` | global `ListingRowMapper → EisCsvRowMapper` bind | cross-platform execution collision | P8.2 resolves the mapper from the selected source-method capability. |
| `FreshRunProcessor` / `ArchiveWindowScanner` | read `crawler.eis.block_size` in generic loops | provider leak in cursor semantics | P8.2 gets cursor advance/short-page semantics from the selected capability. |
| `StatusMappingService` | direct registry-only `firstOrCreate` | persistence and precedence owner missing | P8.4 read/store repositories own exact/platform lookup and race-safe exact unknown creation; service owns precedence. |
| `StatusMappingSeeder` | `Registry::where('code', 'fz44')` across every platform | cross-platform seed collision | P8.4 resolves exact EIS platform+registry and keeps seeded rows exact-registry. |
| `StatusMappingForm` / `StatusMappingsTable` | numeric registry ID, no platform/applicability view, unknown-only filter | operator ambiguity | P8.4 adds human platform/registry labels, scope selectors/filters and immutable identity on edit. |
| `PlatformOnboardingTest` | claims data-only rows prove platform onboarding | false capability claim | P8.5 renames/splits data-only proof and adds a synthetic runnable adapter with colliding registry code. |
| status mapping tests | registry-only lookup/repeat/seeder paths | incomplete target proof | P8.3/P8.4/P8.5 add schema, precedence, race, rollback and cross-platform leakage vectors. |
| `CrawlerProfileSeeder` | explicitly resolves `eis` + `fz44` | correct provider-owned selector | Keep exact pair; it is positive collision evidence and a template for `StatusMappingSeeder`. |
| `EisCsvRowMapper`, `EisCsvUrlBuilder`, `CsvSourceMethod`, `EisPlatformSeeder` | EIS/fz44 literals inside EIS producer/seed ownership | allowed provider specialization | Keep inside the resolved EIS capability; do not move literals into generic factories/forms/repositories. |
| readiness/health EIS selectors | explicit operational proof for the one deployed source | allowed named release consumer | P12 verifies the named EIS capability through the registry; it must not become a global fallback. |

Test fixture literals and named EIS acceptance tests are evidence, not production dispatch branches.
They remain valid when their setup explicitly creates the owning EIS platform.

## Frozen status applicability, precedence, backfill and UI matrix

| Alternative | Schema / identity | Resolution and unknown policy | Backfill / rollback | UI and tests | Verdict |
|:--|:--|:--|:--|:--|:--|
| Current registry-only | non-null `registry_id`; unique `(registry_id, source_stage)` | exact registry only; unknown created exact registry | no migration; safe but duplicates shared vocabularies | current numeric-ID edit and registry-only tests | Preserve as the lossless backfill source, reject as final target because it cannot express a platform default. |
| Platform-only | non-null `platform_id`; unique `(platform_id, source_stage)` | platform rule only | would need to guess whether existing rows are common | cannot express registry exception | Rejected: one exception would fork or overwrite the platform vocabulary. |
| D36 two-scope | exactly one of nullable `platform_id` or `registry_id`; partial unique indexes per scope | exact registry override → owning platform default → create exact-registry `unknown`; no spelling-global lookup | every existing row remains registry scope; rollback succeeds only with no platform-scope rows and refuses before mutation otherwise | human platform/registry/scope labels; identity immutable on edit; scope/status filters; precedence, duplicate, race and rollback tests | Frozen target. |
| Global/predicate/priority rules | nullable/global identity, JSON predicate or numeric priority | overlap/priority decides | ambiguous and not FK-complete | operator-programmable rule engine | Rejected until a third proven applicability requires a superseding D36. |

Additional frozen rules:

- `ProcurementStatus` remains the only canonical status enum.
- A platform default never crosses to another platform, even when raw spelling is identical.
- Unknown discovery always materializes an exact-registry row so an operator sees the precise source.
- `fz44` seed rows stay exact-registry; current evidence does not prove them valid for every EIS
  registry.
- Repository creation must be unique-index/race safe; the service does not own persistence and a
  repository does not open a transaction.

## Consumer map

| Consumer | Exact frozen input from P8.1 |
|:--|:--|
| P8.2 | Exact platform definition; nested registry filter; platform-scoped source method, mapper and cursor policy; generic factory/model/form consumers contain no EIS fallback. |
| P8.3 | Exactly-one applicability schema, two partial unique keys, lossless registry backfill and pre-mutation rollback refusal with platform rows. |
| P8.4 | Repository-owned lookup/store, exact-over-platform precedence, race-safe exact unknown creation, exact EIS seed identity and authorized human Filament CRUD/filter surface. |
| P8.5 | Synthetic same-registry-code platforms, different CSV mapper/page-size capabilities, missing-capability failures, full status matrix and architecture guard for provider-specific branches. |
| P9 | Presentation may improve labels/navigation/responsiveness but must not redefine adapter identity, status precedence or authorization. |
| P10 | Audit generic namespaces for source literals, DB FQCNs, global fallbacks, race/rollback gaps and cross-platform spelling leaks. |
| P12 | Clean bootstrap seeds EIS data and proves the named EIS runtime/status capability; release readiness never treats a data row as runnable capability. |

## Decision stability

- **D35: byte-stable.** The reconcile confirms exact `Platform.code`, nested registry identity,
  typed config ownership, no DB FQCN and fail-closed resolution. Row mapping/cursor semantics are
  required sub-capabilities of the selected method, not a competing identity or fallback choice.
- **D36: byte-stable.** No upstream or current evidence supports a third scope, a global rule, a
  different precedence or a lossy backfill.
- **Superseding decision:** not created; no evidence-backed contradiction exists.

## Verification status

- Terminal prerequisites: complete and committed (`P0.5`, `P0.6`, `P7.6` states are green).
- Identity/capability/collision matrix: complete for the declared runtime, config, DB, UI and test
  seams; producer, consumer and failure proof are named for each minimum capability.
- Status applicability/preference/backfill/UI matrix: complete; D36 remains the frozen target.
- External claims: none added. All conclusions are repository-grounded (`RAG:—`).
- Open owner question: none. A real second platform, global raw-stage vocabulary and generic
  predicate engine remain explicitly out of scope.
