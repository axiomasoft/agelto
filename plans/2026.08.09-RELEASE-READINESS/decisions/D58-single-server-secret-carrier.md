---
id: D58
date: 2026-08-10
status: accepted
item: P0.6
items: [P0.6, P2.1, P2.2, P2.3, P2.4, P12.2, P12.4]
supersedes: []
superseded_by: null
---
## Решение

Первый production deployment использует явные Compose file-backed secrets, создаваемые
идемпотентным bootstrap carrier вне Git. Каждый service получает только объявленные secrets;
preflight проверяет existence, ownership, фактическую readability от container user и отсутствие
значений в Compose output, image layers, process args и logs. Build-time private credentials идут
только через BuildKit secret/SSH mounts. RAG:✅ 2026-08-10
(`findings/P0-rag-infrastructure.md`, INF-04/INF-05); RAG:— (repo-grounded:
`findings/P0-agelto-inventory.md`, раздел Secrets and preflight).

## Почему

Текущий `external: true` carrier не создаёт secret в обычном Compose и не реализует заявленный
fallback; file-backed mode соответствует single-server topology и допускает fail-closed preflight.

## Alternatives

1. Сохранить `external: true` Swarm secrets на single-server Compose. Цена: обычный Compose не
   создаёт их и не реализует заявленный fallback.
2. Использовать file-backed Compose secrets. Цена: host bootstrap и permissions становятся явной
   операторской обязанностью и должны тестироваться.
3. Передавать production secrets обычными environment variables. Цена: более широкая поверхность
   утечки и конфликт с owner-требованием file secrets.

## Consequences

Выбрана альтернатива 2. Silent fallback между file/env/Swarm режимами запрещён. Rotation заменяет
файл атомарно и завершает lifecycle только после controlled worker/container reload и проверки
нового значения без его печати.
