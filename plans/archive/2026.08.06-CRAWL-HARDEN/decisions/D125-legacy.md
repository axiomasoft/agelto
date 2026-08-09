---
id: D125
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Фаза P5 реопенена по `RED` аудита P5, заход 1; заводится один remediation-item P5.7 на F1–F6.** Четыре шага реопена исполнены: Status Board `🟠` → `🟡` (4/7), Version 0.24.0 → 0.25.0, эта D-запись, `## Phase Handoff` помечен `> УСТАРЕЛ — фаза реопенена D125`. **Consequences:** P6.1 временно сдвинут до закрытия P5.7 и повторного аудита; закрытые ТЗ P5.1–P5.6 не переписываются, их продуктовые расхождения исправляет отдельный атомарный item, а бухгалтерия получает воспроизводимые git-якоря.

## Почему

Audit replay воспроизвёл красный интеграционный тест и четыре дефекта непосредственно в Deliverables фазы: выборка смет, key-fields сигнал, requests counter и disable-control; блок `phases/P5.md` `## Audit P5 — 2026-08-08 (заход 1)`. `RAG:— (repo-grounded: phases/P5.md Audit P5; tests/Feature/Health/HealthCheckCommandTest.php; app/Services/Procurement/Health)`
