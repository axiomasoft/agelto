---
id: D61
date: 2026-08-10
status: accepted
item: P0.6
items: [P0.6, P6.1, P6.2, P6.3, P6.4, P6.5, P6.6, P7.1, P7.2, P7.3, P7.4, P7.6, P8.1, P8.2, P8.5]
supersedes: []
superseded_by: null
---
## Решение

Document admission и handler resolution используют типизированную матрицу extension + accepted
MIME + magic/content evidence + issuer/specification. Несовпадение сигналов и неизвестный format
дают явный unsupported reason, а не generic parser fallback. `.gsfx` сохраняется как literal
vendor format; `JSRF`/`GSRF` не являются alias без issuer specification/sample corpus. Procurement
document taxonomy остаётся выше estimate format taxonomy. RAG:✅ 2026-08-10
(`findings/P0-rag-domain-security.md`, DOM-03/DOM-05/DOM-06).

## Почему

Extension и MIME не доказывают content identity, а недоказанные aliases превращают unsupported
input в ложноположительный parser route; typed disposition сохраняет наблюдаемость и безопасность.

## Alternatives

1. Routing только по extension/MIME. Цена: renamed или private payload ошибочно получает handler.
2. Три независимых content signals и typed unsupported reason. Цена: registry и fixtures сложнее,
   а private formats требуют явного evidence record.
3. Нормализовать похожие spelling к GSFX. Цена: ложная совместимость без issuer provenance.

## Consequences

Выбрана альтернатива 2; альтернатива 3 отклонена. Archive extraction дополнительно применяет
containment, depth/count/aggregate/per-entry budgets, normalized-path uniqueness, link/device
rejection и bounded runtime до создания дочерних file records.
