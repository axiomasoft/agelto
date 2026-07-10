# HANDOFF — 2026-07-10 — after P1.3 + owner-решения Q5/Q6 (фаза P1 закрыта, эскалация снята)

**Next:** `/task:plan-design DOMLANG P2` (развернуть скелет фазы), затем exec `P2.1`.
Эскалация P1.3 закрыта владельцем: Q5 → `Resolved → D14` (edit-in-place + `migrate:fresh`),
Q6 → `Resolved → D15` (§4 карты имён вычищен). Блокеров перед P2/P3 нет.

| Параметр | Значение |
|:--|:--|
| Model | P2-design: opus/high; P2.1 exec: opus/high + RAG (этапы ЕИС — внешний факт, память недопустима) |
| Thinking | enabled |
| Context | fresh; холодный старт: plan.md (§5 D1–D15, §4 Status Board) + phases/P2.md + findings/P1-actual-names.md (§3 колонки) + open-questions.md (Q1, Q2) |
| Суть | P2 — сиды `prc_status_mappings` (устраняет `unknown`-дефект) + аудит достижимости `ProcurementStatus::Announced` (Q1, правило D10) |

````
Launch-block (P2):
1. /task:plan-design DOMLANG P2 — развернуть скелет P2 (Items P2.1 сиды status_mappings,
   P2.2 аудит Announced) по D8/D10, Q1/Q2. RAG обязателен для этапов ЕИС (§3 Routing).
2. Прочитать РОВНО: plan.md (§5 Decision Log, §2 Execution Rules) → handoff.md (этот) →
   phases/P2.md → findings/P1-actual-names.md → findings/P1-audit-verification.md §4.2.
3. Канон имён для P2–P5 — findings/P1-actual-names.md + database/migrations/**, НЕ спеки docs/specs/v2/**.
4. Тесты — Pest, `test('<интент>')`, БД agelto_test; LLM — только FakeLlmProvider.
5. Закрыть item, синхронизировать Phase Status + Status Board, перезаписать handoff.md, один коммит.

Автопрогон фазы: Workflow({ scriptPath: 'plans/DOMLANG/workflow.js', args: { phase: 'P2' } })
````

**Done (P1, терминальна — 3/3, 2🟢 + 1🟠):**
- ✅ P1.1 — `findings/P1-actual-names.md`: карта 48 таблиц (43 `prc_*` + 4 `ref_*` + 1 `org_*`),
  13 мёртвых имён, ключевые колонки P3/P4. Item-review: 0 blocking, 2 LOW — обе исправлены.
- ✅ P1.2 — ADR-0006/0007/0008 (bounded contexts / OCDS / thin provenance) + индекс ADR
  в `architecture.md`. Item-review: 0 находок; изоморфизм `prc_events` ≈ OCDS releases верифицирован.
- 🟠 P1.3 — pre-mortem P3/P4 по Klein: 5 сценариев → 3 кластера → mitigations D11–D13.
  **Материальная находка:** target-миграции P3 уже на `origin/main` → предпосылка §1/D10 опровергнута.
  Эскалация отработала штатно; развилка ушла владельцу и **закрыта** (D14).

**Owner-решения 2026-07-10 (D14, D15):**
- **D14 (Q5):** проект нигде не развёрнут, применённых persistent/CI-сред нет → условие безопасности
  edit-in-place из D11 выполнено. P3 правит create-table-миграции **на месте** + `migrate:fresh`;
  forward alter-миграции не заводим. Hard-гейт `P3.1` понижен до preflight-диагностики (в `workflow.js`
  тоже). **Триггер отката к D11:** первая persistent-среда, применившая миграции.
- **D15 (Q6):** §4 `findings/P1-actual-names.md` вычищен от преждевременного дизайна provenance
  (`provenance_source` / `provenance_level` / `analysis_confidence` — 0 продюсеров, 0 потребителей,
  воспроизводили отклонённую ADR-0008 `*-derived`-схему). Заменён процедурой «proof → потребитель →
  схема». Узаконивание в ADR-0008 отвергнуто.

**Remaining:** 20 item в P2–P9. P2–P9 — скелеты, каждая фаза перед exec требует
`/task:plan-design DOMLANG Pn`.

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!).
  §4 больше не содержит гипотез: колонки provenance заводит только P4.1 через proof + потребителя.
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D15, §6 Update Log, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок.
- `plans/DOMLANG/open-questions.md` ← Q1/Q2 (для P2.1), Q4 (для P4.1). Q3/Q5/Q6 — `Resolved`.
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Ренейм P3 (D12): `source_number` полисемичен (procurements / ignored_items / protocols); токен-replace
  сломает protocols/ignored_items. `prc_status_mappings.source_status` + сидер P2 — синхронить атомарно.
- Верификация P3 — только полный `migrate:fresh` + зелёный сюит; grep zero-residue недостаточен (D14).
- `prc_status_mappings` не сидируется — все закупки уедут в `unknown` (P2.1, дефект из аудита §4.2).
- `etp_number` без продюсера (D13); provenance без доказанного потребителя (D13, ADR-0008).
- Единственный оставшийся гейт автопрогона — phase-гейт после P3 (`approvals["after:P3"]`).
- Gemini 2.5 EOL: 2026-10-16 (вне плана).

**Workarounds/Deferred/Open questions:**
- Workarounds: нет.
- Deferred: Q3 (`etp_number` мёртвая колонка) — `Resolved → D5`, mitigation в D13 (P3.3 round-trip).
- Open questions (`open-questions.md`): Q1 (`Announced` достижимость → правило D10, P2.1), Q2
  (`Unsuccessful` vs `Cancelled` → P2.1), Q4 (конфликтные поля provenance → P4.1). Owner-гейтов нет.
