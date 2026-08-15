---
id: D64
date: 2026-08-10
status: accepted
item: P1.6
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P1.6]
supersedes: []
superseded_by: null
---
## Решение

RED-аудит P1, заход 1, закрывается ровно одним remediation-item P1.6. Реопен снимает terminal
статус P1, повышает plan version `0.1.1` → `0.1.2`, фиксирует причину этой D-записью и
инвалидирует `## Phase Handoff` маркером `> УСТАРЕЛ — фаза реопенена D64`. Layout v2 Status
Board после штатной regeneration показывает nonterminal `⬜ Not started`, а не требуемый общим
reopen-каноном `🟡 In progress`: projector выводит phase status только из item states и не читает
phase-level `plan-design` event; P1.6 нельзя ложно помечать исполняемым ради иконки. Это явное
protocol deviation/PROMOTE-CANDIDATE, не скрытый четвёртый шаг. P1.6 не является новым design-pass
и не меняет frozen D16–D18/D25/D57. RAG:— (repo-grounded: `phases/P1/P1.md` →
`## Audit P1 — 2026-08-10`, `research/16-P1-audit-remediation.md`, Task
`packages/task/lib/task/plan_status.py::_phase_status`).

## Почему

F1–F2 относятся к одному Make/test/docs release-carrier hotspot, а F3–F5 — к append-only
доказательству закрытия тех же P1 items. Один атомарный slice сохраняет causal provenance и
не заставляет несколько раз поднимать одинаковую Docker/Vite acceptance-матрицу.

## Alternatives

1. Разнести Make targets, dynamic carriers, docs и journal reconciliation по четырём items.
   Цена: промежуточные ложные GREEN/RED состояния и повтор тяжёлых Docker gates.
2. Один P1.6 с product-коммитом, затем append-only reconciliation и штатным phase close.
   Цена: широкий Files, но один audit-defined hotspot и одна terminal acceptance-матрица.
3. Переоткрыть P1.1–P1.5 и переписать их историю. Цена: нарушение append-only provenance.

## Consequences

Выбрана альтернатива 2. План содержит 77 items; P1.1–P1.5 сохраняют исторические события и
получают только новые reconciliation events. P2 не стартует до terminal P1.6, штатного
`task:plan-close ... P1` и fresh-context audit P1. Общий carrier-completeness defect из F2
и Layout v2 phase-reopen status gap остаются `PROMOTE-CANDIDATE`, но plugin/runtime вне scope P1.6.
