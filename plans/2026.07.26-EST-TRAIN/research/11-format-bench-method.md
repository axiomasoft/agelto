# 11 — Методика бенчмарка форматов входа и критерий победителя (P6.1)

Зафиксировано ДО боевых данных (P6.2 их ещё не производил) — критерий и состав подвыборки ниже
после появления цифр не меняются (Code Guidance P6.1, то же правило, что D22/D35/D42).

## Ячейка и её факты

Единица замера — ЯЧЕЙКА `(эталон, вариант представления)` (D48). Harness (`ai:bench:formats` +
`FormatBenchRunner`) строит матрицу эталон × вариант и по каждой ячейке фиксирует РОВНО эти факты:

- `representation` — `kind` (значение `RepresentationKind` или `null` для оригинала версии),
  `tool` (`meta.tool` представления), `size_bytes`, `page_count`, `token_estimate`.
- `preflight` — решение `ExtractionPreflight::decide()`: `whole`/`windowed`/`unprocessable` + причина.
- `predicted` — `input_tokens`/`input_cost_micros`, детерминированно, БЕЗ LLM-вызова.
- `actual` — `prompt_tokens`/`cached_tokens`/`completion_tokens`/`total_tokens`/`cost_micros`/
  `calls`/`failed_calls`/`duration_ms` реального прогона (P6.2; в `--dry-run` — `null`).
- `quality` — `overall.f1`/`per_field`/`row_coverage` из `EvalMetrics::compare()` (только успешный
  реальный прогон; иначе `null`).
- `outcome` — `succeeded`/`failed`/`unprocessable` + `last_error`.

Вариантов на эталон — СЕМЬ, один и тот же порядок для каждого: `default` (что отдал бы резолвер
сам без принуждения), `pdf`, `pdf_ocr`, `text`, `text_grid`, `structured_xml`, `pdf_alt`.
Отсутствующий у эталона вариант — не ошибка: ячейка `applicable=false`, все факты `null`
(единообразие строк матрицы ради jq-инструментария P6.2, а не пропуск строки). `pdf_alt`
производится САМИМ бенчмарком через `LibreOfficeConverter` (D49) только если исходник табличный
(`xls`/`xlsx`) и конвертер доступен — иначе тоже `not_applicable`.

Вызовы ячейки отделяются от других ячеек ТОЙ ЖЕ фикстуры границей по `LlmCall::max('id')` ДО
прогона (не сбросом `Analysis` — соседние варианты одной фикстуры делят один
`Analysis(document_version_id, step=extraction)`, D48).

Guard: бенчмарк работает только по документам ВНЕ закупки (`procurement_id === null`) — он
сбрасывает статусы документа/анализа перед прогоном ячейки, и запуск по боевой закупке уничтожил
бы её разбор. Отказ по фикстуре не прерывает остальной прогон, причина копится в `rejected`
итогового артефакта.

## Состав подвыборки

Отбор: только эталоны с `prc_ai_fixtures.gold_source = deterministic` И записью
`prc_ai_dataset_examples(task=extraction)` (по эталону без точного gold метрику считать не с чем).
На дату написания методики (2026-07-28, сверено `docker compose exec app php artisan tinker`,
запрос по `Fixture::query()->where('gold_source', GoldSource::Deterministic)`) это РОВНО ШЕСТЬ
эталонов — весь корпус детерминированного gold на сегодня, каждый уже в СВОЁМ кластере форм, поэтому
правило «один эталон на кластер + крайние случаи» вырождается в «взять все шесть»:

| код | формат исходника | форма | кластер |
|:--|:--|:--|:--|
| EST-XLS-KS2-001 | xls | KS2 | CL-13 |
| EST-XLSX-KS2-001 | xlsx | KS2 | CL-01 |
| EST-XLSX-KS2-003 | xlsx | KS2 | CL-11 |
| EST-XLSX-LSR-001 | xlsx | LSR | CL-05 |
| EST-XLSX-VOR-001 | xlsx | VOR | CL-12 |
| EST-GSFX-LSR-001 | gsfx | LSR | CL-15 |

Пропущен (не входит в матрицу): `EST-XLSX-BROKEN-001` — `gold_source` не `deterministic`
(`research/10-gold-corpus-report.md`: вердикт REJECTED).

Подвыборка P6.2 (боевой прогон) — ТА ЖЕ шестёрка, без добавлений «раз уж запустили» (Code
Guidance P6.2).

## Предсказанный объём и цена

Маршрут на дату замера: `estimate` / `extract_rows` → `gemini-2.5-flash` (`prc_ai_routes`,
priority 0). Предсказание: PDF-подобный вариант (`pdf`, `pdf_alt`, `pdf_ocr`, оригинал-PDF) —
`page_count × 258`; текстовый (`text`, `text_grid`, `structured_xml`) — `token_estimate`
представления; цена — `predicted_input_tokens × capabilities['gemini-2.5-flash']['input_per_1m']`
(микро-доллары, единица `cost_micros`). Системная часть промта в предсказание НЕ входит.

