# Release Readiness Contract v1

Status: frozen for P1–P12 implementation; D60 remains an explicit owner gate before P12.3/P12.7.
Evidence basis: `research/02-P0-target-contracts.md`, accepted D4–D10/D14/D15/D56/D57–D59/D61,
and proposed D60. A consumer may strengthen a clause, but may not weaken or silently replace it.

## Development and production topology

- **TOP-01:** Development source is bind-mounted; an ordinary application-code edit must become
  visible without rebuilding the PHP image. Rebuild causes are a finite taxonomy: dependency or
  frontend lock/manifests, Docker/build-context changes, base/package refresh, and explicit clean
  or security rebuild. Evidence: INF-02; decision: D57.
- **TOP-02:** Production application source, Composer dependencies and built frontend assets are
  image-contained. Production source bind mounts are forbidden. Evidence: INF-01/INF-11;
  decision: D57.
- **TOP-03:** Build/promotion/deploy receipts bind source commit, manifest digest, platform digest
  and configuration revision. Tag-only deploy/rollback evidence is invalid. Evidence: INF-10;
  decisions: D57/D59.
- **TOP-04:** Production runtime services consume the same promoted identity; rollback resolves
  exact digest and verifies schema/config compatibility before mutation. Decision: D59.
- **TOP-05:** Production asset build and Vite dev/HMR are separate carriers. Dev uses explicit
  host/allowed-host/HMR settings; production runs built assets and HTTP-verifies emitted URLs.
  Evidence: INF-11; decision: D57.
- **TOP-06:** Root Make remains the single operator entrypoint with deterministic help/preflight
  and agent-safe output. Ordinary build/deploy paths never execute volume destruction. Evidence:
  INF-08/INF-12; decision: D10.

## Bootstrap, secrets and deploy

- **SEC-01:** The first-host order is clone main repository → clone exact private package identity
  into `local/vendors/azguard` → generate file secrets → preflight → build/pull → migrate → required
  seeds → initial admin → assets/docs → workers/readiness. Decisions: D6/D9/D58.
- **SEC-02:** Single-server production secrets are explicit file-backed Compose secrets, generated
  outside Git. There is no implicit env/Swarm fallback. Evidence: INF-05; decision: D58.
- **SEC-03:** Each service receives only declared secrets. Preflight proves host ownership,
  container-user readability and absence of secret values from rendered config, image history,
  process args and logs. Decision: D58.
- **SEC-04:** Private build credentials use BuildKit secret/SSH mounts only; secret rotation uses
  an explicit non-secret cache invalidator where rebuild is required. Evidence: INF-04; D58.
- **SEC-05:** Rotation atomically replaces secret material, performs controlled worker/container
  reload and records a redacted health receipt. Decision: D58.
- **SEC-06:** No deploy/build/cleanup carrier removes DB/object/volume data without separate owner
  authorization, named backup receipt and successful isolated restore evidence. Evidence: INF-08.

## Application, assets and private docs

- **APP-01:** Readiness is a capability matrix, not a package name or `/up` alone: config/package
  discovery, schema, storage, queue/workers, required assets and named HTTP paths are independent
  checks. Evidence: INF-06 and LAR-03/LAR-05.
- **APP-02:** P2.6 may add a Doctor dependency only after a named package/version has official
  Laravel 13 constraint evidence; otherwise it implements the required checks with verified
  first-party carriers and records the package as rejected. Evidence: LAR-05.
- **APP-03:** Long-lived workers are restarted or reloaded after config/secret/settings changes,
  and readiness proves they observe the new revision.
- **APP-04:** Livewire/Filament 404 remediation starts with recorded failing URL, route/cache state,
  rendered directives and web-server forwarding. Asset publication is not accepted as root cause
  evidence. Evidence: LAR-02/LAR-03.
- **APP-05:** Livewire dynamic endpoints, Filament panel assets and production Vite outputs receive
  separate HTTP acceptance checks on a fresh install. Evidence: LAR-01–LAR-03 and INF-11.
- **APP-06:** Package discovery, optional publication and production build are distinct lifecycle
  steps with independent receipts. Evidence: LAR-03.
- **APP-07:** Private documentation source and generated output stay outside `public`; every HTML
  and asset response traverses one authentication/authorization policy. Decision: D7.
- **APP-08:** Document identifiers resolve under a fixed root with path containment; traversal,
  symlink escapes and direct filesystem/public URLs fail closed. Evidence: LAR-04; D7.
- **APP-09:** Private responses use explicit MIME/disposition and conservative authenticated cache
  policy; shared-cache replay between users is tested. Evidence: LAR-04; D7.
- **APP-10:** Exact generator and permission identity are frozen by P4.1 after its threat model;
  no option may weaken APP-07–APP-09.
- **APP-11:** Filament navigation, resources and values use the project Russian naming canon;
  UUIDs are hidden by default, booleans use human labels, and tables are responsive without
  weakening authorization/query behavior.
- **APP-12:** Every Filament route and direct action is checked against the declared role/permission
  matrix, including private docs and relation actions.

## Proxy, documents and extension seams

- **EXT-01:** Only `ProxyResolver` obtains outbound proxies; precedence is profile proxy → optional
  default proxy → direct. `ProxyScope` describes purpose. Decision: D8; evidence: DOM-01.
- **EXT-02:** Global proxy health and per-target health remain separate; no call site passes a raw
  proxy URL or invents fallback semantics. Decision: D8.
