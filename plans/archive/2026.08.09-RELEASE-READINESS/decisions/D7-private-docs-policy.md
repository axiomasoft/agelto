---
id: D7
date: 2026-08-09
status: accepted
item: P4.1
items: [P4.1, P4.2, P4.3, P4.4, P4.5]
supersedes: []
superseded_by: null
---
## Решение

Production documentation build не лежит в public; HTML и assets проходят через одну
authorization policy, path containment и explicit cache policy. Конкретный generator/serving
mechanism выбирается после P0/P4 RAG и threat model.
RAG:— (repo-grounded: brief/00-brief.md).

## Почему

Публичная статика или отдельная неавторизованная asset-ветка нарушают требование владельца
даже при закрытом index.html.
