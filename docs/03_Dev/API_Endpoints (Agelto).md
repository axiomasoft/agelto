---
type: api
tags: [agelto, api]
created: 2026-06-14
updated: 2026-06-14
---
> ← [[Architecture_Agelto]] | ← [[BRD (Agelto)]]
# API / Контракты — Agelto

> Канонический источник контрактов интеграции. Архитектурный контекст — [[Architecture_Agelto]] §9.

---

## CRM-интеграция

### Целевые CRM

| CRM | Приоритет | Метод |
|:---|:---|:---|
| **Bitrix24** | P1 | REST API / Webhooks |
| **amoCRM** | P1 | REST API |
| **1С:ERP** | P2 | XML обмен |

### Структура лида в CRM

```json
{
  "title": "Кабель ВВГнг 3x2.5 — Тендер №2026-123456",
  "contact": {"name": "ООО Стройподряд", "inn": "7701234567"},
  "tender": {"reg_number": "...", "url": "...", "submission_end": "2026-06-15", "initial_price": 15000000},
  "matched_items": [{"smeta_name": "...", "catalog_sku": "...", "match_score": 0.97}]
}
```
