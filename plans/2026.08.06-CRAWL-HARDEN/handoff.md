# HANDOFF — 2026-08-08 — after P8

**Next:** ЗАПУСК ВРУЧНУЮ: frontier/high

P9.1 — в новой сессии. Текущая сессия P9 не запускала.

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P9/P9.1.md and plans/2026.08.06-CRAWL-HARDEN/phases/P9/P9.1.state.md. This is Layout v2. Execute only P9.1 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or execute later P9 items. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes.'
```

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — manual Layout-v2 item | P9.1 начинает ревизию двух деревьев документации; P8 полностью закрыта. | Вручную по Layout v2. Не вызывать `task:plan-audit`/`task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

**Done:** P8.4 закрыт `🟢 Done`; P8.1–P8.4 терминальны. Документация фактической
схемы синхронизирована, исторические v2 sections трёх D82-носителей помечены отменёнными без
переписывания исходного замысла. Item-коммит: `1bba0873`.

**Schema proof:** clean `migrate:fresh --seed` GREEN; `partitions:maintain` GREEN через pg_partman.
Счётная сверка целиком: baseline 73 → current 70; 70/70 surviving tables совпадают по
`cols/idx/FK`, единственные три delta — `prc_daily_stats`, `prc_ignore_rules`, `prc_regions`,
утверждённо удалённые D82. Полная построчная таблица — Completion Notes P8.4.

**Migration/docs proof:** 63 миграции = 57 `create_*` + 6 infrastructure, catch-up
`add|drop|widen|declare` = 0. Прямых ссылок на 27 удалённых файлов в живом коде/доках/тестах/
сидерах = 0; все оставшиеся ссылки `database/migrations/*.php` существуют.

**Validation:** full suite — 1 skipped / 1392 passed / 5856 assertions (root workaround);
Pint — 1039 files GREEN; PHPStan — 639 files, 0 errors; `git diff --check` GREEN.
P8.4 bundle, `state --all` (59), `decisions` (4), `status` (1) regenerate/check GREEN.

**Known deviations:** четыре documented FK ordering-deviation P8.2/P8.3 остаются. Marketplace-
resolved plan-lint остаётся fail-closed на 29 staged P5.3/P6.5/P7/P9 read-admission defects;
диагностик P8.1–P8.4 нет. P8.4 bundle потребовал и получил item-owned v2 repair: prose-only
Completion Notes заменены явными путями `P8.2.state.md`/`P8.3.state.md`. Full suite запускался
от root из-за прав test-storage. Compose preflight встретил конфликт временного replacement-
контейнера с уже healthy canonical `agelto_postgres`; replacement удалён, canonical DB не
изменялась и все проверки прошли.

**Dirty-worktree attribution:** item-коммит содержит только три docs-файла P8.4. Все
предсуществующие application/tests/docs/plan migration изменения сохранены. Bookkeeping —
текущий коммит.

**Sources of truth:** `phases/P8/P8.4.md` + `P8.4.state.md` · `phases/P8/P8.md` ·
`findings/P8-schema-revision.md` · `artifacts/P8-schema-baseline.json` · D82 · D130 ·
`journal.jsonl`.

**Remaining:** P9. P9 этой сессией не запускался.
