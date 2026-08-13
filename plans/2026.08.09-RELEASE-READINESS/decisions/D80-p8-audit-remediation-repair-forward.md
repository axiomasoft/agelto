---
id: D80
date: 2026-08-13
status: superseded
item: P8.6
items: [P8.2, P8.3, P8.4, P8.5, P8.6]
supersedes: []
superseded_by: D81
---
## Решение

RED-аудит P8, заход 1, закрывается ровно одним bounded repair-forward item P8.6. Он не
редетализирует P8.1–P8.5 и не меняет их product contracts: `migration-version` повышается
`0.1.9` → `0.1.10`, добавляется nonterminal P8.6, а Phase Handoff получает stale marker.
Исторические audit/journal/commits/specs не переписываются; state, bundle, decision, status and
closure views materialize only through Task owners. RAG:— (repo-grounded: Audit P8 round 1,
owner payload 2026-08-13 and Layout v2 projection contract).

P8.6 owns the exact factory/test/carrier/architecture/docs contour listed in its Files. Default
`ProcurementFactory` must no longer create repeated `Registry::factory()->eis()` identities;
`RegistryFactory::eis()` remains an explicit EIS fixture. One committed candidate restores the
full no-TIA baseline, proves colliding-registry safety, completes literal P8.5 acceptance, and
records the candidate SHA/counts. The correction must name P8.2 product commit `b27a5c09`, the
P7.6 baseline at `journal.jsonl:307`, and the actual P8.5 `frontier/medium` route; it must not
retroactively call the regression pre-existing or fabricate route evidence. RAG:— (repo-grounded:
Audit P8 findings 1, 4, 5 and 7; `research/23-P8-audit-remediation.md`).

Only after this candidate is GREEN may append-only corrections add canonical write-site/downstream
records for P8.2/P8.4/P8.5, regenerate P8.3 and all bundles/views, and replace the stale rolling
handoff. P8.6 then returns the phase to independent Audit P8, заход 2; it never starts P9 or
closes P8 directly. RAG:— (repo-grounded: Audit P8 findings 2, 5 and 6; Task phase-audit policy).

## Почему

The collision, incomplete carrier and false evidence all describe the same P8 candidate boundary:
separating them would leave either an unusable full suite or an unanchored acceptance claim.
Historical P8.2/P8.5 facts remain auditable while one new owner restores the current release gate.
RAG:— (repo-grounded: Audit P8 round 1).

## Alternatives

1. Mark the 196 full-suite failures pre-existing. Rejected: P7.6 gives a contrary baseline and
   the regression is introduced by P8.2.
2. Rewrite P8.2/P8.5 specs, state or journal. Rejected: it destroys append-only provenance.
3. Add a real platform or modify D35/D36. Rejected: neither repairs the factory or acceptance seam.
4. One P8.6 factory/acceptance/evidence carrier, followed by Audit P8 round 2. Chosen.

## Consequences

The plan contains 83 items. P8.6 executes `implementation/high`, `manual`, `Review=full` on one
committed candidate plus a separate bookkeeping commit. P8.2 may retain its named historical
Files deviation and P8.5 its actual route deviation; P8.6 cannot convert either into a fabricated
green historical fact. P9 remains blocked until terminal P8.6 and GREEN Audit P8 round 2.
RAG:— (repo-grounded: `phases/P8/P8.6.md` and Audit P8 round 1).
