# HANDOFF — 2026-08-09 — after P0.0

**Next:** design-phase: task:plan-design 2026.08.09-RELEASE-READINESS P0

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high |
| Capabilities | web.rag · repository.read |
| Context | cold-start — plan-step |
| Суть | Детализировать P0: repo/reference recon, RAG и заморозка contracts для P1–P12 |
| Команда | $ task:plan-design 2026.08.09-RELEASE-READINESS P0 |

## Native Codex launch-block

    codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS P0'

**Done:** создан Layout v2 skeleton; Design pass 1/3; 13 contract-first фаз и 74 item specs; brief, bootstrap recon, intent trace и D1–D11.
**Remaining:** детализировать P0–P12 по отдельным cold-start design sessions; finish; design audit; исполнение; final clean-room rehearsal.
**Sources of truth:** plans/2026.08.09-RELEASE-READINESS/plan.md · plans/2026.08.09-RELEASE-READINESS/status.md · plans/2026.08.09-RELEASE-READINESS/brief/00-brief.md · plans/2026.08.09-RELEASE-READINESS/research/00-user-intent.md
**Open risks:** внешний RAG ещё не выполнен; точный remote/branch AzGuard не запечатан; предыдущий терминальный план ещё физически не перенесён в archive; его preflight имеет 32 inherited read-budget errors.
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: физическая архивация 2026.08.06-CRAWL-HARDEN — отдельный task:plan-close archive.
- open_questions: технологические развилки RAG принадлежат P0; owner question пока не требуется.
