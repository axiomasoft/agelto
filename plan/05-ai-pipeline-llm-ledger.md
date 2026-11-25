# План 05: AI-пайплайн (`screening`/`extraction`/`publication`), Eval/Gold и LLM Ledger

**Волна внедрения:** W3 (высокий и средний приоритет), W5 (cleanup и низкий приоритет)  
**Затрагиваемые слои:** `app/Services/Llm/**`, `app/Repositories/Llm/**`, `app/Services/Procurement/{Extraction,Screening,Tuning,Analyzers/Estimate}/**`, `app/Repositories/Procurement/Ai/**`, `app/Actions/Procurement/Ai/ReviewAnalysisAction.php`, `config/{llm,ai}.php`.

---

## 1. Высокоприоритетные задачи (Биллинг LLM, Recovery и Целостность датасетов)

### [HIGH] H1. Расчёт фактической стоимости Gemini вместо списания 100% `upperBound`
- **Файлы:**
  - `app/Services/Llm/Providers/GeminiAssetReceiptPolicy.php:78-79, 112-113`
  - `app/Repositories/Llm/Request/RequestRepository.php:235-238`
  - `app/Dto/Llm/Requests/PricingSnapshot.php:46-55`
- **Проблема:**
  `GeminiAssetReceiptPolicy::assess()` всегда возвращает `cost: null` даже при валидной `usageMetadata`. `RequestRepository::acceptReceiptLocked()` подставляет `$assessment->cost ?? $call->upperBound`, списывая 100% наихудшего `upperBound` на каждый вызов в `llm_budget_periods.spent` и `llm_provider_calls.cost_actual`.
  **Последствие:** бюджетные периоды исчерпываются в 10–100× быстрее реального расхода, а `BillingReconciler` гарантированно проваливает сверку с Cloud Billing CSV.
- **План исправления:**
  1. Рассчитывать точную фактическую стоимость по `PricingSnapshot` (зафиксированному в `llm_budget_reservations.pricing_snapshot`) и `ProviderUsage` (с учётом `uncached_input`, `cached_input`, `output`, `thinking` и флага `thinking_included_in_output`) при приёме квитанции.
  2. Списывать фактическую сумму в `settled_amount`, `cost_actual`, `spent` и освобождать остаток `upperBound - actualCost` из `reserved`.
- **Верификация:** Feature-тест в `core-test-ai`: 200-квитанция с известным `usageMetadata` точно обновляет `settled_amount`, `cost_actual`, `spent` и `reserved`.

---

### [HIGH] H2. Классификация не-200 ответов и отказов в `GeminiAssetReceiptPolicy`
- **Файлы:**
  - `app/Services/Llm/Providers/GeminiAssetReceiptPolicy.php:46-48, 67-76`
  - `app/Services/Llm/Cache/ProviderAssetManager.php:73-79`
  - `app/Repositories/Llm/Request/FinancialReservationRepository.php:103-109`
- **Проблема:**
  1. Любой ответ с `httpStatus !== 200` (`429`, `500/502/503/504`, `400`, `404`) возвращает `ReceiptOutcome::Unknown`. `NonbillableRetry`, `NonbillableCacheMiss` и `BilledError` никогда не возвращаются.
  2. В результате `ProviderAssetManager::invalidateMissing()` (404 на asset) — мёртвый код, а транзиентные `429`/`503` навечно блокируют задачи (`ProviderResultUnknown`), удерживают резервы и триггерят `account_unknown_hold`.
  3. Строки 67–74 требуют `is_int($usage['candidatesTokenCount'])`: при safety-отказе (`promptFeedback.blockReason`) Gemini опускает `candidatesTokenCount`, и ответ классифицируется как `Unknown` вместо `Success` → `ModelRefusal`.
