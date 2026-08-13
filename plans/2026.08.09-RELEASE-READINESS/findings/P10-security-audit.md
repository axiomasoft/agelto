# P10.2 — manual Laravel security audit

Product tree: `469acf640fc95944a3accc378445190dbca2a9ba` (P10.1 immutable baseline).
Audit date: 2026-08-13, repeat pass under D85. This is a read-only report: it neither changes
product bytes nor reclassifies a grep match as a defect without a traced source, sink,
authorization and reachability.

## Checkpoint → repeat delta

`e4cb6a89` is only the checkpoint. The repeat pass rebuilt the application, route and installed
private-package inventories on the same P10.1 baseline: 700 `app` PHP files, 2 route files, 11
Blade views, 29 config/bootstrap PHP files, 161 AzGuard package PHP files and 97 registered routes
(all counts on `469acf640fc95944a3accc378445190dbca2a9ba`; commands are recorded below). `P10.2-S01`
and `P10.2-S02` were independently revalidated with unchanged evidence and retain their IDs,
severity and disposition. The fresh process/secret review adds `P10.2-S03`; no prior
`verified-safe` disposition was downgraded without new source-to-sink evidence.

## Coverage manifest

| Boundary | Coverage and manual evidence | Disposition |
|:--|:--|:--|
| HTTP entrypoints | `routes/web.php` has the public welcome route and one GET/HEAD documentation route. The latter has `auth`, and `PrivateDocumentationController` repeats an enum-aware `DocumentationPermission::View` check before locating or returning bytes. `PrivateDocumentationAccessTest` covers guest, roleless, revoked and direct-grant paths. | verified-safe |
| Filament / direct URLs | Resource and custom-page access is supplied by AzGuard; `CrawlerProxySettingsPage` checks `ProxyPermission::Update` both in `canAccess()` and the Action. Direct setting mutation is re-authorized in `UpdateCrawlerProxySettingsAction`. | verified-safe |
| Private documentation files | The locator accepts only sorted manifest entries, closed MIME types, a restricted path grammar and a `realpath` containment check. Responses receive `private, no-store`, `nosniff`, CORP and HTML CSP. `PrivateDocumentationResponseTest` exercises assets, 404 and active-SVG rejection. The manifest's SHA-256 field is syntax-checked but not recomputed at serve time; the artifact is immutable and outside user write access, so this is not an HTTP-reachable integrity finding. | verified-safe |
| Query / mass-assignment | Raw-query occurrences are static aggregate, PostgreSQL JSON, fixed `CASE`, or bound-value expressions. User-controlled filter keys and values are passed as bindings in `FixturesTable`; email lookups bind normalized input. Models use explicit `$fillable`; `forceFill()` is limited to internal lease/error-state traits. | verified-safe |
| Rendering / redirects / CSRF | No `{!!`, `@js`, `Js::from`, `v-html`, CSRF exceptions, request-derived redirects, signed routes, deserialization or executable shell calls were found under `app/`, `routes/`, `resources/views/`, `config/` and `bootstrap/`. The commented Sentry sample `exec()` is not executed. | verified-safe |
| Archive / document storage | `ArchiveEntryValidator` rejects traversal, special/encrypted/colliding entries and enforces count/size/ratio limits; `ArchiveExtractor` additionally bounds actual bytes. Child paths are normalized before `ExpandArchiveEntryAction` persists them. | verified-safe |
| TLS and HTTP egress | All three crawler HTTP callers combine `ProxyResolver` leases with `SourceTlsTrust`; the repository guard finds no `verify => false`. LLM egress has the separate finding below. | finding P10.2-S01 |
| Secrets and output | `SecretValue` prefers readable `*_FILE` values, proxy settings labels omit credentials, and the inspected warning logs only host/source metadata. No secret value reaches an HTTP or log sink in the reviewed paths. | verified-safe |
| Fixture upload | An authenticated Operator can create a fixture; the byte stream is stored on a private disk but has no explicit allowed MIME/content signature/size admission rule before persistence. | finding P10.2-S02 |
| MinIO lifecycle credentials | `StorageLifecycleCommand` reaches `MinioLifecycleManager::apply()`, which passes `filesystems.disks.s3.key` and `.secret` to `mc alias set` as child-process arguments. The command is operator-reachable and its credential values can be exposed through the process argument surface. | finding P10.2-S03 |
| Private package integration | `composer.json` uses only the two local AzGuard paths; the P10.1 locked dependency audit reports zero advisories/abandoned packages. Authorization use sites are enum/panel scoped. | verified-safe |

