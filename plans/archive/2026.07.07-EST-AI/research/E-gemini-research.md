# Отчёт E — Gemini API best practices (июль 2026, сверено с ai.google.dev)

## 1. Context caching
- Implicit caching по умолчанию (2.5+). Мин. префикс: 2.5 Flash/Pro — 2048 ток., 3.5 Flash / 3.1 Pro Preview — 4096. Хит виден в usage_metadata.cached_content_token_count.
- Explicit caching (cachedContents): в новом Interactions API НЕ поддерживается, в классическом generateContent — жив. Cached input ~10% от input. Storage: $1.00/1M ток./час (Flash), $4.50 (Pro). TTL default 1ч.
- Best practice: статичный префикс (system instructions, схемы, few-shot) байт-в-байт стабильный, В НАЧАЛЕ; вариативное (документ) — в конце; временнáя локальность батчей повышает хиты.
- Вывод: implicit-first (префикс ≥2048 ток., версионируемый артефакт); explicit — только для огромных префиксов при ровном потоке.

## 2. Structured output
- responseSchema — подмножество OpenAPI 3.0 + propertyOrdering.
- responseJsonSchema — настоящий JSON Schema ($defs/$ref, anyOf; oneOf трактуется как anyOf; рекурсивный $ref только в non-required; узел с $ref без других ключей). Раскатан на все актуальные модели; работает с Pydantic/Zod.
- Слишком сложная схема → 400 InvalidArgument (длинные имена, большие enum, много optional, format: date-time). Схема считается во входные токены.
- MAX_TOKENS → невалидный JSON (structured output НЕ гарантирует валидность при обрезании). Гейт: finishReason === MAX_TOKENS → брак до parse; лечение — меньше строк за вызов/continuation, не «дописывание скобок».
- Вывод: схему держать плоской/лаконичной; не просить всю смету одним массивом.

## 3. Files API + PDF
- Страница PDF = 258 токенов (фикс). Лимит 50 MB / 1000 стр. Files API: файл до 2 GB, квота 20 GB, хранение 48 ч, бесплатно.
- Gemini 3.x: нативный текст PDF извлекается и НЕ тарифицируется — платим только 258 ток./стр. визуального прохода. Сканы — OCR встроен, те же 258 ток./стр.
- Диапазон страниц передать НЕЛЬЗЯ — резать PDF на бэкенде (qpdf/pdftk/FPDI).
- Свой markdown-конвейер почти никогда не дешевле (текст тарифицируется, layout теряется). XLSX модель не понимает — конвертировать в CSV/текст/PDF на бэкенде.
- Вывод: PDF нативно через Files API, file_uri хранить (48ч покрывает скрининг→парсинг); большие сметы резать по страницам у себя.

## 4. Модели/цены ($/1M, Standard) и Batch
- gemini-2.5-flash-lite: in 0.10 / out 0.40 / cached 0.01 / batch 0.05+0.20
- gemini-3.1-flash-lite: 0.25 / 1.50 / 0.025 / 0.125+0.75
- gemini-2.5-flash: 0.30 / 2.50 / 0.03 / 0.15+1.25
- gemini-3.5-flash: 1.50 / 9.00 / 0.15 / 0.75+4.50
- gemini-2.5-pro (≤200k): 1.25 / 10.00 / 0.125 / 0.625+5.00 (>200k: 2.50/15.00)
- gemini-3.1-pro-preview (≤200k): 2.00 / 12.00 / 0.20 / 1.00+6.00
- Модели 2.0 ОТКЛЮЧЕНЫ с 01.06.2026 (v1 использовала gemini-2.0-flash!).
- Batch API: −50%, turnaround до 24 ч, job истекает 48 ч, результаты 6 недель; поддерживает Files API, structured output, caching; JSONL до 2 GB.
- Вывод: flash-lite — скрининг; 2.5-flash — парсинг смет; Pro — эскалация. Batch для не latency-критичного = ещё −50%.

## 5. Chunking таблиц
- Fixed-token chunking рушит таблицы (~30% потери); «lost in the middle» — не кормить 300 стр. целиком.
- Паттерны: (а) sliding window+overlap (дубли в overlap → dedup по составному ключу; повторные шапки; разрыв строк); (б) carry-over state (последние 30–50 строк + канон схемы; ошибка отравляет следующий чанк); (в) agentic — надёжнее и дороже.
- Сметы: извлекать строки ПЛОСКО с row_type (line_item/section_header/subtotal), иерархию собирать на бэкенде, ВАЛИДИРОВАТЬ АРИФМЕТИКОЙ (сумма позиций раздела == subtotal; гранд-тотал == итог) — расхождение → флаг ручной проверки.
- Вывод: окно по страницам + overlap 1 стр. + carry-over последних строк; dedup (код, наименование, кол-во, сумма); арифметический контроль — основной сигнал качества.

## 6. Валидация/retry/идемпотентность
- Слои: constrained decoding → finishReason-гейт → json_validate() (PHP 8.3) + json_decode(JSON_THROW_ON_ERROR) → schema/доменная валидация → repair/re-ask.
- «Repair — синтаксис, retry — семантика, escalate — неопределённость». Re-ask с текстом ошибки валидатора + прошлым выводом (Instructor-паттерн; есть Instructor PHP c Laravel). 1–3 re-ask, потом эскалация моделью выше или ручная очередь.
- 429/503 — exp backoff + jitter, 3–5 попыток.
- Идемпотентность: дедуп-ключ = document_version_id + step + версия промта/схемы; conditional запись; dead-letter.
- Хранить finish_reason в Ai\Analysis.

## Расхождения источников
1. Explicit caching «убран» — неверно: убран только из Interactions API.
2. Минимумы кэша: верить доке (2048/2048 для 2.5; 4096 для 3.x).
3. gemini-3-flash-preview — на pricing-странице не виден, перепроверить перед хардкодом.
4. «$0.20 read/$0.50 write» — цифры Vertex, не Gemini API.
