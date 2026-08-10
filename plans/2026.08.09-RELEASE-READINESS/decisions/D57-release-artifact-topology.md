---
id: D57
date: 2026-08-10
status: accepted
item: P0.6
items: [P0.6, P1.2, P1.3, P1.4, P1.5, P2.1, P12.2, P12.4, P12.5]
supersedes: []
superseded_by: null
---
## Решение

Development сохраняет bind-mounted source и не требует rebuild PHP-образа при обычной правке
application code. Production исполняет application code, Composer dependencies и production
frontend assets только из promoted immutable image; host source bind mount в production запрещён.
Build и deploy receipt связывают source commit, manifest digest, platform digest и configuration
revision. RAG:✅ 2026-08-10 (`findings/P0-rag-infrastructure.md`, INF-01/INF-02/INF-10/INF-11);
RAG:— (repo-grounded: `findings/P0-reference-comparison.md`).

## Почему

Только image-contained carrier связывает исполняемые production bytes с immutable rollback
identity, сохраняя отдельное owner-требование rebuild-free development.

## Alternatives

1. Оставить production source bind mount и менять код без image build. Цена: image tag/digest не
   идентифицирует исполняемые bytes, rollback образа не откатывает host tree.
2. Включить source/dependencies/assets в promoted image. Цена: production code change требует
   build/promotion, зато release и rollback получают одну проверяемую identity.
3. Разнести code и runtime по нескольким независимо promoted artifacts. Цена: более сложная
   атомарность и receipts без доказанной необходимости для первого single-server deploy.

## Consequences

Выбрана альтернатива 2. Dev no-rebuild остаётся отдельным контрактом; `latest` может быть удобным
alias, но не является release/rollback identity. Обычный build/deploy не вызывает
`docker compose down --volumes`; destructive carriers требуют отдельного owner gate и recovery
evidence.
