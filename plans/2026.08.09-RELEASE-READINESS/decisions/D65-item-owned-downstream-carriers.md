---
id: D65
date: 2026-08-10
status: accepted
item: P1.6
items: [P1.6, P2.5, P10.1, P10.2, P10.3, P10.4, P10.5, P12.5]
supersedes: []
superseded_by: null
---
## Решение

D63 распространяется на семь дополнительно обнаруженных consumers P1.6: P2.5, P10.1–P10.5 и
P12.5. Их Validation получает ровно один item-owned executable carrier из Files; полная прежняя
acceptance-матрица становится обязанностью этого carrier, а не набором independently executable
inline fragments. До реализации consumer отсутствие carrier даёт downstream `unknown`; после
реализации carrier обязан дать единый GREEN/RED. P1.6 отдельно доводит собственный terminal
carrier до прямых AI/docs, citation и Task assertions. RAG:— (repo-grounded:
`research/17-P1.6-downstream-retry-design.md` и `findings/P1.6-downstream-*-cb49b47cd3fe.md`).

## Почему

Первый downstream run на `cb49b47c` исполнил будущие Pint/PHPStan/migration/systemd команды и
prose fragments только потому, что эти семь specs сохранили multi-command Validation. Это не
проверяет P1.6 и нарушает phase-first ownership: будущий consumer должен быть `unknown` до
появления собственного публичного entrypoint.

## Alternatives

1. Удалить общие пути из P1.6 Files или consumer Required Reads — скрывает реальную coupling surface.
2. Игнорировать RED вручную — ломает fail-closed downstream gate.
3. Править marketplace Task runtime внутри Agelto plan — внешний cross-repo scope.
4. Нормализовать семь specs до item-owned carriers — выбранный вариант.

## Consequences

Spec hashes и bundles семи consumers меняются; product scope, Routing, owner gates и их полная
acceptance-матрица остаются прежними. D63 не отменяется: D65 материализует тот же invariant на
новом наборе mechanically discovered consumers. Исторические findings первого P1.6 run остаются
append-only; retry создаёт новый product commit и новые keyed findings, после чего разрешены только
reconciliation/close/audit шаги D64.
