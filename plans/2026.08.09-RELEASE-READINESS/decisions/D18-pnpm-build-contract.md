---
id: D18
date: 2026-08-09
status: accepted
item: P1.4
items: [P1.4, P1.5, P3.1, P3.4, P11.4, P12.3]
supersedes: []
superseded_by: null
---
## Решение

Frontend toolchain P1 переходит со stale yarn prose на pinned `pnpm@9.15.0`: `packageManager` в
`package.json`, committed `pnpm-lock.yaml`, frozen installs в dev/image/CI. Vite dev server живёт
отдельным Compose service на Node 24; build-only Node/pnpm не попадает в PHP runtime image.
RAG:✅ 2026-08-09 pnpm Docker/install, Vite 7 server and Laravel 13 Vite docs; current host versions
repo-grounded in `findings/P1-docker-make-vite-recon-2026-08-09.md`.

## Почему

Репозиторий не имеет lockfile/package-manager pin, а living docs требуют yarn, которого ни один
committed carrier не фиксирует. Существующий host toolchain уже даёт Node 24.12.0 + pnpm 9.15.0;
пин минимизирует лишний upgrade внутри release-readiness work.

## Consequences

Composer scripts и docs с yarn должны измениться атомарно; upgrade pnpm major остаётся отдельной
задачей. Любой будущий bump package manager обязан обновить lockfile и все frozen-install gates.
