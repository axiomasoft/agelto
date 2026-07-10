# HANDOFF — 2026-07-10 — after P2.2

**Next:** `/task:plan-design DOMLANG P3` — фаза P3 (точечный семантический ренейм БД и кода) —
скелет (0/3), требует детализации ПЕРЕД exec. P2 терминальна (2/2 🟢), блокеров нет.

| Параметр | Значение |
|:--|:--|
| Model | opus/high — детализация P3 задевает pre-mortem-риски (D11–D14): edit-in-place на create-миграциях, полисемия `source_number`/`source_status` (D12), impact-карта по 341-файловому кодбейзу |
| Thinking | high — несущее архитектурное решение (ренейм колонок + миграции), не механическая реализация |
| Context | fresh; холодный старт: plan.md (§2 Execution Rules — edit-in-place/migrate:fresh; §5 D5, D11–D14) → handoff.md (этот) → plans/DOMLANG/phases/P3.md (скелет, Phase Context) → findings/P1-actual-names.md (канон имён) |
| Суть | `/task:plan-design DOMLANG P3` — детализировать items P3.1–P3.3 (impact-карта, ренейм колонок/классов, zero-residue verification) до 16 полей каждый, пройти DoR |

````
Launch-block (P3 design):
1. Прочитать РОВНО: plans/DOMLANG/plan.md (§2 Execution Rules, §5 D5/D11-D14) →
   handoff.md (этот) → plans/DOMLANG/phases/P3.md (скелет целиком) →
   findings/P1-actual-names.md (канон имён таблиц/колонок для P2-P5).
2. Pre-mortem уже проведён (P1.3): D11 (edit-in-place разрешён владельцем — D14, снят как
   hard-гейт, остаётся diagnostic), D12 (source_number/source_status полисемичны — 3 таблицы,
   анкорить ренейм на Procurement-scoped код, НЕ на голый токен), D13 (etp_number — forward-
   provision без продюсера, provenance §4 вычищен — D15).
3. P3.1 должен: (a) прогнать git cat-file -e origin/main:<файл> по каждой целевой миграции
   (диагностика, не блокер — D14); (b) построить impact-карту source_number/source_status/
   source_guid по таблице-владельцу и концепту (D12); (c) решить судьбу
   prc_status_mappings.source_status согласованно с сидером P2.2.
4. Верификация P3 — ТОЛЬКО полный migrate:fresh + зелёный сюит (D14), zero-residue grep
   недостаточен сам по себе.
5. Команды окружения: docker compose из shell раннера падает (missing MINIO_ROOT_PASSWORD) →
   выполнять через `docker exec agelto_app <cmd>`; pint гнать явным списком файлов (--dirty в
   контейнере видит 0 файлов).

Автопрогон фазы (после design): Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P3' } })
Единственный гейт автопрогона — phase-гейт ПОСЛЕ P3 (approvals["after:P3"]), см. plan.md D10.
````

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). **P2 терминальна (2/2 🟢).**
- 🟢 P2.1 — удалён недостижимый `ProcurementStatus::Announced` (D16). Предпосылка подтверждена
  фактом (grep=3, 2 присваивания в фабриках, 0 в проде). PHPDoc-матрица достижимости над enum;
  `ProcurementFactory`/`LotFactory` → `Bidding`; `source_status` `'Размещение извещения'` →
  `'Подача заявок'`. Затронут `DiscoveryTest` (произвольная фикстура `canonical_status='announced'`
  → `'bidding'`, анти-подгонка, не эскалация). Все 5 Validation passed (462 теста, pint green).
- 🟢 P2.2 — новый `database/seeders/StatusMappingSeeder.php`: 4 доказанных пары `fz44`
  (`findings/P2-eis-stages.md` §1) через `updateOrCreate` по `(registry_id, source_status)`;
  канон значений — `ProcurementStatus::*->value`. Вызов `$this->call(StatusMappingSeeder::class)`
  в `DatabaseSeeder` ДО local/testing-гейта, рядом с `AiRoutingSeeder`/`PromptSeeder`. Нет
  реестров `fz44` → no-op + `warn`. Новый `StatusMappingSeederTest` (5 тестов); 3 старых теста
  `StatusMappingTest` не тронуты. Все 5 Validation passed (467 тестов, 1543 assertions; ручной
  двойной `db:seed` подтвердил идемпотентность — 4 строки после обоих прогонов; pint green).
  Детали — phases/P2.md P2.2 Completion Notes.

**Remaining:** 18 items в P3–P9. Все — скелеты, каждая фаза перед exec требует
`/task:plan-design DOMLANG Pn`. Следующий шаг — P3 (см. Launch-block выше).

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!).
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон этапов ЕИС (использован P2.1/P2.2, закрыт).
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D17, §6 Update Log, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок (unique-индекс сидера —
  `2026_07_04_100002:24`).
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Ренейм P3 (D12): `source_number` полисемичен (procurements/ignored_items/protocols); токен-replace
  сломает protocols/ignored_items. `prc_status_mappings.source_status` + сидер P2.2 — синхронить
  атомарно в P3.1 (сидер использует старое имя `source_status`, P3.1 обязан решить, переименовывать
  ли эту колонку тоже, и если да — обновить сидер и данный сид-тест в том же коммите). Мёртвый
  docblock `plt_status_mappings` в `StatusMappingTest.php:16` ловит P3.3.
- Верификация P3 — только полный `migrate:fresh` + зелёный сюит; grep zero-residue недостаточен (D14).
- Реестр, созданный ПОСЛЕ прогона сида, остаётся без маппингов до повторного `db:seed` (Q7).
- `etp_number` без продюсера (D13); provenance без доказанного потребителя (D13, ADR-0008).
- Единственный гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`).
- Окружение: `docker compose` из shell раннера падает на `MINIO_ROOT_PASSWORD` → `docker exec
  agelto_app`; `pint --dirty` в контейнере видит 0 файлов → явный список.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: реализм `source_status`-значения в `SnapshotFactory` (`'Размещение извещения'` —
  несуществующий этап ЕИС) не правился в P2.1 (вне scope, ключ маппинга совпадает) — кандидат в P3.
  Q3 (`etp_number` мёртвая колонка) — `Resolved → D5`, mitigation в D13 (P3.3 round-trip).
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
