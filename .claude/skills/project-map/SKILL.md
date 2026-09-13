---
name: project-map
description: "Быстрая карта структуры проекта Agelto и зон ответственности по директориям."
---

# Project Map (Agelto)

## Когда активировать

Нужно быстро понять, где вносить изменения в crawling/parsing/EIS/Inertia.

## Быстрый роутинг

Структура плоская (`app/**`), без `Modules/` — три домена по короткому префиксу таблицы:

- `app/Models/Procurement/`, `app/Services/Procurement/`, `app/Dto/Procurement/`, `app/Enums/Procurement/`, `app/Repositories/Procurement/`, `app/Console/Commands/Procurement/` — единый домен закупок (`prc_*`): crawling, document prepare, AI analysis, estimate extraction, площадки, логи операций. Внутри `Services/Procurement/` — подпапки `Eis/` (обход ЕИС), `Modes/`, `Contracts/`, `Exceptions/`, `Detail/`; внутри `Models/Procurement/` — подпапки по назначению `Document/`, `Estimate/`, `Crawler/`, `Ai/`, `Platform/` (ядро закупки и `OperationLog` — плоско в корне).
- `app/Models/Reference/`, `app/Services/Reference/` — общие справочники (`ref_*`): ОКПД2, КТРУ, ОКВЭД2, КБК.
- `app/Models/Organization/`, `app/Services/Organization/`, `app/Repositories/Organization/` — организации (`org_*`).
- `app/Http/Controllers/` — Inertia, settings, Fortify (тонкий HTTP).
- `resources/js/pages/` — Inertia/Vue UI.
- `routes/` — web, settings.
- `docker/` — Dockerfile, Makefile, compose; deploy через `make prod:*`.
- `docs/workflow/` — бизнес-процессы (procurement lifecycle, crawling, document/estimate pipeline, глоссарий).
- `docs/dev/` — архитектура, схема БД, crawling/document pipeline, LLM providers, admin panel.
- `.ai/` — source of truth для AI guidelines.
- `tests/` — Pest feature/unit.

## Принцип размещения логики

- Домен parsing/crawling — `app/{Models,Services,Dto,Enums,Repositories}/Procurement/` (площадка ЕИС — `Services/Procurement/Eis/`).
- Длительные операции (browser, Gemini, file processing) — queued Jobs, Horizon.
- Синхронный HTTP — только быстрые операции и UI.
- Filament CRUD — ресурсы админки, см. `docs/dev/admin-panel.md`.

## Связанные источники

- `.ai/guidelines/architecture.md`
- `.ai/guidelines/navigation.md`
- skill `procurement-pipeline-dev`
- `docs/workflow/crawling-and-discovery.md`
- `docs/dev/architecture.md`
