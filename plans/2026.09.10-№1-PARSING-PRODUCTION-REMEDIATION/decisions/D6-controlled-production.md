---
id: D6
date: 2026-09-10
status: superseded
item: P4.3
items: [P4.1, P4.2, P4.3]
supersedes: []
superseded_by: D10
---
## Решение

Controlled-production gate — owner-authorized 72-часовое ограниченное окно после GREEN P4.1 и
resolved P4.2. Закрытие требует непрерывного evidence по run completeness, retries, orphan states,
storage truth, backlog age и ручной сверке всех выбранных контрольных изменений; необъяснённый
разрыв, растущий хвост или ложный lifecycle возвращает verdict RED/ATTENTION, не waiver.

## Почему

Первичный аудит предлагает 48–72 часа как практический старт, не универсальный SLA; выбрана верхняя
граница. RAG:— (repo-grounded: `artifacts/PARSING_PRODUCTION_AUDIT.md`, раздел «Конечный объём работы»).
**Consequences:** календарное ожидание живёт в отдельном item; план не авторизует production traffic
сам по себе, а P4.3 обязан получить owner gate и сохранить точные timestamps/scope/metrics.
