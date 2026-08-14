# P11 risk test matrix

## Contract

This is the complete D19–D42 inventory on tree `48aa8f58`. `Covered` means a current deterministic
carrier exists; `Partial` means useful evidence exists but an explicit risk edge is still missing;
`Gap` means the decision's final P11 carrier does not exist yet. Terminal upstream state is evidence
of implementation, not a substitute for the named test. P11.4 must leave zero Critical/High gaps;
P11.5 owns CI/workflow execution and P11.6 owns final budgets/flake evidence.

| D | Risk / invariant | Severity | Current evidence on terminal tree | Status | Required closure owner |
|:--|:--|:--|:--|:--|:--|
| D19 | Private package identity, immutable SHA and allowed subpaths; no dirty/floating/credential origin | Critical | `docker/tests/vendor-contract.sh`, `docker/private-vendors.lock.json`, terminal P2.6 | Covered | P11.5 must run the carrier in trusted private-package job |
| D20 | Secrets never enter env/rendered config; create/rotation and per-service grants stay bounded | Critical | `docker/tests/secrets-contract.sh`, `docker/tests/p2-release-contract.sh`, terminal P2.6 | Covered | P11.5 CI negative secret/config checks; P12 host permissions |
| D21 | Doctor uses same-source companion image, JSON/agent output and no production `--fix` | High | `docker/tests/doctor-contract.sh`, `ToolsDoctorTest`, Doctor/readiness Feature tests | Covered | P11.5 invokes existing carrier; P12 proves published image pair |
| D22 | Livewire dynamic hashed routes and Filament assets remain exact, recoverable and non-bypassable | High | `docker/tests/assets-contract.sh`, `assets-idempotency-recovery.sh`, `livewire-route-repro.sh` | Covered | Reuse in P11.5 quality/build and D24 browser job |
| D23 | `/up` is light liveness; typed active readiness is separate, redacted and deterministic | Critical | `docker/tests/readiness-contract.sh`, `ApplicationReadinessTest`, Doctor diagnostics | Covered | P11.5 required readiness job; P12 live rehearsal |
| D24 | One isolated Playwright/Chromium acceptance carrier; ignored auth state and clean lifecycle | High | `e2e/{admin-livewire,admin-navigation-responsive,private-documentation}.spec.ts`, terminal P3.5/P9.6 | Covered | P11.5 runs exact existing Playwright carrier; no second browser stack |
| D25 | Compose project/resources/ports are unique; cleanup cannot touch owner dev/prod | Critical | `docker/tests/compose-contract.sh`, `clean-install-e2e.sh`, terminal P3.5 | Covered | P11.5 isolated project job; P12 owner-safe rehearsal |
| D26 | Private docs artifact is deterministic, immutable-image-owned and absent from public/writable roots | High | `documentation-build-contract.sh`, private-doc acceptance/response contracts | Covered | P11.5 immutable build job |
| D27 | Every docs byte is authorized and path-contained; no public alternate path; secure response headers | Critical | `PrivateDocumentationAccessTest`, `PrivateDocumentationResponseTest`, locator Unit test, D24 E2E | Covered | P11.4 retain auth/traversal/MIME/header corpus; P11.5 browser job |
| D28 | Proxy default stores only typed proxy ID; refresh/freshness is cross-process safe | High | `p5.2-settings-contract.sh`, `CrawlerProxySettings{,Storage}Test` | Covered | P11.4 map cache/freshness regression explicitly |
| D29 | Typed profile/default/direct precedence, exact scope and fail-closed outage; no health conflation | Critical | `p5.3-proxy-resolver-contract.sh`, `ProxyResolverTest`, binding/health concurrency Features | Covered | P11.4 keep precedence + outage + concurrency rows explicit |
| D30 | Exactly one format handler by extension/MIME/probe; unknown/mismatch fail closed, no catch-all | Critical | `DocumentFormatRegistryTest`, `DocumentFormatContractTest`, terminal P6.6 | Covered | P11.4 retain adversarial format families and registry completeness |
| D31 | Hostile archives are bounded and path-safe; child identity is race-safe and dispatched after commit | Critical | `ArchiveEntryValidatorTest`, `ArchiveStrategyTest`, `ArchivePipelineTest` concurrency cases | Covered | P11.4 name resource/path/collision/after-commit risks separately |
| D32 | Semantic type is one closed enum across DB/schema/prompt/eval/UI; Unknown differs from pre-screen NULL | High | `ScreeningContractMatrixTest`, architecture screening contract, prompt/gold/eval Features | Covered | P11.4 enumerate all eight consumer axes |
| D33 | Screening v2 bounds/normalizes fields and enforces type/cardinality rules; backend disposition is excluded | Critical | `ScreeningResultTest`, `ScreeningContractMatrixTest`, `ScreeningGoldTest`, `ScreeningEvalTest` | Covered | P11.4 retain invalid combination and bound cases |
| D34 | Versioned screening envelope has one source; decision/status/event/dispatch are atomic and after-commit | Critical | schema migration, branching/processor and stored-result Feature/Unit tests | Partial | P11.4 add/identify atomic rollback + after-commit negative proof |
| D35 | Platform registry is the only runtime extension reader and fails closed on missing capability | High | platform registry Unit, platform extension architecture/Feature contracts, onboarding Feature | Covered | P11.4 inventory every factory/form consumer |
| D36 | Status applicability is exactly platform default or registry override with deterministic precedence/races | Critical | applicability migration, status mapping, seeder and Filament resource Features; shell contract | Covered | P11.4 retain race-safe unknown and rollback-loss refusal |
| D37 | Historical navigation decision is superseded by D82 and must not be tested as current policy | Medium | decision metadata `superseded_by: D82`; `LayerNavigationTest` and label tests cover current D82 | Covered | P11.4 points navigation row to D82, never resurrects D37 |
| D38 | Filament labels/booleans/tables/schema stay native and do not couple provider-agnostic enums | High | enum presentation, schema/table contracts, layer navigation and architecture tests | Covered | P11.4 explicitly include Llm boundary and technical-ID policy |
| D39 | Security/architecture/production gates are deterministic; critical/high defects and red gates cannot defer | Critical | P10 contract scripts + quality/remediation carriers; terminal P10.6 | Covered | P11.5 calls P10 aggregate unchanged; P11.4 consumes matrix dispositions |
| D40 | Local TIA is never acceptance; process/shard mode needs repeated exact name/status parity and cost threshold | High | P11.1 five-run/TIA/shard evidence; parallel is deterministically red | Partial | P11.2 corrects TIA config, repeats provisional 2-shard mode and repairs/rejects parallel |
| D41 | Testing permits only exact serial/token DB and process roots; sabotage fails before mutation; PAO markers propagate | Critical | suffix guard Feature exists, but exact token/filesystem sabotage and Codex marker contract do not | Gap | P11.3 owns exact DB/disk/log/temp guards, sabotage corpus and PAO marker tests |
| D42 | CI is least-privilege/SHA-pinned, full no-TIA, cache-safe, risk/coverage/performance/flake gated | Critical | policy and P11.1 baseline exist; no tracked workflow yet | Gap | P11.4 closes risk rows; P11.5 creates/proves workflows; P11.6 seals floors/budgets |

## Baseline counts

- 24 decision rows are present exactly once (D19 through D42).
- Critical/High gaps at P11.1 close: D41 and D42. Partial rows: D34 and D40.
- The green 1,569-test suite and 88.27% statement coverage do not waive any Gap/Partial row.
- D37 is intentionally represented even though superseded; its evidence points to current D82 so the
  historical policy cannot silently re-enter acceptance.
