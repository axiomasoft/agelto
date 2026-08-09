---
id: D89
date: 2026-08-06
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Периметр слияния: слить ВСЁ, каталог `docs-private/` исчезает физически.** Отвергнуты: сжать `docs-private` до одного файла экономики; отключить зеркалирование `docs/` в Brain. **Consequences:** реальные тарифы маршрутов, лимиты аккаунта провайдера, реестр рисков и глубокая промт-инженерия с момента P9.3 зеркалятся в Obsidian-vault Brain — периметр расширяется, и item обязан НАЗВАТЬ дату этой смены в Completion Notes; обратный ход стоит либо восстановления второго дерева, либо правки `.projects.json` (внешняя система)

## Почему

Решение владельца 2026-08-06 (`brief/01-refinements.md` блок P9 п. 1) на предъявленной фактуре: различие деревьев — не «публичное/приватное», а канал синхронизации (оба лежат в git, `git ls-files docs-private` возвращает все восемь файлов), а сам vault локальный и приватный. `RAG:— (repo-grounded: docs/meta/editing.md §«Где что живёт»; ~/Vaults/Brain/.projects.json — Agelto/docs, visibility: private)`