Артефакт: `docker compose exec app php artisan ai:bench:formats --dry-run` →
`artifacts/bench-formats-dry-2026-07-28.json` (42 ячейки: 6 эталонов × 7 вариантов, 22
`applicable=true`, `LlmCall`-счётчик до/после — 0/0, см. Completion Notes P6.1).

| эталон | вариант | страниц | token_estimate | предсказано токенов | предсказано `cost_micros` |
|:--|:--|--:|--:|--:|--:|
| EST-GSFX-LSR-001 | default (оригинал gsfx) | — | 1 560 048 | 1 560 048 | 468 015 |
| EST-GSFX-LSR-001 | structured_xml | — | 92 559 | 92 559 | 27 768 |
| EST-GSFX-LSR-001 | text | — | 6 689 | 6 689 | 2 007 |
| EST-XLS-KS2-001 | default / pdf / pdf_alt | 2 | — | 516 | 155 (×3 варианта) |
| EST-XLSX-KS2-001 | default / pdf | 8 | — | 2 064 | 620 |
| EST-XLSX-KS2-001 | pdf_alt | 6 | — | 1 548 | 465 |
| EST-XLSX-KS2-001 | text_grid | — | 21 689 | 21 689 | 6 507 |
| EST-XLSX-KS2-003 | default / pdf / pdf_alt | 2 | — | 516 | 155 (×3 варианта) |
| EST-XLSX-KS2-003 | text_grid | — | 9 962 | 9 962 | 2 989 |
| EST-XLSX-LSR-001 | default / pdf / pdf_alt | 8 | — | 2 064 | 620 (×3 варианта) |
| EST-XLSX-LSR-001 | text_grid | — | 68 880 | 68 880 | 20 664 |
| EST-XLSX-VOR-001 | default / pdf | 4 | — | 1 032 | 310 |
| EST-XLSX-VOR-001 | pdf_alt | 3 | — | 774 | 233 |
| EST-XLSX-VOR-001 | text_grid | — | 2 732 | 2 732 | 820 |

**ИТОГО предсказано (сумма `input_cost_micros` по всем 22 `applicable`-ячейкам подвыборки):
534 118 микро-$ ≈ $0.53** (`jq '[.cells[] | select(.applicable==true) | .predicted.input_cost_micros] | add'`).

**Это НЕ полная цена боевого прогона.** Предсказание — цена ОДНОЙ загрузки документа (первый
input одного вызова); реальный прогон делает НЕСКОЛЬКО вызовов на документ (паспорт → диапазоны
строк → итоги) и несёт completion-токены — обе величины принципиально не предсказуемы без
реального прогона (RAG:✅ `findings/rag-P6-tokens-2026-07-27.md`). Число выше — нижняя граница
input-объёма для апрува владельца в P6.2, не итоговый счёт.

**Наблюдение (не вход в критерий, а сырьё для интуиции):** `default`-вариант `EST-GSFX-LSR-001`
падает на ОРИГИНАЛ gsfx-контейнера (у формата нет `pdf`-представления, ArchiveStrategy/GsfxStrategy
его не производят) — эвристика `strlen/4` применена к 6,24 МБ бинарного контейнера, что явно
переоценивает объём (контейнер не текст). Эта ячейка структурно `outcome=unprocessable` в реальном
прогоне (`resolveEffectiveFile()` пропускает только PDF/изображение, P6.1 Implementation Rules) —
предсказанная цена для нее декоративна и не участвует в решении победителя.

## Критерий победителя

Дословно (зафиксирован ДО данных, после появления цифр НЕ меняется — сдвиг стоит дороже всего,
то же правило, что D22/D35/D42):

> Побеждает вариант с наименьшей `cost_micros` среди тех, чей `overall.f1` не ниже (лучший f1 по
> эталону − 0.02) и `row_coverage` не ниже (лучший row_coverage по эталону − 0.02); при равенстве
> цены — меньшее число вызовов. Вариант, давший `outcome != succeeded` хотя бы на одном эталоне
> подвыборки, победителем быть не может.

Критерий применяется МЕХАНИЧЕСКИ в P6.2: если победил вариант, противоречащий гипотезе владельца
«PDF проще всего» (`brief/00-brief.md` §«выбор оптимального формата») — так и записывается.

## Когда замер недействителен

- Сменился набор промтов (`prompt_version_set` — тот же во ВСЕХ ячейках прогона, D54); прогон с
  разъехавшимся набором недействителен целиком.
- Сменилась модель активного маршрута (`estimate`/`extract_rows`) — тарификация PDF-страницы
  (258 токенов, RAG:✅ `findings/rag-P6-tokens-2026-07-27.md`) специфична линейке 2.5 и на 3.x
  МЕНЯЕТСЯ по знаку, не только по величине (OQ-4).
- Перегенерированы представления другой версией инструментов (`meta.tool_version` разошёлся между
  предсказанием и боевым прогоном).
- Появился формат, которого не было в подвыборке — на него правило D3 не распространяется молча
  (P6.3 явно называет `default` для неизмеренных форматов).
