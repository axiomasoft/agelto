---
id: D38
date: 2026-08-09
status: accepted
item: P9.2
items: [P9.1, P9.2, P9.3, P9.4, P9.5, P9.6, P10.3, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Filament presentation contract состоит из четырёх правил: context-independent enum, уже напрямую
переданный Filament, получает единый русский `HasLabel`; context-specific/Llm interface values
остаются Filament-free и переводятся в app-owned presenter; boolean показывается однозначной
boolean icon/entry или «Да/Нет»; schema/table используют только штатные Section/helperText,
toggleable/breakpoint, wrap/lineClamp/tooltip и ActionGroup contracts. RAG:✅ 2026-08-09 official
Filament enum/schema/table docs; applicability matrix — `research/11-P9-execution-design.md`.

## Почему

Per-screen arrays дрейфуют, а blanket dependency всех enums от Filament нарушит provider-agnostic
границу prompt/LLM contracts. Нативные component contracts закрывают owner UX без custom CSS/JS и
не требуют менять persistence/domain semantics. UUID/internal IDs остаются доступны по явному
toggle, но не занимают primary table surface без operator reason.

## Consequences

P9 добавляет dataset/static guards на enum labels, schema Sections, technical-ID policy и responsive
columns. Help text обязан объяснять смысл/единицу/precedence/side effect, а не заполнять каждое поле.
Любая находка P9.5, требующая domain/schema/permission изменения, эскалирует к новой D-записи; UI
cleanup не получает права менять validation, dehydration, policy или stored values.
