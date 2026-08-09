---
id: D119
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Отдельный P4.8 восстанавливает три общих validation-гейта P4.1 и не реопенит её сданный носитель настроек:** `EisPlatformSeeder` создаёт `Registry.uuid` только при insert; core-contract возвращается в document-neutral границы (нейтральный `ExtractionRoute`, без ссылки на type-specific command); `Http::preventStrayRequests()` заменяет ложный grep URL. **Consequences:** P4 растёт с 7 до 8 items, а следующая фаза ждёт завершения P4.8; guard может честно открыть дополнительные stray HTTP в старых тестах — их нельзя обходить или разрешать молча.

## Почему

Три красных гейта были уже до P4.1, но именно её завершение сделало их блокирующими для честной приёмки. Фактура и сужение решения — `findings/P4.8-validation-gates-2026-08-08.md` и `research/13-p4.8-validation-gates.md`. `RAG:— (repo-grounded: findings/P4.8-validation-gates-2026-08-08.md; research/13-p4.8-validation-gates.md)`
