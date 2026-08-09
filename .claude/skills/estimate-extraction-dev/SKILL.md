---
name: estimate-extraction-dev
description: "Dev-раннбук извлечения смет (стадия 5 пайплайна): PHP Data-контракты как SSOT промта/схемы, два пути (row-range vs windowed-discovery), preflight по capability-лимитам провайдера, checkpoint-requeue, unprocessable-таксономия, границы stitcher/validator/persister. Активировать при правках в Services/Procurement/Extraction, Dto/Procurement/Analyzers/Estimate, EstimateExtractionJob, config('ai.extraction.*')."
paths:
  - "app/Services/Procurement/Extraction/**"
  - "app/Dto/Procurement/Analyzers/Estimate/**"
  - "app/Jobs/Procurement/EstimateExtractionJob.php"
  - "resources/estimates/ai/prompts/extraction-*/**"
---

# Estimate Extraction — dev-раннбук

Стадия 5 пайплайна (`screened → extracted`). Источник домена: `docs/03_Dev/ai/estimate-extraction.md`.
Смежное: skill `llm-layer-dev` (транспорт/кэш/EOL), `prompt-engineering-agelto` (тексты промтов),
`procurement-pipeline-dev` (весь пайплайн, стадии 1–5).

## 1) Контракты — единственный источник истины (D2)

`app/Dto/Procurement/Analyzers/Estimate/*.php` (`EstimatePassport`, `EstimateRow`, `EstimateTotalsBlock`,
`DiscoveryResponse`+`DiscoveryMarkerLine`, `*Response`-обёртки) — PHP Data-класс одновременно
определяет: (1) JSON Schema для structured output (`Llm\Schema\JsonSchemaGenerator` →
`GeminiResponseSchemaAdapter`), (2) текст-в-промте (`Llm\Schema\PromptContextGenerator` — очищенный
исходник класса, докблоки/`#[Alias]`-словари модель читает как инструкцию). Оба генерируются
РЕФЛЕКСИЕЙ из класса при каждом `PromptComposer::compose()` — **никаких сгенерированных файлов
коммитить не нужно**, рассинхронизация PHP↔JSON исключена по построению.

**Чек-лист «поменял DTO»:**

1. Правь Data-класс (поле/докблок/`#[Alias]`) — здесь и только здесь.
2. Найди КАЖДОГО вызывающего `PromptComposer::compose($step, $schemaClasses)` для затронутого шага
   (`RowRangeChunkSource`/`WindowedChunkSource`/`ScreeningProcessor`) — `$schemaClasses` передаётся
   явным списком на call-сайте, не выводится автоматически; забытый класс в списке — новое поле
   молча не попадёт в промт/схему конкретного вызова.
3. `rules_hash` (`llm_prompts`-независимый sha1 системной части, `PromptComposer::compose()`)
   пересчитывается САМ на следующий вызов — ничего вручную не генерировать. Если используется
   explicit context cache (`Analysis.cache_id`) — старый кэш с прежним `rules_hash` инвалидируется
   логически, `LlmCacheManager` пересоздаст по несовпадению хэша.
4. Прогони `php artisan estimate:eval:run` на gold-датасете (`prc_ai_dataset_examples`, task=extraction) —
   регресс контракта ловится сравнением `analysis.result.estimates[]` с эталоном (`EvalMetrics`).
5. Смысл контракта изменился (не только формулировка) — синхронно правь
   `docs/03_Dev/ai/estimate-extraction.md`.

## 2) Два пути извлечения (D3/D29/D30/D40/D42)

`ExtractionPreflight` выбирает путь ДО первого upload по capability-матрице активного маршрута
(`config('ai.capabilities.<model>')`, size+pages) — `ExtractionRoute::Whole`/`Windowed`, не решение LLM.

