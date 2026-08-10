---
id: D56
date: 2026-08-10
status: accepted
item: P0.3
items: [P0.3, P0.4, P0.6]
supersedes: []
superseded_by: null
---
## Решение

P0.3 закрывается только после MCP-backed replay прежних infrastructure findings: зелёный
`perplexity-web` login и focused cited-search smoke предшествуют research, Context7 используется
для доступных versioned docs, а INF-01–INF-12 получают явную replay matrix. Существующий
WebSearch-only `findings/P0-rag-infrastructure.md` сохраняется как provisional seed, но не
считается terminal evidence и не открывает P0.4/P0.6. RAG:— (repo-grounded:
`brief/01-refinements.md`, `journal.jsonl` P0.3 escalation,
`findings/P0-rag-provider-runtime-2026-08-10.md`).

## Почему

Владелец явно потребовал MCP-backed повторную проверку прежних findings и plan design. Текущий
Perplexity MCP присутствует в tool catalog, но smoke падает на cross-runtime graphical/profile
boundary; молчаливое понижение acceptance до WebSearch повторило бы уже отклонённый degraded run.

## Alternatives

1. Принять готовый WebSearch-only findings — отклонено owner requirement и отсутствием MCP replay.
2. Исправить user-level MCP config/profile внутри P0.3 — отклонено: это внешняя mutation вне Files
   и ownership application-плана.
3. Удалить provisional findings — отклонено: он сохраняет query/source provenance и служит
   проверяемым seed для replay, не получая terminal status.

## Consequences

P0.3 остаётся `🔴 Blocked`, пока fresh-session smoke не станет зелёным; P0.4 и P0.6 не стартуют.
Цена — явная пауза execution вместо быстрого принятия degraded evidence. После восстановления
provider тот же item повторяет 12 verdicts, фиксирует расхождения и только затем может закрыться.
