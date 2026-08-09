---
id: D114
date: 2026-08-07
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**P3.4 переведён `🟢 Done` → `🟠 Done with deviations`** по двум material-триггерам сразу: (1) item-коммит `5b237ab0` несёт дифф вне `Files` — `app/Exceptions/Procurement/Crawler/RunAlreadyTerminal.php` (F3); (2) Scope Included п. 10 выполнен не полностью — тест консольного отказа не написан (F5). **Отменяет** закрывающий вердикт `🟢` этого item'а. **Consequences:** счёт доски по фазе меняется, но код P3.4 не переисполняется — недостающий гейт пишет P3.9, а лишний файл остаётся на месте (он механически необходим Scope Included п. 3 и реестра решений не меняет); правка `Files` задним числом НЕ делается — `Files` фиксирует то, что было предписано, отклонение живёт в `Known Deviations`

## Почему

Оба триггера перечислены в status-rule (`plan-protocol` §6) прямо, и ТА ЖЕ фаза применила первый из них к P3.5 (`🟠` за тест не в том файле): два одинаковых факта с разными статусами внутри одной фазы — та же болезнь, что F4. `RAG:— (repo-grounded: git show 5b237ab0 --stat; phases/P3.md P3.4 Known Deviations, P3.5 Known Deviations)`
