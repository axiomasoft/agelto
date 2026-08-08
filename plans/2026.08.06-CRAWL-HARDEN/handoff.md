# HANDOFF — 2026-08-08 — after P8.2

**Next:** ЗАПУСК ВРУЧНУЮ: P8.3 в новой сессии. Текущая сессия P8.3 не запускала.

| Model | Thinking | Context | Суть | Команда |
|:--|:--|:--|:--|:--|
| frontier | high | NEW SESSION — manual Layout-v2 item | P8.3 поглощает только AI/LLM/сметную часть и удаляет `100069`/`100070` после последних строк. | Вручную по Layout v2. Не вызывать `$ task:plan-audit`/`$ task:plan-exec` и не создавать v1 carriers, пока Task-плагин остаётся v1. |

Native Codex launch-block:

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' -a never -s danger-full-access 'Read AGENTS.md, plans/2026.08.06-CRAWL-HARDEN/handoff.md, plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.3.md and plans/2026.08.06-CRAWL-HARDEN/phases/P8/P8.3.state.md. This is Layout v2. Execute only P8.3 manually; do not invoke task:plan-audit, task:plan-exec, create v1 carriers, or start P8.4. Resolve the Swissknifeman marketplace root and run the v2 bundle plus the lint/view preflight from AGENTS.md before work. Preserve unrelated dirty worktree changes.'
```

**Done:** P8.2 закрыт `🟠 Done with deviations`. Поглощены 17 закупочных/краулинговых
догоняющих миграций; кросс-контурные `100069`/`100070` оставлены на диске только с AI/LLM/
сметными строками. В каталоге осталось 73 миграции: 57 `create_*`, 10 табличных догоняющих
AI-контура и шесть инфраструктурных операций.

**D82:** полностью удалены `prc_daily_stats`, `prc_regions`, `prc_ignore_rules`: три create-
миграции, модели, фабрики, IgnoreRules Filament CRUD, `IgnoreRulePermission` и грант роли
оператора. `prc_ignored_items` сохранена. Grep по снесённым таблицам/классам в `app/` и
`database/` пуст.

**Q16:** D130 воплощён без новых колонок: 55 полей остаются в
`prc_procurements.attributes`. `prc_documents.kind` уже существует и schema-change не требует.

**Schema proof:** после `migrate:fresh --seed` текущая БД содержит 70 обычных/родительских
таблиц против baseline 73. Для всех 70 surviving tables `cols/idx/fks` совпали 70/70; ровно три
расхождения — отсутствие утверждённых D82 таблиц (`prc_daily_stats` 16/3/1,
`prc_ignore_rules` 9/2/1, `prc_regions` 7/2/1). Других расхождений нет.

**Validation:** `migrate:fresh --seed --no-interaction` GREEN; полный `migrate:reset` и повторный
fresh+seed GREEN; `SchemaHardening|PartitionRetention|CsvBlockIngestor` — 19 tests / 66
assertions GREEN; full suite — 1 skipped / 1392 passed / 5856 assertions GREEN; Pint — 1049
files GREEN; PHPStan — 639 files, 0 errors; `git diff --check` GREEN. P8.2 bundle regenerate/check
GREEN. Marketplace-resolved full `plan-lint --baseline HEAD` остаётся fail-closed на 32 staged
future/legacy read-admission defects, но диагностики P8.2 больше нет. `state --all`, `decisions`,
`status` regenerate/check — GREEN.

**Known deviation:** два FK нельзя физически объявить в собственных ранних create-миграциях без
запрещённой D81 перенумерации: `prc_procurement_parties` создаётся до `prc_procurements`, а
`prc_revisions` — до `prc_crawler_runs`. Поэтому FK объявлены сразу после появления поздних
родителей в `100011_create_prc_procurements` и `100039_create_prc_crawler_runs`; их `down()`
симметрично снимает FK перед удалением родителя. Reset/fresh проверил оба пути.

**Commits:** item `4d499f29` (`refactor(db): схлопнуть закупочные миграции (P8.2)`);
bookkeeping — текущий коммит.

**Dirty-worktree attribution:** item-коммит включает только P8.2 migrations/models/factories/
IgnoreRules/RBAC. Предсуществующие незакоммиченные application, tests, docs и Layout-v2 migration
worktree сохранены; незакоммиченная `100087_add_succeeded_at...` была обязательным входом карты
P8.1 и осознанно поглощена в базовую `prc_procurements` вместе с остальными catch-up файлами.

**Remaining:** P8.3–P9. P8.3 этой сессией не запускался.

**Sources of truth:** `phases/P8/P8.2.md` + `P8.2.state.md` ·
`findings/P8-schema-revision.md` · `artifacts/P8-schema-baseline.json` · D130 · `journal.jsonl`.

**Open risks/workarounds:** Compose требует временный `MINIO_ROOT_PASSWORD`; artisan выполнялся
от root из-за прав на cache. Полный plan-lint остаётся красным на сохранённых 32 staged defects.
