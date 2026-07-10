# HANDOFF — 2026-07-10 — after P1.1

**Next:** P1.2 (ADR-0006/0007/0008), затем P1.3 (pre-mortem P3/P4)

| Параметр | Значение |
|:--|:--|
| Model | P1.2–P1.3: opus/xhigh (архитектурные решения требуют рассуждения) |
| Thinking | enabled (сложные решения с ADR-форматом) |
| Context | fresh; холодный старт: чтение plan.md + P1.md + audit-verification |
| Суть | P1.2: три ADR (D2/D3/D4 → bounded contexts/OCDS/provenance). P1.3: pre-mortem по Klein для P3/P4. |

````
P1.1 завершён ✅ : создан `plans/DOMLANG/findings/P1-actual-names.md` — машинная карта 48 таблиц домена 
(42 prc_* + 4 ref_* + 1 org_*), 13 мёртвых имён, ключевые колонки, каждая строка с file:line ссылкой.
Validation: ✅ мёртвых имён как таблиц = 0 (grep ложноположительные на подстроки в prc_ai_* и комментарии); 
✅ таблицы все учтены (48/48); ✅ discrepancies раздел (§5) заполнен; ✅ пропущенная prc_ai_corrections добавлена.
Источник истины установлен: миграции > P1-actual-names > docs/specs/v2 > docs/03_Dev/database.md.
````

Исполнитель P1.2: обосновать отклонение рекомендаций аудита три ADR-файлами в `docs/03_Dev/adr/`.
Исполнитель P1.3: провести pre-mortem по Klein для P3/P4, записать mitigations в D-лог.
Далее: P2–P5 (код), используя P1-actual-names как канон, не спеки.

**Done:** 
- ✅ P1.1 — карта имён (48 таблиц домена с file:line ссылками, 13 мёртвых имён, пропущенная prc_ai_corrections добавлена)
- ✅ Validation: мёртвых имён таблиц = 0 (grep ложноположительные); таблицы все учтены; discrepancies раздел ✅
- ✅ Исправлены blocking findings: grep interpretation + prc_ai_corrections инвентаризация + file:line references + etp_number contradiction

**Remaining:** 21 item в P1–P9. Фаза P1 (2/3 items), P2–P9 — полный объём.

**Sources of truth:** 
- `plans/DOMLANG/findings/P1-actual-names.md` ← канон имён для P2–P5 (НЕ спеки!)
- `plans/DOMLANG/plan.md` (Decision Log для D1–D10, Update Log)
- `database/migrations/**` ← источник истины на уровне таблиц
- `docs/03_Dev/adr/` ← новые ADR-0006/0007/0008 в P1.2
- `docs-private/risk-register.md` ← риски, которые разбирает P1.3

**Open risks:**
- До закрытия P6 спеки `docs/specs/v2/**` — **ненадёжный источник** (166 мёртвых префиксов). 
  Используйте P1-actual-names.md.
- P3 правит published миграции на месте. Hard-гейт P3.1: `git log origin/main -- database/migrations/` должен вернуть empty.
- `prc_status_mappings` не сидируется (P2.1).
- `PipelineDispatcher` не мутирует статус (P5.1).
- Gemini 2.5 EOL: 2026-10-16 (вне плана, но скоро).

**Workarounds/Deferred/Open questions:**
- Workarounds: нет.
- Deferred: Q3 (`etp_number`: сразу или после источника) решается в pre-mortem P1.3.
- Open questions: `open-questions.md` (4 записей, 1 требует решения owner'а).
