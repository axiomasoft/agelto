# HANDOFF — 2026-07-10 — after P3.2

**Next:** `/task:plan-exec DOMLANG P3.3` — zero-residue верификация, реализм фикстур ЕИС
(3 места), пин `etp_number` round-trip-тестом, актуализация `findings/P1-actual-names.md`.

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium — типовая реализация в уже детализированной фазе |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | P3.3 — закрыть хвосты ренейма: gate-счётчики §5 карты, 3 фикстуры `'Размещение извещения'`→`'Подача заявок'`, docblock `plt_status_mappings`→`prc_status_mappings`, новый round-trip-тест `etp_number`, правка `findings/P1-actual-names.md` §1/§3; фаза P3 терминальна после этого item'а |

````
/task:plan-exec DOMLANG P3.3

Холодный старт — прочитать РОВНО в этом порядке:
1. plans/DOMLANG/plan.md (§2 Execution Rules, §5 D13/D15/D17/D20)
2. plans/DOMLANG/handoff.md (этот файл)
3. plans/DOMLANG/phases/P3.md (Phase Context + item P3.3 целиком)
4. plans/DOMLANG/findings/P3-rename-impact.md (§3.2, §5)
5. plans/DOMLANG/findings/P2-eis-stages.md §1
6. app/Dto/Procurement/ProcurementSnapshotData.php + app/Repositories/Procurement/ProcurementStoreRepository.php (write-path etpNumber)
7. tests/Feature/Procurement/StatusMappingSeederTest.php (образец стиля Pest)
8. skill testing-rules

P3.3 — хирургичность фикстур: менять `'Размещение извещения'` РОВНО в трёх местах
(SnapshotFactory.php:26, DiscoveryTest.php:27/:51, RevisionTest.php:21) — массовая замена
по tests/ запрещена (PlatformOnboardingTest/StatusMappingTest/StatusMappingSeederTest держат
намеренно-незнакомые значения для проверки деградации в unknown, их не трогать).

Окружение: `docker compose` из shell раннера падает (missing MINIO_ROOT_PASSWORD) →
выполнять через `docker exec agelto_app <cmd>`; pint гнать явным списком файлов
(`--dirty` в контейнере видит 0 файлов).
````

Автопрогон фазы целиком (альтернатива поитемному exec):
`Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P3' } })`
Единственный гейт автопрогона — phase-гейт ПОСЛЕ P3 (`approvals["after:P3"]`), см. plan.md D10.
P3.3 — последний item фазы; после его закрытия автопрогон упрётся в этот гейт.

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 в работе (2/3):
- 🟢 P3.1 — preflight impact-карты: все 6 gate-счётчиков сошлись, drift не найден.
- 🟢 P3.2 — атомарный ренейм: `source_number`→`registry_number`, `source_guid`→`registry_guid`,
  `source_status`→`source_stage` (`prc_procurements`, `prc_ignored_items` D19, `prc_status_mappings`
  D18) по списку `findings/P3-rename-impact.md` §3.1 edit-in-place (3 create-table-миграции +
  зависящие PHP-идентификаторы). Фактически задето 42 файла, не заявленные в шапке item'а 32 —
  это арифметическая неточность авторинга карты (подсписки §3.1 суммируются в 39, «Тесты (11)»
  реально перечисляет 14), а не scope creep: единственный источник скоупа — САМ ПЕРЕЧЕНЬ файлов
  §3.1, он выполнен целиком и без выхода за границы (все 6 gate-счётчиков §5 сошлись точно).
  `migrate:fresh --seed --force` — 0 ошибок; `php artisan test` — 467 passed (1543 assertions),
  0 failed; `pint --test` по 42 файлам — зелёный. Коммит `83acb83`.

**Remaining:** P3.3 в этой фазе (последний item, после закрытия — phase-гейт `after:P3`);
17 items в P4–P9 (P4–P9 — скелеты, каждая фаза перед exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/findings/P3-rename-impact.md` ← **единственный источник скоупа P3** (§3.1 —
  список файлов на ренейм, уже выполнен P3.2; §3.2 — 8 файлов группы K не трогать; §5 gate-числа
  для P3.3-верификации).
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 — **устарел
  после P3.2** (ещё несёт старые `source_number`/`source_status`), актуализация — Scope P3.3.
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон 4 этапов ЕИС (используется фикстурами P3.3).
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D20, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок (после P3.2 актуальны).
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md` (после P3.3-актуализации).
- Верификация P3.3 — gate-счётчики §5 карты; `source_?number` обязан остаться = 18 в ровно 8
  файлах группы K (протоколы) — любое отклонение = сигнал, что P3.2 или P3.3 задели протоколы.
- `etp_number` без продюсера (D13) — пин в P3.3 (round-trip-тест); provenance без доказанного
  потребителя (ADR-0008, D15) — предмет P4.1, не путать с `etp_number`.
- `findings/P1-actual-names.md` временно лжёт (старые имена колонок) до правки в P3.3 — не
  использовать как канон до закрытия item'а.
- Единственный гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`); P3.3 — последний
  item перед ним.
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.
- В рабочем дереве незакоммичены доменные (не P3-scope) правки `plans/DOMLANG/workflow.js`
  (7 строк, существовали до P3.1) — не трогать при exec P3.3, если не входят в его Scope.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: реализм фикстуры `'Размещение извещения'` (несуществующий этап ЕИС) — закрывается
  в P3.3 (ровно 3 места; намеренно-незнакомые значения в `PlatformOnboardingTest`/
  `StatusMappingTest`/`StatusMappingSeederTest` не трогать). Мёртвый docblock `plt_status_mappings`
  (`StatusMappingTest.php:16`) — тоже P3.3.
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
