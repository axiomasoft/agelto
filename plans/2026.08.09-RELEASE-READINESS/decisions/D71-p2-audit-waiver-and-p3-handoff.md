---
id: D71
date: 2026-08-11
status: accepted
item: P2.7
items: [P2.1, P2.2, P2.3, P2.4, P2.5, P2.6, P2.7]
supersedes: []
superseded_by: null
---
## Решение

Owner directive 2026-08-11 заменяет только требование D70 о новом fresh-context audit P2.
Уже выполненные P2 audits и committed GREEN release carrier P2.7 `cdc6ae5b` достаточны для
штатного закрытия фазы. Новый audit P2 не проводится; P2 закрывается append-only, после чего
исполнение передаётся на P3. RAG:— (repo-grounded: owner directive 2026-08-11).

## Почему

Директива владельца является явным authority gate и фиксирует достаточность уже собранного
audit/release evidence, не отменяя исторический RED-аудит, P2.7 remediation или их provenance.
RAG:— (repo-grounded: owner directive 2026-08-11, `phases/P2/P2.7.state.md`).

## Alternatives

1. Провести новый fresh-context audit P2 по D70. Отклонено прямой директивой владельца.
2. Переписать или удалить D70/исторический RED-аудит. Отклонено: append-only provenance сохраняется.
3. Закрыть P2 по terminal item states и GREEN P2.7 carrier, затем передать P3. Выбрано.

## Consequences

D70 остаётся историческим remediation decision, но его post-close fresh-audit requirement более
не действует. P3.1 может стартовать после штатного P2 close; новый P2 audit не является gate.
RAG:— (repo-grounded: owner directive 2026-08-11).
