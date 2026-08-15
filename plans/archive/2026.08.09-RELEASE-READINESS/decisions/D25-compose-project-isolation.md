---
id: D25
date: 2026-08-09
status: accepted
item: P3.4
items: [P1.2, P1.3, P1.5, P3.4, P3.5, P11.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Dev/prod Compose carriers не задают fixed `container_name`; service/container/volume/network names
принадлежат явному Compose project. Automated rehearsal всегда задаёт уникальный project name и host
ports и удаляет только собственные resources. Нового third Compose carrier не создаётся.
RAG:— (repo-grounded: current `docker-compose*.yml`, D9/D16 and
`findings/P3-livewire-readiness-recon-2026-08-09.md`).

## Почему

Clean-install proof не должен останавливать или переиспользовать owner's dev/prod containers и volumes.
Fixed names делают изоляцию и параллельный CI недоказуемыми независимо от `-p`/`COMPOSE_PROJECT_NAME`.

## Consequences

Operator scripts и docs обращаются к service names через `docker compose`, а не к глобальным container
names. P1 contract tests дополнительно запрещают `container_name`; возможные внешние скрипты с прямыми
именами требуют миграции на Compose service commands.
