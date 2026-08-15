---
id: D33
date: 2026-08-09
status: accepted
item: P7.3
items: [P7.1, P7.2, P7.3, P7.4, P7.6, P10.3, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Provider screening response v2 несёт `document_type`, bounded `confidence`, обязательный bounded
`summary`, typed `estimate_cardinality=none|single|multiple|unknown`, bounded normalized
`tags:list<string>` и optional non-authoritative `classification_note`. Backend disposition/reason
в provider schema не входят. `single|multiple` требуют type `estimate`; `estimate+none` запрещён,
а `estimate+unknown` честно выражает недостаточный first-page evidence. RAG:— (repo-grounded:
owner brief, current screening prompt/DTO and `research/09-P7-execution-design.md`).

## Почему

Текущий flat `description/tags/ignore_reason` уже доказывает возможность typed response, но не
различает число смет и смешивает model note с backend-решением. Отдельная cardinality axis сохраняет
поддержку one/multiple/unknown без multi-label analyzer redesign.

## Consequences

P7.1 фиксирует точные limits/tag normalization по terminal corpus. Prompt/schema/gold/eval/tests
мигрируют вместе; старый key `description` не dual-write'ится бессрочно. Если corpus требует
multi-label routing вместо правила «видимая смета ⇒ type estimate», нужна новая D,
`supersedes D33`, с ценой переработки analyzer registry и extraction dispatch.