- **EXT-03:** `PLAYWRIGHT_MCP_PROXY_SERVER` is tooling-only and never enters runtime resolution.
  Decision: D8.
- **EXT-04:** `SourceTlsTrust` is the only TLS-trust owner; `verify => false` is forbidden.
  Evidence: DOM-01 and project architecture invariant.
- **EXT-05:** Handler resolution uses typed extension, accepted MIME, magic/content and issuer/spec
  evidence. Unknown/conflicting signals produce a typed unsupported reason. Decision: D61.
- **EXT-06:** `.gsfx` remains literal vendor evidence. `JSRF`/`GSRF` are not aliases until an issuer
  specification/sample corpus is accepted. Evidence: DOM-06; D61.
- **EXT-07:** Archive admission enforces depth, entry-count, aggregate/per-entry expanded bytes,
  normalized target uniqueness, containment, link/device rejection, timeout and disk budget before
  child materialization. Evidence: DOM-04; D61.
- **EXT-08:** Archive expansion and child creation are idempotent; each accepted child obtains a
  stable file record and dispatch occurs after commit. Failure/retry cannot duplicate children.
- **EXT-09:** Extractor/handler registry is typed and registry-resolved; a new handler declares
  signals, limits, reasons and fixtures without switch sprawl.
- **EXT-10:** Unsupported or rejected input is a terminal observable disposition, not an exception
  swallowed into generic failure.
- **EXT-11:** Procurement document taxonomy is independent of estimate format. Notice, protocol,
  contract and attachment are not failed estimates. Evidence: DOM-05; D61.
- **EXT-12:** Classification represents unknown/null explicitly and supports zero, one or multiple
  estimates without lossy coercion.
- **EXT-13:** Every analysis result has a summary, including non-estimate and unsupported outcomes;
  tags and anchors round-trip through persistence/API/Filament.
- **EXT-14:** Platform definitions and status mappings are typed extension seams; mapping is
  configuration/domain data rather than a hard-coded procurement-law switch.

## Release acceptance

- **REL-01:** Final acceptance starts from a clean clone and empty persistent volumes/DB, then
  exercises private package, secrets, migrations, required seeds, initial admin, assets, docs,
  queues, storage, readiness and browser smoke. Decision: D9.
- **REL-02:** Ubuntu provisioning uses official Docker packages and records Engine, Buildx,
  Compose, architecture and daemon state. Evidence: INF-07.
- **REL-03:** Effective published-port/firewall behavior is tested in Docker's packet-filter path;
  ufw status alone is insufficient. Evidence: INF-07.
- **REL-04:** Deploy is readiness-gated, non-destructive and emits an exact release receipt per
  TOP-03. Destructive teardown is absent from the ordinary path. Evidence: INF-08; D59.
- **REL-05:** Backup acceptance pairs artifact creation with successful isolated restore. Artifact
  existence alone is insufficient. Evidence: INF-09.
- **REL-06:** Before P12.3, D60 must become accepted or be superseded with explicit RPO, RTO,
  retention, off-host destination and restore cadence. READY is fail-closed while it is proposed.
- **REL-07:** Rollback proves exact-digest availability, database/schema compatibility, queue drain
  or restart, asset/docs consistency and post-rollback readiness. Decision: D59.
- **REL-08:** CI/release uses an uncached full suite with DB/media isolation; local Pest TIA is never
  authoritative release evidence. Evidence: LAR-06.
- **REL-09:** READY report names every receipt and residual risk; missing, unverified or stale
  evidence yields NOT READY rather than a narrative waiver.

## Consumer and route matrix

| Consumer | Clauses | Required proof before terminal state |
|:--|:--|:--|
| P1 | TOP-01–TOP-06, SEC-06 | Target harness, dev no-rebuild proof, immutable prod topology and destructive-path guard |
| P2 | SEC-01–SEC-05, APP-01–APP-03 | Clean private install, redacted secret lifecycle, fail-closed preflight and readiness capability |
| P3 | APP-01–APP-06 | Runtime 404 diagnosis, causal repair, fresh-install HTTP/browser evidence and recovery proof |
| P4 | APP-07–APP-10/APP-12 | Threat model plus traversal/direct-URL/cache/authz adversarial suite |
| P5 | EXT-01–EXT-04 | Precedence, health separation, secret safety, no bypass and no TLS-disable checks |
| P6 | EXT-05–EXT-10 | Typed format contract and hostile archive/idempotency/retry/concurrency evidence |
| P7 | EXT-11–EXT-13 | Schema/prompt/persistence fixtures for taxonomy, plurality, unknown/null, summaries and anchors |
| P8 | EXT-09/EXT-14 | Typed platform/status extension contract, migration/rollback and race-safe persistence |
| P9 | APP-05/APP-11/APP-12, EXT-13 | Russian UX/labels, responsive browser checks and direct-route authorization |
| P10 | all security/data clauses | Risk inventory mapped to committed fixes; static analysis/full suite without weakened gates |
| P11 | TOP-03/TOP-06, SEC-03/SEC-04, REL-08 | Isolated uncached CI, deterministic logs, credential/cache/action/image security evidence |
| P12 | all clauses | Clean-host bootstrap/deploy/recovery rehearsal and final READY/NOT READY synthesis |

Route/review: exact per-item routing remains `plan.md` §3. Security, migration, authz, extraction,
secrets, deployment and public-contract work requires full review; P12.7 remains frontier/xhigh.
