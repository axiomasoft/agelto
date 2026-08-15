---
id: D87
date: 2026-08-14
status: superseded
item: P11.4
items: [P11.4, P11.5, P11.6]
supersedes: []
superseded_by: D88
---
## Решение

P11.4 закрывает все runtime/test Critical/High `Gap`/`Partial` строки risk-test matrix, но не
реализует и не объявляет `Covered` CI-only строку D42. На terminal P11.4 ровно D42 может оставаться
единственным Critical/High `Gap`, причём matrix обязана назвать P11.5 его текущим владельцем.
P11.5 принимает только такой handoff, реализует item-owned CI/workflow carrier, переводит D42 в
`Covered` и оставляет zero Critical/High gaps overall; P11.6 затем запечатывает live-CI,
performance/coverage/flake evidence. D42 не superseded и его floors не ослабляются. RAG:—
(repo-grounded: `research/26-P11.4-acceptance-scope-reconciliation.md`, D42, P11.4/P11.5 specs).

## Почему

Предыдущий P11.4 contract одновременно исключал P11 CI YAML и требовал закрыть D42, хотя D42
фактически и по item Files принадлежит последующему P11.5. Owner-scoped gate сохраняет строгую
последовательность test proof → CI carrier, не подменяет отсутствие workflow процентом покрытия и
делает оба item'а исполнимыми без расширения product scope.

## Alternatives

1. Добавить workflow в P11.4 — отклонено: нарушает sealed Files/scope и дублирует P11.5.
2. Считать D42 закрытым по policy/prose — отклонено: нет tracked workflow и executable proof.
3. Перенести общий zero-gap gate на P11.5, сохранив P11.4 runtime/test gate — принято.

## Consequences

Historical P11.4 `blocked`/`terminal-block` events сохраняются. Возобновление требует нового
route-attested P11.4 attempt по обновлённому spec. P11.4 не может закрыться при любом втором
Critical/High Gap/Partial; P11.5 не может стартовать на таком состоянии и не может закрыться,
пока D42 не доказан item-owned CI carrier. Цена решения — явный межitem handoff в matrix; выигрыш —
нулевой waiver и однозначное владение workflow boundary. RAG:— (repo-grounded:
`journal.jsonl:442-444`, `artifacts/P11-risk-test-matrix.md`).
