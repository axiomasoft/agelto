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
- [Смена S3-провайдера](storage-provider-switch.md) — переименование дисков, материализация, фолбэк, backfill, cutover.
- [Архивный обход площадки (ЕИС)](archive-run.md) — как запустить `crawler:run-archive`, предусловия, что смотреть, эскалация.
- [Площадка не проходит healthcheck](platform-health-broken.md) — разбор `broken`/`degraded`, витрина истории и эскалация.

- [Изолированный process-core стенд](process-test-rig.md) — identity, install, fixtures и deterministic checks.
- [Обновление окружения](environment-upgrade.md) — PHP 8.6, PostgreSQL 19 (дамп и восстановление, новая раскладка volume), переход с MinIO на Silo.

Формат: Symptoms → Triage → Fix → Rollback → Escalation.
