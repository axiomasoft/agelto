---
id: D68
date: 2026-08-10
status: accepted
item: P2.1
items: [P2.1, P5.2]
supersedes: []
superseded_by: null
---
## Решение

Правило D63/D65 применяется к механически обнаруженному consumer P5.2 для producer P2.1:
P5.2 получает ровно один item-owned executable carrier
`docker/tests/p5.2-settings-contract.sh`, объявленный в Files. Пока carrier отсутствует
в дереве producer-коммита, downstream-gate возвращает `unknown`; после реализации P5.2
carrier исполняет всю её acceptance-матрицу единым GREEN/RED результатом. RAG:—
(repo-grounded: `findings/P2.1-downstream-P5.2-97e91d8a9c33-8fd8e2f67793.md`, D63/D65).

## Почему

Первый downstream run P2.1 выполнил будущие P5.2 Composer, test-preflight и container-runtime
fragments до создания P5.2 carrier и ошибочно выдал RED producer-у. Это нарушает
phase-first ownership: P2.1 не владеет Spatie settings, test DB или будущим runtime P5.2.

## Alternatives

1. Игнорировать RED finding — нарушает fail-closed gate.
2. Удалить реальную P2.1→P5.2 coupling surface — скрывает зависимость.
3. Править Task runtime в Agelto — внешний cross-repository scope.
4. Нормализовать P5.2 до named item-owned carrier — выбранный вариант.

## Consequences

Меняются только P5.2 spec и bundle; proxy design, Routing, Files product scope и полная
acceptance-матрица сохраняются. Исторический RED finding остаётся append-only provenance;
повторный P2.1 downstream run создаёт отдельный keyed finding и разрешает reconciliation
P2.1 без повторной product mutation.
