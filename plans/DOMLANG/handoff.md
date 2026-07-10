# HANDOFF — 2026-07-10 — after P1.3

**Next:** `/task:plan-exec DOMLANG P2.1` — фаза P2 **детализирована** (DoR пройден, 2 items).
Эскалация P1.3 закрыта владельцем: Q5 → D14 (edit-in-place + `migrate:fresh`), Q6 → D15
(§4 карты имён вычищен). Q1/Q2 закрыты при проектировании P2 (D16). Блокеров нет.

| Параметр | Значение |
|:--|:--|
| Model | P2.1 exec: opus/high (правит enum, читают Filament/DTO). RAG уже сделан → `findings/P2-eis-stages.md`, повторять не нужно |
| Thinking | enabled |
| Context | fresh; холодный старт: plan.md (§5 D1–D17, §4 Status Board) + phases/P2.md + findings/P2-eis-stages.md + findings/P1-actual-names.md (§3 колонки) |
| Суть | P2.1 — удалить недостижимый `ProcurementStatus::Announced` (D16). P2.2 — `StatusMappingSeeder` для `fz44` (D17), устраняет `unknown`-дефект |

````
Launch-block (P2.1):
1. Прочитать РОВНО: plans/DOMLANG/plan.md (§2 Execution Rules, §5 D5/D10/D13/D16) →
   handoff.md (этот) → plans/DOMLANG/phases/P2.md (Phase Context + item P2.1 целиком) →
   plans/DOMLANG/findings/P2-eis-stages.md.
2. Решение по Announced УЖЕ принято (D16). Задача — проверить предпосылку
   (grep -rn 'Announced' app/ database/ tests/ = ровно 3 вхождения, 2 из них в фабриках)
   и применить. Не совпало → 🔴 Blocked + эскалация, ничего не удалять.
3. Канон имён для P2–P5 — findings/P1-actual-names.md + database/migrations/**, НЕ спеки docs/specs/v2/**.
4. Тесты — Pest, test('<интент>'), БД agelto_test; LLM — только FakeLlmProvider.
5. Закрыть item, синхронизировать Phase Status + Status Board, перезаписать handoff.md, один коммит.

Автопрогон фазы: Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P2' } })
````

**Done:** P1 терминальна — 3/3 (2🟢 + 1🟠).
- ✅ P1.1 — `findings/P1-actual-names.md`: карта 48 таблиц (43 `prc_*` + 4 `ref_*` + 1 `org_*`),
  13 мёртвых имён, ключевые колонки P3/P4. Item-review: 0 blocking, 2 LOW — обе исправлены.
- ✅ P1.2 — ADR-0006/0007/0008 (bounded contexts / OCDS / thin provenance) + индекс ADR
  в `architecture.md`. Item-review: 0 находок; изоморфизм `prc_events` ≈ OCDS releases верифицирован.
- 🟠 P1.3 — pre-mortem P3/P4 по Klein: 5 сценариев → 3 кластера → mitigations D11–D13.
  **Материальная находка:** target-миграции P3 уже на `origin/main` → предпосылка §1/D10 опровергнута.
  Эскалация отработала штатно и **закрыта** (D14). Статус 🟠 — запись истории, открытого долга нет.

**Owner-решения 2026-07-10 (D14, D15):**
- **D14 (Q5):** проект нигде не развёрнут, применённых persistent/CI-сред нет → условие безопасности
  edit-in-place из D11 выполнено. P3 правит create-table-миграции **на месте** + `migrate:fresh`;
  forward alter-миграции не заводим. Hard-гейт `P3.1` понижен до preflight-диагностики (в `workflow.js`
  тоже). **Триггер отката к D11:** первая persistent-среда, применившая миграции.
- **D15 (Q6):** §4 `findings/P1-actual-names.md` вычищен от преждевременного дизайна provenance
  (`provenance_source` / `provenance_level` / `analysis_confidence` — 0 продюсеров, 0 потребителей,
  воспроизводили отклонённую ADR-0008 `*-derived`-схему). Заменён процедурой «proof → потребитель →
  схема». Узаконивание в ADR-0008 отвергнуто.

**Remaining:** 20 item в P2–P9. **P2 детализирована** и готова к exec; P3–P9 — скелеты,
каждая перед exec требует `/task:plan-design DOMLANG Pn`.

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!).
  §4 больше не содержит гипотез: колонки provenance заводит только P4.1 через proof + потребителя.
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D17, §6 Update Log, §4 Status Board).
- `plans/DOMLANG/findings/P2-eis-stages.md` ← RAG-канон этапов ЕИС + матрица достижимости enum.
- `database/migrations/**` ← источник истины на уровне таблиц/колонок.
- `plans/DOMLANG/open-questions.md` ← Q4 (для P4.1), Q7 (`Exploration`). Q1/Q2/Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Ренейм P3 (D12): `source_number` полисемичен (procurements / ignored_items / protocols); токен-replace
  сломает protocols/ignored_items. `prc_status_mappings.source_status` + сидер P2 — синхронить атомарно.
- Верификация P3 — только полный `migrate:fresh` + зелёный сюит; grep zero-residue недостаточен (D14).
- `prc_status_mappings` не сидируется — все закупки уедут в `unknown` (закрывает P2.2, D17).
- Реестр, созданный ПОСЛЕ прогона сида, остаётся без маппингов до повторного `db:seed` (Q7).
- `etp_number` без продюсера (D13); provenance без доказанного потребителя (D13, ADR-0008).
- Единственный оставшийся гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`).
- Gemini 2.5 EOL: 2026-10-16 (вне плана).

**Workarounds/Deferred/Open questions:**
- Workarounds: нет.
- Deferred: Q3 (`etp_number` мёртвая колонка) — `Resolved → D5`, mitigation в D13 (P3.3 round-trip).
- Open questions (`open-questions.md`): Q4 (конфликтные поля provenance → P4.1), Q7
  (автопровижининг маппингов при создании реестра → онбординг второй площадки). Owner-гейтов нет.
