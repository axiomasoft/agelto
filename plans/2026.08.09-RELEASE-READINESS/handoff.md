# HANDOFF — 2026-08-15 — after P12.7

**Next:** audit-phase: task:plan-audit 2026.08.09-RELEASE-READINESS P12

| Параметр | Значение |
|:--|:--|
| Batch | B12-PREP |
| Model | implementation |
| Thinking | high — complete-max исполнение security/deploy/recovery scripts, research и cross-carrier docs |
| Context | continue-root |
| Суть | Проверить terminal local preparation batch перед штатным закрытием P12. |

**Native Codex command:**

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="xhigh"' -a never -s danger-full-access '$ task:plan-audit 2026.08.09-RELEASE-READINESS P12'
```

**Done:** P12.2–P12.7 local carriers are green and their evidence templates remain unfilled; the
aggregate report is `PREPARATION_READY`, never a production D45 verdict.

**Remaining:** Audit P12, then create a separate operator rehearsal plan for real external proof.

**Sources of truth:** `plan.md` · `phases/P12/P12.md` · P12.2–P12.7 specs ·
`decisions/D89-p12-local-preparation-boundary.md` · `decisions/D90-p12-complete-max-no-external-block.md` ·
`findings/P12-local-preparation-rag-2026-08-15.md` ·
`research/25-P12-local-preparation-redesign.md` · `roadmap.md`.

**Open risks:** Exact Ubuntu 24.04 Certbot installation selector and pinned Community MinIO/`mc`
metadata matrix require future disposable runtime verification; current P12 does not depend on or
claim those facts.

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: all real host/DNS/GitHub/registry/CI/TLS/deploy/backup/alert/rotation/rollback/restore/browser evidence and D45 verdict belong to the follow-up rehearsal plan
- open_questions: —
