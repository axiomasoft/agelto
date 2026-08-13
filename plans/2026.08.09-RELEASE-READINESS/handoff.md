# HANDOFF — 2026-08-13 — after P8

**Next:** audit-phase: task:plan-audit 2026.08.09-RELEASE-READINESS P8

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | frontier |
| Thinking | xhigh |
| Capabilities | — |
| Context | cold-start-root: audit-root-terminal |
| Суть | Провести независимый Audit P8 round 2 по завершённому P8.6 carrier; не закрывать P8 напрямую в P9. |
| Команда | `$ task:plan-audit 2026.08.09-RELEASE-READINESS P8` |

#### Provider-neutral launch-block

```text
task:plan-audit 2026.08.09-RELEASE-READINESS P8
```

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access '$ task:plan-audit 2026.08.09-RELEASE-READINESS P8'
```

**Done:** P8.6 repair carrier is committed in `8caf8acc4990da82357f5ae061726e36176d77e6`:
the canonical EIS factory identity no longer collides, EIS-only fixtures opt in explicitly, and
the platform extension guard/guide/allowlist are reconciled. Its full carrier passed with 1547
tests / 6775 assertions and all plan gates. P8.2/P8.4/P8.5 correction evidence and downstream
records are regenerated; P8.5's historical “pre-existing” verdict is superseded.

**Remaining:** independent Audit P8 round 2. It must verify the bounded remediation and decide
whether P8 may close; no P9 handoff is authorized before that result.

**Sources of truth:** `plans/2026.08.09-RELEASE-READINESS/plan.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.6.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P8/P8.6.state.md` ·
`plans/2026.08.09-RELEASE-READINESS/bundles/P8.6.json` · D80 ·
`plans/2026.08.09-RELEASE-READINESS/research/23-P8-audit-remediation.md`

**Open risks:** P8.6 must not repair a new signal by changing D35/D36, integration behavior or
undeclared P8.1/P8.3/P8.4 paths. The pre-P8.6 P8.5 “pre-existing” verdict is historical false
evidence, not a usable baseline; candidate counts and provenance must be recorded before correction.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: real second platform, global raw-stage scope and generic predicate engine remain outside P8.
- owner_note: P9–P12 and unrelated Pint/PHPStan debt are not P8.6 work.
- open_questions: —
