---
id: D138
date: 2026-08-09
status: accepted
item: P10.7
items: [P10.7]
supersedes: []
superseded_by: null
---
## Решение

**P10.7 и P10 переоткрываются для нового F5 Blocker: current logical revision определяется
максимальным `revision_number`, а не временем наблюдения `detected_at`. Семантика
`findRevisionAsOf()` остаётся временной и не меняется.**

## Почему

Два сериализованных worker могут захватить разное прикладное время до ожидания
`pg_advisory_xact_lock`: revision 1 коммитится с более поздним `detected_at`, чем revision 2.
Текущий `findLatestRevision()` сортирует сначала по `detected_at`, возвращает revision 1 при
`procurement.revision_count=2`, и следующий ingest падает с inconsistent revision head.

## Reopen scope admission

Product-code delta этого реопена ограничена следующими путями:

1. `app/Repositories/Procurement/ProcurementReadRepository.php` — canonical head по
   `revision_number DESC`.
2. `tests/Feature/Procurement/ProcurementIngestConcurrencyTest.php` — детерминированный regression
   на двух реальных PostgreSQL sessions и третий ingest.
3. `app/Services/Procurement/ProcurementIngestProcessor.php` — условно, только если regression
   докажет отдельную необходимость менять момент захвата timestamp; без такого доказательства
   файл не меняется.

Bookkeeping delta ограничена `decisions/D138-revision-head-order.md`, `journal.jsonl`,
`phases/P10/P10.md`, генерируемым `phases/P10/P10.7.state.md`, `handoff.md`,
`artifacts/P10-validation.json`, `findings/P10-code-adversarial-audit-2026-08-09.md` и
необходимыми генерируемыми `bundles/P10.7.json` и decision/status views. P10.7 item specification,
`plan.md`, закрытые P1–P9 specifications, v1 carriers и Swissknifeman files не меняются.

**Consequences:** до исправления P10.7/P10 имеют статус in progress; новый тест обязан доказать
порядок `detected_at(revision 2) < detected_at(revision 1)`, затем успешный revision 3,
`revision_count=3` и canonical head revision 3. Реопен закрывается только после полного P10
F1–F8 replay и повторного Layout-v2 postflight с теми же 27 inherited diagnostics и нулём P10.
