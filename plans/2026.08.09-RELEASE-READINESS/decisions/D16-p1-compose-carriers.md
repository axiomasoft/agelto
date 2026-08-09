---
id: D16
date: 2026-08-09
status: accepted
item: P1.1
items: [P1.1, P1.2, P1.3, P1.5]
supersedes: []
superseded_by: null
---
## Решение

P1 сохраняет два явных standalone carriers: `docker-compose.yml` для dev и
`docker-compose.prod.yml` для production. Они обязаны отдельно проходить `docker compose config`;
общие service/image/health contracts сверяет executable parity test, а не неявная merge-цепочка.
RAG:✅ 2026-08-09 Docker Compose production and multiple-environment guidance; repo alternative
analysis — `research/03-P1-execution-design.md`.

## Почему

Base+override уменьшает YAML duplication, но повышает цену ошибки: dev source mount или build
может попасть в effective production config из-за merge/order semantics. Standalone prod carrier
делает forbidden mounts и required immutable image видимыми напрямую.

## Consequences

Общие блоки частично дублируются между двумя files; P1.5 обязан оставить parity test, который
падает при рассинхронизации service set, health dependencies, persistent volumes или image tag.
