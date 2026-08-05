# HANDOFF — 2026-08-05 — after P7.5

**Next:** «ЗАПУСК ВРУЧНУЮ: frontier/high» — **P7.6** (аудит процессов пайплайна на
изолируемость и тестируемость — research-item фазы). Routing требует `frontier/high`
(§3 Routing) — сессия сейчас на `sonnet/high`, ниже предписанного тира: нужен переход на
Opus перед стартом.

| Параметр | Значение |
|:--|:--|
| Model | opus |
| Thinking | high — предписано §3 Routing (`frontier/high`), открытые design-решения: критерий изолируемости формулируется здесь и определяет объём P7.7 |
| Context | continue (/clear) — ручной item |
| Суть | Пройти по всем процессам пайплайна (job'ы, очереди, команды, расписание, supervisor'ы), вынести вердикт «изолированно тестируется / не тестируется + чего не хватает» по каждому, разделить находки на «чинится в P7.7» / «отдельный план» |

```
/model opus
/effort high
/task:plan-run 2026.07.31-SYS-INTEGRITY P7.6
```

**Сделано этим заходом (`plan-run P7.5`, sonnet/high, 2026-08-05).**

- **Гейт владельца разрешён прямым вопросом в сессии (D133, `plan.md` §5).** Item Inputs требовали
  «утверждённый владельцем перечень» (`research/08-db-review.md` §12) — двух строк не хватало:
  F-02-гейт (ON DELETE для пяти аудитных детей `prc_procurements`) → **RESTRICT**; F-06-гейт (выход
  дат за диапазон партиций) → **предсоздание исторических партиций, 12 месяцев назад**
  (`[2025-08-01, 2026-08-01)`). Записано в Decision Log и в §12 самого research-документа.
- **P7.5 закрыт 🟠 Done with deviations**, item-коммит **`a5b066c`** — 8 находок → 8 миграций
  (`database/migrations/2026_08_05_100069`…`100076`), `app/Models/Procurement/PurchaseItem.php`
  (каст `decimal:6`), `tests/Feature/Infra/SchemaHardeningTest.php` (5 тестов на изменённое
  поведение).
  - F-01 — индексы на 23 FK-колонках (15 таблиц). F-02 — явный `ON DELETE` на 28 FK (23 `NO
    ACTION`, 5 аудитных детей `prc_procurements` → `RESTRICT`, D133). F-03 — `prc_items.quantity`
    → `numeric(18,6)`. F-04/F-05 — FK `prc_revisions.crawler_run_id`/`llm_calls.analysis_run_id`.
    F-06 — 12 месяцев исторических партиций на пять таблиц (D132/D133). F-08 — выражающие
    индексы `prc_ai_analyses.result`. F-13 — обобщённый прун `partman.part_config`.
  - **Технический факт, не описанный research-документом:** Postgres 17 не поддерживает
    `NOT VALID` при добавлении FK НА партиционированный родитель (`SQLSTATE[42809]`) — для
    констрейнтов на `llm_calls`/`prc_fetches`/`prc_events`/`prc_revisions` использован обычный
    `ADD CONSTRAINT` вместо `NOT VALID`+`VALIDATE` (безопасно на объёмах ≤ 18 004 строк).
  - **Побочный эффект F-06:** чинит боевой дефект F-06/P4.16 — 3 кейса
    `SupplierResultsProcessorTest`, красных с P7.4, теперь зелёные.
- **Validation (якорь `a5b066c`).** `migrate` → `rollback --step=8` → `migrate` — все зелёные,
  схема после rollback побайтово вернулась к состоянию до (проверено по `quantity`-типу,
  числу партиций, `confdeltype`, составу `partman.part_config`). `pg_index NOT indisvalid` = 0.
  Счётчики строк ДО/ПОСЛЕ идентичны (`prc_procurements` 17943, `prc_revisions` 17942,
  `prc_documents` 66, `prc_items` 77, `prc_events` 18004, `prc_estimates` 0). Полный сюит
  `--no-tia` — **1081 passed, 6 failed, 1 skipped** (было 1072/10/1 на `a73d3b8`) — все 6 вне
  `Files` item'а, тот же класс, что уже в Known Deviations P7.4. `phpstan` — `[OK] No errors`,
  `phpstan-baseline.neon` не менялся (`git status --porcelain` пуст). `pint --dirty --test` —
  чисто.
