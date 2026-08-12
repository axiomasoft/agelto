---
id: D77
date: 2026-08-12
status: accepted
item: P6.7
items: [P6.3, P6.4, P6.5, P6.6, P6.7]
supersedes: []
superseded_by: null
---
## Решение

RED-аудит P6, заход 1, закрывается ровно одним bounded remediation-item P6.7. Реопен повышает
`migration-version` `0.1.8` → `0.1.9`, добавляет nonterminal P6.7 и инвалидирует Phase Handoff
маркером `> УСТАРЕЛ — фаза реопенена D77`. Historical audit/journal/product commits не
переписываются; state/bundle/decision/status views создаются только штатными генераторами.
RAG:— (repo-grounded: `phases/P6/P6.md` → `## Audit P6 — 2026-08-12`, owner scope-payload
2026-08-12, Task Layout v2 projection contract).

P6.7 не меняет D30/D31: GSFX сохраняет typed `ReadyForScreening` outcome, но его ZIP attachments
обязаны пройти полную common listing→`ArchiveEntryValidator`→bounded per-entry
`ArchiveExtractor` цепь до первой child mutation, а durable child lineage/event/job создаются
только `ExpandArchiveEntryAction` с DB uniqueness и after-commit dispatch. Silent unsafe-entry
skip, `ZipArchive::extractTo()` и второй private child persistence path запрещены.
RAG:— (repo-grounded: audit F2, `research/22-P6-audit-remediation.md`, D30/D31).

Три stale strategy suites переводятся с boolean `process()`/`canProcess()` на
`PreparationResult` и current registry/inspector contract. Один committed P6.7 candidate обязан
дать GREEN focused suites и полный no-TIA suite. Только после этого append-only corrections
материализуют factual write-site/downstream evidence P6.3/P6.5/P6.6 и exact P6.4 scope drift.
Historical route undershoot не превращается задним числом в `allow`; P6.7 несёт новый собственный
`allow/allow` anchor. После terminal P6.7 следует обязательный независимый Audit P6, заход 2, а не
прямой close или переход к P7. RAG:— (repo-grounded: audit F1/F3–F5 and
`research/22-P6-audit-remediation.md`).

## Почему

GSFX security seam, stale public-contract tests и closure evidence должны относиться к одному
candidate tree: только после фактического GREEN product anchor можно честно исправить terminal
claims и проверить downstream. Разделение оставило бы либо временный unsafe child path, либо новый
ложный GREEN evidence carrier.

## Alternatives

1. Исправить только 27 stale tests. Отклонено: GSFX продолжил бы обходить D31.
2. Переоткрыть и переписать P6.3–P6.6 specs/history. Отклонено: ломает append-only provenance.
3. Вынести GSFX attachments в новый parallel lifecycle. Отклонено: создаёт вторую security policy.
4. Один P6.7 с common bounded path, typed-contract suite и truthful corrections. Выбрано.

## Consequences

План содержит 82 items. P6.7 исполняется `implementation/high`, `manual`, `Review=full`; новый
product/evidence anchor и отдельный bookkeeping commit обязательны. P6.3/P6.5 могут остаться
исторически оранжевыми из-за доказанного route undershoot, P6.4 — из-за exact scope drift, но
никакая correction не вправе скрыть эти факты. P7 не стартует до terminal P6.7 и GREEN Audit P6,
заход 2. RAG:— (repo-grounded: P6 audit, `phases/P6/P6.7.md`, owner scope-payload).
