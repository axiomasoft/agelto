# HANDOFF — 2026-08-08 — after P8.1

**Next:** ЗАПУСК ВРУЧНУЮ: P8.2 в новой сессии. Текущая сессия P8.2 не запускала.

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — manual Layout-v2 item | P8.2 поглощает только закупочно-краулинговую часть по карте P8.1 и применяет утверждённый состав D82/D130. | Вручную по Layout v2. Не вызывать `$ task:plan-audit`/`$ task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.2.md and plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.2.state.md. This is Layout v2. Execute only P8.2 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P8.3. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes.'
```

**Done:** P8.1 закрыт `🟠 Done with deviations`. Командный инвентарь текущего дерева покрывает
ровно 33 не-`create` миграции: 27 табличных и 6 инфраструктурных. Карта поглощения перечисляет
каждую миграцию; `100069` разложена на все 23 индекса, `100070` — на все 28 пар
«таблица/колонка/ON DELETE» с разделением закупочного и AI-контуров.

**Baseline:** `migrate:fresh --seed --no-interaction` зелёный. Точный SQL-снимок покрывает 73
родительские/обычные таблицы `public`; артефакт и повторное чтение БД совпадают 73/73 по
`cols/idx/fks`. Снимок намеренно учитывает существующую незакоммиченную миграцию
`100087_add_succeeded_at...`, поскольку P8.1 обязан фиксировать текущее дерево, но P8.1 этот файл
не менял и себе не присваивал.

**Q16:** владелец утвердил D130 — ни одно из 55 уникальных полей без надёжного носителя не
получает новую колонку; все остаются в `prc_procurements.attributes` (jsonb). Уже существующая
`prc_documents.kind` должна заполняться найденной категорией документа без schema-change.

**Deliverables:** `findings/P8-schema-revision.md` · `artifacts/P8-schema-baseline.json` ·
`decisions/D130-q16-schema-carriers.md` · датированный блок в `brief/01-refinements.md`.

**Validation:** P8.1 bundle GREEN; JSON валиден; карта 33/33; baseline exact match 73/73;
`git diff --check` GREEN. Marketplace-resolved full `plan-lint --baseline HEAD` остаётся
fail-closed, но снизился с 38 до **33** ошибок: пять staged defects разрешились естественно после
появления deliverables P8.1 и исправления read-admission самого P8.1; новых P8.1 diagnostics нет.
`state --all` 59, `decisions` 4 и `status` 1 regenerate/check — GREEN.

**Commits:** item `5d9e59f5` (`docs(plan): зафиксировать ревизию схемы (P8.1)`); bookkeeping —
текущий коммит `chore(plan): закрыть P8.1`.

**Layout-v2 input repair:** bundle первоначально fail-closed на `там же §4`, затем на внешнем
symlink проектного skill-файла. В owning carrier пути research раскрыты явно, обе
кросс-контурные миграции перечислены полными путями; skill `database` был реально прочитан и
оставлен execution rule, но удалён из file-admission списка, потому что symlink резолвится вне
repo root. Финальный bundle GREEN.

**Dirty-worktree attribution:** P8.1 менял только `plans/**`. Чужие tracked/untracked изменения
в application, tests, docs и Layout-v2 migration worktree сохранены. Генерируемые views были
пересобраны согласно AGENTS.md; в bookkeeping-коммит входят только item-local carriers и чистые
tracked handoff/journal changes, без захвата остальных незакоммиченных Layout-v2 файлов.

**Remaining:** P8.2–P9. P8.2 этой сессией не запускался.

**Sources of truth:** `phases/P8/P8.1.md` + `P8.1.state.md` ·
`findings/P8-schema-revision.md` · `artifacts/P8-schema-baseline.json` · D130 · `journal.jsonl`.

**Open risks/workarounds:** Compose metadata не показывает canonical `agelto_postgres`, хотя
контейнер healthy; Compose требует временный `MINIO_ROOT_PASSWORD`; artisan выполнялся от root из-за
прав на cache. Полный plan-lint остаётся красным на сохранённых 33 staged read-admission defects.
