---
audience: dev
type: reference
status: living
---

# Feature card: Estimate Extraction (извлечение смет)

## Что делает

Второй AI-шаг: полное извлечение позиций сметы(-ет) из документа — паспорт (шапка), построчные
позиции (плоско, с `row_type`), итоги. Оркестрируется `ExtractionOrchestrator`
(`app/Services/Procurement/Extraction/ExtractionOrchestrator.php`), полная последовательность —
диаграмма Д4 в `../document-pipeline.md`.

## Модель, промт, схема

- Маршрут: рабочая лошадка `gemini-2.5-flash`, эскалация по флагу сложности/провалу валидации —
  `gemini-2.5-pro` (D4). Discovery-подшаг windowed-пути — отдельный маршрут
  `(estimate, purpose=discovery) → flash-lite-класс` (D37).
- Промты: `resources/ai/prompts/global/` (кросс-документная база) +
  `resources/estimates/ai/prompts/{extraction-global,extraction-passport,extraction-rows,
  extraction-totals,extraction-discovery}/` (D32) — по одному файлу на тип запроса контракта.
- Схема — плоские DTO с `row_type` (`line_item`/`section_header`/`subtotal`), НЕ вложенное дерево
  section→subsection→item (D10 — глубокая вложенность хрупка в structured output; иерархия
  восстанавливается детерминированно на бэкенде из `row_type`+section-полей).

## Два пути извлечения (D3, D30, D40, D42)

- **Row-range (основной, канон ТЗ 04 §4).** Весь документ загружен в Files API + context cache;
  `ChunkSource`/`RowRangeChunkSource` запрашивают паспорт/диапазоны строк/итоги построчными
  адресуемыми диапазонами. Работает, пока документ укладывается в лимиты маршрута (D29).
- **Windowed (fallback, D30).** Сверх лимита провайдера (`ExtractionPreflight`, preflight ДО
  upload) — `WindowedChunkSource` (НЕ реализует `ChunkSource`, D42 — другая форма адресации,
  постраничная, не row-index): (1) discovery-проход дешёвой моделью по окнам страниц → маркеры
  границ смет → `SegmentAssembler` детерминированно (без LLM) склеивает «смета k = страницы a..b»;
  (2) per-estimate извлечение rows-окнами с overlap внутри сегмента; (3) totals по хвосту сегмента.

Оба пути делят один и тот же контракт ответа для rows (несовершённый буфер `incomplete_items` +
stitcher — склейка на границе окна/диапазона, дедуп по составному ключу).

## Checkpoint-requeue и арифметическая валидация

Разбор документа любого размера — цепочка коротких запусков `EstimateExtractionJob`, не один
handle() (D31, детали идемпотентности — `../document-pipeline.md`). После persist —
`EstimateValidationStatus` считается арифметикой (сумма позиций раздела == subtotal; grand total ==
итог, допуск ±0.01) — дешёвый объективный сигнал качества без второго LLM-вызова
(research/E §5–6); расхождение → `suspicious`, видно оператору
(`docs/operators/how-to/review-estimate.md`, P10.4).

## Failure modes

| Симптом | Куда |
|:--|:--|
| Документ висит на `Screened`, `EstimateExtractionJob` не двигается несмотря на checkpoint | [`../runbooks/stuck-analysis.md`](../runbooks/stuck-analysis.md) |
| Терминальный `Unprocessable` (лимит провайдера/нечитаемый формат) | `UnprocessableReason` в `analysis.result`, см. `../document-pipeline.md` §Статусы |
| Gemini-ошибки/квоты/EOL модели маршрута | [`../runbooks/gemini-errors.md`](../runbooks/gemini-errors.md) |
| `MAX_TOKENS` finish reason (обрезанный JSON) | Implementation Rules P6.1: уменьшение `row_range_size` вдвое (до `min_row_range_size`), не «дописывание скобок» |

## Eval-результаты

`estimate:eval:run --task=extraction [--dataset-version=] [--limit=] [--dry-run]` — прогон по
golden-датасету (`prc_ai_dataset_examples`), метрики precision/recall/F1 + row coverage
(`EvalMetrics`). На реальных данных ни разу не прогонялся (открытый вопрос плана — датасет ждёт
эталонов владельца, `estimate:dataset:import`).

## Смежное

- Диаграмма extraction-сессии — Д4, `../document-pipeline.md`.
- Полный контракт извлечения — [estimate-extraction.md](estimate-extraction.md).
- Обучающий контур (review → correction → gold) — [`training-loop.md`](training-loop.md).
