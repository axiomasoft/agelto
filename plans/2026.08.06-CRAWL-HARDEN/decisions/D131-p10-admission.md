---
id: D131
date: 2026-08-09
status: accepted
item: P10.1
items: [P10.1, P10.2, P10.3, P10.4, P10.5, P10.6, P10.7]
supersedes: []
superseded_by: null
---
## Решение

**После терминальной P9 в план добавляется P10 из семи последовательных items для исправления
восьми product-code дефектов ручного adversarial-аудита. Закрытые ТЗ P1–P9 не переоткрываются и
не переписываются. Swissknifeman Layout-v2 rollout, read-admission migration и projection defects
в P10 не входят.**

## Почему

Владелец уточнил, что Swissknifeman ещё дорабатывается и сам выполнит миграции; от этого захода
требуется проверка кода, логики и ошибок, а затем — зафиксированная новая фаза. Поэтому продуктовые
дефекты получают исполнимые carriers P10, а 27 известных fail-closed diagnostics сохраняются как
независимое pre/postflight evidence. **Consequences:** P10 добавляет 7 items (всего 66), исполняется
вручную по Layout v2, application code в design-сессии не меняется; historical delivery RED из
`findings/final-manual-audit-2026-08-09.md` остаётся отдельным риском.
