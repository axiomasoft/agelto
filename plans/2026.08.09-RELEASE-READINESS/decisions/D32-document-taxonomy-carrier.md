---
id: D32
date: 2026-08-09
status: accepted
item: P7.1
items: [P7.1, P7.2, P7.3, P7.4, P7.5, P7.6, P8.1, P9.2, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Semantic document type остаётся backed enum `AiDocumentType`; `Unknown` — честный завершённый
вердикт, SQL NULL — только состояние до успешного screening. `Document.kind` остаётся raw source
label. Новый case добавляется одним schema/prompt/fixture/eval/UI/DB-check изменением; наличие
extraction analyzer по-прежнему определяется `DocumentAnalyzerRegistry`, а не самим enum.
P7.1 замораживает exact case-set только после terminal P0.5/P0.6 и corpus reconcile. RAG:—
(repo-grounded: `findings/P7-screening-contract-recon-2026-08-09.md`, archived
`root/document-type-axis.md`, current `AiDocumentType`/`DocumentAnalyzerRegistry`).

## Почему

Raw source labels нестабильны между platforms, а runtime dictionary разнесёт один closed value set
между provider schema, DB constraints и consumers. Enum+registry уже доказывает второй тип без
сметного coupling и сохраняет fail-closed check-constraints.

## Consequences

Любая terminal P0/corpus contradiction создаёт новую D с `supersedes D32`; executor не добавляет
case «по названию из одного документа». Расширение case-set обязано обновить все восемь DB axes и
автоматические consumer tests, даже если новый тип пока не имеет extraction analyzer.