## Deterministic sharp-edge inventory

The repeat pass ran the following source inventory on `469acf640fc95944a3accc378445190dbca2a9ba`:

```bash
rg --files app -g '*.php'; rg --files routes -g '*.php'; rg --files resources/views
rg -n --glob '*.php' 'DB::raw|whereRaw|selectRaw|orderByRaw|DB::statement|DB::select|DB::table' app
rg -n --glob '*.php' '\$guarded\s*=\s*\[\]|->(create|update|fill|forceFill|createMany|sync)\(' app
rg -n --glob '*.{php,blade.php,js,vue}' '\{!!|@js\(|Js::from\(|v-html|unserialize\(|VerifyCsrfToken|redirect\(\$request|redirect\(\)->to\(\$' app routes resources/views config bootstrap
rg -n --glob '*.php' "'verify'\s*=>\s*false|\"verify\"\s*=>\s*false" app config
rg -n --glob '*.php' 'Process::|\bexec\(|\bshell_exec\(|\bsystem\(' app config
```

The searches yielded 49 raw-SQL, 120 write/mass-assignment, 18 process-execution, 23 archive,
61 log and 124 authorization candidates; each was traced to a fixed expression/internal writer,
validated archive boundary, scoped authorization path, or a finding in this ledger. The rendering,
request-redirect, CSRF-exception, unserialization and TLS-disable searches produced zero hits.
Counts are discovery inputs, never severity verdicts. Representative manual dispositions are
preserved in the coverage and false-positive ledgers; all security-relevant candidates are
`verified-safe` or a numbered `finding`.

## Finding ledger

### P10.2-S01 — LLM env-proxy bypasses typed resolver policy

| Field | Evidence |
|:--|:--|
| Category | outbound proxy / policy bypass |
| Severity / confidence | high / high |
| Location | `app/Services/Procurement/Proxy/PoolOutboundProxy.php:25-40` |
| Input → sink chain | Operator-controlled `AI_GEMINI_PROXY` → `config('ai.gemini.proxy')` → `PoolOutboundProxy::optionsFor()` fallback after `NoProxyAvailableException` → Guzzle `proxy` option used by `PrismGeminiProvider` for Gemini generation, Files API and cache CRUD. |
| Reachability / authorization | Any configured Gemini request uses the bound `OutboundProxy` port. No user request is needed after the env value is configured; the path is intentionally asserted by `GeminiProxyRoutingTest`. |
| Broken invariant | `.ai/guidelines/architecture.md` requires outbound proxies to be obtained only through `ProxyResolver`. D29 requires typed, fail-closed, observable proxy routing and separates global/per-target health. The fallback has neither country metadata nor resolver health/breaker/lease reporting. |
| Reproduction | `rg -n "AI_GEMINI_PROXY|env-фолбэк|return \['proxy' => \$envProxy\]" config/ai.php app/Services/Procurement/Proxy/PoolOutboundProxy.php tests/Feature/Llm/GeminiProxyRoutingTest.php` shows the complete chain and the currently passing test that permits it. |
| Public behavior at risk | A configured fallback can send LLM data through an unclassified, unhealth-checked proxy while the resolver correctly rejects the managed pool (including the LLM country policy). The warning is not a policy enforcement or request outcome record. |
| Disposition | escalated — release blocker; frozen D29/architecture contradiction must be resolved explicitly, not silently reinterpreted. |
| Owner / regression seam | P10.5; replace the fallback with a typed resolver-supported carrier or a new decision that explicitly supersedes D29 and restores fail-closed country/health/observability tests in `tests/Feature/Llm/GeminiProxyRoutingTest.php`. |

### P10.2-S02 — fixture upload lacks explicit content-admission boundary

