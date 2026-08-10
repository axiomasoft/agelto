# HANDOFF — 2026-08-10 — after P0

**Next:** exec-items: task:plan-exec 2026.08.09-RELEASE-READINESS P0.7

| Параметр | Значение |
|:--|:--|
| Model | frontier |
| Thinking | medium — детерминированный remediation slice |
| Context | continue (reset the session context) — ручной item |
| Суть | Закрыть F1–F5 одним P0.7 без изменения P1–P12. |
| Команда | `$ task:plan-exec 2026.08.09-RELEASE-READINESS P0.7` |

### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="medium"' -a never -s danger-full-access '$ task:plan-exec 2026.08.09-RELEASE-READINESS P0.7'
```

**Done:**

- RED-аудит P0 захода 1 зафиксирован коммитом `cf5700dc`.
- D62 формально переоткрыл P0 и ограничил remediation одним P0.7; P0.0–P0.6 остаются исторически терминальными.

**Remaining:**

- Исполнить и self-close P0.7, закрыть фазу, затем независимо аудировать P0; P1 не запускать до GREEN.

**Sources of truth:**

- `phases/P0/P0.7.md`
- `phases/P0/P0.md` → `## Audit P0 — 2026-08-10`
- `root/contracts/release-readiness-v1.md`
- `journal.jsonl`

**Open risks:**

- P0.7 обязан сохранить reference/RAG/frozen readiness outputs и исправить только F1–F5.
- `PROMOTE-CANDIDATE` по общему Task write-site carrier остаётся неблокирующим долгом вне P0.7.

**Workarounds/Deferred/Open questions:**

- Workarounds: —
- Deferred: общий Swissknifeman/Task runtime patch и product/future-consumer implementation остаются вне P0.7.
- Open questions: —
