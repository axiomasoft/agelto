---
audience: dev
type: explanation
status: draft
---

# Document & Estimate Pipeline: internals

Здесь будет: мост → подготовка → скрининг → извлечение; jobs/очереди/lease/retry; инварианты
идемпотентности; статусы (включая `unprocessable`, D32); failure modes со ссылками на runbooks.

## Д1 — FSM документа

```mermaid
stateDiagram-v2
    [*] --> Discovered
    Discovered --> Downloaded: DocumentDownloader (краулинг)
    Downloaded --> Prepared: PrepareDocumentJob.process()
    Downloaded --> Unprocessable: DocumentPreparer-стратегия (D32/D38, напр. RAR/7z)
    Downloaded --> Failed: PrepareDocumentJob.recordFailure() (attempts исчерпаны)
    Prepared --> Screened: ScreeningJob → ScreeningOutcomeHandler.markScreened()
    Prepared --> Ignored: ScreeningOutcomeHandler.ignore() (unknown/low_confidence/ignore_rule)
    Prepared --> Failed: ScreeningJob.recordFailure()
    Screened --> Extracted: EstimateExtractionJob → ExtractionOrchestrator.finalizeSuccess()
    Screened --> Unprocessable: ExtractionOrchestrator.markUnprocessable() (D29 лимит провайдера, D38 unreadable/password)
    Screened --> Failed: EstimateExtractionJob.recordFailure()
    Extracted --> [*]
    Ignored --> [*]
    Failed --> [*]
    Unprocessable --> [*]
```

Узлы = `App\Enums\Procurement\DocumentStatus` (`app/Enums/Procurement/DocumentStatus.php`);
переходы подписаны job/методом-триггером:

| Класс | Файл |
|:--|:--|
| `DocumentStatus` | `app/Enums/Procurement/DocumentStatus.php` |
| `PrepareDocumentJob` | `app/Jobs/Procurement/PrepareDocumentJob.php` |
| `DocumentPreparer` | `app/Services/Procurement/Prepare/DocumentPreparer.php:26` |
| `ScreeningJob` | `app/Jobs/Procurement/ScreeningJob.php` |
| `ScreeningOutcomeHandler` | `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:42` |
| `EstimateExtractionJob` | `app/Jobs/Procurement/EstimateExtractionJob.php` |
| `ExtractionOrchestrator` | `app/Services/Procurement/Extraction/ExtractionOrchestrator.php:81` |

Упрощённая версия без job'ов/ошибок (бизнес-язык) — `docs/02_Workflow/document-and-estimate-pipeline.md` (Д1-lite).

## Д2 — стадии, очереди, lease/retry

```mermaid
flowchart LR
    Bridge["PipelineDispatcher (app/Services/Procurement/PipelineDispatcher.php)<br/>dispatch() — сразу после FetchDetails<br/>dispatchBacklog() — everyMinute поллер"]
    Bridge -->|"PrepareDocumentJob::dispatch()->afterCommit()<br/>queue: doc_prepare"| Prepare["PrepareDocumentJob<br/>lease: Document"]
    Prepare -->|"config('ai.screening.enabled')<br/>ScreeningJob::dispatch()->afterCommit()<br/>queue: ai_screening"| Screen["ScreeningJob<br/>lease: Analysis(step=screening)"]
    Screen -->|"config('ai.extraction.enabled')<br/>EstimateExtractionJob::dispatch()->afterCommit()<br/>queue: ai_extraction"| Extract["EstimateExtractionJob<br/>lease: Analysis(step=extraction)"]
    Extract -->|"needsContinuation=true<br/>afterRelease(): self::dispatch()->afterCommit()<br/>(checkpoint-requeue, release-then-dispatch)"| Extract
    Prepare -.->|"recordFailure() → HasErrorBlock backoff<br/>next_retry_at"| Retry1["pipeline:retry-failed<br/>(app/Console/Commands/Procurement/PipelineRetryFailed.php)"]
    Screen -.->|recordFailure backoff| Retry1
    Extract -.->|recordFailure backoff| Retry1
    Retry1 -.->|переотправка при next_retry_at| Prepare
```

Все три job'а наследуют `App\Jobs\Procurement\PipelineJob` (`app/Jobs/Procurement/PipelineJob.php`) —
шаблонный метод `handle()`: status-guard → `acquireLease()` → `process()` → (при исключении)
`recordFailure()` + rethrow → `finally releaseLease()` → `afterRelease()`. Уникальность диспатча:
`PrepareDocumentJob`/`ScreeningJob` — `ShouldBeUnique` (короткий single-shot); `EstimateExtractionJob`
— `ShouldBeUniqueUntilProcessing` (checkpoint-requeue, D31/D39) — lock снимается ДО обработки,
чтобы self-dispatch продолжения не дедуплицировался.

## Д4 — extraction-сессия

```mermaid
sequenceDiagram
    participant Job as EstimateExtractionJob
    participant Orch as ExtractionOrchestrator
    participant Pre as ExtractionPreflight
    participant Chunk as ChunkSource
    participant WChunk as WindowedChunkSource
    participant Seg as SegmentAssembler
    participant Persist as EstimatePersister

    Job->>Orch: run(document, analysis)
    Orch->>Pre: decide(localPath, extension, route)
    alt unprocessable (лимит провайдера, D29)
        Pre-->>Orch: PreflightDecision::isUnprocessable()
        Orch->>Orch: markUnprocessable() → Document::Unprocessable
    else route = WholeDocument (D3, канон)
        loop до passport_done && все estimates rows_done && totals_done
            Orch->>Chunk: fetchPassport()/fetchRows()/fetchTotals()
            Chunk-->>Orch: ChunkFetchResult(data, usage)
            alt checkpointDue() (D31)
                Orch->>Orch: checkpoint() — persist progress
                Orch-->>Job: return true (needsContinuation)
                Job->>Job: afterRelease(): self::dispatch()->afterCommit()
            end
        end
    else route = Windowed (D30, fallback сверх лимита)
        loop discovery-окна до total_pages
            Orch->>WChunk: fetchDiscoveryWindow(pageStart, pageEnd)
            WChunk-->>Orch: DiscoveryResponse(markers)
        end
        Orch->>Seg: assemble(markers, total_pages)
        Seg-->>Orch: segments (детерминированно, НЕ LLM-вызов)
        loop per-segment: passport → rows-окна (overlap) → totals(tail)
            Orch->>WChunk: fetchSegmentPassport()/fetchSegmentRows()/fetchSegmentTotals()
        end
    end
    Orch->>Persist: persist(analysis, supersededAnalysisId)
    Orch->>Orch: finalizeSuccess() → Analysis::Succeeded, Document::Extracted
```

Точечное переизвлечение диапазона (не всей сметы) — `RerunRangeAction`
(`app/Services/Procurement/Extraction/RerunRangeAction.php`, Scope Included P6.3): переиспользует
`ChunkSource::fetchRows()` и файловый handle из `analysis.progress`, не участвует в
checkpoint-цикле, не пересчитывает `validation_status`/иерархию целиком (Known Deviations P6.3).

Наполняется в P10.3 (развёрнутый текст: инварианты идемпотентности, failure modes → runbooks).