| | `Whole` (основной, D3) | `Windowed` (fallback, D30) |
|---|---|---|
| Источник чанков | `RowRangeChunkSource` — реализует `ChunkSource` (row-index-адресация: паспорт/строки/итоги) | `WindowedChunkSource` — **свой** контракт, `ChunkSource` НЕ реализует (D42, страничная адресация: discovery-окно/segment-паспорт/segment-строки/segment-итоги) |
| Документ в контексте | целиком, в Files API (`ExtractionSession::fileHandle`) | НЕТ — каждый вызов грузит свой временный PDF-срез (`PdfPageSlicer::sliceRange()`), reupload на каждый вызов |
| Как находим границы смет | паспорт одним LLM-вызовом над целым документом | 3-стадийный: (1) discovery по окнам (sliding window, overlap 1 стр., `extraction:discovery`, `DiscoveryMarker`) → бэкенд ДЕТЕРМИНИРОВАННО склеивает сегменты `{estimate_ordinal, page_start, page_end}`; (2) паспорт/строки per-сегмент; (3) итоги по хвостовым страницам сегмента |
| Оркестратор | `ExtractionOrchestrator::runWholeDocument()` | `ExtractionOrchestrator::runWindowed()` (D43) — свой top-level метод, своя progress-схема; переиспользует `checkpointDue()`/`checkpoint()`, не завязан на `estimates`-аккумулятор whole-пути |
| Гейт | всегда включён | `config('ai.extraction.windowed_enabled')` |

Оба пути сходятся в одном формате — `analysis.result.estimates[].{rows, incomplete_items}` — который
дальше ест общий `EstimateStitcher`/`EstimateValidator`/`EstimatePersister` (§4).

## 3) Preflight и лимиты провайдера (D29)

`ExtractionPreflight` сравнивает размер файла (Storage) + число страниц (`qpdf --show-npages`,
только PDF) с `config('ai.capabilities.<model>').max_request_file_mb`/`max_pages` ДО upload.
Рантайм-ошибка провайдера о превышении лимита в processing — тот же исход, что preflight-отказ:
**не** обычный retry, а маршрут в unprocessable (§5). Меняешь модель маршрута — проверь, что в
`config('ai.capabilities.*')` у неё заведены `max_request_file_mb`/`max_pages`/`supports_files_api`,
иначе preflight не сможет решить.

## 4) Checkpoint-requeue (D31/D39)

Разбор документа любого размера = цепочка коротких запусков `EstimateExtractionJob`. Единица
прогресса — один вызов `ChunkSource`/`WindowedChunkSource`. После `config('ai.extraction.
checkpoint_ranges')` единиц ИЛИ `checkpoint_seconds` секунд — прогресс персистится отдельной
транзакцией, job ре-диспатчит себя `afterCommit()`, текущий запуск завершается успешно (`return`,
не exception).

**Инвариант, который легко сломать:** `EstimateExtractionJob` обязан оставаться на
`ShouldBeUniqueUntilProcessing` (lock снимается В НАЧАЛЕ обработки), НЕ `ShouldBeUnique` — под
обычным `ShouldBeUnique` self-dispatch продолжения тихо дедуплицируется, пока текущий запуск ещё
держит lock, и документ навсегда «застревает» в extraction. Короткие single-shot jobs
(`PrepareDocumentJob`, `ScreeningJob`) остаются на обычном `ShouldBeUnique` — не путать их модель
уникальности между собой. Защита от двойного диспатча ВО ВРЕМЯ обработки — lease + status-guard
(`HasLease`), не уникальность job'а.

## 5) Unprocessable — терминальный, не сбой (D32/D38)

`DocumentStatus::Unprocessable` + `UnprocessableReason` — константация «не можем обработать»
(`too_large`/`zip_bomb`/`unreadable_format`/`password_protected`/`provider_limit_size`/
`provider_limit_pages`), НЕ `failed`. Не ретраится, attempts не жжём. Extraction-стадия добавляет
`provider_limit_*` (preflight §3 или рантайм-отказ провайдера) — не пиши новый reason без
обновления `docs/03_Dev/ai/estimate-extraction.md` §4в/§4г и `PipelineStuckCheck`/алертов P7.3.

## 6) Stitcher / Validator / Persister — границы ответственности

- `EstimateStitcher` — детерминированный (без LLM) merge построчных чанков ОДНОЙ сметы (дедуп,
  сортировка по `row_index`). Чистая функция над DTO — никакого состояния/БД. Обслуживает ОБА пути.
- `EstimateValidator` — детерминированная арифметика/структура (bcmath, **не float** — конверсия
  во float запрещена во всём контракте P2.2). НЕ выбрасывает строки при аномалиях — только
  классифицирует и перечисляет вовлечённые `row_index` (кандидаты `RerunRangeAction`).
