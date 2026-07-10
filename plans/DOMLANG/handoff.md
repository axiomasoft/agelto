# HANDOFF — 2026-07-10 — after P7.1

**Next:** `P7.2` — `docs/03_Dev/ocds-mapping.md` (таблица `prc_*` ↔ OCDS). Фаза P7 уже детализирована
(DoR пройден, D33), item готов к прямому exec — новое расследование не требуется.

| Параметр | Значение |
|:--|:--|
| Model | sonnet/medium |
| Thinking | medium — реализация в уже детализированной фазе, факты верифицированы дизайном (D33), Routing `plan.md` §3 |
| Context | fresh session — handoff свежий, рекомендован /clear |
| Суть | Создать `docs/03_Dev/ocds-mapping.md` — mapping-таблица `prc_procurements`/`prc_revisions`/`prc_events` ↔ OCDS `release`/`record`/`compiledRelease`/`versionedRelease` + секции tender/award/parties/documents |

```
/task:plan-exec DOMLANG P7.2
```

**Done:** P1 терминальна (3/3: 2🟢 + 1🟠). P2 (2/2 🟢). P3 (3/3 🟢). P4 (1/2 🟠 — `P4.2` ⛔).
P5 (4/4 🟢). P6 терминальна (2/2 🟢) — spec drift убит. **P7.1 🟢 Done (1/2)** — оба глоссария
(`.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md`) дополнены 8 терминами D33:
`ProcurementRevision`/`ProcurementEvent` — новые строки в таблице «Доменные сущности»/«Основные
сущности»; `projection`/`provenance`/`quality flag`/`notice`/`registry number`/`ETP number` — новый
под-раздел «OCDS/оспариваемые термины» в каждом файле, с явным контрастом `quality flag`
(`EstimateValidationStatus`) vs `ReviewStatus` (операторское ревью). Все 5 Validation-команд
passed. Пункт также закоммитил ранее незакоммиченный дизайн фазы P7 (`plan.md` D33 + Update Log,
`phases/P7.md` DoR/скелеты items, `workflow-P7.js`), найденный уже изменённым/untracked в рабочем
дереве к началу exec-сессии.

**Remaining:** `P7.2` (mapping-таблица OCDS). После P7 — P8 (lifecycle-map/FSM), P9 (финальный
cross-phase review, включая owner-item `/code-review ultra` P9.3, D9) — скелеты.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §5 D33 (маппинг 8 терминов), §4 Phase Index & Status Board (P7 1/2 🟡).
- `plans/DOMLANG/phases/P7.md` — P7.1 Completion Notes, P7.2 целиком (Required Reads, Implementation
  Rules, Validation).
- `docs/03_Dev/adr/0007-ocds-as-exchange-layer.md`, `docs/03_Dev/adr/0008-thin-provenance-slice.md`.
- `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md` — актуальное состояние после P7.1.

**Open risks:**
- `plans/DOMLANG/workflow-P6.js` — untracked файл из более раннего (P6) design-сеанса, не закоммичен
  тогда; вне Scope P7 (относится к уже терминальной фазе P6), не тронут этим item'ом. Следующая
  сессия, работающая с git-деревом DOMLANG, не должна принять его за дефект своего шага.
- Остаток грепа спек-дрейфа P6 (42 строки KEEP-v1/N-A) — заморожённый легитимный residue, сверяться
  с `findings/P6-drift-map.md`, не считать дефектом заново.

**Workarounds/Deferred/Open questions:**
- Deferred: `docs-private/history/carryover-from-v1.md` §3/8 — вне Scope P6 (не rename).
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений.
