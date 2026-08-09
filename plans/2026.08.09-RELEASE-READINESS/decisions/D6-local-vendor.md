---
id: D6
date: 2026-08-09
status: accepted
item: P2.1
items: [P2.1, P2.4, P12.2]
supersedes: []
superseded_by: null
---
## Решение

Target carrier private package — отдельный nested clone local/vendors/azguard, не Git
submodule и не содержимое основного репозитория; основной repo хранит только пустую директорию.
Exact remote, branch/tag и Composer subpaths верифицирует P0/P2 до правки.
RAG:— (repo-grounded: brief/00-brief.md и composer.json).

## Почему

Это дословно сохраняет owner workflow «clone main → clone private package → install» и
устраняет зависимость от ../../packages на production.
