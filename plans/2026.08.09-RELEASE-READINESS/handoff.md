# HANDOFF — 2026-08-11 — after P4.3

**Next:** run-items: task:plan-run 2026.08.09-RELEASE-READINESS P4.4

| Параметр | Значение |
|:--|:--|
| Model | implementation |
| Thinking | high — AzGuard access boundary |
| Context | continue (/clear) — ручной item |
| Суть | Заменить fail-closed placeholder на enum-aware AzGuard и добавить Filament discovery без второго route. |
| Команда | — |

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P4.4'
```

РЕЖИМ: execution — AzGuard authorization и Filament discovery
ВХОД: `plans/2026.08.09-RELEASE-READINESS/plan.md` → `phases/P4/P4.md` → `phases/P4/P4.4.md` → `bundles/P4.4.json` → `phases/P4/P4.4.state.md`

**Done:** P4.3 добавил configured private artifact root, строгий manifest-backed locator и immutable
asset value. Единственный GET/HEAD route/controller остаётся fail-closed до P4.4; ответный путь
выставляет declared MIME, no-store и browser-security headers. Item-owned carrier прошёл DB preflight,
focused Pest, Pint, PHPStan, route topology и diff check.

Product commit: `d8b9ed77` — `feat(documentation): закрыть private serving path (P4.3)`.

**Remaining:** P4.4 должен заменить literal fail-closed placeholder на scoped
`DocumentationPermission::View`, не создавая middleware/controller/asset bypass, и доказать grant
matrix с Filament navigation.

**Sources of truth:** `phases/P4/P4.3.state.md` · `phases/P4/P4.4.md` · `journal.jsonl` · D27 ·
`app/Services/Documentation/PrivateDocumentationLocator.php` ·
`docker/tests/private-documentation-response-contract.sh`.

**Open risks:** До terminal P4.4 documentation route отвечает `403` до filesystem и не является
доступным production documentation surface; P4.4 обязан сохранить эту fail-closed гарантию во время
замены на AzGuard.

**Workarounds/Deferred/Open questions:** Workarounds — test-only configuration override открывает
P4.3 controller branch; production config literal остаётся false. Deferred — P4.4 permission/nav и
P4.5 terminal acceptance. Open questions — none.
