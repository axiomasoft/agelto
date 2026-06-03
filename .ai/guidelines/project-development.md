# Project Development (Parsing Pipeline)

Короткий техрегламент для изменений в домене crawling/parsing/AI analysis.  
Цель: не ломать многостадийный пайплайн и держать согласованность между модулями, jobs, Filament и docs.

Правки AI-правил — только в `.ai/...`; `CLAUDE.md` и `AGENTS.md` не редактируются напрямую.

## 1) Стадии пайплайна (source of truth)

Master-описание: `docs/workflow/parsing-process.md`.

| Стадия | Суть | Типичный код |
|---|---|---|
| 1 Collector | сбор ссылок из ЕИС | `Modules/Eis`, `Process/Crawling` |
| 2 Document Indexer | список вложений | `Process/DocumentPrepare` |
| 3 AI Screening | быстрая проверка «это смета?» | `Process/DocumentAnalysis` |
| 4 Full Extraction | полный разбор позиций | `Process/EstimateAnalysis`, `OrderParsing` |

Не переносить логику стадии N в стадию N−1 «для ускорения» без явного решения — это ломает фильтрацию и расход API.

## 2) Структура и нейминг

- Platform-agnostic код — `Modules/Platform/`.
- Площадка ЕИС — `Modules/Eis/`.
- Job: `*Job` в `Process/*/Jobs/`.
- Processor: `*Processor` — оркестрация одной стадии.
- DTO: `Modules/Platform/app/Dto/` или `app/Dto/` для cross-cutting.
- Filament: `Modules/Platform/app/Filament/Resources/`.

## 3) Dependency map (что менять вместе)

При изменении стадии, статуса или модели:

1. **Миграции / модели** — `Modules/Platform/database/migrations`, Eloquent models.
2. **Enums / DTO** — статусы analysis, crawling, estimate schema.
3. **Jobs + Processors** — dispatch, retry, failed handling.
4. **Horizon** — queue names, supervisors (если добавляется новая очередь).
5. **Filament** — ресурсы, если меняется операторский UI.
6. **Docs** — `docs/workflow/parsing-process.md`, `docs/dev/architecture.md`.
7. **Tests** — feature/unit для изменённого поведения.

## 4) Минимальный test-gate (Docker)

```bash
make test-preflight
make test-php
# или точечно:
docker compose exec app php artisan test --compact --filter=ChangedArea
```

## 5) Frontend

- Inertia pages: `resources/js/pages/`.
- Маршруты backend — через Wayfinder (`@/actions`, `@/routes`), без хардкода URL.
- Сборка: `yarn build` / `make frontend-build`.

## 6) Proxy / EIS

- Локальная разработка с прокси: `docs/dev/proxies.md`.
- Docker `extra_hosts` для `eis-auth.local`, `eis-api.local` — не удалять без замены.

## 7) PR checklist

- Какая стадия пайплайна затронута (1–4).
- Новые/изменённые jobs и очереди.
- Влияние на Gemini/browser (стоимость, timeout, retry).
- Обновлены ли workflow/dev docs.
- Прогнан test-gate.
