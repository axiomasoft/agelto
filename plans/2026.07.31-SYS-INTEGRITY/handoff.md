# HANDOFF — 2026-08-04 — after P5.2

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/high

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — открытые design-решения о носителе величин, замораживает вход P5.3–P5.8 (§3 Routing P5.3) |
| Context | continue (/clear) — ручной item |
| Суть | P5.3 — решение владельца: носители подтверждённых величин (GAP-2/GAP-8), имена/типы, индексы под сортировку, резолв `work_type_id`, карта следствий |

Сессия сейчас на `sonnet/high` — Routing P5.3 предписывает `frontier/high` (design-item, tier выше
`implementation`): переключение модели ОБЯЗАТЕЛЬНО, код-блок ниже несёт полную D18-форму.

```
/model opus
/effort high
/task:plan-exec 2026.07.31-SYS-INTEGRITY P5.3
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.2 закрыт 🟠 Done with
deviations, item-коммит `576794b`:

- Оба writer-пути (`EstimatePersister`/`RerunRangeAction`) доведены до полноты уже существующей
  схемы: 13/13 колонок класса A run-confirmed в Scope P5.2 (`work_type_id` — Scope Excluded,
  решение P5.3) теперь пишутся — контрольная сверка на реальном 6-файловом gold (реюз зонда P5.1,
  `artifacts/persistence_inventory.php`) показала `db_nonempty == gold_nonempty` по всем 13 (сумма
  1426/1426), было 0 у всех 13 до item'а.
- Единый носитель набора полей позиции — `EstimatePersister::itemAttributes(EstimateRow $row): array`
  (`public static`), читают ОБА writer-пути (Implementation Rules п.2) — точечный re-run больше не
  расходится с полным persist. `attributes` собирается по `RowAttributes::ALLOWED_KEYS` (п.3), не
  перечислением `modifiers` руками.
- `RerunRangeAction::preserveUnrecomputed()` — точечный re-run не затирает
  `unit_price_base`/`total_price_base`/`index_code`/`index_value`/`source_position_number`, если
  ЭТОТ прогон вернул `null` там, где раньше уже было записано значение (Implementation Rules п.5).
- `section_number` удалён целиком (мёртвый параметр, D100) — из
  `EstimatePersister`/`EstimateStoreRepository::findOrCreateSection()`; осиротевшая запись
  `phpstan-baseline.neon` снята следом (62 → 61).
- Полный сюит: 975 passed / 10 failed (pre-existing, не изменились этим item'ом) / 1 skipped.
  `phpstan analyse` — чисто. `pint --dirty --test` — падает только на чужом `config/cms.php`
  (не диф item'а); scoped-прогон на пяти файлах `Files` item'а — чисто.

**Status:** 🟠 Done with deviations (item). Единственное отклонение — item-коммит несёт дифф ВНЕ
`Files` item'а (`phpstan-baseline.neon`, −1 запись): обязательное следствие Implementation Rules
п.6 (удаление `section_number` осиротило единственную существующую запись про эту колонку) — не
процесс-выбор, детали `phases/P5.md` P5.2 Known Deviations.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠 Done with deviations, **P5.2** 🟠 Done with deviations — фаза P5 **не терминальна**
  (6 items впереди, следующий — P5.2).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 — порядок items СОВПАДАЕТ
  с нумерацией: следующий — **P5.3** (решение владельца о носителях, `frontier/high manual` —
  `plan-run` исполняет его тоже, но требует сессию `opus/high`, не `sonnet/high`).
- **`## Phase Handoff` фазы P2 не собран** («—») — отдельный висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, не блокирует продолжение P5.
- **Follow-up вне плана (не блокирует P5.3):** десять pre-existing красных тестов (см. предыдущий
  handoff, `open-questions.md` OQ-8 п.3) — воспроизводятся на `HEAD`, не изменились P5.2.
- **Pending Work P5.2:** `RerunRangeAction` не вызывает `replaceResources()` вовсе (точечный re-run
  оставляет `prc_estimate_resources` заменяемой позиции старыми на все 15 колонок разом) — находка
  P5.1, явно вне Scope Included P5.2; следующий владелец не назначен, фикс — НОВЫЙ item при
  заведении, не расширение P5.2 задним числом.

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-corpus-coverage.md` — три таблицы инвентаря/дельты,
  протоколы вложенности, кандидаты сортировки (вход P5.3).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/estimate-gap-coverage.json` — машинная фактура для
  P5.3 (носитель каждой подтверждённой величины ещё предстоит выбрать).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.php` — воспроизводимый зонд,
  реиспользован P5.2 для контрольной сверки (команда — Completion Notes P5.2).
- `phases/P5.md` — Phase Status, P5.1/P5.2 Completion Notes/Known Deviations.
- `roadmap.md` — очередь фаз (D63).

**Open risks:**

- **Implementation Rules п.4 P5.1 неполно выполнено** на 4+3 колонках/ключах (см. Known Deviations
  P5.1, code-confirmed без прогона на нулевой eval-выборке) — без изменений этим item'ом.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, частично `CLAUDE.md`, `.swissknife*`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений из
  предыдущего handoff, состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; ДЛЯ tinker-прогонов на `agelto_test` дополнительно нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff (см. Open risks).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close`); слои класса B/не утверждённые D113
  классы A (OQ-8 п.3); типизированный носитель `RowType` (OQ-6); переписывание текстов промтов
  сверх дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls` под даты корпуса;
  P5.3-гейт по двум кандидатам (GAP-2, GAP-8) — форма носителя не выбрана; `RerunRangeAction`
  `replaceResources()` не вызывается (Pending Work P5.2, новый item при заведении).
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
