---
id: D10
date: 2026-08-09
status: accepted
item: P1.1
items: [P1.1, P1.2, P1.3, P1.4, P1.5]
supersedes: []
superseded_by: null
---
## Решение

Текущий Makefile и docker/Makefile — requirements/reference, не compatibility freeze.
Целевой интерфейс можно пересобрать после inventory; сохранение старой цели требует явного
решения и теста, а не инерции.
RAG:— (repo-grounded: brief/00-brief.md).

## Почему

Владелец прямо разрешил breaking cleanup ради целостного dev/prod/deploy интерфейса.
