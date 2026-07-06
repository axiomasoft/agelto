# Project Development (Parsing Pipeline)

Короткий техрегламент для изменений в домене crawling/parsing/AI analysis.  
Цель: не ломать многостадийный пайплайн и держать согласованность между слоями домена `Procurement`, jobs, Filament и docs.

Правки AI-правил — только в `.ai/...`; `CLAUDE.md` и `AGENTS.md` не редактируются напрямую.

## 1) Стадии пайплайна (source of truth)

Master-описание: `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`.

| Стадия | Суть | Типичный код |
|---|---|---|
| 1 Collector | сбор извещений из ЕИС | `app/Services/Procurement/Eis/`, `app/Services/Procurement/CrawlerRunManager.php` |
| 2 Document Indexer | список вложений | `app/Services/Procurement/DocumentIndexer.php`, `DocumentDownloader.php` |
| 3 AI Screening | быстрая проверка «это смета?» | `app/Services/Procurement/` (analysis-часть), `app/Models/Procurement/Analysis.php` |
| 4 Full Extraction | полный разбор позиций | `app/Models/Procurement/Estimate.php` и сателлиты (`Item`, `Resource`, `Section`, `Total`) |

Не переносить логику стадии N в стадию N−1 «для ускорения» без явного решения — это ломает фильтрацию и расход API.

## 2) Структура и нейминг

- Единый домен закупок (`prc_*`) — `app/{Models,Services,Dto,Enums,Repositories}/Procurement/`, без разделения по модулям.
- Реализация обхода ЕИС — `app/Services/Procurement/Eis/`.
- Job: `*Job` (где есть очереди) — рядом с доменным сервисом.
- Processor/manager: оркестрация стадии (например, `CrawlerRunManager`, `PipelineDispatcher`).
- DTO: `app/Dto/Procurement/`.
- Filament: ресурсы админки (см. `docs/03_Dev/admin-panel.md`).

## 3) Dependency map (что менять вместе)

При изменении стадии, статуса или модели:

1. **Миграции / модели** — `database/migrations/` (плоско, без папки на модуль), `app/Models/Procurement/`.
2. **Enums / DTO** — `app/Enums/Procurement/`, `app/Dto/Procurement/` (статусы analysis, crawling, estimate schema).
3. **Repositories** — `app/Repositories/Procurement/` — dispatch, retry, failed handling завязаны на них.
4. **Horizon** — queue names, supervisors (если добавляется новая очередь).
5. **Filament** — ресурсы, если меняется операторский UI.
6. **Docs** — `docs/02_Workflow/crawling-and-discovery.md`, `docs/02_Workflow/document-and-estimate-pipeline.md`, `docs/03_Dev/architecture.md`.
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

- Локальная разработка с прокси: см. `docs/03_Dev/crawling-pipeline.md` (отдельного doc-файла по proxy-setup сейчас нет).
- Docker `extra_hosts` для `eis-auth.local`, `eis-api.local` — не удалять без замены.

## 7) PR checklist

- Какая стадия пайплайна затронута (1–4).
- Новые/изменённые jobs и очереди.
- Влияние на Gemini/browser (стоимость, timeout, retry).
- Обновлены ли workflow/dev docs.
- Прогнан test-gate.