- `EstimatePersister` — нормализация `analysis.result.estimates[]` → `prc_estimate_*`, ОДНА
  транзакция на смету. Не переключает `DocumentStatus` (это делает `ExtractionOrchestrator`) и
  сознательно пропускает документы без `procurement_id` (эталоны P7.4/`estimate:dataset:import`).
  Материализует ТОЛЬКО `row_type = item` (КС-3/печатные агрегаты/итог раздела — решение владельца,
  не хранятся); пишет `unit_base`/`unit_multiplier` детерминированным нормализатором `unit`
  (позиция и ресурс) и резолвит `work_type_id` через `findOrCreate(estimate_id, title)` — нормативы
  НР/СП в резолве не заполняются (`docs/03_Dev/database.md`).

Если меняешь формат `analysis.result.estimates[]` — обнови ВСЕ три класса синхронно, они читают
один и тот же промежуточный формат позиционно, не через общий DTO-контракт между собой.

## 6a) Деградированный источник — пометка неопределённости позиции (план `2026.07.31-SYS-INTEGRITY` P8)

Плохой скан/нечитаемый фрагмент документа не превращается в отдельную ветку пайплайна — он виден
на уровне ОДНОЙ строки, через `EstimateRow::$quality` (`RowQuality{reason, fields}`,
`docs/03_Dev/ai/estimate-extraction.md` §3.2). Модель вправе вернуть только
`RowQualityReason::Unreadable` — построчные аномалии (`ArithmeticMismatch`/`NumberingGap`)
производит ТОЛЬКО `EstimateValidator`, `EstimateRow::validateAndCreate()` отклоняет любую другую
причину модели тем же приёмом, что и неизвестный ключ `attributes`.

- **Контрактная форма ≠ форма хранения.** `EstimateRow::$quality` (ответ модели) — плоский объект;
  `prc_estimate_items.quality` (БД, jsonb) — провенанс ключом верхнего уровня `model`/`validator`,
  каждый в форме контракта. Мёрж — приватный сборщик `EstimatePersister::rowQuality()`, второй
  писатель не заводится (канон «персистентность только через `*StoreRepository`»).
- **Пишется ВСЕГДА, включая явный `NULL`.** `RerunRangeAction` не несёт отдельной ветки для
  пометки — она следует из необязательного `?ValidationReport`-параметра общего сборщика: без
  явного `NULL` на переизвлечённой строке пометка первого прогона пережила бы факт, который её
  вызвал.
- **`AsCollection::class` на nullable jsonb — ловушка null-safety** (найдено P8.4): этот каст
  безусловно JSON-кодирует `null` в строку `"null"`, а не пишет SQL `NULL` — партиционирующий
  индекс `WHERE quality IS NOT NULL` тогда вечно пуст. `Item::$quality`/`Estimate::$validation_notes`
  используют примитивный каст `'collection'` вместо `AsCollection::class` — та же ловушка ждёт
  любую другую nullable jsonb-колонку проекта, кастуемую через `AsCollection::class`.
- **Eval мерит заполненность (`fill_rate`), не F1** (`EvalMetrics::compare()['quality']`,
  `research/12-uncertainty-model.md` §9) — extraction-gold деградированного эталона в корпусе нет,
  сравнивать пометку не с чем; F1 на всегда-пустом поле был бы структурным нулём, а не сигналом.

## 7) Калибруемые параметры (`config('ai.extraction.*')`)

`row_range_size`/`min_row_range_size` (размер row-range диапазона и его уменьшение при
`MaxTokensExceededException`), `checkpoint_ranges`/`checkpoint_seconds` (§4), `window_pages`/
`window_overlap_pages`/`totals_tail_pages` (оконный путь), `max_estimates_per_document`,
`windowed_enabled`. Пороги НЕ откалиброваны на живом трафике (Open risk плана) — трогай
осторожно, синхронно с `PipelineStuckCheck` (P7.3), если меняешь checkpoint-тайминги.

## 8) Test-gate

Реальные LLM-вызовы в тестах запрещены — `Tests\Support\Doubles\FakeLlmProvider`
(`succeeding()`/`truncated()`/`throwing()`/`andThen()` для re-ask-сценариев). См. skill
`testing-rules` §Test doubles.

```bash
docker compose exec app php artisan test --compact --filter=Extraction
docker compose exec app php artisan estimate:eval:run   # регресс на gold-датасете, если менялся контракт
```
