---
id: D20
date: 2026-08-09
status: accepted
item: P2.2
items: [P2.2, P2.3, P2.4, P2.6, P12.4, P12.5]
supersedes: []
superseded_by: null
---
## Решение

Dev/prod Compose используют file-backed secrets из ignored `local/secrets/` с явными per-service
grants; Swarm не является обязательным runtime. Generator create-only и atomic; production env files
не содержат secret values. External-provider tokens объявляются operator-supplied и не генерируются.
RAG:✅ 2026-08-09 Docker Compose secrets, PostgreSQL `_FILE`, Redis ACL and MinIO `_FILE` official
docs; synthesis — `research/04-P2-execution-design.md`.

## Почему

Текущий carrier одновременно требует external Swarm secrets и передаёт часть паролей через env,
причём app entrypoint не читает один из объявленных `_FILE` keys. File secrets работают в обычном
Compose, дают явный service scope и не требуют класть значения в rendered config.

## Consequences

Deploy host обязан защищать `local/secrets/` правами и backup policy; file-source mode внутри Compose
не заменяет host permission gate. Rotation не смешивается с create/bootstrap и требует отдельного
staged apply/rollback path.
