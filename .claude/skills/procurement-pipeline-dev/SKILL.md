---
name: procurement-pipeline-dev
description: "Dev-раннбук изменений в домене crawling/parsing/AI-анализа закупок: стадии пайплайна, dependency map (что менять вместе), test-gate, PR-чеклист. Активировать при правках в Services/Procurement, Models/Procurement, миграциях, jobs/очередях парсинга."
paths:
  - "app/Services/Procurement/**"
  - "app/Jobs/Procurement/**"
  - "app/Models/Procurement/**"
  - "app/Dto/Procurement/**"
  - "app/Enums/Procurement/**"
  - "app/Repositories/Procurement/**"
  - "database/migrations/**"
---
<!-- swissknifeman: перенесено из .ai/guidelines/project-development.md при чистке CLAUDE.md
(skill context-economy, тест факт-vs-процедура) — проектный файл, swissknifeman им не управляет
и не перезаписывает, правь свободно. -->

# Procurement Pipeline — dev-раннбук

Короткий техрегламент для изменений в домене crawling/parsing/AI analysis. Цель: не ломать
многостадийный пайплайн и держать согласованность между слоями домена `Procurement`, jobs, Filament
и docs.

## 1) Стадии пайплайна (source of truth)

Master-описание: `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md`.

| Стадия | Суть | `DocumentStatus` переход | Типичный код |
|---|---|---|---|
| 1 Collector | сбор извещений из ЕИС | — (`Procurement`/`Crawler\Run`) | `app/Services/Procurement/Eis/`, `CrawlerRunManager.php` |
| 2 Document Indexer + Download | список вложений, скачивание | `discovered → downloaded` | `DocumentIndexer.php`, `DocumentDownloader.php` |
| 3 Prepare | нормализация архивов/xlsx/сканов под LLM | `downloaded → prepared` (или `unprocessable`: `too_large`/`zip_bomb`) | `app/Services/Procurement/Prepare/*Strategy`, `PrepareDocumentJob` |
| 4 Screening | быстрая проверка «это смета?» (первые N страниц, D6) | `prepared → screened` (или `ignored`/`unprocessable`) | `app/Services/Procurement/Screening/*`, `ScreeningJob`, `Ai\Analysis` (шаг `screening`) |
| 5 Extraction | полный разбор позиций сметы (row-range/windowed, D3/D30) | `screened → extracted` (или `unprocessable`: `provider_limit_*`) | `app/Services/Procurement/Extraction/*`, `EstimateExtractionJob`, `Estimate` + сателлиты (`Item`, `Resource`, `Section`, `Total`) — детали и dependency map стадий 4–5: skill `estimate-extraction-dev` |

Не переносить логику стадии N в стадию N−1 «для ускорения» без явного решения — это ломает фильтрацию и расход API. `DocumentStatus::Unprocessable` (D32/D38) — терминальный, не-ретраебельный исход «не можем обработать» (лимит провайдера/формат/пароль/zip-бомба), не путать с `failed` (транзиентный сбой, ретраится через `HasErrorBlock`).

## 2) Структура и нейминг

- Единый домен закупок (`prc_*`) — `app/{Models,Services,Dto,Enums,Repositories}/Procurement/`, без разделения по модулям.
- Реализация обхода ЕИС — `app/Services/Procurement/Eis/`.
- Job: `*Job` (где есть очереди) — рядом с доменным сервисом.
- Processor/manager: оркестрация стадии (например, `CrawlerRunManager`, `PipelineDispatcher`).
- DTO: `app/Dto/Procurement/`.
- Filament: ресурсы админки (см. `docs/dev/admin-panel.md`).

## 3) Dependency map (что менять вместе)

При изменении стадии, статуса или модели:

1. **Миграции / модели** — `database/migrations/` (плоско, без папки на модуль), `app/Models/Procurement/`.
2. **Enums / DTO** — `app/Enums/Procurement/`, `app/Dto/Procurement/` (статусы analysis, crawling, estimate schema).
3. **Repositories** — `app/Repositories/Procurement/` — dispatch, retry, failed handling завязаны на них.
4. **Horizon** — queue names, supervisors (если добавляется новая очередь).
5. **Filament** — ресурсы, если меняется операторский UI.
6. **Docs** — `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md`, `docs/dev/architecture.md`.
7. **Tests** — feature/unit для изменённого поведения.

Extraction-цепочка (стадия 5) — своя, более тонкая dependency map (Data-контракт ↔ JSON-схема ↔
промт-файл ↔ stitcher/validator ↔ persister): **не дублируется здесь**, см. skill
`estimate-extraction-dev` §Dependency map. Меняешь `LlmProvider`/кэш/маршрутизацию — skill
`llm-layer-dev`. Меняешь текст/приоритет промта — skill `prompt-engineering-agelto`.

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

- Локальная разработка с прокси: см. `docs/dev/crawling-pipeline.md` (отдельного doc-файла по proxy-setup сейчас нет).
- Docker `extra_hosts` для `eis-auth.local`, `eis-api.local` — не удалять без замены.
- Доверие TLS площадки ЕИС — бандл в образе (`docker/php/certs/`), не системный доверенный набор;
  единственный носитель — `SourceTlsTrust` (`docs/dev/crawling-pipeline.md`). `verify => false`
  запрещён абсолютно.
- Типизированный фильтр профиля и площадка резолвятся config-картами (`crawler.filters`,
  `crawler.source_methods`) — новая площадка получает свой `Data`-класс строкой в карте, не
  `match`/`if` в билдере.
- Запуск архивного/контрольного обхода — `docs/dev/runbooks/archive-run.md`.

## 7) PR checklist

- Какая стадия пайплайна затронута (1–5).
- Новые/изменённые jobs и очереди.
- Влияние на Gemini/browser (стоимость, timeout, retry).
- Обновлены ли workflow/dev docs.
- Прогнан test-gate.
