# P11 Pest 5 / isolation / CI recon — 2026-08-09

## Snapshot

Authoring tree: `1ab8f0531241a0c127b5e8fa9b5688ed0482f56d` plus the owner's uncommitted
master-plan authoring work. Product facts below come from tracked files and locked Composer metadata;
they are seeds for P11.1, not a substitute for measurement on the terminal P10 tree.

- Locked test stack: Pest 5.0.4, pest-plugin-laravel 5.0.1, pest-plugin-agent 5.0.0,
  PHPUnit 13.2.6, Paratest 7.23.1 and Laravel PAO 1.1.3 (`composer show --locked
  --format=json`). RAG:— (repo-grounded: `composer.lock`, queried 2026-08-09).
- The tracked suite has 43 Unit and 189 Feature files, no Browser directory and 1,490 `test(`
  calls / zero `it(` calls. These authoring counts are anchored to `1ab8f05` and MUST be
  recomputed by P11.1 after P10. RAG:— (repo-grounded: `git ls-files tests` and `rg`).
- `tests/Pest.php` currently chains `pest()->tia()->always()->locally()`. The dev Compose carrier
  already provides PCOV, a persistent `/var/www/.pest` volume and git safe-directory ownership.
  RAG:— (repo-grounded: `tests/Pest.php`, `docker-compose.yml`, `docker/php/**`).
- `phpunit.xml` and `tests/bootstrap.php` pin `agelto_test`; `TestEnvironmentGuard` verifies only
  the DB suffix. No equivalent filesystem/media containment exists, and the Make agent marker
  pass-through omits PAO's `CODEX_SANDBOX` / `CODEX_CI` / `CODEX_THREAD_ID` signals. RAG:—
  (repo-grounded: `phpunit.xml`, `tests/{bootstrap.php,TestCase.php,Support/TestEnvironmentGuard.php}`,
  `docker/Makefile`, `vendor/laravel/agent-detector/src/AgentDetector.php`).
- There are no tracked `.github/workflows/**` files. P3.4/P9.6 already own the single pinned
  Playwright/Chromium E2E carrier; P10.6 will own the local Pint/PHPStan/Rector/dependency/docs
  aggregate. P11 must reuse both rather than create competitors. RAG:— (repo-grounded:
  `git ls-files .github/workflows`, D24, D39 and P3.4/P9.6/P10.6 specs).
- Historical `plans/archive/2026.08.01-L13-PEST5.md` D4–D10 records why PCOV, local TIA,
  `--no-tia` acceptance and the agent plugin exist. Its D6 rejected a shared baseline only because
  CI did not yet exist; P11 is the explicitly deferred owner of that boundary.

## External fact classes and official verification

### Pest 5 TIA and CI

- Source: https://pestphp.com/docs/tia — checked 2026-08-09. A coverage driver is required to
  record the graph; `locally()` is the preferred automatic configuration, while `always()` is an
  alternative. Regular CI must execute the full suite; only a dedicated baseline workflow uses
  TIA. `baselined()` may fetch the `pest-tia-baseline` artifact via authenticated `gh` and falls
  back to a local record when unavailable. Verdict: replace the current chain with local TIA and
  add shared-baseline opt-in only when the trusted workflow exists. Confidence: high.
- The same source states that structural inputs such as `composer.lock`, `phpunit.xml`, Vite and
  Node lockfiles invalidate the graph, and that the baseline storage is user-local under
  `~/.pest/tia/<project-key>/`. Verdict: TIA state is a dedicated artifact/volume, never a general
  dependency cache or acceptance proof. Confidence: high.

### Parallelism, sharding, profiling and coverage

- Sources: https://pestphp.com/docs/optimizing-tests and
  https://pestphp.com/docs/continuous-integration — checked 2026-08-09. Pest exposes
  `--parallel`, explicit `--processes`, `--profile`, `--update-shards` and time-balanced
  `--shard=i/n` backed by committed `tests/.pest/shards.json`. Verdict: benchmark small explicit
  process/shard matrices; do not equate «all cores» with a safe optimum. Confidence: high.
- Source: https://pestphp.com/docs/cli-api-reference — checked 2026-08-09. `--ci` ignores focused
  tests and runs the entire suite; coverage/minimum, JUnit, random ordering/seeds and strict/fail
  switches are public CLI carriers. Verdict: acceptance commands must be explicit, reproducible
  and machine-readable. Confidence: high.
