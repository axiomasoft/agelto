# HANDOFF — 2026-08-08 — after P8.3

**Next:** ЗАПУСК ВРУЧНУЮ: P8.4 в новой сессии. Текущая сессия P8.4 не запускала.

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — manual Layout-v2 item | P8.4 выполняет пост-аудит схемы и синхронизирует описание; код P8.3 уже закрыт. | Вручную по Layout v2. Не вызывать `$ task:plan-audit`/`$ task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.4.md and plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.4.state.md. This is Layout v2. Execute only P8.4 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P9. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes.'
```

**Done:** P8.3 закрыт `🟠 Done with deviations`. Поглощены последние 10 догоняющих миграций
AI/LLM/сметного контура, включая кросс-контурные `100069`/`100070`. В каталоге осталось ровно
63 миграции: 57 `create_*` и шесть разрешённых инфраструктурных операций; табличных
`add|drop|widen|declare` и кросс-контурных файлов — 0.

**D82:** полностью удалены `prc_daily_stats`, `prc_regions`, `prc_ignore_rules`: три create-
миграции, модели, фабрики, IgnoreRules Filament CRUD, `IgnoreRulePermission` и грант роли
оператора. `prc_ignored_items` сохранена. Grep по снесённым таблицам/классам в `app/` и
`database/` пуст.

**Q16:** D130 воплощён без новых колонок: 55 полей остаются в
`prc_procurements.attributes`. `prc_documents.kind` уже существует и schema-change не требует.

**Schema proof:** все 18 таблиц AI/LLM/сметного контура совпали со снимком P8.1 по
`cols/idx/fks` — 18/18, расхождений 0. Общий результат P8.2 остаётся: 70 surviving tables
против baseline 73, единственные три delta — утверждённый D82-снос (`prc_daily_stats`,
`prc_ignore_rules`, `prc_regions`).

**Validation:** fresh+seed GREEN после каждой из трёх групп; полный `migrate:reset` и повторный
fresh+seed GREEN; full suite — 1 skipped / 1392 passed / 5856 assertions GREEN (root workaround);
Pint — 1039 files GREEN; PHPStan — 639 files, 0 errors; `git diff --check` GREEN. Первый full
run штатным пользователем упал 284 permission-errors на `storage/framework/testing/disks/**`,
после handoff-workaround `-u root` весь прогон GREEN. P8.3 bundle regenerate/check и все
`state --all`/`decisions`/`status` regenerate/check GREEN. Marketplace-resolved plan-lint
остаётся fail-closed на 30 staged future/legacy defects; диагностик P8.1–P8.3 нет.

**Known deviation:** два P8.3 FK нельзя физически объявить в собственных ранних create-файлах
без запрещённой D81 перенумерации: `prc_ai_dataset_examples.fixture_id` привязан в поздней
`100053_create_prc_ai_fixtures`, а `llm_calls.analysis_run_id` — в
`100059_create_prc_ai_analysis_runs`. Оба `down()` симметрично снимают constraint; reset/fresh
проверил оба пути. Аналогичные два P8.2 ordering deviation остаются задокументированы там.

**Commits:** item `d57f259a` (`refactor(db): схлопнуть AI-миграции (P8.3)`);
bookkeeping — текущий коммит.

**Dirty-worktree attribution:** item-коммит включает только 11 create-миграций AI/LLM/сметного
контура и удаление 10 поглощённых catch-up файлов. Предсуществующие незакоммиченные application,
tests, docs и Layout-v2 migration worktree сохранены.

**Remaining:** P8.4–P9. P8.4 этой сессией не запускался.

**Sources of truth:** `phases/P8/P8.3.md` + `P8.3.state.md` ·
`findings/P8-schema-revision.md` · `artifacts/P8-schema-baseline.json` · D130 · `journal.jsonl`.

**Open risks/workarounds:** Compose требует временный `MINIO_ROOT_PASSWORD`; artisan выполнялся
от root из-за прав на cache/testing disks. Полный plan-lint остаётся красным на сохранённых 30
staged future/legacy defects.
