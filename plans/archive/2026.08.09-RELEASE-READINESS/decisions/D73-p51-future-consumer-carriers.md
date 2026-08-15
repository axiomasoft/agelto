---
id: D73
date: 2026-08-11
status: accepted
item: P5.1
items: [P5.1, P5.3, P5.4, P5.5]
supersedes: []
superseded_by: null
---
## Решение

Downstream RED P5.1 классифицируется как локальный generated-carrier mismatch, а не как product
или cross-phase escalation. P5.3, P5.4 и P5.5 получают по одному item-owned executable carrier
из собственного поля Files; полная существующая acceptance-матрица каждого item'а остаётся внутри
его carrier. До создания carrier в product-коммите соответствующего consumer downstream-gate
возвращает `unknown` и не исполняет пояснительный Markdown либо будущие команды отдельно. RAG:—
(repo-grounded: `findings/P5.1-return-downstream-red-2026-08-11.md`, D63, owner refinement
2026-08-11).

P5.1 reconciles append-only на неизменном product commit `41e4e7cb`: historical RED findings
сохраняются как provenance, повторный keyed downstream record на новых consumer-spec hashes обязан
дать `unknown` для P5.2–P5.5, после чего штатный `plan-close` восстанавливает terminal GREEN state
и handoff на P5.2. D28/D29, runtime semantics и завершённые фазы не меняются. RAG:—
(repo-grounded: P5.1 downstream records and `phases/P5/P5.1.state.md`).

## Почему

Минимальный parser-only repro доказал, что старые P5.3–P5.5 Validation разбивались по `·`, а первый
inline-code token каждого prose-фрагмента становился shell-командой: среди результатов оказались
`ProxyLease::direct`, `save`, `--no-tia` и другие будущие identifiers. Consumer discovery при этом
нашёл только ещё не начатые P5.2–P5.5; уже выполненные items и P5.1 product validation не затронуты.

## Alternatives

1. Повторить product design/development P5.1. Отклонено: product commit и собственная Validation
   P5.1 зелёные, blast radius ограничен будущими specs.
2. Игнорировать RED либо удалить реальные dependency edges. Отклонено: это ломает fail-closed
   provenance или скрывает coupling.
3. Править generated Swissknifeman runtime. Отклонено: plugin cache защищён, а D63 уже задаёт
   корректную plan-side форму.
4. Нормализовать три future-consumer specs и повторить keyed gate. Выбрано как минимальный
   обратимый repair.

## Consequences

Меняются только P5.3–P5.5 specs, их generated bundles/views и P5.1 plan evidence/bookkeeping;
отрицательный prose-маркер execution mode в P5 phase context переформулируется без изменения
последовательной topology, чтобы консервативный workflow-lint не считал отрицание декларацией.
Будущие исполнители создают carriers вместе со своими product changes и запускают через них всю
исходную acceptance-матрицу; ослабления тестов нет. Rollback — возврат plan-only commit, product
tree не затрагивается. Общий audit P5 после P5.5 сохраняет полную cross-item проверку.
