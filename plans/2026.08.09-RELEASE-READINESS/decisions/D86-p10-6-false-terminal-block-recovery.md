---
id: D86
date: 2026-08-13
status: accepted
item: P10.6
items: [P10.6]
supersedes: []
superseded_by: null
---
## Решение

Владелец разрешил append-only recovery P10.6 после ложного terminal `blocked` на
`journal.jsonl:413`. Новый attempt обязан продолжить исходное P10.6 acceptance: устранить
root type/behavior defects, удалить PHPStan baseline, включить `level: max` без
ignore/exclude/level downgrade и доказать declared quality carrier. Количество PHPStan
findings не является authority/frozen/public/external boundary, потому что exact remediation
уже входит в Scope Included и Implementation Rules P10.6. RAG:— (repo-grounded:
owner instruction 2026-08-13, `phases/P10/P10.6.md`,
`findings/P10.6-quality-gate-block-2026-08-13.md`).

## Почему

Предыдущий executor неверно использовал `acceptance-failure` как terminal block, несмотря на
то что исправление full-tree PHPStan debt прямо объявлено его item scope. Это false escalation,
а не изменение D39 или отмена качества.

## Alternatives

1. Оставить `blocked` и перейти в plan-design — отклонено: причина уже входит в P10.6.
2. Ослабить PHPStan baseline/ignore/exclude/level — отклонено: нарушает D39 и P10.6.
3. Продолжить P10.6 новым attempt с полным исходным acceptance — принято.

## Consequences

Historical journal rows и finding не переписываются. Новый execution attempt несёт D86 в
provenance, повторяет исходную Validation и закрывается только после green full quality carrier;
P11/P12 consumers не получают ослабленный contract. RAG:— (repo-grounded:
`phases/P10/P10.6.md`, D39).
