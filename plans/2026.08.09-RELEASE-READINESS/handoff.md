# HANDOFF — 2026-08-11 — after P4.2

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P4.3

| Параметр | Значение |
|:--|:--|
| Model class | implementation |
| Effort | high — immutable build/security boundary |
| Capabilities | — |
| Context | continue (/clear) — ручной item |
| Суть | Реализовать manifest-backed locator и единственный безопасный Laravel serving path. |
| Команда | `$ task:plan-run 2026.08.09-RELEASE-READINESS P4.3` |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P4.3'

РЕЖИМ: execution — manifest locator и HTTP-only controller
ВХОД: `phases/P4/P4.2.state.md` → `research/06-P4-execution-design.md` → D26 → D27
СКОУП: `config/documentation.php`, locator/value/controller, один GET|HEAD route, focused tests и response-contract carrier
НЕ ТРОГАТЬ: P4.4–P4.5, public/Nginx/X-Accel/signed URLs, role catalog/navigation и dynamic Markdown rendering
```

**Done:** P4.2 published a deterministic VitePress artifact outside `public`, a sorted verified manifest
and an immutable application-image copy; `bash docker/tests/documentation-build-contract.sh` passed.
Product commit: `8f0c14dc404fe3fbe14ddb4cf3e63a701f97a053`.

**Remaining:** P4.3 consumes the committed artifact and implements the manifest-backed serving seam.

**Sources of truth:** `phases/P4/P4.2.state.md` · `phases/P4/P4.3.md` · `journal.jsonl` · D26 · D27.

**Open risks:** P4.3 must serve only exact manifest members and preserve a uniform 404 surface for
missing build, malformed manifest and all unsafe paths.

**Workarounds/Deferred/Open questions:** Known deviations — owner-classified false escalation was
resolved by five bounded docs syntax/link repairs; `frontier/high` was an allowed route overshoot.
Deferred — P4.3–P4.5. Open questions — none.
