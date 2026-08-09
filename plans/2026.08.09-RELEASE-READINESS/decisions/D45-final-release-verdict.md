---
id: D45
date: 2026-08-09
status: accepted
item: P12.7
items: [P12.4, P12.5, P12.6, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Final readiness report имеет закрытый verdict `READY|NOT_READY` и привязан к fresh Ubuntu host,
candidate Git SHA, exact app/Doctor image digests, private package SHA, Compose project и redacted
evidence hashes. `READY` допустим только при literal green runbook, P11 candidate-SHA CI, clean
volumes/DB, first deploy, admin/assets/docs/readiness/Doctor browser smoke, backup+restore и rollback.
Любой mandatory red/missing proof оставляет P12.7 blocked с точным remediation/resume и verdict
`NOT_READY`. RAG:— (repo-grounded: D9, D17, D39, D42 and owner final acceptance).

## Почему

Терминальный item, который только перечисляет намерения или скрывает незапущенный внешний gate,
создаёт ложное разрешение на первый deploy. Exact anchors и binary verdict отделяют доказанную
готовность от отчёта о незавершённой работе.

## Consequences

Недоступность реального host/DNS/registry/CI или owner-approved recovery profile не может быть
заменена local emulation. `NOT_READY` report сохраняется как evidence, но phase/plan не объявляются
готовыми до отдельного green rerun.
