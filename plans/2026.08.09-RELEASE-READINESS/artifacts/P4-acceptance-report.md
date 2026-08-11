# P4 acceptance report — private documentation

## Threat → proof matrix

| Threat | Control | Evidence carrier |
|:--|:--|:--|
| Guest, role and direct-URL disclosure | One enum-aware route, explicit access dataset and sentinel-byte assertions | `PrivateDocumentationAccessTest.php` |
| Traversal, symlink and missing artifact | Manifest-only locator with hostile-path dataset and uniform 404 | `PrivateDocumentationLocatorTest.php` |
| MIME sniffing and response-policy drift | Closed manifest MIME dataset for HTML, CSS, JS, JSON, SVG and font | `PrivateDocumentationResponseTest.php` |
| Browser asset/cache bypass | Isolated Chromium request/header/error corpus followed by cookie removal and direct reload | `e2e/private-documentation.spec.ts` |
| Mutable/public image artifact | Deterministic build, final-image filesystem inspection and clean-install runner | `documentation-build-contract.sh`, `prod-image-contract.sh`, `clean-install-e2e.sh` |

## Evidence

- `bash docker/tests/private-documentation-response-contract.sh` passed: 24 Pest tests, Pint and
  PHPStan for the documentation serving boundary.
- `pnpm exec playwright test --list` passed: setup plus the P3 admin and P4 documentation scenarios
  compile and are discoverable.
- `bash docker/tests/documentation-build-contract.sh` passed: two VitePress builds were identical,
  hostile distribution fixtures were rejected, and the application image contained the read-only
  private artifact with no public documentation tree.
- `bash docker/tests/prod-image-contract.sh application` is red before image inspection because its
  inherited P1 assertion expects `setpriv --reuid=33 --regid=33` in `10-secrets.sh`, while the
  committed entrypoint uses the current `install`/`chown` secret hand-off instead. This is an
  inherited production-image contract defect, not a documentation-route bypass.

## Required committed-candidate gate

Run `make documentation:acceptance` from a clean worktree with `E2E_ADMIN_EMAIL` and
`E2E_ADMIN_PASSWORD_FILE`. It executes the full focused-PHP, reproducible-build, private-image and
isolated Chromium proof. The candidate tree/image digest and final result must be appended here for
P12 consumption.

## Limitation

This workspace has no supplied `E2E_ADMIN_EMAIL` or `E2E_ADMIN_PASSWORD_FILE`; therefore the
isolated browser acceptance has not yet produced a committed-candidate evidence record. The stale
P1 `setpriv` assertion must also be reconciled against the actual secret-entrypoint contract before
the composite carrier can turn green.
