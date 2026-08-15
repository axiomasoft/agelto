---
id: D34
date: 2026-08-09
status: accepted
item: P7.4
items: [P7.2, P7.3, P7.4, P7.5, P7.6, P9.2, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

`Analysis.document_type` остаётся indexed discriminator, а остальные screening facts хранятся в
versioned nested `Analysis.result` envelope (`schema_version`, `screening`, backend `decision`).
Backend один вычисляет typed `disposition=extract|retain|review|ignore`, typed reason и отдельный
`extraction_dispatched`; короткий Action/repository path атомарно пишет decision, Document status,
event/run outcome и after-commit dispatch. Tags/filter paths получают partial JSONB indexes; второй
document/screening result table и denormalized Document columns не создаются. RAG:— (repo-grounded:
ADR 0004, current Analysis lifecycle/handler and `research/09-P7-execution-design.md`).

## Почему

Analysis уже является versioned per-run/per-step carrier с rerun/shadow provenance. Копирование
screening facts в Document или новую one-to-one таблицу создаст второй source of truth; flat JSON
без версии и typed backend reason, напротив, остаётся непригодным для безопасной миграции/UI.

## Consequences

P7.2 выполняет deterministic flat→v2 backfill и rollback/reapply proof; неизвестная историческая
форма не получает выдуманный disposition. P7.5 читает envelope через model/query helpers и
сохраняет raw JSON как audit fallback. Транзакция никогда не охватывает Files API/LLM/storage;
изменение этого boundary требует superseding D34.
