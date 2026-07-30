---
audience: dev
type: reference
status: living
---

# Runbooks — каталог сбоев

- [Анализ завис](stuck-analysis.md) — lease/статусы не двигаются.
- [Ошибки Gemini](gemini-errors.md) — квоты/429/региональные блокировки/прокси.
- [Затор Horizon](horizon-backlog.md) — растёт очередь, supervisor'ы.
- [Сверка с биллингом провайдера](billing-reconcile.md) — `ai:cost-reconcile`, расхождение трекинга с биллингом.

Формат: Symptoms → Triage → Fix → Rollback → Escalation.
