# P2 — Первичное подтверждение вендора: structured output Gemini (гейт D9)

**Дата проверки:** 2026-08-01 · **Item:** P2.1 · **Слой:** 1 (raw — цитата/выжимка + URL + вердикт,
без интерпретации; синтез и проектные решения — `research/03-prompt-contract.md`).

**Предмет.** D8 (порядок работ фазы P2) стоит на RAG-выжимке `findings/rag-2026-07-31.md` §3 —
вторичном источнике (blog.google + Medium). D9 требует подтвердить её ПЕРВИЧНОЙ вендорской
документацией ДО исполнения фазы. Все вердикты формулируются для канала, который проект
ФАКТИЧЕСКИ шлёт — `generationConfig.response_schema` метода `generateContent` (D21), — а не для
`responseJsonSchema`.

## 0. Метод и честная граница доказательств

- **Инструменты:** `context7` (`/websites/ai_google_dev_gemini-api`, индекс официальной
  документации) + прямое чтение страниц `ai.google.dev` через WebFetch. Ladder skill
  `verify-claims`: context7 → прямое чтение → WebSearch (только для локализации страницы).
- **Ограничение, названное явно:** WebFetch отдаёт страницу через модель-суммаризатор, поэтому
  «дословность» цитат ниже — дословность в пределах этого канала. Каждая несущая цитата
  подтверждена ДВУМЯ независимыми обращениями (context7-чанк + WebFetch страницы) либо помечена
  `подтверждение одиночное`. Реконструкция полного HTML страницы не выполнялась.
- **`docs.cloud.google.com` (Vertex/Gemini Enterprise) отдаёт только навигацию** без тела статьи
  при машинном чтении — цитаты оттуда получены через сниппет поисковой выдачи и помечены
  `retrieval: search-snippet` с пониженной уверенностью. Это ДРУГАЯ поверхность продукта
  (Vertex AI), перенос на наш канал — только с явной оговоркой.
- **Классы источника:** `вендор-первичный` (`ai.google.dev` — документация API) ·
  `вендор-официальный-вторичный` (`blog.google` — анонс продукта, не спецификация) ·
  `вендор-другая-поверхность` (`docs.cloud.google.com` — Vertex AI / Gemini Enterprise) ·
  `вторичный` (Medium, форумы — только как контекст).

## 1. Дублирование схемы в промте — что вендор говорит НА САМОМ ДЕЛЕ

**Вопрос:** подтверждает ли первичная документация тезис «схему нельзя дублировать в text prompt,
иначе модель *not constrained to follow the schema*»?

**Найдено — прямого запрета НЕТ.** Ни одна страница `ai.google.dev` по structured output не несёт
ни запрета, ни формулировки «not constrained to follow the schema». Три обращения
(`/gemini-api/docs/generate-content/structured-output`,
`/gemini-api/docs/interactions/structured-output`, context7-запрос по теме) дали отрицательный
результат по этому конкретному утверждению.

**Что вендор говорит вместо этого:**

> «**Prompt engineering:** Clearly state in your prompt what you want the model to do. For example,
> "Extract the following information from the text..." or "Classify this feedback according to the
> provided schema...".»
> — `ai.google.dev/gemini-api/docs/generate-content/structured-output`, раздел *Best practices*,
> проверено 2026-08-01 (`вендор-первичный`, подтверждение двойное).

> «Repetitive text in structured output may occur if the model's output field order differs from the
> defined schema. To mitigate this, avoid specifying a strict order for fields in the prompt and
> ensure all output fields are marked as required.»
> — `ai.google.dev/gemini-api/docs/troubleshooting`, проверено 2026-08-01
> (`вендор-первичный`, подтверждение двойное — тот же чанк выдан context7 в двух разных запросах).

> «If there are any descriptions (for example, in a bulleted list), schemas, or examples (for example,
> for few-shot learning or RAG) in the prompt, they must present the same property ordering as is
> specified in the responseSchema. A mismatch in ordering can confuse the model and lead to incorrect
> or malformed output.»
> — `docs.cloud.google.com/.../control-generated-output` (Vertex AI / Gemini Enterprise),
> проверено 2026-08-01 (`вендор-другая-поверхность`, `retrieval: search-snippet`).

