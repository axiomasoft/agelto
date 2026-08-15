---
id: D85
date: 2026-08-13
status: accepted
item: P10.2
items: [P10.2]
supersedes: []
superseded_by: null
---
## Решение

Владелец выбирает `reexecute` из decision request `p10-2-route-recovery-20260813`: P10.2
повторно исполняется как полный read-only Laravel security audit и закрывается только после
GREEN item-owned carrier. Вариант `accept-captured-route` не применяется. Report/carrier commit
`e4cb6a89` остаётся immutable historical checkpoint; он перечитывается и сравнивается с новым
discovery/manual-verification pass, но не подменяет его. RAG:— (repo-grounded:
`decisions/queue.jsonl:10-12`, `journal.jsonl:394-396`, `findings/P10-security-audit.md`).

## Почему

Первый запуск доказал два findings, но terminal carrier не завершился под host resource pressure,
а последующая попытка route-only reconciliation потребовала owner authority. Принятие captured
route исправило бы bookkeeping, но не выполнило явный owner критерий повторного полного audit и
не доказало GREEN Validation. Новый запуск сохраняет исходный baseline и позволяет сравнить
coverage/findings без product mutation. RAG:— (repo-grounded:
`research/25-P10.2-reexecution-design.md`, owner response 2026-08-13).

## Alternatives

1. Принять captured provider-route evidence и восстановить terminal state без повтора — отклонено
   владельцем через option `reexecute`.
2. Считать `e4cb6a89` готовым результатом и запустить P10.3 — отклонено: item снова `In progress`,
   а полный carrier не дал GREEN.
3. Повторить read-only audit с checkpoint diff и portable marketplace-root carrier — принято.

## Consequences

**Consequences:** P10.2 spec/bundle добавляют D85, checkpoint report/carrier и reexecution design
как ordered inputs; P10.3 остаётся blocked до нового terminal P10.2. Findings `P10.2-S01` и
`P10.2-S02` сохраняют IDs, пока новое evidence не докажет material change. P10.2 не получает
права исправлять product code; remediation остаётся P10.5. Resource pressure не разрешает
ослабить carrier или повторно закрыть item с deviations: после bounded recovery применяется
обычный block-confirmation/authority protocol.
