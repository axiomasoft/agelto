# P4 acceptance report — private documentation

## Threat → proof matrix

| Threat | Control | Evidence carrier |
|:--|:--|:--|
| Guest, role and direct-URL disclosure | One enum-aware route, explicit access dataset and sentinel-byte assertions | `PrivateDocumentationAccessTest.php` |
| Traversal, symlink and missing artifact | Manifest-only locator with hostile-path dataset and uniform 404 | `PrivateDocumentationLocatorTest.php` |
| Active same-origin SVG | SVG is absent from Node/PHP MIME allowlists; hostile build and forged-manifest/direct-URL tests reject it without returning bytes | `documentation-build-contract.sh`, `PrivateDocumentationLocatorTest.php`, `PrivateDocumentationResponseTest.php` |
| MIME sniffing and response-policy drift | Closed manifest MIME dataset for HTML, CSS, JS, JSON and font; exact header assertions | `PrivateDocumentationResponseTest.php` |
| Browser asset/cache bypass | Manifest-driven Chromium body/hash/MIME/header proof followed by direct logout and revoke reloads | `e2e/private-documentation.spec.ts` |
| Mutable/public image artifact | Deterministic build, final-image filesystem inspection and clean-install runner | `documentation-build-contract.sh`, `prod-image-contract.sh`, `clean-install-e2e.sh` |

## Evidence

- Candidate: `d9f025f3c421621060d192dd6142c74e8e2a4614`.
- Application image: `sha256:f4cbc49282d6e773de1f630390a00a3459c0abd81316c6f73b4f7def413189c0`.
- `bash docker/tests/private-documentation-response-contract.sh` exited 0: 25 Pest tests / 94
  assertions, eight Pint files and focused PHPStan passed; active SVG forged-manifest and direct
  response regressions returned no bytes.
- `bash docker/tests/documentation-build-contract.sh` exited 0 from a detached clean worktree after
  repository-owned pinned vendor sync: two VitePress 1.6.4 builds were byte-identical; hostile SVG,
  symlink, extension, percent-path and remote-script fixtures were rejected; the image artifact was
  read-only and absent from public/runtime Node paths.
- `bash docker/tests/prod-image-contract.sh application` exited 0 against the current ordered secret
  hand-off, application health and private-documentation filesystem contract.
- `bash docker/tests/clean-install-e2e.sh` exited 0 without external credentials: candidate-local
  credentials were generated and removed by the isolated trap; Playwright passed 3/3 scenarios,
  including exact manifest body SHA-256, MIME and all cache/security headers for HTML/CSS/JS/JSON/
  font, zero page/console/CSP failures, and denial of the same direct asset after logout and revoke.
- `bash docker/tests/private-documentation-acceptance-contract.sh` exited 0 for the same committed
  candidate and combined the focused response, clean build, production image, isolated browser,
  scoped Pint/PHPStan, Task views, strict citations and clean-diff gates. The scoped PHPStan step
  uses an in-memory SQLite bootstrap and an explicit memory ceiling, so it does not depend on a
  checkout-external database after the isolated browser stack is removed.

## Committed-candidate verdict

GREEN. `make documentation:acceptance` delegates to the same composite carrier and requires no
external `E2E_*` values. P12 must consume the exact candidate SHA and application image identifier
above; a different tree or image requires a fresh carrier run.

## Limitations

Unresolved limitations: 0.
