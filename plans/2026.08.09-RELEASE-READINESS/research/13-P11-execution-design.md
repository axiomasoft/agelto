# P11 execution design — measured Pest topology, isolation and hardened CI

## Sources

- `findings/P11-pest-ci-recon-2026-08-09.md` — current repo/locked-stack inventory and official
  Pest 5, Laravel 13, PAO and GitHub Actions facts.
- `plans/archive/2026.08.01-L13-PEST5.md` — historical D4–D10 provenance for PCOV, TIA cache,
  no-TIA acceptance and the agent plugin.
- D24/D25 and P3.4/P9.6 — the one Playwright/isolated E2E carrier.
- D39 and P10.6 — the local quality/security/docs commands P11 integrates, not duplicates.
- Owner brief §10 and `research/00-user-intent.md` — fast honest agent feedback, full risk-level
  coverage and CI without weaker isolation.

## Alternatives and decisions

### TIA and acceptance

- Keeping `always()->locally()` and using replay in CI is rejected: current official Pest docs call
  the methods alternatives and require the ordinary CI pipeline to run every test.
- Disabling TIA entirely is rejected: the installed PCOV/volume carrier already provides a useful
  local edit loop.
- D40 chooses `locally()` plus a separate trusted TIA-baseline workflow; `baselined()` is added only
  with that workflow. Item/phase acceptance and ordinary CI use `--ci --no-tia`.

### Parallel and sharded execution

- «Use all cores» is rejected: current DB/filesystem/log/global-state surfaces are not yet proven
  process-safe and total runner cost may grow without wall-time benefit.
- Serial-only by policy is also rejected: Pest/Laravel expose first-party process/shard carriers and
  the suite is large enough to measure them.
- D40 selects the smallest stable process count from serial/2/4 and the smallest stable shard count
  from 1/2/4. A candidate is adopted only after three clean no-TIA repetitions, zero divergent test
  names/statuses and at least 20% median wall-time improvement without more than 125% total
  runner-minutes. Otherwise the honest product is serial execution plus the benchmark evidence.
  RAG:— (internal selection policy over official capabilities captured in the P11 finding).

### Isolation and output

- Suffix-only DB checking is rejected for parallel mode: it cannot prove that the database is the
  exact project test database for the active Laravel process token.
- Per-test ad hoc `Storage::fake()` is retained for behavior tests but rejected as the global safety
  boundary: an omitted fake must still be unable to reach S3 or production paths.
- D41 chooses three lines: forced bootstrap values before autoload; exact serial/tokenized DB and
  process-scoped local filesystem roots in the booted config; sabotage tests that fail before any DB
  or filesystem mutation. PAO remains the only agent formatter, with all supported Codex/Claude/
  Cursor markers forwarded into containers.

### CI, caches and quality floors

- Reimplementing local commands in YAML is rejected: P1/P10 own executable Make/Composer carriers.
- Caching ready-to-execute `vendor`, `node_modules`, env/auth/TIA/test-state or secrets is rejected:
  trust and provenance are wider than their lockfiles.
- D42 chooses thin SHA-pinned workflows with read-only permissions, lock/runtime-keyed download
  caches, isolated full tests, the existing Playwright stack, separate coverage/security/image jobs
  and a trusted TIA-baseline artifact. A risk matrix, not raw coverage percentage, decides what tests
  must be added. Final hard floors are global line coverage ≥70%, every configured critical file
  ≥55%, zero uncovered critical/high matrix rows, and no regression from the higher measured P11.4
  baseline. Performance budgets use five clean measurements; each hard threshold is the greater of
  `median × 1.50` and `median + 5s`, then is committed with runner fingerprint and commands. RAG:—
  (internal release policy; official CLI/security carriers are captured in the P11 finding).

## Item topology

