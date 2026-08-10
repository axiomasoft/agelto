---
id: D66
date: 2026-08-10
status: superseded
item: P1.7
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P1.6, P1.7]
supersedes: []
superseded_by: D67
---
## Решение

RED-аудит P1, заход 2, закрывается ровно одним remediation-item P1.7. Реопен снимает terminal
статус P1, повышает plan version `0.1.2` → `0.1.3`, фиксирует причину этой D-записью и
инвалидирует `## Phase Handoff` маркером `> УСТАРЕЛ — фаза реопенена D66`. Layout v2 Status Board
до первого execution event P1.7 показывает nonterminal `⬜ Not started`, а не общий reopen-канон
`🟡 In progress`: projector читает только execution events item state, поэтому fabricated event не
создаётся. Это явное protocol deviation/PROMOTE-CANDIDATE, как в D64. RAG:— (repo-grounded:
`phases/P1/P1.md` → последний Audit P1, `research/18-P1-audit2-remediation.md`, Task
`packages/task/lib/task/plan_status.py::_phase_status`).

P1.7 выбирает новый clean product commit как единый full-Validation anchor P1.6 вместо добавления
product/post-close mode: перед product commit plan views уже fresh, а product-only alias removal не
меняет их inputs. P1.1–P1.6 получают только append-only correction events с exact lint/tree anchors.
Journal lines 90–94 и 95–99 сохраняются byte-for-byte и считаются одним logical reconciliation,
записанным дважды; это immutable process deviation, не два product executions. RAG:—
(repo-grounded: audit F6–F10 и `journal.jsonl:90-99`).

## Почему

F6–F10 относятся к одному release-carrier/evidence hotspot. Alias removal меняет малый product
surface, после чего тот же тяжёлый carrier одновременно даёт воспроизводимый clean anchor для
исправления Completion Notes и exact lint/tree evidence. Разделение на несколько items повторило
бы Docker/Vite gates и создало промежуточную ложную terminality.

## Alternatives

1. Развести product/post-close modes в `p1-release-contract.sh`. Цена: новый contract и две точки
   интерпретации, хотя единый carrier воспроизводим после fresh plan-design commit.
2. Один P1.7 с новым clean full-carrier product anchor и append-only corrections. Цена: тяжёлый
   повтор Validation, зато одна проверяемая acceptance-команда и неизменная история.
3. Переписать journal lines 90–99 или generated states вручную. Цена: нарушение append-only/v2 SSOT.

## Consequences

Выбрана альтернатива 2. План содержит 78 items. P2 не стартует до terminal P1.7, штатного
`task:plan-close ... P1` и fresh-context GREEN-аудита P1. D16–D18/D25/D57/D63–D65 и доказанные
Docker/Compose/Vite/image/deploy-class semantics не меняются.