- **План исправления:**
  1. Разделить исходы в `GeminiAssetReceiptPolicy::assess()`: `Lookup` 404 → `NonbillableRetry`, `cachedContent` 404 → `NonbillableCacheMiss`, `429`/`503` (без биллинга по политике) → `NonbillableRetry`, 4xx → `NonbillableRetry` / `BilledError`.
  2. При наличии `promptFeedback.blockReason` или пустых `candidates` трактовать отсутствующий `candidatesTokenCount` как `0`.
- **Верификация:** Unit-тесты `GeminiAssetReceiptPolicy::assess()` на 404 lookup, 404 cache-miss, 429/503 и `promptFeedback.blockReason` без `candidatesTokenCount`.

---

### [HIGH] H3. Устранение утечки holdout в `DatasetExporter`
- **Файлы:** `app/Services/Procurement/Analyzers/Estimate/DatasetExporter.php:308-332`
- **Проблема:** При отсутствии `estimates/eval-splits.json` метод `loadHoldoutCodes()` возвращает `[]` (с предупреждением «все строки помечены `split=none`»), но `splitOf()` проверяет `in_array($code, [], true)` → `false` и помечает **все** фикстуры как `'tune'`, сливая holdout в обучающий датасет.
- **План исправления:** Возвращать `null` вместо `[]` при отсутствии файла; возвращать `'none'` из `splitOf()` при `$holdoutCodes === null`; падать fail-closed, если оператор явно запросил `--split=tune` или `--split=holdout` без `eval-splits.json`.
- **Верификация:** Feature-тест `DatasetExporter::export(..., 'tune', ...)` без `eval-splits.json` → 0 строк или исключение; при `--split=all` все строки имеют `split=none`.

---

### [HIGH] H4. Инвалидация gold при ABA-переходе `Approve → Reject`
- **Файлы:**
  - `app/Actions/Procurement/Ai/ReviewAnalysisAction.php:24-71`
  - `app/Repositories/Procurement/Ai/DatasetExampleReadRepository.php:50-65, 95-109`
  - `app/Repositories/Procurement/Ai/DatasetExampleStoreRepository.php:38-73`
- **Проблема:**
  1. `rejectWithCorrection()` не сбрасывает `verified_at`/`verified_by` у связанной записи в `prc_ai_dataset_examples`, а `exportableSft()`/`exportableDpo()` не проверяют `prc_analyses.review_status`. Отклонённый анализ продолжает экспортироваться как доверенный gold!
  2. `rejectWithCorrection()` не проверяет `$analysis->status === AnalysisStatus::Succeeded`, а повторный `Approve` после `Reject` оставляет `verified_by` старого ревьюера.
- **План исправления:** В транзакции `rejectWithCorrection()` проверять `AnalysisStatus::Succeeded` и сбрасывать `verified_at = null, verified_by = null` у `prc_ai_dataset_examples` для `analysis_id`; в `recordAnalysisSnapshot()` обновлять `verified_by`/`verified_at` при повторном одобрении.
- **Верификация:** Feature-тест `approve → rejectWithCorrection → DatasetExporter::export('sft', ...)` подтверждает исключение записи (`not_trusted`).

---

### [HIGH] H5. Согласование `abandoned_unknown` в отчётах и лимитах
- **Файлы:**
  - `app/Repositories/Llm/Request/RequestRepository.php:289-308`
  - `app/Repositories/Llm/Request/FinancialReservationRepository.php:103-118`
  - `app/Repositories/Llm/LlmCallReadRepository.php:27, 52-54`
- **Проблема:** `abandon(..., conservativelySettle: true)` списывает `upperBound` в `llm_budget_periods.spent`, но оставляет `llm_provider_calls.cost_actual = NULL` (в `LlmSpendReporter` расход = `0` — расхождение с бюджетом), и при этом `assertSendEligible()` продолжает учитывать `abandoned_unknown` в `unknown_count_threshold` и `hard_exposure_cap`, навсегда блокируя аккаунт.
- **План исправления:** Вычислять `cost_micros` через `COALESCE(c.cost_actual, CASE WHEN b.state='settled' THEN b.settled_amount END)` (или заполнять `cost_actual` при консервативном settlement); исключать уже урегулированные вызовы из `hard_exposure_cap` и добавить аудируемый переход для снятия `unknown_count_threshold`.
- **Верификация:** Feature-тест: после `abandon(..., conservativelySettle: true)` сумма в `LlmSpendReporter` совпадает с `llm_budget_periods.spent`.

