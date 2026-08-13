---
id: D84
date: 2026-08-13
status: accepted
item: P9
items: [P9]
supersedes: [D83]
superseded_by: null
---
## Решение

Владелец принимает уже выполненный независимый GREEN audit P9 как достаточный для закрытия
фазы. Его fingerprint `577bfdf16e1d6c2175cf222d04e5df62bc5d398d491d0cfb458256abd7553b23`
стал stale только потому, что сам audit commit `5e6ce95b` записал terminal evidence и
пересобрал P9 state/bundle projections. Новый product risk, non-green item или material
deviation не появился. Владелец разрешает `phase-close: direct-green-validation`: один штатный
`task:plan-close` с `owner_decision="D84"`; повторный Audit P9 не требуется. RAG:— (repo-grounded: P9 audit
GREEN, `journal.jsonl:388`, `5e6ce95b` and owner directive 2026-08-13).

Это решение supersedes D83 только в части P9 phase-close successor: completed D83
design-audit и все остальные topology/route constraints сохраняются. Оно не меняет продукт,
historical audit, P9 item statuses или правила будущих phase audits. RAG:—.

## Почему

`phase_candidate_fingerprint()` включает SHA generated item states. Audit bookkeeping изменило
эти носители после фиксации GREEN verdict, поэтому close gate увидел другой digest без новой
проверяемой product-семантики. Повторение двухчасового full audit не добавит независимого
evidence и создаст тот же self-induced stale cycle. RAG:— (repo-grounded: task
`plan_gates.py`, P9 states and audit commit).

## Alternatives

1. Повторить полный Audit P9. Отклонено владельцем: это дублирует уже выполненный evidence и
   не устраняет самопорождаемый stale fingerprint.
2. Подменить fingerprint в historical audit event. Отклонено: нарушает append-only provenance.
3. Direct close по узкому owner override D84 с сохранением всех доказательств. Выбрано.

## Consequences

P9 закрывается только при шести terminal `🟢 Done` items, чистом committed tree, текущем
GREEN evidence и зелёных Task/citation checks. Следующий execution root — P10.1 в новой сессии
на `frontier/high`; P10 не запускается текущим close root. Swissknifeman roadmap получает
отдельную запись о необходимости делать audit fingerprint устойчивым к собственным projections.
