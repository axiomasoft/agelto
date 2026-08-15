---
id: D52
date: 2026-08-09
status: superseded
item: finish
items: [finish]
supersedes: [D46, D47]
superseded_by: D83
---
## Решение

Additive correction к immutable D46/D47 в точном item-count и carrier финального reconcile:
после prerequisite P0.0 из D48 полный план содержит 13 фаз / 75 items. Owner-directed design budget
остаётся `1/1`, но текущим доказательством полного cross-phase `finish` становится повторный
reconcile всех 75 specs, Routing rows, bundles и roadmap в этом заходе; исторический finish D47
для 74 items больше не является current approval carrier. Исполнение по-прежнему запрещено до
GREEN fresh-context `plan-audit design`. RAG:— (repo-grounded: D46–D48, phase specs, Routing and
roadmap item inventories).

## Почему

D48 добавил P0.0 после D47, поэтому точные утверждения D46/D47 о 74 items перестали совпадать с
исполняемым деревом из 75 specs. Без новой записи cold-start consumer не отличает исторически
корректный первый finish от обязательного повторного reconcile после изменения состава плана.

## Alternatives

1. Переписать D46/D47 задним числом — отклонено: decision records immutable.
2. Считать P0.0 вне item-count — отклонено: он имеет spec, Routing, state, bundle и roadmap row.
3. Additive superseding record и повторный full finish — принято.

## Consequences

Meta и Routing prose фиксируют 75 items и D52 как current additive finish carrier; D46/D47 сохраняются как
история. Повторная регенерация views выполняется только после journal event этого remediation,
чтобы state/bundle/status остались byte-fresh для следующего audit.
