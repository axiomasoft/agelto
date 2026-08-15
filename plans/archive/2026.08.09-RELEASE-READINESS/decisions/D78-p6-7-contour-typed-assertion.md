---
id: D78
date: 2026-08-12
status: accepted
item: P6.7
items: [P6.7]
supersedes: []
superseded_by: null
---
## Решение

Владелец авторизовал минимальное расширение P6.7: добавить
`tests/Feature/Procurement/ContourTest.php` в Files и заменить его stale boolean assertion для
`ArchiveStrategy::process()` на exact existing enum `PreparationResult::ExpandedContainer`.
Это исправляет consumer P6.2 typed public contract, уже применённый P6.7 в Gsfx/Scan/Spreadsheet,
и не меняет D30/D31, archive lifecycle, budgets, migration или production semantics.
RAG:— (repo-grounded: owner directive 2026-08-12, `tests/Feature/Procurement/ContourTest.php:131`,
`app/Services/Procurement/Prepare/ArchiveStrategy.php:39`).

## Почему

Full no-TIA acceptance был красным только из-за obsolete boolean expectation, тогда как strategy
уже возвращает typed enum. Владельцем подтверждено, что это локальная remediation existing public
contract, необходимая для завершения единственного P6.7 carrier.

## Alternatives

1. Оставить test-path за пределами P6.7. Отклонено владельцем: full acceptance остаётся red.
2. Вынести один assertion в новый item. Отклонено владельцем: добавило бы искусственную фазовую
   границу без изменения production semantics.
3. Расширить P6.7 только на exact assertion. Выбрано.

## Consequences

P6.7 сохраняет полный no-TIA, typed contract и bounded GSFX acceptance без waiver; его release
carrier обязан запускать `ContourTest` focused до полного suite. После green candidate остаются
обязательными все D77 append-only/downstream/evidence/generation gates и независимый Audit P6,
заход 2. RAG:— (repo-grounded: D77, P6.7 Validation, owner directive 2026-08-12).