**Вердикт:** тезис RAG-выжимки первичным источником **НЕ подтверждён**. Вендор нигде не запрещает
держать описания/схему в промте; он (а) прямо предполагает, что промт несёт инструкцию задачи,
(б) на соседней поверхности продукта ЯВНО допускает «descriptions … schemas, or examples in the
prompt» и ставит им ОДНО условие — совпадение порядка свойств со схемой, (в) в разделе
troubleshooting советует не задавать в промте жёсткий порядок полей. Это не «уточнение
формулировки», а другая форма утверждения: не «убрать второй канал», а «не дать двум каналам
разойтись». Отсутствие запрета — именно отсутствие подтверждения, а не доказанное опровержение:
формулировка «не дублировать» могла существовать в редакции документации до 2026-08-01 и быть снята.

## 2. `description` в `response_schema` — поддержка

**Найдено:**

> «**Clear descriptions:** Use the `description` field in your schema to provide clear instructions to
> the model about what each property represents. This is crucial for guiding the model's output.»
> — `ai.google.dev/gemini-api/docs/generate-content/structured-output`, раздел *Best practices*,
> проверено 2026-08-01 (`вендор-первичный`, подтверждение двойное).

`description` присутствует в перечне полей объекта схемы, отданном той же страницей: `type`,
`title`, `description`, `properties`, `required`, `additionalProperties`, `enum`, `format`,
`minimum`, `maximum`, `items`, `prefixItems`, `minItems`, `maxItems`.

Тот же совет продублирован на странице Interactions API: «Clear descriptions: Use the `description`
field to guide the model.» (`ai.google.dev/gemini-api/docs/interactions/structured-output`,
2026-08-01) — то есть он не привязан к одной поверхности.

**Вердикт для НАШЕГО канала (`generateContent` + `generationConfig.response_schema`): подтверждено.**
`description` — документированное поле схемы, и вендор не просто допускает его, а называет
«crucial for guiding the model's output». Страница, с которой взята цитата, — именно страница
метода `generateContent` (в текущей навигации помечена как *Gemini Generate Content API (Legacy)*,
см. §7), то есть ровно нашего канала, а не Interactions.

## 3. `propertyOrdering` — поддержка и НУЖНОСТЬ

**Найдено на странице нашего канала:**

> «Gemini 2.0 requires an explicit `propertyOrdering` list within the JSON input to define the
> preferred structure.»
> — `ai.google.dev/gemini-api/docs/generate-content/structured-output`, таблица поддержки моделей,
> проверено 2026-08-01 (`вендор-первичный`, подтверждение одиночное). Отдельного раздела
> «Property ordering» на странице в редакции 2026-08-01 НЕТ.

**Найдено в официальном анонсе вендора:**

> «the API now preserves the same order as the ordering of keys in the schema. This is supported for
> all Gemini 2.5 models and beyond.»
> — `blog.google/innovation-and-ai/technology/developers-tools/gemini-api-structured-outputs/`,
> дата публикации **2025-11-05**, проверено 2026-08-01 (`вендор-официальный-вторичный`).

**Найдено о механике порядка (другая поверхность):**

> «When you define a schema, the model doesn't strictly follow the order of properties that you define
> in the properties field. By default, the API orders properties alphabetically and does not preserve
> the order in which the properties are defined (although the Google Gen AI SDKs may preserve this
> order).» · «To enforce a specific order for property generation, use the propertyOrdering field.
> Properties listed in propertyOrdering are generated first, in the specified order, followed by any
> other properties.» · «This field … is not a standard field in OpenAPI specification.»
> — `docs.cloud.google.com/.../control-generated-output` и
> `docs.cloud.google.com/.../rest/v1beta1/Schema`, проверено 2026-08-01
> (`вендор-другая-поверхность`, `retrieval: search-snippet`).

**Вердикт:** `propertyOrdering` — реальное, но НЕ-OpenAPI поле схемы, обязательное только для
линейки 2.0. Для моделей маршрутов проекта (все — 3.x, §7) вендор заявляет **неявное сохранение
порядка ключей схемы**, то есть эмиссия `propertyOrdering` перестаёт быть необходимой для
детерминизма порядка. Требование, которое ОСТАЁТСЯ живым, — не сама эмиссия, а СОВПАДЕНИЕ порядка
свойств между схемой и любым описанием/примером в промте (§1, третья цитата). Утверждение
RAG-выжимки «`propertyOrdering` поддерживается и важен» верно по букве и устарело по следствию:
для нашей линейки он не нужен, а нужна согласованность двух носителей. Оговорка: цитата про
неявный порядок — из анонса (`вендор-официальный-вторичный`), в документации нашего канала
подтверждения отдельным разделом не найдено.

