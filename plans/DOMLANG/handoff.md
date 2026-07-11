# HANDOFF — 2026-07-11 — after P9.3 (owner-gate prepared, awaiting owner run)

**Next:** P9.3 — owner-gate. Агент довёл вход до готовности-к-передаче и **СТОПнул на 🟡** (D9:
`/code-review ultra` — user-triggered/billed, агент её не инициирует). Дерево почти чисто (см.
Known Deviations ниже — 1 untracked plan-tooling файл, не код). P9.1 (`ATTENTION`, 0 Blocker/RED,
reopen нет) и P9.2 (`ATTENTION`, 0 Blocker, 2 Major процессные — вне reopen) терминальны и
разобраны — предпосылка запуска ultra выполнена.

**Владелец, запусти:**

| Параметр | Значение |
|:--|:--|
| Команда | `/code-review ultra` — локальная ветка `feat/v2-scaffold` (PR не заведён; альтернатива —
завести PR и запустить `/code-review ultra <PR#>`) |
| На что смотреть | (1) связность домена после P3-ренейма (`source_number`→`registry_number` и
т.д., D5/D12 — 3 таблицы с разными концептами, ренейм НЕ должен был задеть `prc_protocols`/
`prc_ignored_items`); (2) spec drift P6 закрыт — мёртвые префиксы `doc_`/`est_`/`ai_`/`crw_`/
`sys_`/`plt_` отсутствуют в `app/`/`database/migrations/` вне легитимного P6-drift-map residue;
(3) provenance thin slice P4 — материализованы только доказанные поля (ADR-0008), спекулятивных
колонок нет; (4) `prc_status_mappings` сидируется (P2, `canonical_status ≠ 'unknown'`); (5) guard
двойного dispatch в `PipelineDispatcher`/`PrepareDocumentJob` (P5, ADR-0009) |
| Контекст для ревью | `plans/DOMLANG/plan.md` (Decision Log D1–D14+), `plans/DOMLANG/findings/P9-milestone-review.md` (P9.1 вердикт ATTENTION + 6 уникальных находок, все MED/LOW, 0 Blocker), `plans/DOMLANG/phases/P1.md`…`P8.md` (`## Audit Pn` — P9.2 вердикт по фазе) |
| После прогона | Передай агенту находки ultra (или подтверди «чисто») — агент разберёт: Blocker → reopen-тикет в фазу-владельца (D37); чисто → P9.3 закрывается `🟢` с твоим sign-off в Completion Notes |

```
/code-review ultra
```

**Done:** P1–P8 терминальны. P9.1 🟢 (milestone-харнесс, ATTENTION, 0 reopen). P9.2 🟢 (plan-audit
P1–P8, ATTENTION-роллап, 0 Blocker, 0 reopen). P9.3 — вход подготовлен, ждёт прогона владельца.

**Remaining:**
- **P9.3** (owner-gate) — дождаться подтверждённого прогона `/code-review ultra` владельцем,
  разобрать находки (Blocker → reopen по D37; чисто → закрыть `🟢` с sign-off). До этого момента
  статус item'а остаётся `🟡`.
- После закрытия P9.3 — фаза P9 терминальна (3/3), план DOMLANG переходит в состояние,
  готовое к `plan-close`/архивации (владелец решает судьбу `plans/DOMLANG/workflow-P6.js` при
  архивации, вне scope P9).

**Sources of truth:**
- `plans/DOMLANG/plan.md` §5 D9 (ultra — только владелец), §3 Routing (P9.3 → opus/xhigh).
- `plans/DOMLANG/findings/P9-milestone-review.md` — вердикт P9.1.
- `plans/DOMLANG/phases/P9.md` — P9.1/P9.2 Completion Notes, P9.3 текущий статус `🟡` +
  Pending Work (ожидание прогона владельца).
- `plans/DOMLANG/phases/P1.md`…`P8.md` — append-блоки `## Audit Pn` (P9.2).

**Open risks:**
- **Untracked plan-tooling residue:** `plans/DOMLANG/workflow-P6.js` — НЕ code-defect (диф
  приложения от него не зависит); `git status --porcelain` из-за него не пуст. Судьба
  (commit/gitignore/удаление) — за владельцем на plan-close, вне Scope P9.3 (Scope Excluded
  явно называет этот файл). Ultra ревьюит закоммиченный дифф ветки — untracked-файл в обзор не
  попадёт, но формальная Validation-команда P9.3 (`git status --porcelain` = пусто) не проходит
  буквально из-за него — зафиксировано как Known Deviation, не блокирует передачу владельцу.
- **2 Major (процессные, вынесены владельцу, из P9.2):** routing-адхеренс P6.1 (haiku/low пинн vs
  Update Log sonnet, undocumented) и P7 review-лейн opus/high без записи о прогоне (контент
  переверифицирован корректным дважды). Корректностного дефекта нет → не reopen.
- **3 PROMOTE-CANDIDATE для plan-protocol (вынесены владельцу):** per-leg routing records;
  plan-design коммитит дифф отдельно; DoR-гейт Scope↔Validation консистентность.
- **F1 (MED, owner-disposition, из P9.1):** `ShadowAnalysisJob::onQueue('ai_shadow')` без Horizon-
  супервайзера — вне scope DOMLANG (pre-existing EST-AI).

**Workarounds/Deferred/Open questions:**
- workarounds: —
- deferred: коррекция Update Log в plan.md + решение по review-лейну P7 + 3 PROMOTE-CANDIDATE —
  владельцу/plan-design; F1 `ai_shadow` — вне DOMLANG; судьба `workflow-P6.js` — plan-close.
- open_questions: `open-questions.md` Q1–Q8 без изменений; P9.3 — owner-gate, новых не порождает.
