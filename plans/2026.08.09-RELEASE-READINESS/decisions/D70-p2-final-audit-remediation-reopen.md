---
id: D70
date: 2026-08-11
status: accepted
item: P2.7
items: [P2.1, P2.2, P2.3, P2.4, P2.5, P2.6, P2.7]
supersedes: []
superseded_by: null
---
## Решение

RED-аудит P2, заход 1, закрывается ровно одним remediation-item P2.7. Реопен снимает terminal
статус P2 добавлением nonterminal P2.7, повышает plan version `0.1.4` → `0.1.5`, фиксирует причину
этой D-записью и инвалидирует `## Phase Handoff` маркером `> УСТАРЕЛ — фаза реопенена D70`.
Layout v2 Status Board до первого execution event P2.7 показывает nonterminal `⬜ Not started`,
а не общий reopen-канон `🟡 In progress`: projector выводит фазовый статус только из item states,
поэтому fabricated execution event не создаётся. Это известное protocol deviation/
PROMOTE-CANDIDATE, а не скрытый пропуск шага. RAG:— (repo-grounded:
`phases/P2/P2.md` → `## Audit P2 — 2026-08-11`, Task
`packages/task/lib/task/plan_status.py::_phase_status`).

P2.7 — последний design-carrier P2 по прямому решению владельца. После него путь закрыт:
`task:plan-run ... P2.7` → `task:plan-close ... P2` → fresh-context `task:plan-audit ... P2`.
Если повторная проверка найдёт дефект уже запечатанной acceptance, он исправляется bounded fix-ом
в рамках D70/P2.7 с append-only evidence, без ещё одного design-прохода; новый product contract
или выход за anti-scope всё ещё требует отдельного решения владельца. RAG:— (repo-grounded:
owner direction 2026-08-11, `brief/01-refinements.md`).

## Почему

F1–F6 сходятся в один P2 release-readiness hotspot: diagnostics, secrets, vendor/preflight/
bootstrap carriers, seed/admin suites и парный app/Doctor image обязаны стать зелёными на одном
committed tree. F7 допустим только после этого product anchor, а F8 завершает ту же причинную
цепочку. Разделение создало бы промежуточные ложные GREEN и повтор тяжёлых Docker/DB gates.
RAG:— (repo-grounded: `phases/P2/P2.md` → `## Audit P2 — 2026-08-11`,
`research/19-P2-audit-remediation.md`).

## Alternatives

1. Переоткрыть P2.1–P2.6 и переписать их specs/state. Отклонено: ломает append-only provenance.
2. Разнести F1–F8 по нескольким items. Отклонено: carrier/evidence строго последовательны и
   несколько раз поднимут одинаковый Docker/DB acceptance-контур.
3. Один P2.7 с product commit(s), единым committed GREEN anchor и append-only corrections P2.1–P2.6.
   Выбрано: один audit-defined scope и один terminal proof.
4. Исправить Swissknifeman regex/runtime в этом item. Отклонено: предупреждение снимается честной
   формулировкой phase carrier; marketplace source не является product scope Agelto P2.

## Consequences

План содержит 79 items. P2.7 широк, поэтому исполняется `implementation/high`, `manual`,
`Review=full` и не закрывается без обязательных real Docker/Compose, isolated test-DB, Pest,
Pint, PHPStan, Task, citation и diff gates. Исторические product commits и post-close fixes
P2.1–P2.6 остаются неизменными; новые journal corrections называют их и P2.7 remediation anchor.
P3 не стартует до terminal P2.7, штатного close и GREEN повторного аудита P2.
RAG:— (repo-grounded: `phases/P2/P2.7.md`, `roadmap.md`, `handoff.md`).
