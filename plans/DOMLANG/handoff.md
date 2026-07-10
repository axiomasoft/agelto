# HANDOFF — 2026-07-10 — after P7.2

**Next:** `P8` — фаза «Lifecycle-map и state machines единым документом» ещё скелет (0/2, Status
Board), детализации (Required Reads/Implementation Rules/Validation по items) нет — прямой exec
упадёт на предусловии. Следующий шаг — plan-design фазы P8.

| Параметр | Значение |
|:--|:--|
| Model | opus/high |
| Thinking | high — детализация фазы (Scope/Required Reads/Validation по items), несущие решения по объёму lifecycle-map, `plan.md` §Routing (P8 без явного отклонения — по умолчанию design = opus/high) |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Детализировать P8: скелеты items lifecycle-map/state machines единым документом (объединение FSM `ProcurementStatus`/`DocumentStatus`/`AnalysisStatus`/… + связей между ними) |

```
/task:plan-design DOMLANG P8
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 (2/2 🟢). P3 (3/3 🟢). P4 (1/2 🟠 — `P4.2` ⛔).
P5 (4/4 🟢). P6 терминальна (2/2 🟢) — spec drift убит. **P7 терминальна (2/2 🟢)** — оба
глоссария дополнены 8 терминами D33 (P7.1); `docs/03_Dev/ocds-mapping.md` создан (P7.2) —
mapping-таблица `prc_procurements`/`prc_revisions`/`prc_events` ↔ OCDS
`compiledRelease`/`versionedRelease`/`releases` + секции `tender`/`award`/`parties`/`documents`,
явно объявляющая OCDS экспортным слоем (ADR-0007) с триггером пересмотра; некрытые секции
(`planning`/`contract`/`implementation`) помечены явно, не пропущены молча. Все 5 команд Validation
P7.2 passed; поля таблицы анкорены напрямую на миграции `prc_procurements`/`prc_revisions`/
`prc_events`/`prc_lots`/`prc_procurement_parties`/`prc_awards`/`prc_documents` (`docs/03_Dev/
database.md` не несёт полевого канона этих 7 таблиц — только AI-пайплайн).

**Remaining:** P8 (lifecycle-map/FSM, скелет — 0/2), P9 (финальный cross-phase review, включая
owner-item `/code-review ultra` P9.3, D9) — скелеты.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Phase Index & Status Board (P7 2/2 🟢), §5 D33 (маппинг 8 терминов),
  §6 Update Log (P7.1/P7.2 записи).
- `plans/DOMLANG/phases/P7.md` — P7.1/P7.2 Completion Notes, Phase Handoff (фаза терминальна).
- `docs/03_Dev/ocds-mapping.md` — новый файл, сам является source of truth для будущего экспортёра.
- `docs/03_Dev/adr/0007-ocds-as-exchange-layer.md`, `docs/03_Dev/adr/0008-thin-provenance-slice.md`.
- `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md` — актуальное состояние после P7.1.

**Open risks:**
- `plans/DOMLANG/workflow-P6.js` — untracked файл из более раннего (P6) design-сеанса, всё ещё не
  закоммичен (не задет и этим item'ом — вне Scope P7, относится к уже терминальной фазе P6).
  Следующая сессия, работающая с git-деревом DOMLANG, не должна принять его за дефект своего шага;
  коммитить его стоит той сессии, что явно возьмётся за P6-хвосты (их сейчас нет открытых).
- Остаток грепа спек-дрейфа P6 (42 строки KEEP-v1/N-A) — заморожённый легитимный residue, сверяться
  с `findings/P6-drift-map.md`, не считать дефектом заново.

**Workarounds/Deferred/Open questions:**
- Deferred: `docs-private/history/carryover-from-v1.md` §3/8 — вне Scope P6 (не rename).
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений.
