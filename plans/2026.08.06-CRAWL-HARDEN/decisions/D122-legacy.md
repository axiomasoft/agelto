---
id: D122
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P4.6 дополняет закрытый перечень `Files` единственной точкой факта авто-дизейбла — `app/Services/Procurement/Proxy/ProxyResolver.php`; `OperationLogWriter` вызывается в ветке, которая уже переводит узел в disabled.** Альтернатива — новый event/listener — отвергнута: он создал бы второй механизм доставки для одного синхронного операторского события и расширил бы контракт сверх задачи. **Consequences:** item меняет ещё один существующий сервис и добавляет регрессионный тест записи события дизейбла; остальной механизм breaker и критерии отключения не меняются.

## Почему

Исполнение P4.6 остановилось до кодовых правок: `ProxyResolver::reportOutcome()` — единственное место, устанавливающее `is_active = false`, `disabled_at` и `disabled_reason`, а в исходном `Files` оно отсутствовало. `RAG:— (repo-grounded: phases/P4.md P4.6 escalation; app/Services/Procurement/Proxy/ProxyResolver.php:125-132)`
