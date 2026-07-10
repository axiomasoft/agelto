# HANDOFF — 2026-07-10 — after P8.1

**Next:** P8.2 (`docs/03_Dev/entity-lifecycle-map.md`) — детализирован (DoR пройден), исполняется
поитемно. Можно продолжить runner'ом фазы (P8.2 impl+review → phase-review веером) либо напрямую:

| Параметр | Значение |
|:--|:--|
| Model | sonnet/medium (impl) + opus/high (review) — plan.md §3 |
| Thinking | medium — docs-only транскрипция по пред-анкорам (`findings/P8-status-writers.md` §5), не расследование |
| Context | fresh session — handoff свежий; findings несёт все код-анкоры сущностей |
| Суть | Создать `docs/03_Dev/entity-lifecycle-map.md`: таблица «сущность → таблица → создатель → мутатор» для `Procurement`/`ProcurementRevision`/`ProcurementEvent`/`Document`/`DocumentVersion` + раздел «повтор / новая ревизия / новая версия документа» (`snapshot_hash` ⟂ `content_hash`) |

```
/task:plan-exec DOMLANG P8.2
```

**Done:** P1–P7 терминальны (см. предыдущие handoff-снапшоты и Update Log). P8.1 🟢 Done — создан
`docs/03_Dev/state-machines.md` (5 осей: 2 новых `stateDiagram-v2` для `AnalysisStatus`/`RunStatus`
с рёбрами, анкорёнными на код-сайты; классификация `ProcurementStatus` без transition-графа, D35;
cross-ref на Д1 для `DocumentStatus`, не дублирован, D34; раздел «не FSM» для
`PipelineStage`/`AnalysisStep`) + однострочная обратная ссылка из `document-pipeline.md`. Все 7
команд Validation — passed. Находка сверх findings: `RunStatus::Cancelled` не имеет писателя в коде
(`grep -rn 'RunStatus::Cancelled' app/` = 0) — ребро НЕ нарисовано, кейс помечен недостижимым без
продюсера (тот же класс, что forward-provision `ProcurementStatus::Awarded`/`Unsuccessful`, но без
предшествующей D-декларации).

**Remaining:** P8.2 (0/1 в рамках фазы, детализирован), P9 (финальный cross-phase review, включая
owner-item `/code-review ultra` P9.3, D9) — скелет.

**Sources of truth:**
- `plans/DOMLANG/plan.md` §3 Routing (P8), §4 Status Board (P8 1/2 🟡), §5 D34/D35, §6 Update Log.
- `plans/DOMLANG/phases/P8.md` — P8.1 Completion Notes; P8.2 полное ТЗ, Phase Context, DoR.
- `plans/DOMLANG/findings/P8-status-writers.md` — код-анкоры писателей статусов/сущностей + природа
  каждой оси (§0 неоднородность, §1 ProcurementStatus внешне-маппируется, §5 lifecycle для P8.2).
- `docs/03_Dev/state-machines.md` — новый деливерабл P8.1, канон представления пяти осей.
- `docs/03_Dev/document-pipeline.md` — Д1 (единственная существующая stateDiagram, `DocumentStatus`),
  получил обратную ссылку на `state-machines.md`.

**Open risks:**
- `plans/DOMLANG/workflow-P6.js` — untracked из P6-сеанса, всё ещё не закоммичен (вне Scope P8,
  относится к терминальной P6). Не принимать за дефект своего шага.
- Остаток грепа спек-дрейфа P6 (42 строки KEEP-v1/N-A) — замороженный легитимный residue
  (`findings/P6-drift-map.md`), не дефект.

**Workarounds/Deferred/Open questions:**
- Workaround mermaid: `stateDiagram-v2` ломается на `Class::method()` — использовать `Class.method()`
  (прецедент — Д1; применено и в `state-machines.md`).
- Deferred: `docs-private/history/carryover-from-v1.md` §3/8 — вне Scope P6 (не rename).
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений (P8.1 не задел).
