---
id: D53
date: 2026-08-09
status: accepted
item: P12.3
items: [P12.3, P12.4, P12.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Additive ordering correction к immutable D50. P12.3 до первого deploy владеет production
profile, DNS/Nginx/TLS configuration, backup/retention/watchdog/alert scripts and units и их
deterministic config/fixture gates; он не требует живое приложение, real scheduled backup или
application-checkable restore. P12.4 разворачивает первое приложение и доказывает live HTTPS,
readiness, Doctor и Livewire/browser paths. P12.5 после deploy включает recurring units, получает
реальный scheduled off-host recovery set, доказывает verified-copy-before-prune, watchdog/alert
delivery и isolated application-checkable DB/object/secret restore. P12.7 повторяет всю цепь.
Policy и fail-closed semantics D50 сохраняются. RAG:— (repo-grounded: audit design заход 2 F7,
P12.3–P12.5 Inputs/Validation and D44/D50).

## Почему

Живые `/up`/Livewire и содержательный DB/object/secret restore невозможны до P12.4, где впервые
появляются exact app+Doctor artifacts, migrations, seeds, admin и production data services.
Требовать их от P12.3 создавало цикл P12.3→P12.4 и вынуждало незаявленный temporary deploy либо
synthetic proof, выданный за production evidence.

## Alternatives

1. Временно deploy application внутри P12.3 — отклонено как scope leak из P12.4.
2. Признать fixture restore production proof — отклонено как подмена evidence class.
3. Разделить pre-deploy carrier gates и post-deploy real proof между P12.3/P12.5 — принято.

## Consequences

P12.3 закрывается на config/fixture evidence и не включает recurring timers до появления
application stack. P12.5 становится владельцем real recurring/retention/watchdog/alert/restore
evidence и дополняет `artifacts/P12-backup-evidence.md`; первый deploy P12.4 больше не зависит от
несуществующего application backup. Roadmap, phase contract и research отражают новый порядок.
