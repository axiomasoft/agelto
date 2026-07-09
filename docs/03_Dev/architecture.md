---
audience: dev
type: explanation
status: draft
---

# Архитектура

## Домены

Структура `app/**` плоская, без модулей: три домена по короткому префиксу таблицы.

| Домен | Префикс | Что покрывает |
|:--|:--|:--|
| `Procurement` | `prc_*` | Единый домен закупок: заказы, документы, сметы, AI-пайплайн (промты/маршруты/анализы/датасет), crawling-инфраструктура, площадки, логи |
| `Reference` | `ref_*` | Общие справочники: ОКПД2, КТРУ, ОКВЭД2, КБК |
| `Organization` | `org_*` | Организации |

Таксономия `prc_`-мегадомена — осознанное решение (D11, `plans/EST-AI/plan.md` §5), не 9-префиксная
схема из раннего ТЗ; закреплена ADR [`adr/0001-prc-megadomain.md`](adr/0001-prc-megadomain.md).

## Раскладка `app/**` по слоям

Нет `app/Process/**` (v1-канон корневого слоя не возрождается, D1) — процессная когезия v1
сохраняется на уровне подпапок сервиса, не корневого слоя:

- `Controllers/` — только HTTP: auth, validate, delegate to Action/Service/Job dispatch, response.
- `Repositories/` — read/write доступ к `prc_*`/`org_*`; `*ReadRepository`/`*StoreRepository` (см.
  skill `repositories`). Персистентность — ТОЛЬКО через них (не сквозной Eloquent из сервисов).
- `Services/Procurement/{Prepare,Screening,Extraction}/` — стадии пайплайна документа/сметы
  (`app/Services/Procurement/`: `PipelineDispatcher`, `DocumentDownloader`, `Prepare/`, `Screening/`,
  `Extraction/`, `Eis/`, `Detail/`).
- `Services/Llm/` — транспортный LLM-слой: `AiRouteResolver`, `PromptComposer`,
  `CorrectionAwareComposer`, `LlmCacheManager`, `LlmCallRecorder`, `ShadowDispatch`, `Providers/`
  (`PrismGeminiProvider`, `OpenAiCompatibleProvider`), `Contracts/` (`LlmProvider` и capability-
  интерфейсы), `Schema/` (генерация промт-контекста и `responseJsonSchema` из Data-классов), `Dto/`,
  `Validation/`. Подробнее — [`llm-providers.md`](llm-providers.md).
- `Dto/` / `Enums/` — доменные контракты и enum'ы (`App\Enums\Procurement\*`: `DocumentStatus`,
  `AnalysisStatus`, `AnalysisStep`, `ExtractionRoute`, `UnprocessableReason`, `RowType`,
  `ReviewStatus`, `CorrectionReason` и др.).
- `Jobs/Procurement/` — очереди (`PipelineJob` — общий абстракт, `PrepareDocumentJob`,
  `ScreeningJob`, `EstimateExtractionJob`).
- `Console/Commands/{Procurement,Ai}/` — artisan-команды пайплайна (`pipeline:*`, `crawler:*`,
  `ai:*`) — шпаргалка [`commands.md`](commands.md).
- `Filament/Resources/Procurement/**` — админ-панель, см. [`admin-panel.md`](admin-panel.md).

## Слои и транзакции/jobs

Полное изложение — корневой `CLAUDE.md` (Layer Responsibilities, Transaction & Jobs Policy), не
дублируется здесь. Кратко: короткие синхронные мутации — в Action/Service с явной транзакцией;
длительный crawling/parsing/AI — queued Jobs через Horizon, не в HTTP-request; Chromium — только в
worker-контейнере `horizon`; повтор job не должен дублировать Document/Analysis (idempotency-guard
по статусу + lease, см. [`document-pipeline.md`](document-pipeline.md)).

## Смежное

- [`database.md`](database.md) — схема, FK, unique-инварианты.
- [`crawling-pipeline.md`](crawling-pipeline.md) / [`document-pipeline.md`](document-pipeline.md) /
  [`llm-providers.md`](llm-providers.md) — internals по стадиям.
- Канон имён/глаголов методов — `.ai/guidelines/glossary.md`, `docs/02_Workflow/glossary.md`.
