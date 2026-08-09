---
id: D129
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Fast path D126 применён к P6.3 по прямому указанию владельца: `tests/Unit/Architecture/DomainLayoutTest.php` входит в `Files` и Required Reads, чтобы зарегистрировать уже предписанные подобласти `Procurement\\History` для DTO и read-репозитория.** **Consequences:** P6.3 расширяет item-коммит на один архитектурный реестр и получает честный статус `🟠`, поскольку исходный `Files` не перечислял обязательный тест; предметный контракт модуля истории, его Routing и соседние фазы не меняются.

## Почему

Без регистрации `History` полный прогон детерминированно отклоняет пути, прямо заданные Scope Included P6.3. Владелец явно потребовал устранить блокировку в текущем проходе. `RAG:— (repo-grounded: tests/Unit/Architecture/DomainLayoutTest.php:29-59; phases/P6.md P6.3; D126)`
