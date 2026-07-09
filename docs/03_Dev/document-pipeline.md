---
audience: dev
type: explanation
status: draft
---

# Document & Estimate Pipeline: internals

Здесь будет: мост → подготовка → скрининг → извлечение; jobs/очереди/lease/retry; инварианты
идемпотентности; статусы (включая `unprocessable`, D32); failure modes со ссылками на runbooks.
Диаграммы: Д1 (FSM документа), Д2 (стадии+очереди), Д4 (extraction-сессия) — из P10.2.

Наполняется в P10.2 (диаграммы) и P10.3 (текст).
