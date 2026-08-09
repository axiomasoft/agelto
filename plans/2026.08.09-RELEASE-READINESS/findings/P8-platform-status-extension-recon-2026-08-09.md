# P8 repo recon — platform adapters and status applicability

## Scope

Read-only inventory of the current platform/registry identity, crawler adapter lookup, status
mapping persistence and Filament surfaces. This is repo evidence for P8 authoring; external
extensibility guidance remains an execution input of terminal P0.5/P0.6.

## Platform identity and runtime extension seams

| Surface | Current carrier | Finding |
|:--|:--|:--|
| Platform identity | `Platform.code` unique in `prc_platforms` | Stable data identity; a row can exist without runtime crawl capability. |
| Registry identity | unique `(platform_id, code)` in `prc_registries` | `Registry.code` is intentionally not globally unique. |
| Source method lookup | `SourceMethodFactory` → `config('crawler.source_methods.<enum>')` | Lookup is global by transport enum, while the only `CsvSourceMethod` implementation is EIS-specific. |
| Typed filter lookup | `CrawlerFilterFactory` → `config('crawler.filters.<registry code>')` | The runtime key is weaker than the DB identity and can collide across platforms. |
| Filament filter form | `CrawlerForm` repeats resolution/rendering rules around the same filter class | A second consumer must stay synchronized with the factory/config map. |
| EIS behavior | `Services/Procurement/Eis/**`, `EisCsvRowMapper`, `EisCsvUrlBuilder`, `EisPlatformSeeder` | EIS-specific parsing and URL behavior is already mostly namespaced, but registration is spread across global maps and seeders. |
| Existing onboarding proof | `tests/Feature/Procurement/PlatformOnboardingTest.php` | Proves only that platform/registry/status rows can be inserted; it never resolves a source method/filter or runs a synthetic adapter. |

The current `config('crawler.filters')` key cannot represent two platforms that both have a
registry named `main`, although the schema permits exactly that. A missing filter mapping fails
closed, which is correct; the identity used for lookup is the defect. RAG:— (repo-grounded:
`database/migrations/2026_07_04_100001_create_prc_registries_table.php`,
`app/Services/Procurement/CrawlerFilterFactory.php`, `config/crawler.php`).

`SourceMethod::Csv` describes transport, but `CsvSourceMethod` composes EIS URL building, row
mapping and detail collection. A second CSV platform therefore cannot be made crawlable by adding
only DB rows despite the existing test name. RAG:— (repo-grounded:
`app/Services/Procurement/SourceMethodFactory.php`, `app/Services/Procurement/Eis/CsvSourceMethod.php`,
`tests/Feature/Procurement/PlatformOnboardingTest.php`).

## Status mapping persistence and UI

| Surface | Current carrier | Finding |
|:--|:--|:--|
| Natural key | unique `(registry_id, source_stage)` | Every rule is exact-registry; sharing an identical vocabulary across registries requires duplicate rows. |
| Resolve | `StatusMappingService::resolve()` uses `firstOrCreate` | Unknown raw stages are safely materialized as `unknown`, but persistence is performed directly by the service. |
| Bootstrap data | `StatusMappingSeeder` selects every registry with `code=fz44` | Seeder is coupled to one registry code and cannot distinguish two platforms with the same code. |
| Canonical value | `ProcurementStatus` backed enum | Global canonical status set is already the correct SSOT and should not be copied into per-platform enums. |
| Filament edit | disabled numeric `registry_id`/`source_stage`, editable canonical status | Operator cannot see platform/applicability clearly, create a platform default, or filter by platform/scope. |
| List filters | only `canonical_status=unknown` | Missing platform, registry, applicability and canonical-status filters explain the owner's reported UX concern. |

The raw stage is source vocabulary and must never be normalized across platforms by spelling alone.
The safe reusable level is therefore a platform default with an exact registry override, not a
global rule. Existing rows are evidence only for exact registry applicability; no repo evidence
proves that the five EIS/fz44 labels are valid for every future EIS registry. RAG:— (repo-grounded:
`database/seeders/StatusMappingSeeder.php`, archived CRAWL-HARDEN stage evidence and
`app/Services/Procurement/StatusMappingService.php`).

## Hard-coded and collision surfaces to close

1. `config('crawler.filters')` keyed by registry code alone.
2. `config('crawler.source_methods')` keyed by method alone while implementations may be platform-specific.
3. `StatusMappingSeeder` querying `Registry::where('code', 'fz44')` without platform identity.
4. `EisCsvRowMapper::FZ44_REGISTRY_CODE` for procedure-type applicability.
5. `CrawlerForm` resolving filter fields separately from the runtime registry.
6. `PlatformOnboardingTest` claiming data-only onboarding without proving a crawlable capability.

The target is not zero source-specific literals: EIS literals belong inside the EIS adapter,
descriptor/config entry or EIS seed data. The forbidden outcome is a source-specific branch in
provider-agnostic factories, forms, repositories or status resolution.

## Test gaps

- No synthetic second platform with a colliding registry code proves exact source identity.
- No contract test checks one registration drives both runtime factory and Filament filter schema.
- No platform-default versus exact-registry precedence test exists.
- No concurrency test proves two unknown resolutions create one exact-registry row.
- No migration test covers existing-row backfill, partial uniqueness, exactly-one-scope check or
  data-preserving rollback refusal.
- Filament tests do not cover create/applicability selectors, platform/registry filters, duplicate
  prevention or unauthorized mutation.

## Verification status

- Repo carriers and current tests: complete for authoring.
- External platform-extension patterns: intentionally deferred to terminal P0.5/P0.6; P8.1 is the
  hard reconcile gate before changing factories or schema.
- A real second procurement platform: absent; P8 proves extension with a synthetic test adapter and
  does not ship a speculative production integration.
- Open owner question: none. The brief asks for extensibility and flexible applicability, not a real
  new source or a global raw-status vocabulary.

