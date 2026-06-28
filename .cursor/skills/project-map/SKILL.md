---
name: project-map
description: "Быстрая карта структуры проекта Agelto и зон ответственности по директориям."
---

# Project Map (Agelto)

## Когда активировать

Нужно быстро понять, где вносить изменения в crawling/parsing/EIS/Inertia.

## Быстрый роутинг

- `Modules/Platform/` — ядро: crawling, document prepare, AI analysis, estimate extraction, Filament admin.
- `Modules/Eis/` — реализация обхода ЕИС (`EisCrawlingService` и связанное).
- `app/Services/` — инфраструктура: Browser, Gemini, proxy, SmetaParser.
- `app/Models/Order/` — заказы/лоты после успешного парсинга.
- `app/Http/Controllers/` — Inertia, settings, Fortify (тонкий HTTP).
- `app/Dto/` — DTO смет, browser options.
- `resources/js/pages/` — Inertia/Vue UI.
- `routes/` — web, settings.
- `docker/` — Dockerfile, Makefile, compose; deploy через `make prod:*`.
- `docs/workflow/` — бизнес-процессы (parsing).
- `docs/dev/` — архитектура, docker, proxies.
- `.ai/` — source of truth для AI guidelines.
- `tests/` — Pest feature/unit.

## Принцип размещения логики

- Домен parsing/crawling — `Modules/Platform` (+ `Modules/Eis` для площадки).
- Длительные операции (browser, Gemini, file processing) — queued Jobs, Horizon.
- Синхронный HTTP — только быстрые операции и UI.
- Filament CRUD — в модульных Filament Resources.

## Связанные источники

- `.ai/guidelines/architecture.md`
- `.ai/guidelines/navigation.md`
- `.ai/guidelines/project-development.md`
- `docs/workflow/parsing-process.md`
