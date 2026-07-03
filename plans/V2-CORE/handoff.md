# HANDOFF — 2026-07-03 — after Step 1 (до старта P0)

**Next:** /task:plan-exec V2-CORE P0.1
**Done:** Step 0 (v1 → `Old/`, ветка `feat/v2-scaffold` от `main`), Step 1 (живая разведка 3 рисков + правки схемы `01-database.md`/`02-crawling.md`), план V2-CORE создан (P0 каркас, P1 схема БД).
**Remaining:** Вся Фаза 0 (P0.1-P0.3) и Фаза 1 (P1.1-P1.11) — ничего ещё не начато по коду.
**Sources of truth:** `plans/V2-CORE/plan.md` · `plans/V2-CORE/phases/P0.md` · `plans/V2-CORE/phases/P1.md` · `docs/specs/v2/00-overview.md`…`07-carryover-from-v1.md` · `research/eis-card-recon.md`
**Open risks:** разъяснение документации ("Размещено разъяснение…") как триггер `lastDateChangeCsv` — не подтверждено живым примером (экстраполяция); pg_partman доступность в выбранном Docker-образе Postgres 17 — не проверена (см. P1.9 Escalation Needed при недоступности).
**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: слой контрактов (реестр контрактов/исполнение), ГАР-адресный слой, `api`-метод сбора (Открытые данные ЕИС) — за рамками Фазы 0/1, см. `01-database.md` §12.
- open_questions: P1.10 требует явного решения (Decision Log), создаёт ли `StatusMappingService` автоматически строку в `plt_status_mappings` для неизвестного статуса, или только читает существующие — не зафиксировано в ТЗ.