- **Known Deviations P7.5** (детали — `phases/P7.md` P7.5 Known Deviations): (1) process —
  гейт владельца разрешён вопросом в сессии, записан D133, не отклонение от ТЗ; (2) material —
  полный сюит несёт 6 предсуществующих красных теста вне Scope (тот же класс, что P7.4);
  (3) собственный текст Validation item'а называет «74 записи» ожидаемым числом строк
  `phpstan-baseline.neon` — на дереве их 56/61 (по разным способам счёта), но файл НЕ менялся —
  неточность спеки item'а, не дефект исполнения.

**Done:**

- **P1 4/10** 🟠 Done with deviations — фаза терминальна.
- **P2 5/13** 🟠 Done with deviations — фаза терминальна.
- **P3 1/5** 🟠 Done with deviations — фаза терминальна.
- **P5 3/8** 🟠 Done with deviations — фаза терминальна.
- **P6 5/6** 🟠 Done with deviations — фаза терминальна.
- **P4 6/16** 🟠 Done with deviations — фаза терминальна.
- **P7 3/8** 🟡 In progress — P7.1/P7.2/P7.3 🟢, P7.4/P7.5 🟠; открыты P7.6–P7.8.
- **P8 0/7** ⬜ Not started — заведена скелетом, не детализирована.

**Remaining:**

- **P7 — 3 items**: P7.6 (frontier/high, manual, research-item — **требует переключения на
  Opus**) → P7.7 (implementation/high, manual) → P7.8 (implementation/low, plan-exec, приёмка
  фазы и закрытие плана).
- **P8 требует отдельного дизайн-захода** `/task:plan-design 2026.07.31-SYS-INTEGRITY P8`
  (frontier/high, NEW SESSION) и ПОСЛЕ него — повторного `finish` (`plan-protocol` §5).
- **`## Phase Handoff` фаз P2 и P5 не собран**; **P6** нуждается в формальном `plan-close` —
  висящий bookkeeping-хвост, продолжение плана НЕ блокирует.
- **Расширение окна прогона и включение delta-scan/fresh** (D126) — решение владельца; вне Scope
  плана, если владелец не решит иначе.
- **Серия M-5 (60 сэмплов) не снята** на контрольном прогоне P4.15 — дефект скрипта-сэмплера;
  пересдать нечем до следующего боевого прогона.
- **F-13 «сторож видимости отказа `run_maintenance()`»** (вторая половина, `research/08` §10.3) —
  плановый разрыв между P7.1 и текстом P7.4, не входил в Scope P7.5 (только сама миграция-прун);
  ждёт D# от `plan-design`.
- **Четыре строки гейта владельца остаются открытыми** (`research/08-db-review.md` §12): F-07
  (13 пустых колонок `prc_procurements`), F-09 (8 неиспользуемых индексов), F-01b (индексы в
  таблицах пакета azguard), cache-гейт (удаление `cache`/`cache_locks`) — P7.5 их не блокировали,
  решение вне Scope этого item'а.
- **`agelto_postgres_pre_p74`** — стоп-контейнер, требует `docker rm` владельцем вне харнесса
  (см. Known Deviations P7.4).

**Sources of truth:**

- `plans/2026.07.31-SYS-INTEGRITY/research/08-db-review.md` §12 (гейт владельца, Resolved →
  D133) · §13 (сводка находок).
