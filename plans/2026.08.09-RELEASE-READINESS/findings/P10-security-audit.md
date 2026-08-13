# P10.2 — manual Laravel security audit

Product tree: `469acf640fc95944a3accc378445190dbca2a9ba` (P10.1 immutable baseline).
Audit date: 2026-08-13. This is a read-only report: it neither changes product bytes nor
reclassifies a grep match as a defect without a traced source, sink, authorization and reachability.

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
| Private package integration | `composer.json` uses only the two local AzGuard paths; the P10.1 locked dependency audit reports zero advisories/abandoned packages. Authorization use sites are enum/panel scoped. | verified-safe |

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

## False-positive ledger

| Candidate | Manual conclusion |
|:--|:--|
| `whereRaw` / `selectRaw` / `orderByRaw` | Fixed SQL expressions use bindings for dynamic values; no inspected candidate concatenates request input into SQL. |
| `forceFill` | Only `HasLease` and `HasErrorBlock` set fixed internal state attributes; no request-derived mass assignment reaches these calls. |
| `response()->file()` | The documentation controller authorizes first; locator uses a manifest allowlist, safe path grammar and realpath containment before returning the absolute path. |
| `Process::run()` in archive extraction | Executables and argument positions are fixed config/validated entry values; archive entry paths are validated before use. This needs continued hostile-archive tests, not a regex severity. |
| Documentation manifest SHA field | Serve-time code does not compare the file hash, but the immutable image/private volume is its trust boundary; no web actor can change one side of the pair. Record for a future integrity-hardening decision, not as a reachable finding. |

## Release-blocker list

- `P10.2-S01` — high, escalated frozen-contract contradiction. P10.5 must either remediate it or obtain an explicit superseding decision with equivalent fail-closed controls.

P10.2-S02 is medium and remains in the P10.5 matrix with a defined remediation seam. No critical
finding was verified in this audit.
