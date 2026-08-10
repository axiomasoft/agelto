# HANDOFF — 2026-08-10 — after P0.7

**Next:** close: task:plan-close 2026.08.09-RELEASE-READINESS P0

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | medium — закрыть фазовый reconciliation carrier |
| Context | NEW SESSION — шаг-не-item |
| Суть | Закрыть P0 после P0.7; P1 не запускать. |
| Команда | `$ task:plan-close 2026.08.09-RELEASE-READINESS P0` |

### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-close 2026.08.09-RELEASE-READINESS P0'
```

**Done:**

- P0.7 закрыт item-коммитом `6b567ada`: citation selftest покрывает full matrix, old carrier красен, strict scan зелён, D60 нормализован.
- P0.0–P0.6 reconciled append-only terminal events; states несут `write-site: pre_mutation: allow · pre_final: allow`.
- P0.6 честно различает product commit `52fb69c6` (decisions check RED) и bookkeeping anchor `bf234d23` (GREEN).

**Remaining:**

- Закрыть фазу P0, затем независимо аудировать P0; P1 не запускать до GREEN.

**Sources of truth:**

- `phases/P0/P0.7.md`
- `phases/P0/P0.md` → `## Audit P0 — 2026-08-10`
- `root/contracts/release-readiness-v1.md`
- `journal.jsonl`

**Open risks:**

- P0 phase-close обязан сохранить stale marker D62 до собственного terminal step и не запускать P1.
- `PROMOTE-CANDIDATE` по общему Task write-site carrier остаётся неблокирующим долгом вне P0.7.

**Workarounds/Deferred/Open questions:**

- Workarounds: old carrier `cf5700dc` завершается non-zero при новом selftest-carrier entrypoint; общий Task invariant остаётся PROMOTE-CANDIDATE.
- Deferred: общий Swissknifeman/Task runtime patch и product/future-consumer implementation остаются вне P0.7.
- Open questions: —
