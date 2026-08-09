---
id: D49
date: 2026-08-09
status: accepted
item: P12.5
items: [P2.2, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

P12.5 становится единственным apply-owner staged rotation из D20. P2.2 по-прежнему только
создаёт полный `.next` set; P12.5 на работающем rehearsal stack выполняет preflight+lock+backup,
dependency-ordered apply APP/DB/Redis/MinIO credentials, проверяет новые access paths и отключение
старой active-auth роли после cutover; прежний APP key допустим только как явно bounded decrypt-only
compatibility input, если terminal framework contract этого требует. Затем P12.5 отдельно доказывает
reverse-order rollback к старому set с readiness/queue/object/browser acceptance. Любая частичная apply/verify/rollback или утечка
блокирует item и P12.7. RAG:— (repo-grounded: D20, P2.2, P12.5 and audit finding F2).

## Почему

Generation-only P2 не имеет running production topology, а прежний P12.5 исключал rotation и
проверял только image/data recovery. Именно release/recovery rehearsal уже владеет drain,
backup, rollback, full-stack checks и может доказать credential cutover без второго lifecycle owner.

## Consequences

P12.5 получает отдельный rotation script/test/runbook/evidence section и owner gate перед apply.
P2.2 не расширяется до live mutation; P12.7 потребляет terminal P12.5 evidence и не создаёт
второго rotation implementation.