- Source: https://laravel.com/docs/13.x/testing — checked 2026-08-09. Laravel's Artisan parallel
  runner creates/migrates a database per process, suffixes it with the process token and exposes
  `ParallelTesting` hooks/token for other resources. Verdict: DB and filesystem guards must accept
  only the exact serial database or the exact tokenized derivative, and writable resources must
  be process-scoped before parallel mode can pass. Confidence: high.

### Agent output and CI trust boundary

- Source: https://github.com/laravel/pao — checked 2026-08-09. PAO 1.x supports PHP 8.3+,
  PHPUnit 12–13, Pest 4–5, Paratest, PHPStan, Rector and Laravel 12+; it emits compact structured
  output only in detected agent environments and leaves human output unchanged. Verdict: retain
  PAO and fix container marker propagation; do not add a second output wrapper. Confidence: high.
- Source: https://docs.github.com/en/actions/reference/security/secure-use — checked 2026-08-09.
  GitHub recommends minimum `GITHUB_TOKEN` permissions, avoiding privileged untrusted checkout and
  pinning third-party actions to full commit SHAs. Verdict: `pull_request_target` is forbidden,
  permissions are read-only by default, and every non-local action is SHA-pinned with provenance.
  Confidence: high.
- Source: https://docs.github.com/en/actions/reference/workflows-and-actions/dependency-caching —
  checked 2026-08-09. Cache keys should change with dependency inputs; caches may be readable from
  fork PRs, must not contain credentials, and trusted triggers should own cache writes. Verdict:
  cache download stores keyed by exact lock/runtime fingerprints only; never cache `.env`, private
  keys, auth state, generated secrets, test DB/media or executable vendor/node_modules trees from
  an untrusted run. Confidence: high.

## Terminal-tree reconciliation — 2026-08-14

P11.1 re-opened the same eight primary sources and reconciled them against locked Pest 5.0.4,
Laravel 13.25.0 and the terminal tree `48aa8f58`:

- Five full no-TIA runs were green with identical 1,569-name/status sets; median wall time 89.97 s.
- Fresh TIA recorded the same set in 114.65 s; a no-change replay took 22.59 s and reported all
  1,569 tests as replayed. This confirms useful local feedback and also why replay cannot be CI or
  item acceptance evidence.
- The current `always()->locally()` chain remains contrary to the documented alternative modes.
  P11.2 must reduce it to local TIA and ordinary acceptance must keep `--ci --no-tia`.
- Explicit 2/4-process Pest discovery is currently unusable: all three probes per count fail on the
  existing `use RuntimeException` warning. P11.1 did not suppress it or mutate tests.
- Complete 2- and 4-shard unions were green, unique and exact-name/status equal to serial. Two shards
  are the smallest provisional D40 candidate (45.48-second critical path; 100.8% runner cost), but
  P11.2 still owes three clean repetitions and P11.3 isolation proof.
- PCOV Clover measured 88.27% statements and 66.80% methods. Risk closure remains matrix-based; a
  high aggregate percentage cannot close D41/D42 or the partial D34/D40 rows.

The official-source verdicts above remain current as checked 2026-08-14. Exact GitHub Action commit
SHAs and the private-package credential path remain intentionally unselected until P11.5 inspects the
then-current official action releases and trusted repository environment.

## Reconciliation verdict

P11 is not a Pest upgrade. It is a sequential evidence-and-hardening phase over an already upgraded
stack: terminal measurement → correct local TIA/empirical concurrency → destructive-resource and
agent-output guards → risk-based missing tests → hardened CI/shared TIA baseline → final coverage,
flake and performance gates. Parallelism/sharding are selected only when repeated no-TIA runs prove
both safety and useful wall-time reduction. Browser coverage continues through D24 Playwright.

## Verification status

- RAG: known official pages opened directly through Web RAG on 2026-08-14, 8 primary sources, OK. Context7 and
  Perplexity were not connected in this session; no external claim above rests on a secondary source.
- Проверено P11.1: terminal P0/P1–P10 endpoints, locked graph, runner fingerprint, suite inventory,
  serial/TIA/parallel/shard/profile/coverage behavior and raw hashes.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact GitHub Action SHAs, private-package CI credential path and
  live workflow runs — intentionally owned by P11.5.
- [POSSIBLE-DEFECT]: parallel discovery promotes the redundant global `use RuntimeException` in
  `tests/Unit/Llm/GeminiResponseSchemaAdapterTest.php:11` to an ErrorException. Repair/remeasurement
  belongs to P11.2; no warning suppression or product mutation was used here.
