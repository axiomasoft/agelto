---
id: D4
date: 2026-08-09
status: accepted
item: P0.6
items: [P0.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

P0 замораживает факты и contracts до product changes; P12 является единственным финальным
production gate и повторно трассирует весь бриф.
RAG:— (repo-grounded: research/00-user-intent.md).

## Почему

Так implementation items получают конечные решения, а зелёное текущее окружение не подменяет
доказательство чистого bootstrap.