## 4. Лимиты схемы

**Найдено:**

> «**Schema subset:** Not all features of the JSON Schema specification are supported. The model
> ignores unsupported properties. **Schema complexity:** The API may reject very large or deeply
> nested schemas. If you encounter errors, try simplifying your schema by shortening property names,
> reducing nesting, or limiting the number of constraints.»
> — `ai.google.dev/gemini-api/docs/generate-content/structured-output`, раздел *Limitations*,
> проверено 2026-08-01 (`вендор-первичный`, подтверждение двойное).

Аналогичная формулировка про отказ на больших/глубоко вложенных схемах повторена для
function-calling: «the API may reject very large or deeply nested schemas»
(`ai.google.dev/gemini-api/docs/generate-content/function-calling`, *Notes and limitations*).

**Вердикт: подтверждено, с двумя следствиями, которых RAG-выжимка не давала.**
(1) Неподдерживаемое поле схемы **игнорируется, а не роняет запрос 400** — значит добавление
`description` (поддерживается) и даже `propertyOrdering` (если бы канал его не понимал) не является
рискованной операцией по классу «сломать боевой вызов».
(2) Числовых лимитов (число свойств, глубина, размер enum, длина имени) первичная документация НЕ
называет ни на одной проверенной странице — только качественное «very large or deeply nested».
Совет «сначала упрощать схему» подтверждён дословно (в форме «shortening property names, reducing
nesting, or limiting the number of constraints»).

## 5. Влияние на входные токены и на префикс кэша

**Про системную инструкцию:**

> «System instructions provided to the Gemini API are counted as part of the total input tokens.»
> — `ai.google.dev/gemini-api/docs/tokens`, проверено 2026-08-01 (`вендор-первичный`,
> подтверждение двойное).

**Про implicit-кэш (это главный НОВЫЙ факт для фазы):**

> «Implicit caching is enabled by default for all Gemini 2.5 and newer models.»
>
> Минимальный вход для срабатывания implicit-кэша: **Gemini 3.5 Flash — 4096**, **Gemini 3.1 Pro
> Preview — 4096**, Gemini 2.5 Flash — 2048, Gemini 2.5 Pro — 2048.
>
> «To increase the chance of an implicit cache hit: Try putting large and common contents at the
> beginning of your prompt» · «Try to send requests with similar prefix in a short amount of time».
> — `ai.google.dev/gemini-api/docs/caching` и
> `ai.google.dev/gemini-api/docs/generate-content/caching?generate-content=true`, проверено
> 2026-08-01 (`вендор-первичный`, подтверждение двойное — обе страницы дали одну таблицу).
>
> Моделей линейки **Flash-Lite в этой таблице НЕТ** — порог для `gemini-3.1-flash-lite`
> документацией не назван.

**Про explicit-кэш:**

> «The model treats cached content as a prefix to the prompt, making no distinction between cached and
> regular input tokens. … token limits include cached tokens.»
> — `ai.google.dev/gemini-api/docs/generate-content/caching?generate-content=true`,
> *Additional considerations*, проверено 2026-08-01 (`вендор-первичный`).

**Вердикт:**
(а) «системная инструкция считается во входные токены» — **подтверждено дословно**;
(б) «**схема** считается во входные токены» — **НЕ подтверждено**: ни одна проверенная страница
этого не утверждает. Утверждение RAG-выжимки «Схема считается во входные токены» остаётся
`[UNVERIFIED]`; правдоподобно, но первичного носителя не имеет;
(в) **обнаружен порог, которого в плане нет:** implicit-кэш включается только начиная с 4096
входных токенов на моделях `gemini-3.5-flash` и `gemini-3.1-pro-preview` — то есть сокращение
системной части (шаг 3 порядка D8) может МОЛЧА выключить кэш, а не только удешевить вызов. Для
`gemini-3.1-flash-lite` порог не документирован (`[UNVERIFIED]`).

## 6. `response_schema` vs `responseJsonSchema`

**Найдено:**

> «We've now added support for JSON Schema to all actively supported Gemini models.» — параметр в
> примере кода: `response_json_schema`; «enables libraries like Pydantic (Python) or Zod
> (JavaScript/TypeScript) to work out-of-the-box with the Gemini API.»
> — `blog.google/…/gemini-api-structured-outputs/`, 2025-11-05, проверено 2026-08-01
> (`вендор-официальный-вторичный`).

