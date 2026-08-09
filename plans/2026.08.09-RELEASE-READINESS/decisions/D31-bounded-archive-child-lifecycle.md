---
id: D31
date: 2026-08-09
status: accepted
item: P6.3
items: [P6.1, P6.3, P6.4, P6.5, P6.6, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Архив обрабатывается как hostile container: typed metadata listing, reject special/unsafe/colliding
paths, declared depth/count/per-entry/total/ratio gates, затем bounded one-entry stream с actual-byte
и SHA-256 verification. Blind bulk extraction запрещена. Child lineage хранит normalized
`archive_entry_path` и partial unique `(parent_version_id, archive_entry_path)`; короткий Action
transaction создаёт/reuses child rows/event и регистрирует `PrepareDocumentJob` after commit.
Parent получает terminal `DocumentStatus::Expanded` и не идёт в screening. RAG:✅ 2026-08-09
official OWASP archive validation/symlink guidance, PHP ZipArchive streams/metadata and Laravel 13
after-commit API; repo alternatives — `research/08-P6-execution-design.md`.

## Почему

Текущий bulk extract ограничивает только declared aggregate и допускает path/type/resource gaps;
application precheck не доказывает concurrency-idempotency. Bounded stream не доверяет metadata,
database constraint фиксирует identity, а after-commit dispatch гарантирует, что worker увидит child
rows. Отдельный expanded outcome не отправляет container bytes в LLM pipeline.

## Consequences

Archive adapters обязаны вернуть единый `ArchiveEntry` contract или fail closed; host-only отсутствие
RAR encoder не разрешает fake happy path. Object-store I/O остаётся вне DB transaction, child writes
идут на current documents disk, parent reads — только через `FileLocationResolver`. Новые limits
получают production defaults/env/Doctor evidence и adversarial tests до включения.
