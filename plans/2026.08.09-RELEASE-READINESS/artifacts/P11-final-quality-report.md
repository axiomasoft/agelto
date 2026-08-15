# P11 final quality report

## Local acceptance evidence

- Tested candidate: `cd3b534af39c70dd02a48ad34caab5f517f49d8c`.
- Carrier: `bash docker/tests/release-quality-contract.sh`.
- Result: GREEN; 13 fresh full no-TIA runs, each `1575` tests / `7107` assertions.
- Exact name/status set: `adca0f89c9501f863c68e9cb07c7c58147aaa28f59d2c741e2e7e20db3a665cf`.
- Five serial runs: `85224`, `84458`, `87969`, `88004`, `88520` ms; median `87969` ms against the `180000` ms budget.
- Profile: `86759` ms against the `180000` ms budget.
- Coverage: `102900` ms against the `200000` ms budget.
- Fixed random-order seeds: `104729`, `130363`, `155921`; all green with exact set parity.
- Selected mode: serial; repetitions `86898`, `88478`, `86966` ms, all green with exact set parity.

## Coverage

- Global statement coverage: `88.22%` (floor `88.22%`).
- `ApplyScreeningDispositionAction.php`: `96.05%`.
- `PrivateDocumentationLocator.php`: `88.37%`.
- `ApplicationReadiness.php`: `92.50%`.
- `ArchiveEntryValidator.php`: `94.44%`.
- `ProxyResolver.php`: `92.20%`.
- Critical-file floor: `55%`; all five files pass.

## Evidence integrity

- `summary.json` SHA-256: `0b1623ba07d76eb9bcff3c1a68c749f15eb650d349addc5fcbb7c15d9f421feb`.
- `coverage-clover.xml` SHA-256: `6f509b27f1830fad82c89919f61cb6632c4ed87a657f3d1f854048b539a02177`.
- Raw artifacts are generated under `.ci-artifacts/release-quality/` and are not committed.

## Limitations and P12 contract

Live CI was deliberately not run during this audit by repository-owner direction. This report makes
no live-CI-green claim. P12 must obtain terminal CI evidence for its release candidate before any
release approval. The first local attempt also encountered host OOM/PostgreSQL recovery; the complete
unchanged rerun above passed after the host stabilized. The audit repaired three committed-data leaks
found by seed `104729`, then repeated the entire carrier from a clean test database.
