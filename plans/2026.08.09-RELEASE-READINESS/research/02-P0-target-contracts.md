# P0.6 — Target contracts and trace

## Evidence boundary

Синтез использует только digest-qualified bundle P0.6: terminal states P0.1–P0.5, их шесть
объявленных findings, P0 execution design и D4–D10/D14/D15/D56. Внешние claims не
перепроверялись: external basis наследует RAG-verdict и дату исходного findings; repo basis
ссылается на его path/evidence. P0.3 MCP replay является terminal input по D56.

## Trace matrix

| Requirement | Evidence | Alternatives / cost | Decision or gate | Contract clause | Consumers | Deterministic acceptance |
|:--|:--|:--|:--|:--|:--|:--|
| Dev code edit без PHP rebuild; production topology и rollback | Owner trace; inventory Docker/Make; reference comparison; INF-01/02/10/11 | Host source in prod: cheap deploy, non-atomic identity; image-contained release: build cost, atomic provenance | D57, D59 | TOP-01–TOP-05 | P1.2–P1.5, P12.2/P12.4/P12.5 | Dev edit preserves image ID; prod receipt contains commit + manifest/platform digest + config revision; rollback resolves exact digest |
| Makefile можно переписать, но операторский интерфейс должен быть проверяемым | D10; reference Make comparison; INF-08/12 | Alias-heavy compatibility: less churn, hidden semantics; small explicit taxonomy: breaking cleanup, deterministic help/preflight | D10 + TOP-06 | P1.1, P11.2/P11.3, P12.6 | Golden help/target harness; ordinary targets contain no `down --volumes`; synchronized logs |
| local/vendors AzGuard clone и private install | D6; inventory Composer path gap; INF-04 | Host credentials copied/build args: simple, leak risk; BuildKit secret/SSH mount: explicit tooling, no credential layer | D6, D58 | SEC-01–SEC-04 | P2.1/P2.4, P12.2 | Clean clone installs locked package from declared identity; image/history/log scan finds no secret |
| Generated secrets and first-host bootstrap | Inventory secret mismatch; INF-05 | Swarm external on plain Compose: missing source; env: wider exposure; file-backed: permissions/bootstrap cost | D58 | SEC-01–SEC-05 | P2.2–P2.4, P12.4 | Fresh-host preflight proves source/readability per service and redacted config/logs; rotation reload receipt |
| Laravel Doctor and readiness | Inventory readiness carrier; LAR-03/05; INF-06 | Install unnamed package: fast, unverified; capability contract first: selection work, fail-closed compatibility | APP-01–APP-03 | P2.6, P3.3, P12.4 | Named package has official Laravel constraint or is rejected; readiness checks schema/assets/workers/routes, not only `/up` |
| Livewire/Filament asset 404 and Vite | Inventory assets; INF-11; LAR-01/02/03 | Publish blindly: may mask proxy/route-cache fault; reproduce branches: diagnostic cost, causal fix | D57 + APP-04–APP-06 | P1.5, P3.1–P3.4, P9.6, P12.4 | Recorded failing URL plus route/cache/web-server evidence; HTTP checks for Livewire, Filament and built Vite assets |
| Private production docs вне public | D7; reference authorization liability; LAR-04 | Public/static assets: easy serving, bypass/cache leak; one protected response path: implementation/testing cost | D7 + APP-07–APP-10 | P4.1–P4.5, P9.5/P9.6, P12.4 | Direct URL/traversal/unauthorized/shared-cache tests fail closed for HTML and assets |
| Profile proxy → default → direct; tooling proxy separate | D8; DOM-01 | Per-call transport choice: flexible, policy drift; ProxyResolver only: centralized typed policy | D8 + EXT-01–EXT-04 | P5.1–P5.5, P10.2, P12.4 | Registry/source scan finds no bypass/`verify => false`; precedence and health-scope tests green |
| Archive children, typed handlers and unsupported reason | Inventory tool carrier; DOM-03/04/06 | Extension-only/generic fallback: simple, unsafe; typed multi-signal registry: more fixtures, explicit outcomes | D61 | EXT-05–EXT-10 | P6.1–P6.6, P10.2/P10.4 | Hostile archive matrix covers containment, links, limits, duplicate paths, timeout; unsupported inputs persist typed reason |
| Document type, estimate plurality, unknown/null, summary and anchors | Owner trace; DOM-05/06 | Collapse every attachment to estimate: simpler schema, false failures; taxonomy above formats: schema/mapping cost | D61 + EXT-11–EXT-14 | P7.1–P7.6, P8.1–P8.5 | Fixtures distinguish non-estimate/unknown/null and zero/one/many estimates; every result has summary; anchor round-trip green |
| Filament Russian UX, labels, layout and responsive tables | Owner trace; LAR-01 | Local per-resource cleanup: drift; shared canon with resource acceptance: coordinated work | APP-11–APP-12 | P9.1–P9.6 | Route/role matrix, Russian label snapshot, hidden UUID, boolean labels, responsive browser checks |
| Modern PHP/security/naming and package quality | Owner trace; D5 coupling; DOM/LAR threat findings | Cosmetic lint only: misses races/authz; risk-based audits and fixes: broader evidence cost | QLT-01–QLT-04 | P10.1–P10.6 | Security/data/architecture findings mapped to fixes; max-level static analysis/full suite green without baseline weakening |
| Pest 5 fast feedback and CI | Inventory test carrier; LAR-06; INF-12 | TIA as gate: fast, stale replay risk; TIA local + uncached isolated CI: compute cost, authoritative result | QLT-05–QLT-08 | P11.1–P11.6, P12.6/P12.7 | CI/release lane ignores TIA cache, proves DB/media isolation, records full suite and flake/performance gates |
| Empty Ubuntu, ingress/firewall, deploy/rollback/backup | D9; INF-07/08/09/10 | Ufw-only/local backup: simple, incomplete; Docker firewall proof + off-host policy + restore: operator/storage cost | D59; D60 proposed owner gate | REL-01–REL-09 | P12.1–P12.7 | Clean-host rehearsal, effective exposure scan, non-destructive deploy, isolated restore and exact-digest rollback receipts |

