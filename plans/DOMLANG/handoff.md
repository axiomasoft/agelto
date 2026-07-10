# HANDOFF — 2026-07-10 — after P1.3 (фаза P1 закрыта, ⚠ активна эскалация)

**Next:** ⚠ ESCALATION-гейт перед P2/P3 — владелец решает `open-questions.md` Q5 (стратегия схемных
правок P3). Pre-mortem P1.3 фактически опроверг предпосылку плана «миграции P3 не в `main`»: все 4
target-миграции уже на `origin/main`. Автопрогон остановлен (`escalated=true`). После решения Q5 —
`/task:plan-design DOMLANG P2` (фаза-скелет), затем exec P2.1.

| Параметр | Значение |
|:--|:--|
| Model | Разрешение Q5 — владелец (не агент). P2-design: opus/high; P2.1 exec: opus/high + RAG (этапы ЕИС) |
| Thinking | enabled |
| Context | fresh; холодный старт: plan.md (§5 D1–D13, §4 Status Board) + phases/P2.md + findings/P1-actual-names.md (§3 колонки) + open-questions.md (Q1, Q2, Q5) |
| Суть | P2 — сиды `prc_status_mappings` (устраняет `unknown`-дефект) + аудит достижимости `ProcurementStatus::Announced` (Q1, правило D10). ДО exec любой схемной фазы (P3) — закрыть Q5 |

````
Launch-block (владелец → Q5, затем P2):
1. ВЛАДЕЛЕЦ решает Q5 (open-questions.md): развёрнут ли `origin/main` где-либо / есть ли CI-БД
   поверх main? Нет → edit-in-place P3 всё ещё безопасен (migrate:fresh), Q5 → Resolved, D10/§1
   Context/P3 Phase Context поправить под факт «на main, но не применено». Да → P3 переходит на
   forward alter-миграции (renameColumn), D10-гейт переформулировать. Правку предпосылки делает
   владелец/plan-design, НЕ exec-агент (plan-protocol §10).
2. После Q5 — /task:plan-design DOMLANG P2: развернуть скелет P2 (Items P2.1 сиды status_mappings,
   P2.2 аудит Announced) по D8/D10, Q1/Q2. RAG обязателен для этапов ЕИС (память недопустима, §3 Routing).
3. Прочитать РОВНО: plan.md (§5 Decision Log, §2 Execution Rules) → handoff.md (этот) →
   phases/P2.md → findings/P1-actual-names.md → findings/P1-audit-verification.md §4.2.
4. Канон имён для P2–P5 — findings/P1-actual-names.md + database/migrations/**, НЕ спеки docs/specs/v2/**.
5. Тесты — Pest, `test('<интент>')`, ag15_test... (agelto_test); LLM — только FakeLlmProvider.
6. Закрыть item, синхронизировать Phase Status + Status Board, перезаписать handoff.md, один коммит.
````

**Done (P1.3):**
- ✅ Pre-mortem P3/P4 по протоколу Klein: 5 обязательных сценариев → 3 кластера → mitigations D11–D13
  (каждая — действие в конкретном item'е с owner'ом), записаны в `plan.md` §5 Decision Log.
- ✅ **Материальная находка:** target-миграции P3 (`100011`/`100002`/`100020`/`100044`) уже на
  `origin/main` (verified `git cat-file`) → предпосылка D10/§1 «edit-in-place» опровергнута; развилка
  → владельцу (`open-questions.md` Q5), зафиксирована mitigation D11 + ESCALATION-блок в P1.md P1.3.
- ✅ Сценарий 2/5: полисемия `source_number` (3 таблицы) и связка `prc_status_mappings.source_status`
  ↔ сидер P2 — D12 (table-scoped impact-карта, анкор-ренейм, zero-residue grep по seeders/factories).
- ✅ Сценарий 3/4: `etp_number` без продюсера (forward-provision D5) + provenance §4 actual-names как
  гипотеза — D13 (P3.3 round-trip-тест; P4.1 proof+потребитель, иначе `⛔ Skipped`).
- ✅ Validation: 5/5 сценариев покрыты (1→D11+Q5, 2→D12, 3→D13, 4→D13, 5→D12); §6 Update Log
  содержит строку; 0 живых `[NEEDS CLARIFICATION]` в P3.md/P4.md (единственные вхождения — boilerplate DoR).
- ✅ Фаза P1 закрыта (Phase Handoff в P1.md заполнен), Status Board: P1 = `🟠 Done with deviations`.

**Remaining:** 20 item в P2–P9 (P1 терминальна). P2–P9 — скелеты, каждая фаза перед exec требует
`/task:plan-design DOMLANG Pn`. Ближайший гейт — Q5 (перед P3, но лучше закрыть до P2, т.к. P2 сидирует
`prc_status_mappings`, которую P3 будет ренеймить).

**Sources of truth:**
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён таблиц/колонок для P2–P5 (НЕ спеки!).
  ⚠ §4 (provenance-колонки) — ГИПОТЕЗА, не спека (D13): P4.1 доказывает, не берёт на веру.
- `plans/DOMLANG/plan.md` (§5 Decision Log D1–D13, §6 Update Log, §4 Status Board).
- `database/migrations/**` ← источник истины на уровне таблиц/колонок.
- `plans/DOMLANG/open-questions.md` ← Q1/Q2 (для P2.1), Q5 (гейт перед P3, owner-decision).
- `docs/03_Dev/adr/0001-0008` ← несущие решения (0006/0007/0008 — DOMLANG P1.2).

**Open risks:**
- ⚠ **Гейт D10 фактически нарушен:** target-миграции P3 на `origin/main` → edit-in-place не гарантирован.
  Разрешается Q5 (владелец). НЕ начинать P3 до закрытия Q5.
- До закрытия P6 спеки `docs/specs/v2/**` и `docs/03_Dev/database.md` — ненадёжный источник имён
  (166+ мёртвых префиксов). Используй `findings/P1-actual-names.md`.
- Ренейм P3 (D12): `source_number` полисемичен (procurements/ignored_items/protocols); токен-replace
  сломает protocols/ignored_items. `prc_status_mappings.source_status` + сидер P2 — синхронить атомарно.
- `prc_status_mappings` не сидируется — все закупки уедут в `unknown` (P2.1, дефект из аудита §4.2).
- `etp_number` без продюсера (D13); provenance без доказанного потребителя (D13, ADR-0008).
- Gemini 2.5 EOL: 2026-10-16 (вне плана).

**Workarounds/Deferred/Open questions:**
- Workarounds: нет.
- Deferred: Q3 (`etp_number` мёртвая колонка) — `Resolved → D5`, mitigation выдана в D13 (P3.3 round-trip).
- Open questions (`open-questions.md`): Q1 (`Announced` достижимость → правило D10, P2.1), Q2
  (`Unsuccessful` vs `Cancelled` → P2.1), Q4 (конфликтные поля provenance → P4.1), **Q5 (стратегия
  схемных правок P3 — `Decision pending (owner needed)`, блокирует P3)**.