---

## 2. Средний и низкий приоритет

- **[MEDIUM] M1 (Обработка фактического превышения `upperBound` в `settleLocked`):** `FinancialReservationRepository.php:125-127` бросает `usage_exceeds_reserved_bound` и откатывает БД, пока квитанция уже лежит на диске → бесконечный цикл `ResumeFromReceipt`. При фактическом превышении принимать квитанцию, фиксировать overdraft в `llm_financial_actions` и при необходимости переводить аккаунт в policy review.
- **[MEDIUM] M2 (Учёт `thinking_tokens` в отчётах):** `LlmCallReadRepository.php:24-26` опускает `usage->>'thinking'`. Добавить `thinking_tokens` в `UsageRow` и `LlmSpendRow`.
- **[MEDIUM] M3 (`ExtractionSemanticValidator` & `PublicationRepository`):**
  - В `ExtractionSemanticValidator.php:63-75` добавить конфигурируемый допуск для коэффициентов/копеечного округления (или перевести в warning вместо жёсткого `SemanticReviewRequired`) и сверку суммы позиций с деревом итогов.
  - В `PublicationRepository.php:86` не записывать число страниц (`PageRange`) в колонку `prc_estimates.rows_expected` (использовать `null` или отдельное `pages_expected`).
- **[MEDIUM] M4 (`PromptCanonSynchronizer` & `AnalysisRecipeFactory`):** обернуть `PromptCanonSynchronizer::sync()` (`24-33`) в `DB::transaction()`, запретить in-place мутацию контента защищённых версий в `PromptStoreRepository`, и связать (или явно задокументировать границу) `AnalysisRecipeFactory` с `llm_prompts`.
- **[MEDIUM] M5 (`EvalRunComparator` & `EvalRowMatcher`):**
  - В `EvalRunComparator::profileFor()` (`242-258`) фильтровать вызовы по eval run (иначе параллельный трафик искажает метрики токенов/стоимости); проверять совпадение модели/провайдера/фикстур/версий промптов для `noise-aware` пары (`94-101`).
  - В `EvalRowMatcher` (`143, 352-361`) не перезаписывать молча повторяющиеся `row_index` и одинаковые `(label, kind)` итоговые строки («Итого», «НДС» по разделам).
- **[MEDIUM] M6 (`estimate:dataset:import`):** добавить флаг `--verified` (или предупреждение) в `DatasetImportCommand` / `DatasetImporter`, чтобы явно импортированные примеры не отбрасывались целиком как `not_trusted` при экспорте.
- **[LOW] L1 (`DiscoveryStep`):** передавать `$fields['range']` в `AnalysisRepresentation::anchored()` (`AnalysisGeneration.php:127`) для `TaskPurpose::Discovery`, чтобы не слать весь документ (до 8 MB / 4096 страниц) на каждый split.
- **[LOW] L2 (`llm_file_assets` & `llm_context_caches`):** реализовать `ai:caches prune` (`CachesCommand.php:16-20`) и категорию reconciler для перевода истёкших ассетов/кэшей и освобождения зависших `creating`-лизов.
- **[LOW] L3 (`prism-php/prism`):** удалить неиспользуемую зависимость `prism-php/prism` из `composer.json` и мёртвые адаптеры `UnsupportedProvider`/`OpenAiCompatibleProvider`.
- **[LOW] L4 (Мелкая гигиена ledger/validator):** проверять `! $call->claimed` в `RequestRepository::authorize()` (`58-67`); переиспользовать `$lockedAccount` в `AnalysisProviderSendGate::revalidate()` (`269`); возвращать `SplitRequired` вместо `InvalidSchema` при превышении 1 MB ответа модели в `CanonicalSchemaValidator.php:18`.
