# HANDOFF — 2026-07-10 — after P2.1

**Next:** `/task:plan-exec DOMLANG P2.2` — `StatusMappingSeeder` для реестра `fz44` (D17),
устраняет `unknown`-дефект первого боевого прогона. P2.1 закрыт 🟢 Done, блокеров нет.
P2 фаза 🟡 In progress (1/2).

| Параметр | Значение |
|:--|:--|
| Model | P2.2 exec: sonnet/medium (новый сидер + тесты; RAG уже сделан → `findings/P2-eis-stages.md`) |
| Thinking | enabled |
| Context | fresh; холодный старт: plan.md (§5 D12/D17, §4 Status Board) + phases/P2.md (Phase Context + item P2.2 целиком) + findings/P2-eis-stages.md §1/§4/§5 |
| Суть | P2.2 — идемпотентный `StatusMappingSeeder` (4 пары `fz44`) + вызов в `DatabaseSeeder` до local-гейта + `StatusMappingSeederTest` (5 тестов). `StatusMappingService` НЕ трогать |

````
Launch-block (P2.2):
1. Прочитать РОВНО: plans/DOMLANG/plan.md (§2 Execution Rules, §5 D12/D17) →
   handoff.md (этот) → plans/DOMLANG/phases/P2.md (Phase Context + item P2.2 целиком) →
   findings/P2-eis-stages.md §1/§4/§5. Затем Required Reads P2.2 по порядку.
2. Карта — ровно 4 пары (fz44): 'Подача заявок'→bidding, 'Работа комиссии'→evaluation,
   'Закупка завершена'→completed, 'Закупка отменена'→cancelled. Канон — ProcurementStatus::*->value,
   НЕ строки-литералы. Идемпотентность — updateOrCreate по (registry_id, source_status)
   (= unique-индекс миграции 2026_07_04_100002:24). Нет реестра fz44 → no-op + warn, не исключение.
3. Вызов в DatabaseSeeder рядом с AiRoutingSeeder/PromptSeeder, ДО local/testing-гейта.
4. Тесты — Pest, test('<интент>'), БД agelto_test, сид вызывать явно ($this->seed(...)).
   3 существующих теста StatusMappingTest ломать нельзя. LLM — только FakeLlmProvider.
5. Команды окружения: docker compose из shell раннера падает (missing MINIO_ROOT_PASSWORD) →
   выполнять через `docker exec agelto_app <cmd>`; pint гнать явным списком файлов (--dirty в
   контейнере видит 0 файлов).
6. Закрыть item, синхронизировать Phase Status + Status Board, перезаписать handoff.md, один коммит.

Автопрогон фазы: Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P2' } })
````

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 — 1/2.
- 🟢 P2.1 — удалён недостижимый `ProcurementStatus::Announced` (D16). Предпосылка подтверждена
  фактом (grep=3, 2 присваивания в фабриках, 0 в проде). PHPDoc-матрица достижимости над enum;
  `ProcurementFactory`/`LotFactory` → `Bidding`; `source_status` `'Размещение извещения'` →
  `'Подача заявок'`. Затронут `DiscoveryTest` (произвольная фикстура `canonical_status='announced'`
  → `'bidding'`, анти-подгонка, не эскалация). Все 5 Validation passed (462 теста, pint green).
  Детали — phases/P2.md P2.1 Completion Notes.

**Remaining:** P2.2 (детализирован, готов к exec) + 18 item в P3–P9. P3–P9 — скелеты,
каждая перед exec требует `/task:plan-design DOMLANG Pn`.

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!).
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон этапов ЕИС (§1 — 4 пары для сидера P2.2;
  §5 — матрица достижимости enum).
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D17, §6 Update Log, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок (unique-индекс сидера —
  `2026_07_04_100002:24`).
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- P2.2: `RefreshDatabase` НЕ гоняет `DatabaseSeeder` — в тестах сид вызывать явно, иначе тест
  зелен и без сида (ничего не проверяет). 3 существующих `StatusMappingTest` ломать нельзя.
- Сид на фабричном реестре с чужим `code` — типовая ловушка: сид работает только по `code='fz44'`.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Ренейм P3 (D12): `source_number` полисемичен (procurements/ignored_items/protocols); токен-replace
  сломает protocols/ignored_items. `prc_status_mappings.source_status` + сидер P2.2 — синхронить
  атомарно в P3.1. Мёртвый docblock `plt_status_mappings` в `StatusMappingTest.php:16` ловит P3.3.
- Верификация P3 — только полный `migrate:fresh` + зелёный сюит; grep zero-residue недостаточен (D14).
- Реестр, созданный ПОСЛЕ прогона сида, остаётся без маппингов до повторного `db:seed` (Q7).
- `etp_number` без продюсера (D13); provenance без доказанного потребителя (D13, ADR-0008).
- Единственный гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`).
- Окружение: `docker compose` из shell раннера падает на `MINIO_ROOT_PASSWORD` → `docker exec
  agelto_app`; `pint --dirty` в контейнере видит 0 файлов → явный список.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция padает в shell).
- Deferred: реализм `source_status`-значения в `SnapshotFactory` (`'Размещение извещения'` —
  несуществующий этап ЕИС) не правился в P2.1 (вне scope, ключ маппинга совпадает) — кандидат в P3.
  Q3 (`etp_number` мёртвая колонка) — `Resolved → D5`, mitigation в D13 (P3.3 round-trip).
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
