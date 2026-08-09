# HANDOFF — 2026-08-09 — after P10.3

**Next:** ЗАПУСК ВРУЧНУЮ: P10.4 — truthful initial/terminal procurement timeline

| Параметр | Значение |
|:--|:--|
| Model | `gpt-5.6-terra` (implementation tier) |
| Thinking | `high` |
| Context | новая сессия, не resume |
| Exec / Review | `manual` / `full` |
| Суть | Выполнить только P10.4 after committed P10.3: initial terminal state must be observable, not displayed as ongoing. |
| Команды | Layout v2 вручную; `task:*` не вызывать; v1 carriers и закрытые P1–P9 specs не править. |

## Copy-paste launch

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plan.md, status.md, findings/P10-code-adversarial-audit-2026-08-09.md, decisions/D135-observed-timeline.md, phases/P10/P10.md, phases/P10/P10.4.md and phases/P10/P10.4.state.md. This is Layout v2. Do not invoke any task:* command; do not create or edit v1 carriers or closed P1-P9 specifications. Manually execute only P10.4. Preserve unrelated dirty worktree changes and do not perform Swissknifeman migrations.'
```

## Preflight baseline

```bash
cd /home/vostrikov/projects/agelto/agelto
codex plugin marketplace list --json
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-lint.py plans/2026.08.06-CRAWL-HARDEN
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py state --plan-dir plans/2026.08.06-CRAWL-HARDEN --all --check
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py decisions --plan-dir plans/2026.08.06-CRAWL-HARDEN --check
python3 /home/vostrikov/projects/packages/swissknifeman/packages/task/scripts/plan-views.py status --plan-dir plans/2026.08.06-CRAWL-HARDEN --check
git status --short --branch
```

Expected baseline after this design pass:

- `state --all --check`: 66 item states;
- `decisions --check`: 4 generated views from 137 source decisions;
- `status --check`: one generated status view;
- complete-plan lint: exactly 27 inherited errors / 0 warnings; no diagnostic points to P10.

## P10.1 closure evidence

1. Item commit `18e6c629` contains enum-backed `RunPurpose`, exact owner resume, typed busy,
   crawler-row serialization and nested SAVEPOINT create recovery.
2. Delta returns `null` on a foreign owner; backlog returns `false` and does not write
   `backlog_rescanned_at`.
3. `tests/Support/Postgres/ConcurrentTransactions.php` coordinates two booted PHP workers with a
   pipe barrier; the regression proves two distinct PostgreSQL backend sessions receive one run.
4. Green: `migrate:fresh --seed`; focused 31 tests / 92 assertions; Pint; PHPStan at a
   process-only 1 GiB limit. Full suite remains red only in unrelated existing AI/EIS tests.

## P10.2 closure evidence

1. Commit `c5860d6b` makes `ListingCursor` the only durable cap-detector carrier with
   backward-compatible `lastDedupSignature=null` and `blocksInInterval=0` defaults.
2. Fresh evaluates an exact repeat after ingest and before reached-known/no-change, including
   after a one-request checkpoint continuation; archive restores its detector per interval and
   clears it only on drain/split/pop.
3. Green: focused 35 tests / 116 assertions; Pint; PHPStan at a process-only 1 GiB limit.
4. Full `--no-tia` retains the pre-existing failures in `EvalRunTest` and `PlatformFormTest`,
   outside P10.2 Files; the root-owned storage workaround is still required.

## P10.3 closure evidence

1. Commit `ea65d384` adds a typed PostgreSQL transaction-scoped advisory lock keyed from the
   complete `(registry_id, registry_number)` identity. A rare 64-bit hash collision only adds
   benign serialization; exact post-lock predicates keep identities separate.
2. The lock is acquired before the first procurement lookup. The locked processor rereads the
   procurement/latest revision, derives the next number from that revision, and fails loudly on
   an inconsistent head instead of treating it as an unchanged row.
3. Two independent booted PHP/PostgreSQL workers prove equal snapshots make one revision, while
   different snapshots make revision numbers `1, 2`, a two-row history and a revision-2 head.
4. Green: focused 15 tests / 77 assertions; `migrate:fresh --seed`; Pint; PHPStan at a
   process-only 1 GiB limit. Full `--no-tia` remains red only outside P10.3 (archive temporary
   storage and seeded-state suites); the documented test-storage ownership workaround was used.

## Current state

**P10:** P10.1–P10.3 are closed with deviations; P10.4–P10.7 are not started. DoR is complete; no owner question remains. Product-code verdict is
`RED` until F1–F8 are closed by P10. Historical delivery/projection `RED` from the prior final
manual audit remains a separate risk and is not silently cleared by P10.

**Done:** durable F1–F8 code audit; D131–D137; P10 phase and seven item carriers; P10.1 run
ownership and PostgreSQL race recovery (`18e6c629`); P10.2 durable cap detection (`c5860d6b`);
P10.3 serial procurement ingest and canonical revision head (`ea65d384`); master plan/index/routing;
manual v2 launch.

**Remaining:** execute P10.4 → P10.7 sequentially; P10.7 replays all eight scenarios and produces
`artifacts/P10-validation.json` plus the final code verdict.

**Sources of truth:** `plan.md` · `status.md` · `journal.jsonl` · `phases/P10/P10.md` ·
`findings/P10-code-adversarial-audit-2026-08-09.md` · D131–D137.

**Open risks:** mixed dirty worktree and missing historical P6.4/P6.5/P9.2–P9.8 commits require
patch-level attribution; ordinary-user tests still encounter inherited root-owned storage paths;
full suite retains unrelated archive temporary-storage and seeded-state failures; Swissknifeman
read-admission rollout is deferred to its owner.
