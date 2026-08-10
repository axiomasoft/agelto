# P0.4 — Laravel ecosystem RAG

## Scope and queries

Проверка выполнена 2026-08-10 для locked Agelto stack: Laravel Framework 13.24.0,
Filament 5.7.6, Livewire 4.3.5 и Pest 5.0.4. Exact versions сняты командой
`composer show --locked` (локальный факт); `laravel/doctor` в lockfile отсутствует. Внешние
claims проверены по versioned official documentation; поиск использовал отдельные queries для
runtime lifecycle, Livewire asset/route delivery, Filament panel surface, package discovery,
private-document boundary и Pest execution. WebSearch применён как fallback: Context7 и
perplexity-web недоступны в данной сессии.

Output contract: claim → applicable version/scope → primary URL → failure scenario → consumer;
отсутствующая version-compatible evidence остаётся `[UNVERIFIED]`.

## Sources

| ID | Primary source | Checked | Applicable scope | Confidence |
|:--|:--|:--|:--|:--|
| S1 | Laravel, [Installation 13.x](https://laravel.com/docs/13.x/installation) | 2026-08-10 | Laravel 13 environment configuration and deployment prerequisites | medium — one primary source |
| S2 | Laravel, [Package development 13.x](https://laravel.com/docs/13.x/packages) | 2026-08-10 | Laravel 13 package discovery and `vendor:publish` mechanics | high — cross-checked with S3 |
| S3 | Laravel, [Middleware 13.x](https://laravel.com/docs/13.x/middleware) | 2026-08-10 | Laravel 13 route middleware, `auth`, `can`, cache-header aliases | high — cross-checked with S4 |
| S4 | Laravel, [Authentication 13.x](https://laravel.com/docs/13.x/authentication) | 2026-08-10 | Laravel 13 protected-route authentication | high — cross-checked with S3 |
| S5 | Livewire, [Installation 4.x](https://livewire.laravel.com/docs/4.x/installation) | 2026-08-10 | Livewire 4.3 asset injection, dynamic endpoints, route cache and 404 troubleshooting | high — exact major and route/asset behaviour |
| S6 | Filament, [Getting started 5.x](https://filamentphp.com/docs/5.x/getting-started) | 2026-08-10 | Filament 5 panel pages and default `/admin` panel surface | medium — one primary source |
| S7 | Pest, [Test Impact Analysis](https://pestphp.com/docs/tia) | 2026-08-10 | Pest 5 TIA lifecycle, local/CI boundary and cache invalidation | high — exact major launch documentation |
| S8 | Pest, [Optimizing tests](https://pestphp.com/docs/optimizing-tests) | 2026-08-10 | Pest 5 `--parallel` and `--profile` execution options | high — cross-checked with S9 |
| S9 | Pest, [CLI API reference](https://pestphp.com/docs/cli-api-reference) | 2026-08-10 | Pest 5 CLI flags, parallel execution and profiling | high — cross-checked with S8 |
| S10 | OWASP, [HTTP Headers Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html) | 2026-08-10 | authenticated response cache-control boundary | medium — primary security guidance, not Laravel API |

## Verified findings

### LAR-01 — locked stack is compatible on declared edges

`composer show --locked` records Laravel 13.24.0, Filament 5.7.6, Livewire 4.3.5 and
Pest 5.0.4. Livewire 4 declares Laravel 10–13 support, so its locked version is compatible
with the installed Laravel 13 at Composer level. Filament's panel provider in the tree declares
`id('admin')->path('admin')->login()`; Filament 5 documents panel pages as Livewire components
and the default panel entry surface at `/admin`. **Verdict: CONFIRMED** for dependency-level
compatibility and the intended panel route. RAG:✅ 2026-08-10, S5/S6; local evidence:
`composer.lock`, `app/Providers/Filament/AdminPanelProvider.php`. Confidence: high.

Failure scenario: a deployment reports a generic `/admin` 404 as a package incompatibility even
though the locked dependency ranges and configured panel path agree. Consumers: P2.6, P3.1–P3.5,
P9.1–P9.6, P12.4.

### LAR-02 — Livewire 404 needs runtime reproduction, not hook inference

Livewire 4 normally serves JavaScript and update endpoints dynamically on hash-based,
installation-specific paths. Its official troubleshooting lists blocked Nginx forwarding,
route cache, and missing scripts after disabling injection as distinct 404 causes; direct asset
publication is optional and described as rarely necessary. The existing
`post-update-cmd` publishes `laravel-assets`, not `livewire:assets`; that local hook neither
proves nor refutes any of these runtime causes. **Verdict: CONFIRMED** for the diagnostic
branches; **[UNVERIFIED]** for the current application's root cause until P3 records an HTTP
reproduction with route/cache/Nginx evidence. RAG:✅ 2026-08-10, S5; local evidence:
`composer.json`, `routes/web.php`. Confidence: high for branches, insufficient for root cause.

Failure scenario: publishing a public asset masks a proxy or route-cache fault, or a production
server fails to forward `/livewire-*/` to Laravel. Consumers: P1.5, P3.1–P3.5, P12.4.

### LAR-03 — package discovery and asset publication are separate lifecycle steps

Laravel 13 package discovery registers providers declared through Composer metadata; resource
copying occurs only when the relevant `vendor:publish` group is executed. Therefore a successful
Composer install/discovery cannot prove that a package's optional public assets are present, and
a publish hook cannot by itself prove a dynamic route works. **Verdict: CONFIRMED.** RAG:✅
2026-08-10, S2; local evidence: `composer.json` and its `post-autoload-dump`/
`post-update-cmd` scripts. Confidence: medium.

Failure scenario: a fresh host has providers discovered but no required published resources, or
an asset publication is used as evidence that a separate routing/middleware surface is healthy.
Consumers: P2.4, P3.1–P3.3, P9.2, P12.4.

### LAR-04 — private documentation requires a single protected response path

Laravel 13 supplies `auth` and `can` middleware for request admission, and middleware can
assign response cache headers. OWASP treats cache-control as a security header. Therefore the
private-docs carrier must keep source bytes outside `public`, resolve only an approved relative
identifier under a fixed root, authorize every document request before reading/sending it, and
emit conservative authenticated-response cache directives. **Verdict: CONFIRMED** for the
authorization/cache boundary; exact path-containment implementation remains a P4 design and
test obligation. RAG:✅ 2026-08-10, S3/S4/S10. Confidence: high.

Failure scenario: a guessed static URL bypasses authorization, a traversal identifier escapes the
docs root, or a shared cache replays one user's private HTML/file to another. Consumers: P4.1–P4.5,
P9.5–P9.6, P12.4–P12.5.

### LAR-05 — Doctor is a capability question, not an installed contract

No `laravel/doctor` package is present in the lockfile and P0.4 has no authoritative selected
package or command contract. Laravel's built-in `about` command can accept package-provided
information, but that does not establish a first-party Laravel Doctor product or compatibility
for a candidate dependency. **Verdict: [UNVERIFIED]** for package selection, capabilities and
Laravel 13 compatibility; P2.6 must choose only after a named package has official source and
exact Composer constraint evidence. RAG:✅ 2026-08-10, S2. Confidence: insufficient by design.

Failure scenario: the plan installs an unverified “Doctor” package whose Laravel/Pest constraints
or health semantics differ from the deployment acceptance contract. Consumers: P2.6, P3.3, P11.5,
P12.4.

### LAR-06 — Pest TIA is local feedback, not release acceptance

Pest 5 TIA records a dependency graph on its baseline run and replays unaffected test results on
later runs. `pest()->tia()->always()->locally()` in `tests/Pest.php` matches the documented
local-only mode; Pest skips this configuration in CI unless TIA is explicitly requested. Pest
also supports `--parallel` and `--profile`, but parallel safety depends on test database and
filesystem isolation rather than the flag alone. **Verdict: CONFIRMED** for the current local
TIA intent; **REFUTED** for treating TIA replay as a substitute for an uncached, isolated release
suite. RAG:✅ 2026-08-10, S7/S8/S9; local evidence: `tests/Pest.php`. Confidence: high.

Failure scenario: CI or release readiness accepts a replayed green result after a stale baseline,
or parallel workers share mutable database/media state. Consumers: P3.4, P11.1–P11.6, P12.6–P12.7.

## Contradictions and rejected claims

| ID | Local/provisional claim | Verdict | Evidence-backed consequence |
|:--|:--|:--|:--|
| C1 | The `laravel-assets` publish hook explains a Livewire 404. | REFUTED | P3 must reproduce the exact endpoint and distinguish Nginx forwarding, route cache, injection and optional asset publication. |
| C2 | Composer package discovery proves published assets and routes are ready. | REFUTED | Fresh-install acceptance needs separate discovery, publish/build and HTTP-path checks. |
| C3 | A generic Laravel Doctor dependency is already selected and compatible. | UNVERIFIED | P2.6 must name a package, constrain it, verify Laravel 13 support and define checks before installation. |
| C4 | `pest()->tia()->always()->locally()` is an adequate CI/release quality gate. | REFUTED | P11/P12 need an explicit uncached full-suite lane and isolated parallel acceptance. |
| C5 | Authentication alone makes static private documentation safe. | REFUTED | P4 must additionally contain paths, avoid public source exposure and control shared-cache behaviour. |

## Recommendations

1. **P2.6/P3.3 — split readiness evidence.** Treat config/package discovery, published or bundled
   assets, route cache and live HTTP routes as independent checks; choose a Doctor package only
   after a named official compatibility verdict.
2. **P3.1/P3.2 — diagnose the 404 at runtime.** Record the requested URL, Laravel route list/cache
   state, rendered Livewire directives, web-server forwarding and response status before selecting
   either dynamic delivery, direct publication or manual bundling.
3. **P4.1–P4.5 — make private docs a controller-backed authorization surface.** Keep source files
   outside `public`; combine authorization, canonical identifier/root containment, explicit MIME/
   disposition and authenticated-response cache policy; adversarial tests cover traversal, direct
   URL and shared-cache leakage.
4. **P11/P12 — keep TIA fast but non-authoritative.** Retain local TIA for feedback, while CI and
   release evidence run the declared full suite without depending on replay state and prove parallel
   isolation before using `--parallel` as a quality-speed control.

## Open questions

- `[UNVERIFIED]` P2.6 has not selected a Laravel Doctor package, its supported constraints, or the
  required health checks; no dependency must be added from this finding.
- `[UNVERIFIED]` The current Livewire/Filament 404 has no P3 runtime reproduction. The installed
  stack and configuration show possible diagnostic branches, not a root cause.
- Owner/design choice for P4: which authorization identity and document-level policy gates each
  private-doc request; P0.4 supplies the boundary only.

### Verification status

- RAG: WebSearch fallback ×12 OK; Laravel 13, Livewire 4, Filament 5 and Pest 5 official
  documentation plus OWASP cache-header guidance, checked 2026-08-10.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: named Laravel Doctor package/constraint, runtime Livewire 404
  evidence and exact private-doc authorization identity; consumers P2.6, P3.1 and P4.1.
- Обходки/[POSSIBLE-DEFECT]: no workaround accepted; dynamic Livewire delivery was not replaced
  with asset publication because this read-only research does not establish the 404 root cause.
