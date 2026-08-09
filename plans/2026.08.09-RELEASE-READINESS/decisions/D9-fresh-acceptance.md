---
id: D9
date: 2026-08-09
status: accepted
item: P12.7
items: [P3.4, P12.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Финальная приёмка обязательно начинается с чистого clone и пустых persistent volumes/DB,
а production rehearsal включает private package, secrets, migrations, base seeds, admin,
assets, docs, queues, storage, doctor и browser smoke.
RAG:— (repo-grounded: brief/00-brief.md).

## Почему

Проверка уже прогретого дерева не ловит отсутствующие bootstrap steps и является ложным
доказательством deployability.
