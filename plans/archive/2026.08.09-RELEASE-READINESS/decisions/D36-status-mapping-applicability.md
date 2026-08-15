---
id: D36
date: 2026-08-09
status: accepted
item: P8.3
items: [P8.1, P8.3, P8.4, P8.5, P9.2, P10.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

`ProcurementStatus` остаётся global canonical enum, а каждый raw status rule получает ровно одну
applicability: platform default ИЛИ exact registry override. Resolve order фиксирован:
exact registry → owning platform default → race-safe creation exact-registry `unknown`. Глобальные
raw-stage rules, JSON predicates и numeric priority запрещены. Existing rows остаются exact registry;
fz44 rows не повышаются до platform default без terminal evidence. RAG:— (repo-grounded:
`findings/P8-platform-status-extension-recon-2026-08-09.md` и
`research/10-P8-execution-design.md`).

## Почему

Registry-only модель безопасна, но дублирует общую vocabulary площадки; platform-only модель не
выражает исключение конкретного реестра. Два FK-backed scope с детерминированной precedence дают
нужную гибкость и проверяемую уникальность без преждевременного rule engine.

## Consequences

Migration добавляет exactly-one check и partial unique indexes; rollback никогда не удаляет
platform-scope data и обязан отказать до mutation, если старую схему восстановить без потери нельзя.
Filament показывает platform/registry/applicability и сохраняет identity rule immutable на edit.
Третий scope или другая precedence требуют superseding D36.

