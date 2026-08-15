---
id: D17
date: 2026-08-09
status: accepted
item: P1.3
items: [P1.3, P1.4, P1.5, P2.4, P3.4, P12.5, P12.6]
supersedes: []
superseded_by: null
---
## Решение

Production PHP services запускаются из одного immutable image tag и не bind-mount'ят application
code или `vendor`. Dockerfile разделяет runtime, Composer, frontend и application layers; production
host только pulls/promotes готовый tag. P1 доказывает topology/runtime/frontend targets, P2 закрывает
private Composer closure, P3 — framework assets, P12 — clean production rehearsal.
RAG:✅ 2026-08-09 Docker multi-stage/cache/production and serversideup packaging docs; repo boundary
analysis — `research/03-P1-execution-design.md`.

## Почему

Текущий prod bind mount делает image tag недостоверным описанием running code и не даёт
воспроизводимого rollback. Одновременно P1 не может честно решить sibling path repositories за P2
или доказать Livewire/Filament assets за P3.

## Consequences

Даже code-only production change требует нового immutable application image (с reuse BuildKit cache),
но не server-side rebuild. Полная runnable-image acceptance откладывается до P2/P3 и не может быть
заявлена Completion Notes P1.
