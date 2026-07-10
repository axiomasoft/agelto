# HANDOFF — 2026-07-10 — after P8.2

**Next:** Фаза P8 терминальна (2/2 🟢 Done). Следующий шаг — фаза P9 (финальный cross-phase
review), но `phases/P9.md` — **скелет** (все три item'а с пустыми 16 полями, Phase Context
помечен «СКЕЛЕТ — не детализирован»). Прямой `/task:plan-exec DOMLANG P9.1` упадёт на
предусловии DoR — сначала нужен `/task:plan-design DOMLANG P9`.

| Параметр | Значение |
|:--|:--|
| Model | opus/high |
| Thinking | high — детализация терминальной cross-phase review фазы (не механика), нужен план fan-out + verification харнесса |
| Context | fresh session — handoff свежий, рекомендован `/clear` |
| Суть | Детализировать P9: P9.1 (cross-phase adversarial review, opus/xhigh, образец — `estai-milestone-review`), P9.2 (`plan-audit` по каждой закрытой фазе), P9.3 (owner-item `/code-review ultra`, D9) |

```
/task:plan-design DOMLANG P9
```

**Done:** P1–P8 терминальны (см. предыдущие handoff-снапшоты и Update Log `plan.md` §6). P8.2 🟢
Done — создан `docs/03_Dev/entity-lifecycle-map.md`: таблица писателей пяти сущностей
(`Procurement`/`ProcurementRevision`/`ProcurementEvent`/`Document`/`DocumentVersion`) по §5
`findings/P8-status-writers.md`, каждый анкор (`file:line`) перепроверен чтением файла без
расхождений; раздел «повтор / новая ревизия / новая версия документа» с тремя критериями
(no-op при совпадении хэша; `snapshot_hash` → ревизия; `content_hash` → версия), обе оси названы
независимыми; раздел immutable-сущностей; ссылки на `ocds-mapping.md`, ADR-0007,
`document-pipeline.md` (Д1, не пересказан), `state-machines.md` (P8.1) — без дублирования. Все 6
команд Validation — passed. Правок `app/`/`database/`/`tests/` не вносилось (docs-only гарантия
фазы P8 выполнена целиком).

**Remaining:** P9 (0/3, скелет) — детализация → исполнение трёх items, включая owner-item P9.3
(`/code-review ultra`, D9, запускает владелец, не агент).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §4 Status Board (P8 2/2 🟢, P9 0/3 ⬜), §5 D34/D35, §6 Update Log.
- `plans/DOMLANG/phases/P8.md` — терминальна, P8.1/P8.2 Completion Notes, Phase Handoff.
- `plans/DOMLANG/phases/P9.md` — скелет, три пустых item'а, ждёт `/task:plan-design`.
- `docs/03_Dev/state-machines.md` (P8.1) и `docs/03_Dev/entity-lifecycle-map.md` (P8.2) — оба
  деливерабла фазы P8, канон представления состояний и жизненного цикла сущностей.
- `docs/03_Dev/document-pipeline.md` — Д1, `docs/03_Dev/ocds-mapping.md` — P7.2,
  `docs/03_Dev/adr/0007-ocds-as-exchange-layer.md` — все три переиспользованы ссылкой, не
  продублированы.

**Open risks:**
- `plans/DOMLANG/workflow-P6.js`, `plans/DOMLANG/workflow-P8.js` — untracked, не закоммичены
  (вне Scope P8.2, относятся к предыдущим сеансам P6/P8-дизайна). Не принимать за дефект этого
  шага; решение об их судьбе (закоммитить/удалить) — за P9 или отдельным итемом.
- Остаток грепа спек-дрейфа P6 (42 строки KEEP-v1/N-A) — замороженный легитимный residue
  (`findings/P6-drift-map.md`), не дефект.

**Workarounds/Deferred/Open questions:**
- Workaround mermaid: `stateDiagram-v2` ломается на `Class::method()` — использовать
  `Class.method()` (прецедент — Д1, применено в `state-machines.md`; P8.2 диаграмм не рисует).
- Deferred: `docs-private/history/carryover-from-v1.md` §3/8 — вне Scope P6 (не rename).
- Deferred (из P5): unique-индексы под естественные ключи `prc_financing`/`prc_items`/
  `prc_protocols`/`prc_awards` (класс D26, вне DOMLANG).
- Open questions (`open-questions.md`): Q7/Q8 — `Exploration`, без изменений (P8 не задел).
