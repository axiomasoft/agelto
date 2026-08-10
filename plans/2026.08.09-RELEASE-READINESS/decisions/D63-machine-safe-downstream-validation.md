---
id: D63
date: 2026-08-10
status: accepted
item: P1.1
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P2.1, P2.2, P2.3, P2.4, P2.6, P3.1, P3.2, P3.3, P3.4, P3.5, P4.2, P10.6, P11.1, P11.2, P11.3, P11.5, P11.6, P12.1, P12.3, P12.6]
supersedes: []
superseded_by: null
---
## Решение

Все механические consumers общих P1 carriers получают один item-owned executable Validation
carrier, объявленный в Files. Пока carrier отсутствует в producer tree, downstream-gate фиксирует
`unknown`; после реализации consumer этот carrier исполняет всю его acceptance-матрицу и обязан
закончиться GREEN. Prose fragments, placeholder commands и самостоятельные будущие команды не
являются machine entrypoints. RAG:— (repo-grounded:
`research/15-P1.1-downstream-gate-remediation.md`, Task 0.11.1
`docs/reference/plan-downstream-gate.md`).

## Почему

Первая попытка P1.1 закрыла собственный product scope, но была переведена в `🔴 Blocked` после
преждевременного запуска Validation 20 будущих consumers. Это нарушает phase-first ownership:
producer не должен реализовывать или доказывать deliverables незапущенного consumer, а gate не
должен принимать его будущее acceptance за текущую регрессию.

## Alternatives

1. Игнорировать downstream RED вручную — запрещено fail-closed gate.
2. Удалить shared paths из Files/Required Reads — скрывает реальную coupling surface.
3. Ослабить Task runtime глобально — внешний cross-repo scope и риск для других планов.
4. Нормализовать plan-side Validation через item-owned carriers — выбранный вариант.

## Consequences

Plan migration-version повышается до `0.1.1`; P1.1 закрывается только после нового recorded
downstream run на committed product tree. Старые RED findings и escalated journal event остаются
append-only provenance. P1.2–P1.5 и остальные mechanically discovered consumers обязаны создать
свои named carriers и не могут заменить перечисленные dynamic/negative checks одним формальным
exit 0. Stale P0.1 line citations после Make rewrite сохраняют прежний blob через
`findings/P0-docker-Makefile-baseline-56ce9660.json`, а не переназначаются на новые строки.
