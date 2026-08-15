---
id: D89
date: 2026-08-15
status: accepted
item: P12
items: [P12]
supersedes: []
superseded_by: null
---
## Решение

Владелец ограничивает remaining P12 локально исполнимым preparation deliverable. После
re-design P12.2–P12.7 производят один целостный пакет: R&D-backed Ubuntu/operator runbook'и,
profile/template carriers, safe scripts, source-derived command matrix и redacted evidence
templates. Их единая routing batch выполняется в одном manual implementation root с дешёвыми
локальными validation carriers; каждый carrier обязан быть фактически зелёным.

P12 больше не требует в этом плане dedicated server, DNS, GitHub deploy-key enrollment,
registry/CI access, production deploy, TLS issuance, recurring backup/watchdog execution,
rollback/restore или clean-room rehearsal. Эти действия и их evidence становятся scope отдельного
последующего operator test/rehearsal plan. Они не остаются blocked/deviation/Pending Work текущих
P12 items и не подменяются fabricated output. Дизайн сохраняет existing D19/D43–D45/D49/D50/D53
как технические источники будущего rehearsal, но меняет только ownership/acceptance current P12.
RAG:— (repo-grounded: owner directive 2026-08-15, `brief/01-refinements.md`).

Design обязан провести свежий R&D по первичным upstream-источникам и сохранить дату/URL в
`findings/`; execution не повторяет внешние runtime checks, а проверяет только scripts/docs/
fixtures/source-derived commands, strict citations и Task projections. Historical P12.1 state,
journal и evidence остаются append-only provenance; их не переписывают.

## Почему

Сейчас отсутствуют безопасно предоставленные внешний host и credentials. Имитировать их или
оставлять текущий plan зависимым от них не создаёт полезного operator artifact. Полный, точный и
проверяемый locally пакет сокращает стоимость следующего реального deployment/rehearsal и даёт
ему один явный владелец.

## Alternatives

1. Сохранить live production acceptance в P12 — отклонено: она требует недоступного внешнего
   состояния и не может быть проверена сейчас.
2. Закрыть P12 с непроверенными claims — отклонено: это скрывает границу и не даёт пригодной
   инструкции.
3. Перенести реальный rehearsal в отдельный test plan, а P12 закрыть на локально проверяемом
   preparation deliverable — принято.

## Consequences

`task:plan-design 2026.08.09-RELEASE-READINESS P12` переписывает Phase Context, P12.2–P12.7,
Routing, bundles, roadmap и handoff под один adjacent batch без новых owner/external gates.
P12.2 выходит из parked external-proof topology только через updated item contract and
append-only recovery. Phase-close проверяет изменённый local acceptance; новый operator
test/rehearsal plan является самостоятельной последующей работой.
