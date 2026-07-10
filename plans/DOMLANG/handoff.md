# HANDOFF — 2026-07-10 — after P3.1

**Next:** `/task:plan-exec DOMLANG P3.2` — атомарный ренейм 32 файлов (схема, код, фабрики, тесты)
по неизменной карте `findings/P3-rename-impact.md` §3.1. P3.1 подтвердил: drift отсутствует,
все 6 gate-счётчиков сошлись, 4 миграции `PRESENT` на `origin/main` (не блокер, D14).

| Параметр | Значение |
|:--|:--|
| Model | sonnet |
| Thinking | medium |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | P3.2 — переименовать 5 колонок в 3 миграциях (edit-in-place) + все зависящие PHP-идентификаторы в 32 файлах §3.1; один атомарный коммит; `migrate:fresh --seed` + полный сюит зелёные ДО коммита |

````
/task:plan-exec DOMLANG P3.2

Холодный старт — прочитать РОВНО в этом порядке:
1. plans/DOMLANG/plan.md (§2 Execution Rules, §5 D5/D6/D12/D14/D17/D18/D19/D20)
2. plans/DOMLANG/handoff.md (этот файл)
3. plans/DOMLANG/phases/P3.md (Phase Context + item P3.2 целиком)
4. plans/DOMLANG/findings/P3-rename-impact.md (§1–§3, §5, §6 — вердикт P3.1: карта актуальна)
5. app/Services/Procurement/StatusMappingService.php + database/seeders/StatusMappingSeeder.php
6. skill namespace-refactor-safety

P3.2 — самый рискованный item фазы: 32 файла, ОДИН коммит (код+тесты вместе — разрезание
даёт красный промежуточный сюит, нарушает коммит-гейт). Анкеринг на список §3.1, НЕ sed
по токену — source_number живёт на prc_protocols в другом значении (D20), трогать нельзя.

Окружение: `docker compose` из shell раннера падает (missing MINIO_ROOT_PASSWORD) →
выполнять через `docker exec agelto_app <cmd>`; pint гнать явным списком файлов
(`--dirty` в контейнере видит 0 файлов).
````

Автопрогон фазы целиком (альтернатива поитемному exec):
`Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P3' } })`
Единственный гейт автопрогона — phase-гейт ПОСЛЕ P3 (`approvals["after:P3"]`), см. plan.md D10.

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 терминальна (2/2 🟢). P3 в работе (1/3):
- 🟢 P3.1 — preflight impact-карты: все 6 gate-счётчиков сошлись с картой ровно
  (`source_?number` 126/40 файлов, `source_?guid` 11, `source_?status` 50, `registry_?number` 17,
  `source_?stage` 0, группа K 18 в 8 файлах); 4 целевые миграции `PRESENT` на `origin/main`
  (диагностика, не блокер, D14). Drift не найден. `findings/P3-rename-impact.md` §6 заполнен.

**Remaining:** P3.2, P3.3 в этой фазе; 17 items в P4–P9 (P4–P9 — скелеты, каждая фаза перед
exec требует `/task:plan-design DOMLANG Pn`).

**Sources of truth:**
- `plans/DOMLANG/findings/P3-rename-impact.md` ← **единственный источник скоупа P3** (§3.1 —
  32 файла группы R на ренейм; §3.2 — 8 файлов группы K не трогать; §5 gate-числа; §6 — вердикт
  P3.1 «карта актуальна»).
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!);
  §1/§3 актуализирует P3.3 после ренейма.
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон 4 этапов ЕИС (используется фикстурами P3.3).
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D20, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок.
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Полисемия `source_number` (D12/D20): токен-левел find-replace сломает `prc_protocols`
  (18 вхождений в 8 файлах группы K обязаны выжить нетронутыми). Ренейм анкорится на §3.1 карты.
- `prc_awards.contract_registry_number` — третий концепт; не «унифицировать» с `registry_number`.
- Верификация P3.2 — только полный `migrate:fresh --seed` + зелёный сюит; grep zero-residue
  недостаточен сам по себе (D14). Проверить руками, что тест `etp_number` (P3.3, ещё не написан)
  реально пинит write-path.
- P3.2 — один атомарный коммит: разрезание на «код» и «тесты» даёт красный промежуточный сюит
  и нарушает коммит-гейт plan-protocol §6.
- Реестр, созданный ПОСЛЕ прогона сида, остаётся без маппингов до повторного `db:seed` (Q7).
- `etp_number` без продюсера (D13) — пин в P3.3, не в P3.2; provenance без доказанного
  потребителя (ADR-0008, D15) — предмет P4.1.
- Единственный гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`).
- Окружение: `docker compose` падает на `MINIO_ROOT_PASSWORD` → `docker exec agelto_app`;
  `pint --dirty` в контейнере видит 0 файлов → явный список.
- В рабочем дереве до сих пор незакоммичены доменные (не P3.1) правки `plans/DOMLANG/workflow.js`
  (7 строк) — не в скоупе P3.1, оставлены как есть; при exec P3.2 не трогать, если не входят
  в его Scope.

**Workarounds/Deferred/Open questions:**
- Workarounds: `docker exec agelto_app` вместо `docker compose exec` (env-интерполяция падает в shell).
- Deferred: реализм фикстуры `'Размещение извещения'` (несуществующий этап ЕИС) — закрывается
  в P3.3 (ровно 3 места; намеренно-незнакомые значения в `PlatformOnboardingTest`/
  `StatusMappingTest`/`StatusMappingSeederTest` не трогать). Мёртвый docblock `plt_status_mappings`
  (`StatusMappingTest.php:16`) — тоже P3.3.
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
