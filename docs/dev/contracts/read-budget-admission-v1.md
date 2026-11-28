---
audience: dev
type: reference
status: living
---

# Контракт допуска по бюджету чтения

**Версия:** 1.1.0

    pass_item(i) = estimated_required_read_tokens(i) <= 100000

Этот замороженный порог (часть «а» решения о допуске) — контракт допуска Layout v2. Часть «б» —
отдельная проверка разрешимости и одиночного чтения, которую выполняет `task.plan_weight`.
