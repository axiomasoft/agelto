# HANDOFF — 2026-08-04 — after P5.1

**Next:** ЗАПУСК ВРУЧНУЮ: sonnet/high

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | high — правка боевых writer-путей персистенции, correctness-critical (§3 Routing P5.2) |
| Context | continue (/clear) — ручной item |
| Суть | P5.2 — довести оба writer-пути (`EstimatePersister`, `RerunRangeAction`) до полноты уже существующей схемы: передать 14 run-confirmed + 4 code-confirmed колонок класса A из инвентаря P5.1 |

Сессия уже на `sonnet/high` (совпадает с предписанным Routing P5.2) — переключение не требуется,
код-блок ниже несёт полную D18-форму для холодной сессии:

```
/model sonnet
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P5.2
```

**Сделано этим заходом (`plan-run`, sonnet/high, 2026-08-04).** P5.1 закрыт 🟠 Done with
deviations, item-коммит `8c78b68`:

- Инвентарь записи (задача а) — прогон реального 6-файлового gold-корпуса через боевой
  `EstimatePersister::persist()` в изолированной `agelto_test` (транзакция, откат) —
  `artifacts/persistence_inventory.php`. Покрыты все 88 колонок пяти таблиц `prc_estimate*`.
  **14 колонок класса A подтверждены прогоном** (числами, включая 100%-ю потерю
  `source_position_number` — 214/214 позиций gold, 0 в БД); 4 колонки (`document_number` и три
  парных) — тот же код, но 0/6 непустых в eval-выборке, класс подтверждён только чтением (Known
  Deviations). КС-3-ключи `attributes` — синтетическая проба показала: `row_type=summary_row` не
  материализуется в `prc_estimate_items` вовсе. `RerunRangeAction` — находка вне A/B/C/D:
  `replaceResources()` не вызывается никогда (код-чтение, живой прогон запрещён IR5).
- Дельта-сверка восьми пробелов + `RowModifier` (задача б) — против матрицы SR-01..17 донора
  (`2026.07.26-EST-TRAIN/research/06-schema-decision.md`) + зонд `artifacts/corpus_probe.py` по
  `estimates/files` (157 полезных файлов). **2 кандидата в схему** (GAP-2 разбивка ОТ/ЭМ/МАТ на
  позиции, GAP-8 нормативный документ/дата ценового среза — оба ≥9 файлов, вход P5.3); 1
  переадресован на уже найденный класс A (GAP-1 → SR-11); 1 решается backend-derived аксессором,
  не LLM-полем (GAP-3); 2 отложены — порог не достигнут (GAP-4: 0 файлов, GAP-5: 1 файл); 1 не
  переоткрыт (GAP-6 — решено донором, D10); 1 не пробел схемы вовсе (GAP-7 — носитель уже есть,
  наблюдение — долг качества извлечения). `RowModifier` — решено донором (D95), DTO 1:1 совпадает.
- Протоколы вложенности — оба выражены контрактом (позиция→ресурсы→начисления полностью,
  раздел→промежуточный итог частично — иерархия разделов персистируется, строка `subtotal` нигде
  не хранится, известное поведение).
- Перечень кандидатов сортировки/фильтрации для теста (б3) — §4 `findings/P5-corpus-coverage.md`.
- Состав items P5.2/P5.5 подтверждён БЕЗ расхождений — новой D-записи не потребовалось.

**Status:** 🟠 Done with deviations (item). Единственное отклонение — Implementation Rules п.4
(класс потери подтверждается прогоном, не чтением кода) выполнено НЕ ДЛЯ ВСЕХ колонок: 4 колонки
`prc_estimates` + 3 КС-3-ключа `attributes` имеют 0 непустых примеров в фиксированном 6-файловом
eval-gold — прогон физически не мог дать число на нулевой выборке, класс зафиксирован чтением кода
(та же строка `upsertEstimate(...)`, что run-confirmed соседей) + отдельной синтетической пробой
для КС-3. Расширение gold запрещено Scope Excluded — разрыв назван явно, не скрыт.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5.1** 🟠 Done with deviations — первый item фазы P5 закрыт (Phase Index `0/8` — 🟠 не
  засчитывается в числитель, тот же приём, что P1–P3); фаза P5 **не терминальна** (7 items впереди,
  следующий — P5.2).

**Remaining:**

- **Порядок фаз (D63):** `P1 → P2 → P3 → P5 → P6 → P4 → P7`. Внутри P5 — порядок items СОВПАДАЕТ
  с нумерацией (Phase Context P5.md не переставляет очередь): следующий — **P5.2** (доведение
  записи обоих writer-путей до полноты существующей схемы, по классам A/R инвентаря P5.1).
- **`## Phase Handoff` фазы P2 не собран** («—») — отдельный висящий bookkeeping-хвост:
  `/task:plan-close 2026.07.31-SYS-INTEGRITY P2`, не блокирует продолжение P5.
- **Follow-up вне плана (не блокирует P5.2):** десять pre-existing красных тестов (см. предыдущий
  handoff, `open-questions.md` OQ-8 п.3) — воспроизводятся на `HEAD`, не изменились этим item'ом
  (item без диффа кода приложения).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/findings/P5-corpus-coverage.md` — три таблицы инвентаря/дельты,
  протоколы вложенности, кандидаты сортировки.
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/estimate-gap-coverage.json` — машинная фактура для
  P5.3 (носитель каждой подтверждённой величины ещё предстоит выбрать).
- `plans/2026.07.31-SYS-INTEGRITY/artifacts/persistence_inventory.php` /
  `artifacts/corpus_probe.py` — два воспроизводимых зонда, команды — §6 отчёта.
- `phases/P5.md` — Phase Status, P5.1 Completion Notes/Known Deviations.
- `roadmap.md` — очередь фаз (D63).

**Open risks:**

- **Implementation Rules п.4 неполно выполнено** на 4+3 колонках/ключах (см. Known Deviations
  P5.1) — если P5.2 расширит gold-корпус по ДРУГОЙ причине, эти конкретные колонки стоит
  переприкинуть прогоном заодно (не отдельный item, попутная проверка).
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, частично `CLAUDE.md`, `.swissknife*`,
  `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) — без изменений из
  предыдущего handoff, состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не несёт `MINIO_ROOT_PASSWORD`** — обход `docker exec agelto_app …` вместо
  `docker compose exec …`; ДЛЯ tinker-прогонов на `agelto_test` дополнительно нужны явные
  `-e DB_DATABASE=agelto_test -e DB_USERNAME=agelto -e DB_PASSWORD=agelto_dev_local` (у tinker нет
  автоматического `TestEnvironmentGuard` — он привязан к `TestCase::createApplication()`,
  PHPUnit-специфичен).

**Workarounds/Deferred/Open questions:**

- **Workarounds:** те же, что в предыдущем handoff, + explicit `DB_*` env override для
  `artisan tinker` при прогонах на `agelto_test` (см. Open risks).
- **Deferred:** `## Phase Handoff` P2 (нужен `plan-close`); слои класса B/не утверждённые D113
  классы A (OQ-8 п.3); типизированный носитель `RowType` (OQ-6); переписывание текстов промтов
  сверх дедупликации (OQ-7); партиционирование `prc_events`/`llm_calls` под даты корпуса;
  P5.3-гейт по двум кандидатам (GAP-2, GAP-8) — форма носителя не выбрана.
- **Open questions:** OQ-3, OQ-4, OQ-7 (`Exploration`), OQ-5 (`Decision pending`), OQ-6; OQ-8
  закрыта D113.