Отдельного сравнительного раздела «`responseSchema` vs `responseJsonSchema`» на страницах
`ai.google.dev` не найдено; `ResponseJsonSchema` встречается в Go-примерах страницы structured
output, `responseSchema`/`response_schema` — в Python/JS/REST-примерах и в
`generationConfig` REST-запроса. Полная таблица полей объекта `Schema` (где живёт
`propertyOrdering`) в REST-референсе `ai.google.dev/api/*` машинным чтением не извлечена — страница
отдаётся усечённой.

**Вердикт:** два канала сосуществуют; `response_json_schema` объявлен поддержанным «на всех
активно поддерживаемых моделях» с 2025-11-05, но это анонс, а не спецификация. Для решения P2
разница не критична, потому что **выбор канала проектом не делается**: его делает транспорт —
`Prism v0.100.1` шлёт ИСКЛЮЧИТЕЛЬНО `generationConfig.response_schema` (§8). D21 подтверждён.
Все вердикты §2–§5 сняты для страниц метода `generateContent`, то есть для нашего канала.

## 7. Применимость к моделям маршрутов проекта

Активные маршруты (`database/seeders/AiRoutingSeeder.php:87-119`, на `cdafa91`): три модели —
`gemini-3.1-flash-lite` (screening p0, discovery p0, prompt_critic p0),
`gemini-3.5-flash` (extract_passport/extract_rows/extract_totals p0),
`gemini-3.1-pro-preview` (все эскалационные p1). Линейка 2.5 в сиде закомментирована (D123
плана-донора).

**Найдено:** таблица поддержки structured output на странице нашего канала перечисляет
«Gemini 3.1 Flash-Lite, Gemini 3.1 Pro Preview, Gemini 3.5 Flash, 2.5 series, 2.0 Flash»
(`ai.google.dev/gemini-api/docs/generate-content/structured-output`, проверено 2026-08-01,
`вендор-первичный`, подтверждение одиночное).

**Вердикт:** все три модели маршрутов проекта названы в таблице поддержки structured output
поимённо. Оговорки, привязанные к конкретной модели: (а) требование явного `propertyOrdering`
относится к 2.0 и на наши модели не распространяется (§3); (б) порог implicit-кэша документирован
для `gemini-3.5-flash` и `gemini-3.1-pro-preview` (4096) и НЕ документирован для
`gemini-3.1-flash-lite` (§5); (в) «Structured outputs with tools — Preview: This feature is
available only to Gemini 3 series models» (`ai.google.dev/gemini-api/docs/interactions/structured-output`)
— проекта не касается: extraction-вызовы идут БЕЗ tools по требованию безопасности
(`docs/specs/v2/08-estimate-extraction.md` §7).

## 8. Проверка транспорта чтением кода вендора (`RawSchema` доносит ключи узла)

Требование Implementation Rules P2.1: факт «`RawSchema` доносит ключи узла как есть» подтверждается
чтением кода вендора с файлом и строкой, а не предположением. Версия — `prism-php/prism v0.100.1`
(`composer.lock`, reference `5d6cc65b80b1`).

1. **Куда уходит схема.** `vendor/prism-php/prism/src/Providers/Gemini/Handlers/Structured.php:131`:
   `'response_schema' => (new SchemaMap($request->schema()))->toArray()` внутри `generationConfig`
   запроса `{model}:generateContent`. Поля `responseJsonSchema` в этом хендлере нет — D21
   подтверждён на дереве.
2. **Что делает `SchemaMap` с `RawSchema`.**
   `vendor/prism-php/prism/src/Providers/Gemini/Maps/SchemaMap.php:25-32`:

   ```php
   $schemaArray = $this->schema->toArray();
   unset($schemaArray['additionalProperties'], $schemaArray['name']);   // :28
   if ($this->schema instanceof RawSchema) {
       return $schemaArray;                                              // :31
   }
   ```

   То есть для `RawSchema` вырезаются РОВНО ДВА ключа и РОВНО на верхнем уровне, после чего массив
   возвращается как есть — рекурсивной обработки нет, вложенные узлы не переписываются.
3. **`RawSchema::toArray()`** (`vendor/prism-php/prism/src/Schema/RawSchema.php:29-32`) — `return
   $this->schema;`, без преобразований.

