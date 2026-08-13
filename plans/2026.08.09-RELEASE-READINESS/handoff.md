# HANDOFF — 2026-08-13 — P10.6 active remediation

## Next

**Next:** continue `$ task:plan-run 2026.08.09-RELEASE-READINESS P10.6` until all declared P10.6 gates are green.

## Current facts

- D86 supersedes the former terminal-block escalation: P10.6 remediation is in scope and active.
- PHPStan runs at `level:max`, with no baseline, ignores, excludes, or level downgrade.
- Full PHPStan has reduced from **1,591 errors / 266 files** to **788 errors / 236 files**.
- Recent committed carriers: `23fdde76` (EIS query boundary), `5f0bd994` (dataset exporter), and `d706ff9f` (fixture metadata).
- Each carrier has focused max-PHPStan, Pint, and relevant Pest evidence; the aggregate final gate is still outstanding.

## Remaining

- Remediate the remaining full-tree PHPStan findings honestly at their data/behaviour boundaries.
- Resolve the actual Rector dry-run findings; do not hide them with configuration.
- Run the full P10.6 quality contract and all final local gates only after the static-analysis backlog reaches zero.
- Regenerate Task state/status views and close P10.6 only after the evidence is green.

## Sources of truth

- `phases/P10/P10.6.md` — unchanged acceptance contract.
- `decisions/D86-p10-6-false-terminal-block-recovery.md` — recovery decision.
- `journal.jsonl`, `phases/P10/P10.6.state.md`, and `status.md` — current plan progress.

## Guardrails

- Never use PHPStan baseline, ignore/exclude, or level downgrade.
- A focused green carrier is progress evidence, not P10.6 completion evidence.
- The user has unrelated dirty files: `CLAUDE.md`, `bundles/P0.7.json`, and `bundles/P1.7.json`; do not stage or modify them.
