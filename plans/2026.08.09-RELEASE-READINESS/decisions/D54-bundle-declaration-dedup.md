---
id: D54
date: 2026-08-09
status: accepted
item: finish
items: [finish]
supersedes: []
superseded_by: null
---
## Решение

Decision inputs в `Inputs` и `Required Reads` всех item-specs объявляются одним bare token
`` `D#` ``, без одновременного полного пути `decisions/D#-slug.md`. Это plan-side устраняет
двойной resolve одного carrier как path-candidate и D-token; все 75 bundles пересобираются после
механической нормализации. **PROMOTE-CANDIDATE (ecosystem):** Bundle v1 generator должен
дедуплицировать одинаковые `(field, ordinal, path, selector)` либо fail closed на повторе, чтобы
одна declaration не удваивала admission weight. Plugin cache и Swissknifeman source этим планом
не изменяются. RAG:— (repo-grounded: `plan.md` Audit design заход 3 F12;
`bundles/P2.6.json`; `bundles/P12.7.json`).

## Почему

Полный путь уже содержит D-token, а текущий generator независимо добавляет оба результата в
manifest. На 75-item дереве это дало 235 duplicate groups в 70 bundles, повторное чтение и
завышенный estimated context weight. Однозначный D-token штатно резолвится в immutable decision
record и сохраняет конечный обязательный input без локальной правки ecosystem runtime.

## Alternatives

1. Править plugin cache или marketplace source из consumer-плана — отклонено как выход за scope
   и нарушение ownership Swissknifeman.
2. Оставить дубли, поскольку freshness check зелёный — отклонено: byte-fresh manifest всё равно
   нарушает single-read и admission semantics.
3. Удалить decision reads целиком — отклонено: executor потерял бы load-bearing решения.

## Consequences

Человек видит стабильные идентификаторы D#, а bundle generator материализует точные filenames и
digests. До ecosystem-фикса новые item-specs этого плана обязаны сохранять ту же bare-token форму;
кандидат на общий dedup/reject gate передан владельцу через handoff, без обещания изменения plugin.
