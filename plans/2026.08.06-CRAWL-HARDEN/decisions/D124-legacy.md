---
id: D124
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Owner разрешил снять блокировку P5.3 прямой правкой versioned baseline: для `card_parse`, `listing_shape` и `estimate_fields` добавлены одинаковые некалиброванные границы `degraded_share=0.05` и `broken_share=0.20`; итоговый вердикт берёт худшую измеренную долю.** **Consequences:** P5.3 меняет baseline и вводит общий резолвер порогов сверх исходного Files, поэтому закрывается 🟠; до первого боевого окна эти числа не считаются калиброванными и должны оставаться явно помеченными в истории.

## Почему

В baseline P5.1 были только `min_sample`, тогда как P5.3 требовал четыре вердикта и не мог детерминированно отличить `degraded` от `broken`. Прямое указание владельца: исправить без отдельного design-захода. `RAG:— (repo-grounded: resources/crawler/health/eis-baseline.json; phases/P5.md P5.3)`
