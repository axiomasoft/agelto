# P4 execution design — private production documentation

## Sources

- `findings/P4-private-documentation-recon-2026-08-09.md` — repo/reference evidence, official RAG
  and threat register.
- `research/00-user-intent.md` — owner trace for closed production documentation.
- `research/03-P1-execution-design.md` — pnpm and immutable image contracts.
- `research/05-P3-execution-design.md` — admin/Playwright readiness and no-public-bypass boundary.
- D7 — all HTML/assets share one authorization policy outside `public`; D17/D18/D24/D25 — image,
  pnpm and isolated browser carriers.

P0.4/P0.6/P1.5/P3.5 are not terminal at authoring time. P4.1 is therefore a deliberate
frontier reconcile/freeze item: it reads their fresh states and named products before project
files change. Contradiction means `plan-design P4`, never an executor-side alternative.
RAG:— (repo-grounded: current item-state carriers and D7/D17/D18).

## Alternatives and decisions

### Generator

- A: copy Vintera's VitePress 2 alpha + plugins. Lowest migration effort, but freezes a prerelease
  and unnecessary plugin surface.
- B (D26): exact stable VitePress 1.6.4, no plugins/service worker/analytics, existing `docs/` as
  source. It aligns with the pinned pnpm/Vite toolchain and produces a single static set.
- C: custom Markdown-to-HTML builder. Fewer Node dependencies, but it recreates navigation,
  asset/link handling and accessibility without product value.

### Storage and deployment

- A: `public/documentation`. Fast direct serving, but bypasses Laravel authorization by design.
- B: writable/named `storage/app` volume populated on deploy. Outside web root, but artifact bytes
  can drift from immutable image tag and rollback.
- C (D26): image-built `var/private-documentation/site` plus adjacent deterministic manifest,
  read-only at runtime and absent from public/writable volumes. Build uses a temporary sibling and
  publishes only after verification.

### Serving and authorization

- A: Nginx internal redirect after an auth subrequest. Faster, but adds a second configuration
  boundary and makes cache/header parity harder to prove for first release.
- B (D27): one Laravel wildcard route/controller and locator; every manifest entry receives the
  same `auth` + AzGuard `DocumentationPermission::View` decision and response policy.
- C: signed public asset URLs. They remain extractable bearer URLs, complicate logout/revocation and
  violate the owner's single-policy requirement.

### Cache and browser policy

- A: long-cache hashed JS/CSS, no-store HTML. This is normal public SSG advice but becomes an asset
  bypass after logout or role revocation.
- B (D27): `Cache-Control: private, no-store` for HTML and every asset. Explicit MIME, `nosniff`,
  no-referrer, same-origin resource policy and restrictive CSP trade some performance for one
  auditable confidentiality boundary.

## Frozen security contract

### Build artifact

- Source is committed `docs/`; VitePress is exactly 1.6.4 under the P1 `pnpm@9.15.0` frozen lock.
  `@next`, `@latest`, extra themes/plugins, remote fonts, analytics and service workers are forbidden.
  RAG:✅ 2026-08-09 official VitePress stable/next and site-config docs.
- Base URL is `/admin/documentation/`; output is `var/private-documentation/site` inside the
  immutable application image, not `public`, `storage` or a Compose volume. P4 changes documentation
  therefore classify as image rebuild; production host never runs the generator. RAG:—
  (repo-grounded: D17/D18/D26).
- A deterministic verifier walks the finished tree, rejects symlinks/unsafe paths/unknown extensions/
  remote executable resources, and writes a sorted manifest `{path,mime,sha256}`. The final image
  copies only a verified complete tree + manifest from the frontend builder. Partial output is never
  published as current.

### Route and path containment

- One named GET/HEAD wildcard route owns root HTML, nested pages, CSS, JS, JSON, SVG/images and
  fonts. There is no public/static/Nginx alias, separate asset middleware or controller branch.
- Requested paths must be manifest members and match an ASCII known-good grammar. Reject percent,
  backslash, NUL, empty/dot/dot-dot segments and any extension absent from the closed MIME map.
  Resolve the configured root and candidate with `realpath`, then require the file path to start
  with `root + DIRECTORY_SEPARATOR`; missing build/path/manifest entry is a real 404.
  RAG:✅ 2026-08-09 OWASP Path Traversal.
