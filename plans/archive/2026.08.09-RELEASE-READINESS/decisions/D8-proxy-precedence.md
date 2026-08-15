---
id: D8
date: 2026-08-09
status: accepted
item: P5.3
items: [P5.1, P5.2, P5.3, P5.4, P5.5]
supersedes: []
superseded_by: null
---
## Решение

Runtime precedence фиксируется как profile proxy → optional default proxy → direct.
PLAYWRIGHT_MCP_PROXY_SERVER остаётся отдельным tooling input и не участвует в runtime resolve.
ProxyResolver/ProxyScope и разделение global/per-target health сохраняются.
RAG:— (repo-grounded: brief/00-brief.md и AGENTS.md).

## Почему

Это явный owner contract; иной fallback может незаметно изменить egress crawler/parser.
