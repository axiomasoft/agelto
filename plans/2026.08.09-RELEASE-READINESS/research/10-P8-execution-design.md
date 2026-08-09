# P8 execution design — exact adapter identity and status applicability

## Sources

- `findings/P8-platform-status-extension-recon-2026-08-09.md` — current DB/runtime/UI/test seams.
- `research/00-user-intent.md` — owner trace for easy platform additions, flexible status mappings
  and architecture/contract tests.
- terminal P0.5/P0.6 products — future external extension-pattern evidence and frozen cross-phase
  contract; P8.1 must reconcile them before mutation.
- terminal P7.6 product — future typed schema lessons and final upstream extension-test pattern.

P8 does not implement a real new procurement source. It makes the existing boundary honest: data
rows register identity, while a typed code descriptor registers crawl capability. RAG:—
(repo-grounded: `findings/P8-platform-status-extension-recon-2026-08-09.md`).

## Alternatives and decisions

### Runtime platform extension carrier

- A: keep global `source_methods` and registry-code-only `filters`. Rejected: the lookup identity is
  weaker than DB identity and a second CSV platform collides or reuses EIS behavior accidentally.
- B: store handler FQCNs in `prc_platforms`/`prc_registries`. Rejected: deployable code identity
  becomes mutable DB data, breaks config cache/static analysis and creates unsafe class injection.
- C (D35): one typed `PlatformAdapterDefinition` per `Platform.code`; it owns supported source-method
  classes and nested registry filter classes. `PlatformAdapterRegistry` resolves by exact platform
  identity and fails closed. Registry-specific behavior still receives `Registry`, while EIS code
  stays in `Services/Procurement/Eis/**`.

The descriptor is deliberately a typed class-map, not a universal adapter interface with guessed
listing/detail/status methods. A new capability is added only when a second implementation proves a
shared contract. A synthetic test adapter proves the current minimum without shipping a fake source.

### Status applicability

- A: exact registry only. Safe but duplicates platform-wide vocabularies and cannot express the
  requested flexible applicability.
- B: platform only. Simpler, but one registry-specific exception would fork the whole mapping.
- C (D36): exactly one scope per rule — platform default or exact registry; exact registry wins.
  Unknown discovery always creates an exact-registry `unknown` rule.
- D: JSON predicates plus numeric priority. Rejected: no FK-enforced identity, ambiguous overlap and
  an operator-programmable rule engine before a third scope exists.

The schema uses nullable `platform_id`/`registry_id` with an exactly-one check and two partial unique
indexes over `(scope_id, source_stage)`. Existing rows remain exact-registry rules. No global rule is
introduced, and fz44 seed rows are not promoted to an EIS-wide default without terminal evidence.

## Frozen target contract

1. `Platform.code` is the runtime descriptor key; `Registry.code` is interpreted only below that
   platform. A missing descriptor, method or filter mapping fails closed with platform+registry in
   the diagnostic and never falls back to EIS/global behavior.
2. `PlatformAdapterRegistry` is the sole reader of `config('crawler.platforms')`.
   `SourceMethodFactory`, `CrawlerFilterFactory` and `CrawlerForm` consume it; legacy parallel maps
   are removed after all call sites migrate.
3. DB rows alone may represent an inactive/reference/training source, but «crawlable platform» means
   data identity plus a valid typed descriptor. The developer guide states both levels explicitly.
4. `ProcurementStatus` remains the global canonical enum. A status mapping rule has exactly one
   applicability: `platform_id` or `registry_id`, never both/neither.
5. Resolution order is exact registry → owning platform default → materialize exact-registry
   `unknown`. There is no global spelling match and no numeric priority.
6. Existing `(registry_id, source_stage)` rows backfill without semantic change. Migration rollback
   is data-preserving: it succeeds when no platform-scope rows exist and refuses before mutation when
   they do; it never deletes or guesses those rules.
7. Read/store repositories own lookup and race-safe creation; the service evaluates precedence and
   returns typed status plus matched applicability. No transaction is opened in a repository.
8. Filament create/edit surfaces show human platform/registry labels. Rule identity is immutable on
   edit; canonical status is mutable. Platform/registry/applicability/status/unknown filters and
   authorization are covered without broadening existing permissions.
9. EIS raw labels and fz44-specific procedure semantics stay in EIS registration/seed/adapter code.
   Provider-agnostic factories, forms and repositories contain no `eis|fz44|fz223` branches.

RAG:— (repo-grounded synthesis: current schema/services/config/forms and D35–D36).

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P8.1 | terminal P0.5/P0.6/P7.6 + current repo | reconciled seam/collision/applicability matrix and stable D35/D36 or superseding chain | no runtime/schema mutation before freeze |
| P8.2 | terminal P8.1 | typed platform descriptor/registry and migrated factory/form consumers | P8.3 may rely on exact platform+registry identity |
| P8.3 | terminal P8.2 | applicability schema/model/backfill/index/check contract | P8.4 may switch resolver and UI atomically |
| P8.4 | terminal P8.3 | repositories, precedence resolver, seed path and authorized Filament CRUD/filter surface | P8.5 tests the complete boundary |
| P8.5 | terminal P8.4 | synthetic second-platform, migration/concurrency/architecture tests and developer guide | P9/P10/P12 consume a stable extension contract |

All items are sequential and share config/factory/model/status/UI/test/doc hotspots. The N≥3
independent criterion is false; there is no long external-state chain. Orchestration is not
declared. RAG:— (repo-grounded: dependency matrix above).

## Validation strategy

1. Reconcile: terminal P0.5/P0.6/P7.6 evidence, exact collision inventory and explicit D chain.
2. Adapter identity: synthetic platforms with the same registry code resolve different filters and
   source methods; missing entries fail closed; Filament and runtime consume one registry.
3. Migration: existing-row backfill, exactly-one check, partial uniqueness, clean rollback/reapply
   and refusal with platform-scope data.
4. Resolution: exact override, platform default, exact unknown creation, repeated/concurrent unknown,
   no cross-platform spelling leak and canonical enum rejection.
5. Operator: create platform/registry rules, immutable identity on edit, dependent selects, filters,
   duplicate errors and guest/roleless/authorized cases.
6. Architecture/docs: no provider-specific branches outside declared adapter/config/seed namespace;
   developer checklist adds a synthetic adapter test and names data-only versus crawlable onboarding.

## Verification status

- Repo extension/status carriers: complete for design.
- External extension patterns and final P7 typed-contract lessons: terminal P0.5/P0.6/P7.6 are hard
  inputs to P8.1; no external claim is inferred in this authoring pass.
- Open owner question: none. A real second platform and a global raw-stage mapping are explicitly out
  of scope.

