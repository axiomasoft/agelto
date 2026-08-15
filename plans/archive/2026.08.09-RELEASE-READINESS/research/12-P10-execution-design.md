# P10 execution design — adversarial audit and bounded remediation

## Sources

- `findings/P10-quality-security-recon-2026-08-09.md` — authoring snapshot of tooling and audit
  seeds; counts are advisory until P10.1 repeats them on terminal P9.
- `research/00-user-intent.md` and `brief/00-brief.md` §8 — owner requirement for modern PHP,
  naming, security, data integrity, concurrency and systemic fixes without weakened guards.
- D5 and D29–D38 — shared hotspots and frozen P5–P9 contracts that P10 may verify but not silently
  redesign.
- `.ai/guidelines/{architecture,glossary}.md` plus living architecture/database/static-analysis/
  state-machine docs — project-native invariants.

## Alternatives and decision

### Audit/fix topology

- A: run auto-fixers over the whole tree and review the resulting diff. Rejected: it destroys the
  before baseline, mixes behavior changes with formatting and encourages test-shaped cleanup.
- B: let each audit item fix its own findings immediately. Rejected: severity comparison,
  duplicate root causes and owner boundaries become inconsistent across security/architecture/data.
- C (D39): one immutable baseline, three independent read-only audit reports, one consolidated
  remediation matrix, vertical regression-tested fixes, then one final local-gate/docs item.

### Static-analysis target

- Keeping level 5 plus a growing baseline is rejected by the owner quality brief and existing
  living policy.
- P10 targets PHPStan `max` with no committed baseline. Pint remains the formatter/style gate, but
  there is no blanket `final_class`/mass finalization or wholesale positional-call rewrite.
- Rector is conditional on P10.1 official compatibility evidence for the terminal PHP/Laravel
  graph. Compatible means an exact dev dependency/config and dry-run-only baseline may be adopted;
  incompatible means no placeholder dependency or fake gate. Full-tree blind apply is rejected in
  either branch.

RAG:— (repo-grounded: owner brief §8, `docs/03_Dev/static-analysis.md`, current configs and D39).

## Finding contract

Every audit record has a stable ID, category, severity, confidence, `file:line`, input→sink or
invariant chain, affected frozen D#/doc, public behavior at risk, deterministic reproduction,
recommended owner, exact disposition and regression-test seam. Severity is `critical|high|medium|
low`; confidence is separate and cannot lower severity. Raw grep/tool matches stay `candidate`
until manual verification.

Release blockers are: critical/high security; possible data corruption, race, duplicate side
effect or non-idempotent job; violation of a frozen architecture/security contract; or a red
deterministic P10 gate. Medium/low debt is fixed only when the same root-cause slice is already
open and behavior remains pinned; otherwise it gets an explicit accepted/deferred trigger in the
register. No finding disappears: `fixed|accepted|deferred|false-positive|escalated`, with evidence.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P10.1 | terminal P0/P1–P9 | immutable tool/dependency/baseline manifest and D39 branch evidence | no product mutation before baseline |
| P10.2 | terminal P10.1 | manually verified security report | P10.5 severity matrix |
| P10.3 | terminal P10.2 | architecture/naming/duplication/dead-code debt register | P10.5 severity matrix |
| P10.4 | terminal P10.3 | migrations/transactions/races/jobs/config readiness report | P10.5 severity matrix |
| P10.5 | terminal P10.2–P10.4 | disposition matrix plus vertical fixes and regression tests | final gates see stable product tree |
| P10.6 | terminal P10.5 | PHPStan/Pint/Rector-branch/architecture/security/docs local gates | P11 consumes deterministic carriers |

All items are sequential: each report is a hard input to the next classification/remediation
stage, and P10.5/P10.6 share code/test/config/docs hotspots. The N≥3 independent criterion is
false and there is no autonomous external-state chain; orchestration is not declared.
RAG:— (repo-grounded: dependency matrix and D39).

## Validation strategy

1. Baseline: terminal upstream states, clean product scope, exact tool/dependency versions, Pint,
   PHPStan current/max, dependency audit and optional Rector compatibility/dry-run evidence.
2. Security: deterministic grep inventory plus manual taint/authz/file/cache/secret verification;
   no match-only vulnerability report.
3. Architecture/debt: existing architecture tests, layer/naming canon, git hotspots, duplicate/
   dead/speculative-generalization evidence and impact×effort/trigger disposition.
4. Production readiness: migration/model/schema matrix, transaction and side-effect boundaries,
   concurrent/retry/idempotency proof, config-cache behavior and queue-owner tests.
5. Remediation: one failing public-behavior regression test per finding slice, minimal fix, focused
   green, then full gates; no test/baseline/guard weakening.
6. Final: zero unresolved release blockers, PHPStan max with no baseline, Pint dry check, applicable
   Rector dry-run, architecture/security guards, full P10-focused suites and synchronized living docs.

## Verification status

- Terminal P0/P1–P9 states подтверждены на P10.1; immutable product baseline —
  `469acf640fc95944a3accc378445190dbca2a9ba`. Полные команды, hashes и inventories находятся в
  `findings/P10-static-analysis-audit.md` и проверяются
  `artifacts/P10-static-analysis-contract.sh`.
- Repo-grounded baseline: Pint dry-run red на 7 paths; PHPStan configured red на 34 errors / 13
  paths; Larastan `max` без committed baseline red на 1637 errors / 271 paths; Composer locked
  audit — 0 advisories / 0 abandoned. Эти числа — evidence до mutation, не confirmed-defect
  verdict и не разрешение ослабить gates.
- RAG: Web search/open against official Rector, driftingly and Packagist sources плюс Composer
  metadata/solver cross-check завершены 2026-08-13. Ветка D39 совместима: P10.6 может отдельно
  запинить `rector/rector` 2.6.2 + `driftingly/rector-laravel` 2.5.0 и начать с dry-run;
  P10.1 dependency/config bytes не меняет.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: ничего.
- Обходки/[POSSIBLE-DEFECT]: host PHPStan не загрузил Laravel вне Compose network; authoritative
  configured/max runs выполнены внутри healthy `app` на том же bind-mounted tree.