## Alternatives and frozen outcomes

1. **Production carrier:** image-contained source (D57) wins over host bind mount because only the
   image option makes deploy and rollback identity atomic. The accepted cost is rebuild/promotion
   for production changes; dev retains bind mounts.
2. **Secret mode:** file-backed Compose secrets (D58) win over Swarm-external and plain env for the
   declared single Ubuntu host. The accepted cost is explicit bootstrap/permissions/rotation work.
3. **Release identity:** digest receipt (D59) wins over tag-only identity. The accepted cost is
   provenance plumbing through build, deploy and rollback.
4. **Protected docs:** D7's single authorized response boundary wins over public static assets.
   Exact generator and policy integration remain P4 work, but weakening authorization is not a
   valid alternative.
5. **Proxy:** D8's typed resolver wins over per-call options; the cost is centralized registry and
   health modelling, while `SourceTlsTrust` remains the only TLS owner.
6. **Document admission:** multi-signal typed registry (D61) wins over suffix/MIME-only routing;
   the cost is explicit per-format fixtures and unsupported dispositions.
7. **Test authority:** uncached full suite wins for CI/release; local TIA remains a speed carrier.
   The accepted cost is CI runtime and isolation setup.
8. **Recovery policy:** exact RPO/RTO/retention/off-host values cannot be inferred from Docker
   mechanics. D60 remains proposed and fail-closes P12.3/P12.7 until owner acceptance; it does not
   block implementation phases that do not consume these values.
9. **Doctor package:** no dependency is frozen. P2.6 first proves a named package's official
   Laravel 13 compatibility or records rejection; the frozen object is the readiness capability,
   not an unverified vendor choice.
10. **Livewire 404:** runtime reproduction wins over unconditional asset publication. Publication
    is accepted only if the reproduced branch requires it.

## Consumer and route-review recommendation

Exact item routes remain SSOT in `plan.md` §3; this matrix records the P0.6 cross-phase rationale.

| Phase | Consumed contract | Recommended route/review | Reason |
|:--|:--|:--|:--|
| P1 | TOP | implementation medium/high; full | Command compatibility, destructive guards and image boundary are cross-carrier correctness work. |
| P2 | SEC, APP | implementation/high; full | Private dependency and secret/bootstrap mutation cross the credential boundary. |
| P3 | APP, QLT | implementation/high; full | Runtime diagnosis precedes repair; HTTP/browser evidence is required. |
| P4 | APP | frontier/high freeze, then implementation/high; full | Threat model may change the authorization contract; implementation remains security-critical. |
| P5 | EXT | frontier/high reconcile, then implementation/high; full | Egress policy is shared and secret-sensitive. |
| P6 | EXT | frontier/high reconcile, then implementation high/medium; full | Format/archive contracts are adversarial and feed later schema work. |
| P7 | EXT | frontier/high reconcile, then implementation high/medium; full except read-only projection may be light | Taxonomy/schema/prompt decisions have cross-phase consumers. |
| P8 | EXT | frontier/high reconcile, then implementation high/medium; full | Extension and status mapping affect shared runtime/config/schema. |
| P9 | APP, EXT | frontier/high reconcile, then implementation/high; full | Navigation/presentation must preserve auth and domain semantics. |
| P10 | all | frontier/high baseline, then implementation/high; full | Audit and remediation span security, data and architecture boundaries. |
| P11 | QLT, TOP, SEC | frontier/high baseline, then implementation/high; full | CI selection, credentials, isolation and cache are supply-chain gates. |
| P12 | all | implementation/high through rehearsal; final frontier/xhigh; full | Host mutation and final READY verdict consume every frozen clause and D60 owner gate. |

## Freeze result

Accepted D57–D59/D61 and existing D4–D10/D14/D15/D56 freeze implementable choices. D60 preserves
the only owner-policy fork without inventing values: its decision is required before P12.3 and
READY. `[UNVERIFIED]` items are converted to explicit consumer gates rather than silently accepted.