- `plans/2026.07.31-SYS-INTEGRITY/phases/P7.md` P7.5 Completion Notes — находка → миграция →
  результат, полный текст Validation.
- `database/migrations/2026_08_05_100069`…`100076` — восемь миграций P7.5, каждая с докблоком
  на находку.
- `plan.md` D133 (гейт) · §4 Phase Index (P7 `4/8`) · §6 Update Log (строки P7.1–P7.5).

**Open risks:**

- **Обслуживание партиций (F-13, P1) — ЧАСТИЧНО адресовано.** Прун `partman.part_config`
  обобщён (P7.5); видимость отказа `run_maintenance()` (вторая половина F-13) не реализована
  нигде — плановый разрыв, ждёт D# от `plan-design`. **Партиции кончаются `2026-11-01`**
  (`premake=3` от текущей даты; исторические партиции P7.5 не меняют этот горизонт).
- **`org_organizations` живёт по UPDATE-модели, будучи продуктовой таблицей** (найдено P7.3) —
  решение владельца по OQ-5, вне Scope этого плана.
- **Подтверждение D71 остаётся частичным** до первого боевого извлечения смет.
- **`prc_procurement_parties` подтверждённо пуст** на выборке 4 типов процедуры (P4.16).
- **`agelto_horizon` поднят**; AI-стадии за выключенными гейтами. **`agelto_scheduler` остановлен**
  — `partitions:maintain` не запускается даже неуспешно.
- **Два типа процедуры вне `procedure_url_types`** (аудит бухотчётности, ТКО) — 0.16 % теряют
  детальную стадию.
- **Коллизия нумерации research:** `research/10-p4-remediation.md` и плановый
  `research/10-process-audit.md` (P7.6) делят номер `10` — отмечено в `index.md`.
- **`app/Filament/Resources/Procurement/**` restrictive ACL**, **`rm` заблокирован в харнессе**,
  **незакоммиченный чужой дифф** (`.env.example`, `.swissknife.json`, `.swissknife/`,
  `.swissknifeman/`, `config/cms.php`, `database/seeders/User/`, `estimates/gemini-analys.md`) —
  состав снимать `git status --porcelain` перед каждым item-коммитом.
- **`.env` не читается харнессом вовсе** — `docker compose`/`docker compose exec` требуют
  `MINIO_ROOT_PASSWORD` в shell-окружении инвокации (снимать `docker exec agelto_minio printenv
  MINIO_ROOT_PASSWORD` с уже работающего контейнера, экспортировать перед `docker compose exec`).
  **PHPStan в контейнере падает на дефолтном 256M** (обход `--memory-limit=1G` или
  `-d memory_limit=1G`). **`psql` внутри `agelto_postgres` требует `-U agelto`**.
- **`docker compose {up,restart,down} postgres` сломан до ручного `docker rm
  agelto_postgres_pre_p74`** владельцем — до этого управлять контейнером `postgres` напрямую
  через `docker`.

**Workarounds/Deferred/Open questions:**

- **Workarounds:** снятие `MINIO_ROOT_PASSWORD`/прочих секретов из env уже работающих контейнеров
  вместо чтения `.env`; управление `postgres` через raw `docker` вместо `docker compose` до
  ручного `docker rm agelto_postgres_pre_p74`; на партиционированных referencing-таблицах FK
  добавляется без `NOT VALID` (Postgres 17 не поддерживает `NOT VALID` на партиционированном
  родителе) — см. Known Deviations/Completion Notes P7.5.
- **Deferred:** положения (3), (4), (5) модели D72 — OQ-5, отдельный план. F-13 «сторож
  видимости отказа `run_maintenance()`» — ждёт D# от `plan-design`. Четыре строки гейта
  владельца (F-07, F-09, F-01b, cache) — вне Scope P7.5.
- **Open questions:** OQ-3 (источник истины ЕИС), OQ-5 (продуктовый контур), OQ-6
  (типизированный носитель семантики кейсов enum).