**Вердикт: подтверждено чтением кода.** Любой дополнительный ключ узла — `description` на любом
уровне, `propertyOrdering` на любом уровне, `description`/`propertyOrdering` на КОРНЕ — доезжает до
провайдера без вырезания. Единственные потери на стороне Prism — верхнеуровневые
`additionalProperties` и `name`. Следствие: препятствий транспорта для шага 2 порядка D8 нет;
единственный вырезающий узел в цепочке — СОБСТВЕННЫЙ код проекта,
`GeminiResponseSchemaAdapter::resolveObject()` (`app/Services/Llm/Schema/GeminiResponseSchemaAdapter.php:91-111`),
пересобирающий объектный узел из трёх ключей `type`/`properties`/`required`.

## 9. Побочная находка: наш канал помечен вендором как Legacy

Страница нашего канала в навигации 2026-08-01 называется **«Structured outputs | Gemini Generate
Content API (Legacy)»** (`ai.google.dev/gemini-api/docs/generate-content/structured-output`);
рядом живёт новый Interactions API (`/gemini-api/docs/interactions/structured-output`) с иной
формой параметра — `response_format: {type, mime_type, schema}` вместо
`generationConfig.response_mime_type` + `response_schema`
(`ai.google.dev/gemini-api/docs/interactions-breaking-changes-may-2026`, заголовок
`Api-Revision: 2026-05-20`). Дата отсечения в примерах — «Opt-in needed before May 26th».

**Вердикт:** факт зафиксирован, предметом P2 НЕ является (Scope Excluded фазы: смена
провайдера/маршрута; транспорт выбирает Prism, а не проект). Ни один вердикт §1–§8 от этого не
меняется — все они сняты для страниц ИМЕННО этого, фактически используемого канала. Риск —
«Prism/наш канал объявлены legacy» — вход мониторинга EOL (`config('ai.capabilities')`,
skill `llm-layer-dev` §4), а не этой фазы.

## 10. Сводка вердиктов

| # | Вопрос | Вердикт | Класс источника |
|:--|:--|:--|:--|
| 1 | Дублирование схемы в промте запрещено вендором? | **НЕ подтверждено** — запрета нет; есть требование СОВПАДЕНИЯ порядка свойств промта и схемы | первичный (отрицательный) + другая поверхность |
| 2 | `description` поддержан в `response_schema`? | **Подтверждено**, назван «crucial for guiding the model's output» | вендор-первичный |
| 3 | `propertyOrdering` поддержан / нужен? | Поддержан, НЕ-OpenAPI поле; **для 3.x не нужен** — порядок ключей схемы сохраняется неявно (с 2.5+) | первичный (2.0) + официальный анонс (2.5+) |
| 4 | Лимиты схемы | Числовых лимитов нет; неподдерживаемые поля **игнорируются**, большие/глубокие схемы могут быть отклонены | вендор-первичный |
| 5 | Входные токены и префикс кэша | System instruction считается во вход — да; **схема** — `[UNVERIFIED]`; implicit-кэш требует **≥4096** входных токенов на `3.5-flash`/`3.1-pro-preview` | вендор-первичный |
| 6 | `response_schema` vs `responseJsonSchema` | Сосуществуют; выбор делает Prism (`response_schema`), проект не выбирает. D21 подтверждён | первичный (код) + анонс |
| 7 | Применимость к моделям маршрутов | Все три модели проекта в таблице поддержки; оговорки — §3, §5 | вендор-первичный |
| 8 | `RawSchema` доносит ключи узла? | **Подтверждено чтением кода** `v0.100.1`: вырезаются только верхнеуровневые `additionalProperties`/`name` | код вендора |

## 11. Вторичные источники (только контекст, решениями не являются)

- `blog.google/innovation-and-ai/technology/developers-tools/gemini-api-structured-outputs/`
  (2025-11-05) — официальный канал вендора, но анонс продукта, не спецификация; использован для
  §3 (неявный порядок) и §6 (JSON Schema) с явной пометкой класса.
- `medium.com/google-cloud/structured-output-with-gemini-models-…` — источник исходной RAG-выжимки
  (`findings/rag-2026-07-31.md` §3). Первичной проверки НЕ прошёл в части «не дублировать схему в
  промте» (§1). Основанием решений больше не является.
- `discuss.ai.google.dev` — форум вендора, использован только для локализации страниц поиском.
