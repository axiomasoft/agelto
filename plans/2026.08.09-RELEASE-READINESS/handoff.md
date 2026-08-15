# HANDOFF — 2026-08-15 — after P12

**Next:** design-phase: task:plan-design 2026.08.09-RELEASE-READINESS P12

| Параметр | Значение |
|:--|:--|
| Batch | n/a |
| Model | frontier |
| Thinking | high — перепроектировать границу P12 |
| Context | cold-start-root: capability-missing |
| Суть | По D89 превратить P12.2–P12.7 в единый локально проверяемый preparation batch. |

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS P12'
```

**Done:** P12.1 product commit `c8145ee5` adds a concise Ubuntu 24.04 host carrier. D89 and the
owner refinement record the new local-preparation boundary before remaining P12 execution.

**Remaining:** Re-design P12.2–P12.7 into one batch of R&D-backed instructions, templates,
scripts and local contract checks, then execute that batch in one fresh root.

**Sources of truth:** D89 · `brief/01-refinements.md` (2026-08-15 directive) · P12 Phase Context ·
`research/14-P12-execution-design.md` · `journal.jsonl` P12.1 terminal.

**Open risks:** Historical P12.1 evidence and state are append-only; D89 does not turn them into
server proof. The future operator test plan owns all real runtime evidence.

**Workarounds/Deferred/Open questions:** workarounds: — · deferred: real operator deployment and
rehearsal in a separate follow-up plan · open_questions: —.
