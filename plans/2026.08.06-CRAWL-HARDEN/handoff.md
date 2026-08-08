# HANDOFF — 2026-08-08 — after P9.1

**Next:** ЗАПУСК ВРУЧНУЮ: implementation/medium

P9.2 — в новой сессии. Текущая сессия не выполняла P9.2 и более поздние items.

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-terra -c 'model_reasoning_effort="medium"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P9/P9.2.md and plans/2026.08.06-CRAWL-HARDEN/phases/P9/P9.2.state.md. This is Layout v2. Execute only P9.2 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or execute later P9 items. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes.'
```

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| implementation | medium | NEW SESSION — manual Layout-v2 item | P9.2 заводит docs-integrity gate на baseline P9.1 и переносит `docs/dev/**`; P9.1 полностью закрыт. | Вручную по Layout v2. Не вызывать `task:plan-audit`/`task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

**Done:** P9.1 закрыт `🟠 Done with deviations`. Командный инвентарь на момент исполнения:
84 Markdown-файла; карта покрывает 18/18 переносимых носителей (8 `docs-private`, 9 specs,
1 `docs/dev`); перечень живых ссылок покрывает 37/37 строк обязательного grep. Item-коммит:
`12c2278c`.

**Baseline:** HEAD `c1f071c4`, явно названное dirty execution-time дерево: 84 Markdown-файла,
18 без frontmatter, 158 относительных markdown-ссылок, 0 битых. Команда-однодневка сохранена
прямо в `artifacts/P9-docs-baseline.json`; повторный запуск дал те же 84/18/158/0.

**Map decisions:** private — 6 переносов/2 дубля к удалению; specs 00–06 — только живые факты,
07 — удалить без переноса, 08 — перенести целиком с сохранением §§1–9; `docs/dev` и `.bak`
явно принадлежат P9.2. Research-delta названа: specs выросли 1739 → 1759 строк после P8.4,
дерево 83 → 84 файла, ссылки 150 → 158, broken остались 0.

**Later-item inputs:** P9.4 до исполнения должен адресовать `docs/03_Dev/_index.md` и два
`.claude/runs/**` совпадения обязательного grep; P9.5 — добавить
`docs/03_Dev/document-pipeline.md` в `Files` либо явно передать владельца. Спорные stale-блоки
`open-follow-ups`/`risk-register` помечены `[решает P9.3 при переносе]`; P9.1 их не решил молча.

**Validation:** map 18 = command 18; reference ledger 37 = grep 37; JSON parse GREEN; baseline
rerun 84/18/158/0; `git diff --check` GREEN. Marketplace-resolved P9.1 bundle GREEN. v2 views
regenerate/check GREEN после journal projection.

**Known deviation:** полный plan-lint fail-closed на 25 staged read-admission defects. Четыре
ожидаемых missing-input diagnostics исчезли после появления map/baseline; новых ошибок нет.
Собственная оставшаяся диагностика P9.1 — `docs/specs/v2/08-estimate-extraction.md` оценивается
в 29 633 токена против 25 000 на одно whole-file read; текущая сессия прочитала файл по частям,
не ослабляя gate. P9.1 bundle потребовал три item-owned carrier repair и после них зелёный.

**Dirty-worktree attribution:** item-коммит содержит только
`findings/P9-docs-map.md` и `artifacts/P9-docs-baseline.json`. Ни один файл вне `plans/**` не
изменён P9.1; все предсуществующие application/tests/docs/plan migration изменения сохранены.
Bookkeeping — текущий коммит.

**Sources of truth:** `phases/P9/P9.1.md` + `P9.1.state.md` · `findings/P9-docs-map.md` ·
`artifacts/P9-docs-baseline.json` · `bundles/P9.1.json` · `journal.jsonl`.

**Remaining:** P9.2–P9.8. P9.2 этой сессией не запускался.
