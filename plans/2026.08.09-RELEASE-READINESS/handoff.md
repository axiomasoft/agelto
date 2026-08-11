# HANDOFF — 2026-08-11 — after P4.2

**Next:** design-item: task:plan-design 2026.08.09-RELEASE-READINESS P4.2

| Параметр | Значение |
|:--|:--|
| Model class | frontier |
| Effort | high — scope and acceptance reconciliation |
| Capabilities | — |
| Context | NEW SESSION — шаг-не-item |
| Суть | Разрешить VitePress source-corpus blocker в P4.2 без неявного расширения scope. |
| Команда | `$ task:plan-design 2026.08.09-RELEASE-READINESS P4.2` |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-design 2026.08.09-RELEASE-READINESS P4.2'

РЕЖИМ: remediation — уточнить P4.2 Files/Scope/Validation для source-corpus blocker
ВХОД: plans/2026.08.09-RELEASE-READINESS/findings/P4.2-vitepress-build-blocker-2026-08-11.md → весь файл; прочитать первым
СКОУП: 1) выбрать минимальный разрешённый remedy для malformed `docs/03_Dev/recipes.md` 2) синхронизировать P4.2 spec, bundle и acceptance 3) не менять generator/storage/auth contracts D26/D27
НЕ ТРОГАТЬ: P4.3–P4.5, production serving/auth code, public docs boundary и несвязанный documentation content
```

**Done:** P4.2 pre-mutation contract разрешён и item-owned validation запущен; VitePress source-corpus
blocker зафиксирован в `findings/P4.2-vitepress-build-blocker-2026-08-11.md`. Product files не
закрыты и не коммитятся.

**Remaining:** `plan-design` должен разрешить blocker, затем повторный `task:plan-run` P4.2 выполняет
build/verifier/image contract.

**Sources of truth:** `phases/P4/P4.2.state.md` · `journal.jsonl` ·
`findings/P4.2-vitepress-build-blocker-2026-08-11.md` · D26 · D27.

**Open risks:** Current committed `docs/` is not VitePress-buildable; widening the generator allowlist
or silently changing source content would violate P4.2 scope and the frozen private-documentation contract.

**Workarounds/Deferred/Open questions:** Workarounds — none. Deferred — P4.2 implementation until
the blocked source-corpus scope is redesigned. Open questions — the minimal approved source remedy.
