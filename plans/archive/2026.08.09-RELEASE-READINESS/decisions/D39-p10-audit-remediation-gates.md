---
id: D39
date: 2026-08-09
status: accepted
item: P10.1
items: [P10.1, P10.2, P10.3, P10.4, P10.5, P10.6, P11.1, P11.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

P10 исполняется как последовательный контур: immutable terminal-tree baseline → отдельные
read-only security, architecture/debt и production-readiness audits → единая disposition matrix →
vertical regression-tested remediation → local quality/docs gate. PHPStan target — `max` без
committed baseline; существующий baseline не растёт и удаляется по мере исправления корней. Pint
остаётся formatter/style gate без blanket `final_class`, массового добавления/удаления `final` и
wholesale named-argument rewrite. Rector добавляется только при official compatibility evidence для
terminal PHP/Laravel graph и используется сначала dry-run; несовместимость означает честное
отсутствие Rector gate, а не placeholder. RAG:— (repo-grounded: owner brief §8,
`findings/P10-quality-security-recon-2026-08-09.md`, `research/12-P10-execution-design.md`).

## Почему

Auto-fix-first стирает исходный baseline, а исправления внутри audit items не дают сравнить
severity и общий root cause. Раздельные отчёты сохраняют evidence, после чего один matrix carrier
делает границы P10.5 детерминированными. Target `max`/zero-baseline соответствует требованию
флагманской чистоты; style churn без поведенческого payoff противоречит owner anti-abstraction
требованию и project skill canon.

## Consequences

P10.5 может быть крупным item и допускает несколько атомарных product commits внутри одного
close-cycle, но не получает права менять frozen D29–D38 или ослаблять tests/guards. Critical/high
security, data-loss/race/idempotency risks, frozen-contract violations и red deterministic gates
обязаны стать fixed либо escalated до закрытия P10; medium/low debt получает owner/trigger/effort и
может остаться accepted/deferred. P11 интегрирует готовые gates в CI и Pest topology, P12 доказывает
их на clean production rehearsal; P10 не дублирует эти фазы.
