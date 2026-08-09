---
id: D136
date: 2026-08-09
status: accepted
item: P10.5
items: [P10.5]
supersedes: []
superseded_by: null
---
## Решение

**`prc_fetches` получает отдельные `rows_parsed` и `rows_errors`; ListingShapeCheck судит о
форме по ним, а не по business-delta `rows_new + rows_updated`. Непустой полностью распарсенный,
но неизменившийся блок здоров; non-empty с нулём parsed или с mapping errors считается плохим по
явному baseline threshold.**

## Почему

New/updated измеряет изменение доменных данных, не способность парсера разобрать вход.
Существующий носитель не различает normal unchanged и total mapping failure. **Consequences:**
baseline create migration/model/factory/logger расширяются без догоняющей prod migration (прода
нет); `CsvBlockIngestor` считает каждую принятую и каждую rejected row; non-200 остаётся отдельным
сигналом.