| Item | Hard input | Product | Downstream gate |
|:--|:--|:--|:--|
| P11.1 | terminal P0/P1–P10 product tree | immutable serial/TIA/parallel/coverage/profile baseline and initial risk matrix | no optimizer/CI choice before measurement |
| P11.2 | terminal P11.1 | correct local TIA, safe cache/watch carrier and empirically selected process/shard mode | chosen mode is not accepted until isolation passes |
| P11.3 | terminal P11.2 | exact DB/process filesystem guards, sabotage tests and PAO marker contract | parallel/agent commands may become canonical |
| P11.4 | terminal P11.3 + upstream risk tests/P10 matrix | zero uncovered critical/high risk rows across Unit/Feature/integration/Playwright | CI runs a complete named matrix |
| P11.5 | terminal P11.4 + P1/P2/P10 carriers | hardened CI, dependency caches, security/image/coverage/E2E jobs and trusted TIA baseline | live workflow evidence required by final gate |
| P11.6 | terminal P11.5 | committed coverage/performance/flake budgets, deterministic commands, green CI evidence and final report | P12 consumes the release-quality contract |

All six items are sequential: each consumes the preceding benchmark/config/guard/matrix/workflow
product and P11.2–P11.6 share test/config/Make/CI hotspots. The N≥3 independent criterion is false;
there is no autonomous external-state chain longer than one item. Orchestration is not declared.
RAG:— (repo-grounded: dependency table and plan-protocol criterion).

## Validation strategy

1. Recompute every authoring count/version on the terminal P10 commit; preserve raw JUnit/profile/
   coverage/timing artifacts and test-name sets with runner/tree fingerprints.
2. Run serial no-TIA first; benchmark TIA fresh/replay and explicit 2/4-process candidates without
   letting a cached pass become acceptance evidence.
3. Prove isolation negatively with nonexistent sentinel DB/remote-disk configs and positively with
   serial plus tokenized parallel databases/process roots. Never point sabotage tests at dev/prod.
4. Close the risk matrix row by row. Unit stays pure; Laravel Feature tests cover HTTP/DB/job/
   repository integration; D24 Playwright covers browser execution. No second browser framework.
5. CI calls the same local commands, pins actions by full SHA, uses least privilege, and treats missing
   private-package credentials as blocked—not skipped/green.
6. Final gate repeats random-order serial runs and the selected parallel/shard mode, applies the
   measured coverage/performance floors, exercises deliberate guard/cache/workflow failures and
   requires a real Actions run on the candidate SHA.

## Pre-mortem

| Failure in 6–12 months | Mitigation / owner |
|:--|:--|
| TIA replay is reported as a fresh acceptance run | P11.2 command contract + P11.5 `--ci --no-tia` + P11.6 deliberate replay rejection |
| Parallel workers share a DB, disk, log or auth state | P11.3 exact token/root guard and three repeated parallel runs before D40 selection becomes canonical |
| A cache or privileged trigger leaks a key / executes poisoned bytes | P11.5 least permissions, no `pull_request_target`, trusted cache writes, SHA pins and secret-path negative tests |
| Coverage grows while a release-critical invariant stays untested | P11.4 risk matrix blocks every critical/high row independently of percentage |
| Tight wall-time thresholds flap on hosted runners | P11.6 five-run median, 50%/5s headroom, runner fingerprint and explicit baseline-refresh protocol |

## Verification status

- RAG: official Pest/Laravel/PAO/GitHub sources were re-opened 2026-08-14 and reconciled in
  `findings/P11-pest-ci-recon-2026-08-09.md` against terminal tree `48aa8f58`.
- Проверено P11.1: five-run serial median 89.97 s; TIA fresh/replay 114.65/22.59 s; coverage 88.27%
  statements; exact 2/4-shard unions. Parallel 2/4 is honestly rejected after three deterministic
  discovery failures per count. Provisional P11.2 input is the smallest qualifying 2-shard mode.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: repeated selected-mode proof, exact isolation/PAO behavior,
  action SHAs and live workflow result remain with P11.2–P11.6.
- [POSSIBLE-DEFECT]: redundant `use RuntimeException` blocks current parallel discovery; P11.1 did
  not mutate or suppress it because tests/config are outside this evidence-only item.
