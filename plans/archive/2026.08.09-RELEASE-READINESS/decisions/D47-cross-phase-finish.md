---
id: D47
date: 2026-08-09
status: superseded
item: finish
items: [finish]
supersedes: []
superseded_by: D52
---
## Решение

Design pass 1/1 завершает cross-phase authoring reconcile для Layout v2: 13 фаз и 74 items
сохраняются без scope change. Исправлены четыре класса связности: materialized P0.6 output
carriers; frozen `root/contracts/release-readiness-v1.md` добавлен в contracts P1–P12 и Required
Reads их entry items; phase-index P0/P10–P12 выровнены по external-fact/DoR schema; handoffs,
intent trace, index и roadmap переведены на единый post-finish state. RAG:— (repo-grounded:
полный plan tree и Task checks 2026-08-09).

## Почему

До reconcile item specs были локально полными, но P0.6 обещал отсутствующие carriers, а
downstream entry items зависели от terminal P0.6 state без прямого чтения frozen contract.
P10–P12 также не имели явных phase-level Scope/Sequence/External facts/DoR sections. Это не
меняло product scope, но оставляло неоднозначный cold-start execution path.

## Alternatives

1. Оставить contract transitively доступным через P0.6 state — отклонено: consumer не читает
   фактический frozen source of truth.
2. Размножить contract clauses по всем item specs — отклонено: возник бы drift между копиями.
3. Один versioned carrier, явный entry read каждой фазы и terminal-state gate — принято.

## Consequences

Entry bundles P1.1–P12.1 становятся немного больше, но получают deterministic contract source.
Skeleton carriers не являются execution evidence и должны быть заменены P0.6 только после
terminal P0.1–P0.5. Execution остаётся заблокированным до независимого fresh-context
`plan-audit design` на frontier/xhigh; `ATTENTION|RED` возвращает план в адресный redesign.
