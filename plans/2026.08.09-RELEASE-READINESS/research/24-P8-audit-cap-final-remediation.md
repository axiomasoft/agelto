# P8 AUDIT-CAP final remediation — execution design

## Owner boundary

После RED Audit P8 round 2 владелец запретил сдавать P8 с отклонениями и потребовал исправить весь
named debt. Historical P8.6 commit/audit/journal сохраняются; новый P8.7 владеет последним test/
evidence contour и закрывает фазу GREEN без Audit 3. P9 не стартует до terminal P8.7 и direct
phase close. RAG:— (repo-grounded: owner directive 2026-08-13 and D81).

## Finding-to-green matrix

| Finding | P8.7 remediation | Green evidence |
|:--|:--|:--|
| F1 — out-of-Files tests и удалённый negative | P8.7 Files явно включает пять изменённых crawler/procurement tests; `ProxyBindingResolverTest` снова создаёт proxy другого registry и доказывает его исключение | focused resolver test green; product commit paths — subset P8.7 Files; old and new registry fixtures существуют одновременно |
| F2 — P8.2/P8.6 ложный GREEN ownership | История не переписывается; после P8.7 candidate append-only correction переводит current P8.2/P8.6 в GREEN через нового владельца полного current contour | current states P8.2/P8.6/P8.7: no Pending Work/Known Deviations/Escalation; journal сохраняет прежние events |
| F3 — ложный Pint count | Existing platform extension carrier запускает Pint по явному deduplicated списку каждого PHP path изменённого/принятого P8.6–P8.7 и печатает count из этого списка | evidence содержит exact command, ordered paths, computed count, exit 0 и candidate SHA; старые «9 files» явно superseded |
| F4 — success note как deviation | P8.6/P8.7 terminal correction разделяет Completion Notes и Known Deviations; success evidence живёт только в Completion Notes | generated status/closure не содержит `known deviation`; P8 closure `🟢 Done` |

RAG:— (repo-grounded: Audit P8 round 2, `git diff 8caf8acc^..8caf8acc`, current P8 carriers).

## Candidate and rollback topology

1. Снять exact dirty baseline и не индексировать plan/user changes в product commit.
2. Восстановить cross-registry negative общим production path: другой registry получает отдельный
   crawler/proxy, а resolver результата первой procurement не видит этот proxy.
3. Нормализовать P8.5 carrier: один declared PHP path list используется и для Pint, и для evidence
   count; все P8.6/P8.7 paths, которые carrier заявляет покрытыми, реально входят в command.
4. Сделать pathspec-only product/evidence commit. На его полном SHA прогнать focused matrix и full
   carrier; при red исправлять в том же item, не ослабляя guard или suite.
5. Только после GREEN append correction events, regenerated views/bundles/closure и direct close.
   Rollback — revert одного product/evidence commit; historical plan evidence не удаляется.

## DoR verdict

Открытых product/authority решений нет: D35/D36 сохраняются, D81 фиксирует exact remediation и
запрещает deviations/Audit 3. P8.7 — один последовательный candidate-bound slice,
`implementation/high`, `manual`, `Review=full`; orchestration не объявлена. Validation конечна,
все пути названы, clarification markers отсутствуют. RAG:— (repo-grounded: D35/D36/D81 and
Audit P8 round 2).
