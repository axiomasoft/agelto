---
id: D4
date: 2026-09-10
status: superseded
item: P2.3
items: [P2.3, P4.2, P4.3]
supersedes: []
superseded_by: D8
---
## Решение

Не считать ЕИС `uid` гарантией неизменности bytes. До controlled production P4.2 проверяет все
доступные attachment revision pairs выбранного live-scope. Любой same-uid/different-bytes факт
обязывает bounded revalidation до P4.3. Если подходящих replacements нет или evidence недостаточно,
гейт тоже fail-closed к bounded revalidation; только наблюдённые changed-uid pairs допускают pilot
с явной документационной оговоркой, но не создают универсальной гарантии.

## Почему

Публичный первичный контракт uid не найден, а поголовный redownload создаёт лишний traffic.
RAG:✅ 2026-09-10 — официальные материалы Казначейства/ЕИС перечислены в
`findings/00-source-synthesis.md`. **Consequences:** P2.3 фиксирует ветвящийся контракт и docs;
P4.2 материализует live evidence; P4.3 не стартует при unresolved verdict.
