---
id: D62
date: 2026-08-10
status: accepted
item: P0.7
items: [P0.7]
supersedes: []
superseded_by: null
---
## Решение

RED-аудит P0, заход 1, закрывается ровно одним remediation-item P0.7. Реопен выполнен четырьмя
обязательными шагами: generated Status Board переводит P0 в `🟡 In progress`; plan version
повышается из отсутствующего legacy carrier `—` до `0.1.0`; эта D-запись фиксирует причину;
`## Phase Handoff` P0 инвалидирован маркером `> УСТАРЕЛ — фаза реопенена D62`. P0.7 не является
новым design-pass и не меняет frozen readiness semantics или P1–P12 specs. RAG:—
(repo-grounded: `phases/P0/P0.md` → `## Audit P0 — 2026-08-10`, owner remediation scope 2026-08-10).

## Почему

F1–F3 ломают проверяемость deliverables P0, а F4–F5 относятся к той же локальной бухгалтерии.
Один атомарный item сохраняет provenance: script/D60 меняются item-коммитом, terminal state и
close anchors — только append-only reconciliation и штатной regeneration Layout v2.

## Alternatives

1. Исправлять findings отдельными items. Цена: пять закрытий и расширенная поверхность нового аудита.
2. Один P0.7 с детерминированной Validation. Цена: item связывает script tests и plan reconciliation,
   но остаётся в одном audit-defined scope.
3. Чинить Swissknifeman runtime вместе с P0. Цена: внешний scope и параллельная запись в общий canon.

## Consequences

Выбрана альтернатива 2. P1 временно не исполняется до повторного close и GREEN-аудита P0. Общий
Task write-site invariant помечен `PROMOTE-CANDIDATE`, но не блокирует P0.7 и не правится здесь.
