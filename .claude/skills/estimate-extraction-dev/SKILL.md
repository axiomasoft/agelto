---
name: estimate-extraction-dev
description: "Dev-раннбук извлечения смет (стадия extraction Process Core): PHP Data-контракты как SSOT схемы, двухпроходный ExtractionHandler (Whole/Windowed), preflight и asset claim, checkpoint/recovery, границы stitcher/validator/persister. Активировать при правках в Services/Procurement/Extraction, Dto/Procurement/Analyzers/Estimate, Repositories/Procurement/Ai/ExtractionFinalizer."
paths:
  - "app/Services/Procurement/Extraction/**"
  - "app/Dto/Procurement/Analyzers/Estimate/**"
  - "app/Repositories/Procurement/Ai/ExtractionFinalizer.php"
  - "resources/estimates/ai/prompts/extraction-*/**"
---

# Estimate Extraction — dev-раннбук

Стадия `StageCode::Extraction` в Process Core. Источники: `.ai/guidelines/cheatsheet.md`, `docs/dev/ai/estimate-extraction.md`, `docs/dev/process-core.md`.

## 1. Контракты — единственный источник истины

`app/Dto/Procurement/Analyzers/Estimate/*.php` (`EstimatePassport`, `EstimateRow`, `EstimateTotalsBlock`, `DiscoveryResponse`, `DiscoveryMarkerLine`, `*Response`) определяют JSON Schema для structured output (`Llm\Schema\JsonSchemaGenerator` + `#[Description]`/`#[Alias]` атрибуты через `ContractAttributeReader`). Никаких сгенерированных JSON-файлов коммитить не нужно.

При изменении DTO:
1. Обнови Data-класс и его атрибуты (`#[Description]`, `#[Alias]`).
2. Проверь список `$schemaClasses` во всех вызовах `PromptComposer::compose($step, $schemaClasses)` (`ExtractionHandler`, `ScreeningHandler`).
3. `rules_hash` пересчитывается автоматически; несовпадение `recipe_hash` инвалидирует устаревший checkpoint при `resume()`.

## 2. Исполнение в Process Core (`ExtractionHandler`)

Исполняется через `ExecuteWorkJob` → `ExtractionHandler` (не отдельный legacy Job).
- **Preflight и выбор пути (`ExtractionPreflight`)**: до вызова провайдера сверяет размер и число страниц с `config('ai.capabilities.<model>')` и выбирает `ExtractionRoute::Whole` или `ExtractionRoute::Windowed`. При превышении лимитов возвращает терминальный блок (`document_quality` / `provider_limit_*`), не жжёт retry budget.
- **Asset lifecycle (`AssetRepository`)**: загруженные файлы и explicit context cache управляются через `llm_provider_assets` (`claim` → `activate` / `releaseAfterNonBillableFailure`).
- **Авторизация и списание (`AnalysisProviderSendGate`)**: каждый шаг проходит `FinancialLedgerRepository::reserve()` → `ProviderCallLedgerRepository::registerPlanned()` → `claimForSend()` → `OutboundExecutor` → `recordOutcome()` → `settle()`/`release()`.
- **Checkpoints и fencing**: прогресс по чанкам/окнам сохраняется в `prc_process_work.checkpoint` через `WorkExecutionRepository::checkpoint()` под проверкой `(id, work_epoch, lease_token)`. При истечении slice бюджетного окна возвращается `SliceOutcome::yielded(...)`.

## 3. Stitcher / Validator / Persister / Finalizer

- `EstimateStitcher` — детерминированная склейка построчных чанков одной сметы (`row_index`). Чистая функция над DTO.
- `EstimateValidator` — детерминированная проверка структуры и арифметики (`bcmath`, **float запрещён**). Не выбрасывает строки при аномалиях, а помечает вовлечённые `row_index`.
- `EstimatePersister` — материализует `row_type = item` в `prc_estimates`/`prc_estimate_items`/`prc_estimate_item_resources`, нормализует единицы измерения (`UnitNormalizer`) и резолвит `work_type_id`.
- `ExtractionFinalizer` — атомарно публикует `prc_document_analyses` (`is_current = true`), обновляет `prc_documents.current_extraction_analysis_id` и переводит активный `extraction` intent в `terminal`.

## 4. Пометка качества строк и касты JSONB

- Модель может вернуть только `RowQualityReason::Unreadable`; арифметические и структурные аномалии формирует `EstimateValidator`.
- В моделях Eloquent для nullable JSONB колонок (`quality`, `validation_notes`) используй примитивный каст `'collection'`, а не `AsCollection::class` (который пишет JSON-строку `"null"` вместо SQL `NULL`).

## 5. Проверка

```bash
make core-test-ai
make core-test-file TEST_FILE=tests/Feature/ProcessCore/Ai/ExtractionPipelineTest.php
```
