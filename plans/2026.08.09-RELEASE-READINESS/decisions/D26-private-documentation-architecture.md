---
id: D26
date: 2026-08-09
status: accepted
item: P4.1
items: [P4.1, P4.2, P4.3, P4.5, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Закрытая документация собирается exact stable VitePress 1.6.4 на frozen pnpm carrier с base
`/admin/documentation/`. Проверенный dist и deterministic path/MIME/SHA-256 manifest попадают в
immutable application image под `var/private-documentation/`, вне `public`, writable `storage` и
Compose volumes. Production host не генерирует и не подменяет этот artifact.
RAG:✅ 2026-08-09 official VitePress stable/next, getting-started, site-config and deploy docs;
repo alternatives — `research/06-P4-execution-design.md`.

## Почему

Vintera pattern доказал удобство VitePress, но его 2.0 alpha, public asset bypass и named dist volume
не соответствуют release-readiness D7/D17. Stable exact dependency использует уже выбранный pnpm/Vite
toolchain; image ownership делает tag честным carrier rollback.

## Consequences

Изменение `docs/` в production требует нового image artifact; runtime PHP image получает готовый dist,
но не Node/pnpm. P4.1 обязан сверить terminal P0.4/P0.6/P1.5/P3.5 и эскалировать для superseding D,
если их факты ломают stable version, base или image boundary.