- Locator/service owns normalization, manifest and MIME; controller remains HTTP-only:
  authenticate/authorize → locate → `response()->file()` with headers. No file-system logic in
  middleware/Filament provider and no `Request` below Controller.

### Authorization

- `DocumentationPermission::View = 'documentation.view'` is auto-discovered in panel `admin`.
  Controller checks it through enum-aware AzGuard API with explicit panel id. SuperAdmin wildcard
  passes; AnalystRole, OperatorRole and a roleless authenticated user remain denied.
- Filament navigation uses the same enum check and named route. Hidden navigation is convenience,
  never authorization; direct URLs run the controller check. A future role/direct grant changes only
  role/grant data, not routes or file serving.
- Guest requests yield the canonical admin login flow without bytes; authenticated denial is 403;
  authorized missing/unsafe path is 404. Tests assert body does not contain sentinel artifact bytes
  on every denial.

### Response policy

- Every 200 artifact response: exact manifest MIME; `Cache-Control: private, no-store`;
  `X-Content-Type-Options: nosniff`; `Referrer-Policy: no-referrer`;
  `Cross-Origin-Resource-Policy: same-origin`; no `public`, `s-maxage`, `immutable` or ETag carrier.
  RAG:✅ 2026-08-09 RFC 9111 and OWASP HTTP Headers.
- HTML additionally receives a CSP that blocks remote default/connect/object/frame/form/base
  surfaces. The build test proves the chosen policy against actual VitePress output; weakening it to
  pass without identifying the exact generated inline construct requires a superseding D-record.
- MIME comes only from the build manifest's closed extension table. Unknown active content is 404,
  never `application/octet-stream` inline and never a `finfo` guess.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P4.1 | terminal P0.4/P0.6/P1.5/P3.5 | reconciled threat model and frozen durable contract | P4.2 may add generator only after freeze |
| P4.2 | terminal P4.1 | exact VitePress build, verifier/manifest and immutable image integration | P4.3 reads verified artifact contract |
| P4.3 | terminal P4.2 | locator + one HTTP controller/route + headers | P4.4 adds permission/navigation without alternate route |
| P4.4 | terminal P4.3 | AzGuard enum/catalog, superadmin/future-grant and Filament link | P4.5 tests complete access matrix |
| P4.5 | terminal P4.4 | HTTP/browser/traversal/cache/image/rebuild proof | P12 consumes release gate |

Items are sequential and share package/Docker/route/auth/test/docs hotspots. The binary orchestration
criterion does not fire: N≥3 independent scope is false, and no inter-item autonomous external-state
chain is intended. RAG:— (repo-grounded: dependencies above).

## Validation strategy

1. Reconcile: terminal upstream states + source/fact matrix; contradiction produces a scoped
   plan-design escalation before mutation.
2. Build: frozen install, exact version, two identical builds with identical manifest/tree hash;
   verifier rejects symlink, unsafe path, unknown active extension and remote executable fixture.
3. Image: inspect final image for one docs tree outside `public`/writable mounts; source/dev cache,
   Node and pnpm stay out of PHP runtime.
4. PHP: unit corpus for manifest/path normalization/containment/MIME; feature matrix for guest,
   roleless, Analyst, Operator, SuperAdmin and explicit future grant.
5. Browser: reuse P3's pinned setup/auth and isolated runner; open root and nested page, assert all
   assets 2xx with correct MIME/no-store, no page/console/CSP error, then prove direct denial.
6. Recovery/deploy: docs-only source change changes image artifact/tag via the declared build
   classification; no prod-host build, public fallback or volume population appears.

## Verification status

- RAG: official Web RAG ×10 primary pages + repo/vendor/reference inspection, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0.4/P0.6/P1.5/P3.5 terminal products; P4.1 is the exact
  reconcile/freeze carrier and blocks mutation until they exist.
- Обходки/[POSSIBLE-DEFECT]: none. Serving each asset through PHP is an explicit D27 cost; an
  optimized internal-send path may be designed later only without weakening auth/cache parity.
