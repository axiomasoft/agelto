---
name: estimate-extraction-dev
description: "Разработка extraction/publication Process Core: pinned task/schema/prompt snapshots, bounded subranges, provider receipt recovery, canonical chunks, semantic validation и fenced publication. Использовать при изменениях Services/Procurement/Extraction, Dto/Procurement/Ai и Repositories/Procurement/Ai."
paths:
  - "app/Services/Procurement/Extraction/**"
  - "app/Dto/Procurement/Ai/**"
  - "app/Dto/Procurement/Analyzers/Estimate/**"
  - "app/Repositories/Procurement/Ai/**"
  - "resources/ai/prompts/contracts/**"
---

# Извлечение и публикация смет

Канон: `.ai/guidelines/cheatsheet.md`, `docs/dev/ai/estimate-extraction.md`,
`docs/dev/llm-providers.md` и текущий код. Этот skill — навигация по владельцам, не отдельная политика.
`extraction` принадлежит `prc_analysis_windows`, `publication` — `prc_analysis_runs`;
обе стадии исполняет `ExecuteWorkJob`, предметные изменения принимает `WorkFinalizer`.

## Контракты и планирование

- `app/Services/Procurement/Extraction/AnalysisRecipeFactory.php` фиксирует immutable recipe:
  task manifest, schema/prompt snapshots, provider/account/API/model, options и coverage policy.
  Resume использует сохранённый manifest; новая semantic policy требует нового run.
- Результаты задают PHP Data в `app/Dto/Procurement/Ai/`: `PageDiscoveryOutput`,
  `PageExtractionOutput` / `ExtractionOutput`, `AnchoredEstimateRow`, `FlatTotalNode`.
  Вложенный `EstimateRow` находится в `app/Dto/Procurement/Analyzers/Estimate/`.
  `StructuredTaskValidator` проверяет canonical schema до hydration, затем semantic validator.
- `DiscoveryStep` и `WindowPlanner` объявляют покрытие до extraction. Исходные страницы/anchors
  берутся из preparation/source manifest, не из уже принятых model rows.
  `WindowExtractionTask` связывает pinned prompt/schema с текущим диапазоном.

## Исполнение, деньги и recovery

Основные extraction owners находятся в `app/Services/Procurement/Extraction/`;
контракт LLM ledger описан в `docs/dev/llm-providers.md`.

- `ExtractionHandler` обрабатывает один pending subrange за delivery. Прогресс —
  `ExtractionProgress` / `ExtractionCheckpoint` внутри `WorkCheckpointEnvelope`.
  `StagePartial` передаёт прогресс общему finalizer; самостоятельных AI lease/retry writers нет.
- `AnalysisGeneration` получает generation receipt. `AnalysisCallCoordinator` под Process prefix
  locks проверяет generation/owner token/attempt membership, source/input/recipe и semantic ordinal;
  через `RequestLedger` авторизует деньги и сохраняет exact operation mapping/recovery linkage.
  Авторизация сама не разрешает HTTP: последний joint claim принадлежит `AnalysisProviderSendGate`.
  `StructuredCallExecutor` и `ReceiptStore` отвечают за исполнение и durable raw receipt.
  HTTP и object I/O выполняются вне DB transaction; lock order — в cheat-sheet.
- Ledger использует `llm_provider_requests`, `llm_provider_calls`, `llm_budget_reservations`;
  assets — `llm_file_assets` / `llm_context_caches`. `AnalysisAssetRecovery` восстанавливает
  cache/file/lookup metadata отдельно от generation JSON.
- После claim без receipt исход остаётся unknown с сохранённым reserve; automatic resend нет.
  Pinned receipt восстанавливается без нового send/reserve. `ChunkCommitter` в transaction
  `WorkFinalizer` принимает receipt/chunk, проверяет coverage и consumes mapping/directive атомарно.

## Валидация, split и публикация

- `DiscoverySemanticValidator` проверяет exact page coverage и estimate identity.
  `ExtractionSemanticValidator` проверяет estimate/range/anchors, duplicates, decimal arithmetic,
  units и flat totals (`node_id`, `parent_id`, unique IDs, parents, cycles).
  Деньги/количества — decimal-строки; unknown остаётся `null`.
- Invalid schema/semantics допускают один semantic reask на subrange, затем blocked.
  `MAX_TOKENS` требует bounded split без приёма prefix rows; `[start,end)` делится left-before-right
  в пределах pinned min span/max depth/max splits. Exhaustion — `extraction_incomplete`.
  Refusal, incomplete и unsupported outcomes имеют отдельные blocked reasons.
- `ChunkStitcher` объединяет canonical chunks по source anchor в пределах estimate plan,
  разрешает только одинаковый overlap, проверяет conflicts/gaps и повторяет semantic validation.
  Immutable chunks живут в `prc_analysis_chunks`; завершение всех windows создаёт publication input.
- `PublicationHandler` получает результат от `EstimatePublisher`, который проверяет исходный
  preparation coverage manifest и точное покрытие каждого plan. Под locks общего finalizer
  `app/Repositories/Procurement/Ai/PublicationRepository.php` повторно проверяет digest и пишет
  `prc_estimates`, `prc_estimate_items`, `prc_estimate_sections`, `prc_estimate_resources`,
  `prc_estimate_totals`, provenance, result projection и `prc_document_versions.published_analysis_run_id`.
  Journal/outbox/pointer/slot release входят в общий commit; stale heads/generation/recipe и cancel
  запрещают business publication. Publication не устанавливает operator verification.

## Проверка

Только изолированный стенд из `docs/dev/runbooks/process-test-rig.md`:

```bash
make core-test-ai
make core-test-file TEST_FILE=tests/Feature/ProcessCore/Llm/CanonicalContractTest.php
make core-test-file TEST_FILE=tests/Feature/ProcessCore/Llm/WindowStitchTest.php
make core-test-file TEST_FILE=tests/Feature/ProcessCore/Llm/PageExtractionPublicationTest.php
```

Recovery/fences: `tests/Feature/ProcessCore/Llm/FinancialRecoveryTest.php`,
`ExtractionFinalizationTest.php`, `PublicationFinalizationTest.php`, `ExtractionWorkerKillTest.php`
в том же каталоге; joint send —
`tests/Integration/ProcessCore/Concurrency/ProviderJointSendTest.php`.
При изменениях tracked contracts/inputs синхронизируй owning fixture hashes по `testing-rules`.
Fake provider проверяет orchestration, не semantic accuracy; paid/live eval и production capacity
не следуют из зелёного suite (`docs/dev/runbooks/process-quality-limits.md`).
