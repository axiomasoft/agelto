---
id: D30
date: 2026-08-09
status: accepted
item: P6.2
items: [P6.1, P6.2, P6.3, P6.5, P6.6, P7.1, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Формат документа определяется одним typed `DocumentFormatRegistry`: normalized extension,
detected MIME allowlist и format-specific content probe выбирают ровно один handler. Handler
возвращает typed outcome `ready_for_screening|expanded_container|unprocessable`; catch-all
passthrough запрещён. Unknown extension получает `unsupported_format`, evidence mismatch —
`format_mismatch`. Причина хранится отдельной nullable enum-cast колонкой
`prc_documents.unprocessable_reason`, а `last_error` остаётся human diagnostic. RAG:✅ 2026-08-09
official OWASP File Upload/Input Validation guidance and PHP Fileinfo manual; repo alternatives —
`research/08-P6-execution-design.md`.

## Почему

Текущие strategies дублируют extension arrays, `PassthroughStrategy` принимает всё, а Fileinfo сам
по себе не является достаточным доказательством. Typed registry даёт один расширяемый carrier,
автоматический contract gate для нового handler и надёжно отличает unsupported от временного сбоя.

## Consequences

P6.1 обязан сверить terminal P0.5/P0.6 и representative MIME/signature corpus. Несовместимый alias
или upstream policy требует superseding D до migration. Все существующие unprocessable writers
переводятся на общий Action/repository path; string-prefix сохраняется только как обратимо читаемая
диагностика, не как source of truth.
