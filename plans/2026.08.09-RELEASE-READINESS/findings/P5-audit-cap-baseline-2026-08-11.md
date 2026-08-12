# P5 AUDIT-CAP baseline — 2026-08-11

## Dirty worktree classification

До plan-design рабочее дерево Agelto содержало ровно пять tracked changes:

- `AGENTS.md` — независимое owner/project изменение версии native Task `0.10.0` → `0.11.1`;
  remediation его не откатывает и не включает в свои commits.
- `journal.jsonl`, `phases/P5/P5.md`, generated `phases/P5/P5.closed.md` и generated
  `status.md` — единый незакоммиченный результат второго Audit P5: append-only
  `audit-attention`, блок `round 2` и свежие projections. Эти байты являются входом reopen и
  сохраняются, generated carriers вручную не редактируются.

В Swissknifeman уже существовало отдельное dirty дерево: plan carriers двух других планов,
`roadmap/README.md`, пять новых roadmap-notes и generated `packages/task/docs/.vitepress/dist/`.
Они не относятся к P5.6 и не могут быть изменены, добавлены в индекс или включены в commit.
RAG:— (repo-grounded: `git status --short` обоих repositories, 2026-08-11).

## Machine-current preflight

Обязательная цепочка из marketplace root на исходных байтах дала:

- `plan-lint.py plans/2026.08.09-RELEASE-READINESS` — `20 ERROR / 0 WARN`;
- `plan-views.py state --all --check` — 80 views fresh;
- `plan-views.py decisions --check` — 4 views fresh;
- `plan-views.py status --check` — 1 view fresh;
- source-tree `plan-lint.py --conflicts` — `OK (0 расхождений, 0 предупреждений)`.

Двадцать lint-errors делятся на четыре закрытых класса:

1. один неполный scope-payload у Audit P5 round 2;
2. шестнадцать downstream errors: три missing-current records P5.3 и тринадцать RED records
   P5.4/P5.5;
3. две `RETURN_LOOP_QUALIFICATION_REQUIRED` для resolved P5.2/P5.3 design returns;
4. текущая decision queue машинно разрешена: `_gates.md` сообщает отсутствие unresolved gates,
   request/claim/response P2.1 сохранены append-only; финальный gate обязан доказать это снова.

RAG:— (repo-grounded: Task preflight output 2026-08-11, `decisions/queue.jsonl`,
`decisions/_gates.md`).

## Reproduced root causes

- P5.5 commit `54634414` изменил `CardCollectDetailsTest.php` и
  `ProxyAutoDisableTest.php` вне его `Files`. Оба изменения функционально нужны: первое закрепляет
  новый общий direct-route log, второе передаёт обязательный `CrawlerProxySettingsResolver`.
  Простой revert снова ломает current product contract; новый item должен принять эти paths,
  подтвердить поведение и append-only снять текущую deviation. RAG:— (repo-grounded:
  `git show 54634414 -- <two paths>`).
- Native standalone Task layout не содержит monorepo path
  `packages/skiller/skills/general/plan-protocol/SKILL.md`; `_find_skill()` знает только этот
  source topology. Исправление принадлежит canonical Swissknifeman source/runtime projection,
  а не `~/.codex/plugins/cache/**`. RAG:— (repo-grounded:
  `packages/task/lib/task/plan_lint_checks/conflicts.py`, Audit P5 round 2).
- Current downstream parser всё ещё считает отдельными командами inline prose вроде
  `status => DocumentStatus::Unprocessable`, `eis|fz44|fz223`, `## Verification status` и
  path-less future commands. Это нарушает D63: исполним только один item-owned carrier из Files;
  отсутствующий carrier даёт `unknown`. RAG:— (repo-grounded:
  `packages/task/lib/task/plan_downstream.py`, machine command extraction for P5.3–P5.5).
- Fingerprints `4c8e71…` (P5.2/D74) и `7a4a20…` (P5.3 scoped redesign) имеют по одному
  occurrence и уже разрешены продуктом/решением, но не имеют qualification evidence carrier.
  RAG:— (repo-grounded: journal lines 194–202 and `plan-views.py returns --fingerprint`).

После обязательного append-only reopen event `plan-design · P5` return projector выявляет ещё
один runtime defect: phase-level `audit-attention` ошибочно соединяется с design event как
`ungroupable` fingerprint, но frozen schema не принимает этот friction/result class. Journal
переписывать нельзя; P5.6 исправляет projector/schema boundary и закрепляет phase AUDIT-CAP case
focused regression. RAG:— (repo-grounded: journal lines 217–218 and `task.plan_returns.returns()`).