| Field | Evidence |
|:--|:--|
| Category | file upload / resource and content admission |
| Severity / confidence | medium / high |
| Location | `app/Filament/Resources/Procurement/Ai/Fixtures/Schemas/FixtureForm.php:37-43`; `app/Services/Procurement/Fixture/FixtureUploader.php:38-77` |
| Input → sink chain | Operator `TemporaryUploadedFile` → client extension/MIME and unbounded stream accepted by `FileUpload` → private fixture disk write → later fixture/document processing and possible LLM file upload. |
| Reachability / authorization | `FixtureUploadTest` proves the Operator role can create a fixture. The artifact remains private, so this is not a public file-serving or code-execution vulnerability. |
| Missing invariant | No form or service rule limits bytes, checks an allowlist of supported extensions, or verifies detected content against the claimed extension before the persistent write. D30's typed format registry is the closest project admission policy, but this upload path does not invoke it. |
| Reproduction | `rg -n "FileUpload::make\('source'\)|acceptedFileTypes|maxSize|TemporaryUploadedFile|sourceFormat" app/Filament/Resources/Procurement/Ai/Fixtures app/Services/Procurement/Fixture tests/Feature/Ai/FixtureUploadTest.php` returns no `acceptedFileTypes`/`maxSize` guard and shows test fixture content is accepted under an `.xlsx` name. |
| Public behavior at risk | A permitted operator can persist a content-type-spoofed or oversized corpus item that later consumes storage/worker resources or crosses the configured LLM boundary without an explicit admission decision. |
| Disposition | deferred to P10.5 remediation matrix; not a direct public-byte exposure, but it needs an owner-selected admission policy before release. |
| Owner / regression seam | P10.5; add an allowlisted extension + detected-MIME/size policy at the form/service boundary and adversarial Pest vectors for spoofed content and limit overflow. |

### P10.2-S03 — MinIO lifecycle command exposes credentials via process arguments

| Field | Evidence |
|:--|:--|
| Category | secret handling / subprocess boundary |
| Severity / confidence | high / high |
| Location | `app/Services/Storage/MinioLifecycleManager.php:35-36`; reachable through `app/Console/Commands/Procurement/Ai/StorageLifecycleCommand.php:17-25` |
| Input → sink chain | File-backed S3 `key`/`secret` → `config('filesystems.disks.s3')` → `MinioLifecycleManager::apply()` → `Process::run(['mc', 'alias', 'set', ..., $disk['key'], $disk['secret']])` → child `mc` argument vector. |
| Reachability / authorization | The `ai:storage:lifecycle` Artisan command constructs the manager and invokes `apply()`; it is an operator/deploy boundary, not an unreachable helper. A local same-host observer can read a running child process's argument vector under the applicable OS permissions. |
| Broken invariant | D20 prohibits production secrets in process arguments. Passing an array to Laravel Process avoids shell interpolation, but does not remove the values from the child process argument surface. |
| Reproduction | `rg -n "MinioLifecycleManager|ai:storage:lifecycle|\['alias', 'set'" app/Console app/Services/Storage` shows the command-to-sink chain; `StorageLifecycleTest` executes this seam against MinIO. |
| Public behavior at risk | S3 credentials for the LLM payload-retention bucket can be disclosed to a process observer, permitting unauthorized object-store access within their credential scope. |
| Disposition | escalated — release blocker; remediation must use an `mc` credential mechanism that keeps secret material out of child args (or an explicitly approved equivalent control), with D20-preserving regression proof. |
| Owner / regression seam | P10.5; `tests/Feature/Infra/StorageLifecycleTest.php` plus an adversarial process-argument assertion around `mc alias set`. |

## False-positive ledger

| Candidate | Manual conclusion |
|:--|:--|
| `whereRaw` / `selectRaw` / `orderByRaw` | Fixed SQL expressions use bindings for dynamic values; no inspected candidate concatenates request input into SQL. |
| `forceFill` | Only `HasLease` and `HasErrorBlock` set fixed internal state attributes; no request-derived mass assignment reaches these calls. |
| `response()->file()` | The documentation controller authorizes first; locator uses a manifest allowlist, safe path grammar and realpath containment before returning the absolute path. |
| `Process::run()` in archive extraction | Executables and argument positions are fixed config/validated entry values; archive entry paths are validated before use. This needs continued hostile-archive tests, not a regex severity. |
| Documentation manifest SHA field | Serve-time code does not compare the file hash, but the immutable image/private volume is its trust boundary; no web actor can change one side of the pair. Record for a future integrity-hardening decision, not as a reachable finding. |
| `whereRaw` in the installed AzGuard direct-grant search | The dynamic search text is escaped for `%`, `_` and `!`, while the configured column is wrapped by the query grammar; the user input remains a binding. |
| `Process::run()` in MinIO lifecycle management | The command arguments are not shell-interpreted, but the S3 key/secret remain visible as child args. This is `P10.2-S03`, not a shell-injection false positive. |

## Release-blocker list

- `P10.2-S01` — high, escalated frozen-contract contradiction. P10.5 must either remediate it or obtain an explicit superseding decision with equivalent fail-closed controls.
- `P10.2-S03` — high, escalated D20 secret-in-process-arguments contradiction. P10.5 must remove the argument exposure and prove the replacement boundary.

P10.2-S02 is medium and remains in the P10.5 matrix with a defined remediation seam. No critical
finding was verified in this audit.
