# HANDOFF — 2026-08-13 — after P9.1

**Next:** manual: implementation/high

| Параметр | Значение |
|:--|:--|
| Batch | solo |
| Model | implementation |
| Thinking | high — public package presentation contract |
| Capabilities | — |
| Context | continue-root |
| Суть | Реализовать D82/D38: понятную русскую навигацию, полную видимую локализацию AzGuard и единый label contract без изменения маршрутов и прав. |
| Команда | — |

#### Provider-neutral launch-block

```text
$ task:plan-run 2026.08.09-RELEASE-READINESS P9.2
```

#### Native Codex launch-block

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="high"' -a never -s danger-full-access '$ task:plan-run 2026.08.09-RELEASE-READINESS P9.2'
```

**Done:** P9.1 закрыт `🟢 Done`. Item commit `49016c1d` заморозил D82: семь
человеко-ориентированных групп, естественные названия и purpose copy, exact 58-route baseline,
carrier-level schema/table/action/auth/test matrices. D37 superseded только в navigation/copy части;
URL, permission, policy, plugin и hidden-page identity сохранены. Full review после remediation —
PASS.

**Remaining:** P9.2 реализует package/app label contract; P9.3–P9.6 последовательно закрывают
Sections/help, responsive tables, runtime route/role sweep и browser acceptance.

**Sources of truth:** `plans/2026.08.09-RELEASE-READINESS/plan.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P9/P9.md` ·
`plans/2026.08.09-RELEASE-READINESS/phases/P9/P9.2.md` ·
`plans/2026.08.09-RELEASE-READINESS/findings/P9-filament-surface-recon-2026-08-09.md` ·
`plans/2026.08.09-RELEASE-READINESS/research/11-P9-execution-design.md` · D82 · D38.

**Open risks:** P9.2 touches the public AzGuard presentation config and private-package pin; English
defaults, arbitrary overrides, installed-vendor prohibition and unchanged route/permission behavior
are mandatory gates. The current Doctor page remains panel-only evidence for the P10 auth audit.

**Workarounds/Deferred/Open questions:**

- workarounds: —
- deferred: Filament Cluster for «Настройка AI» may be reconsidered only post-release with explicit
  URL redirects/bookmarks/auth/browser migration design; P9 keeps current URLs.
- open_questions: —
