# ТЗ v2 · 08 — Извлечение смет: контракты, алгоритм, экономика

> Пайплайн — `03-document-pipeline.md` §3.5 (стадия «Полное извлечение»). Транспорт/провайдер — `04-llm-providers.md`. Схема — `01-database.md` §6 (`prc_estimates`/`prc_estimate_sections`/`prc_estimate_items`/`prc_estimate_resources`/`prc_estimate_totals`, `prc_ai_*`). Первоисточники v1, зафиксированные здесь до удаления `Old/` (P11.1): `Old/app/Process/EstimateAnalysis/Schema/**`, `Old/app/Attributes/Alias.php`, `Old/docs/03_Dev/LLM_Strategy.md` §10. Алгоритм разрывов страниц — расшифровка `tmp/agelto2.md` (удаляется в P11.1). Решения — `plans/EST-AI/plan.md` §5, особенно D2, D3, D6, D9, D10, D27, D29–D32, D35.
>
> Назначение документа — самодостаточный контракт для исполнителей P2–P6 и для будущей «наполняющей промты» модели (следующий шаг владельца после плана, см. `plan.md` §1). Old/-первоисточники после P11.1 исчезнут — сверка фактов делалась ПРЯМО по коду, не по пересказу.

## 1. Инвентарь полей v1 → v2

Два независимых семейства схем v1 существовали параллельно (research/C §1A/§1B); только structured-контракт (§1B) переносится содержательно — legacy Excel-контракт (§1A) отброшен целиком (обоснование — конец раздела).

### 1.1 `EstimatePassport` → `prc_estimates`

| v1-поле | Тип v1 | Судьба | v2-адрес |
|---|---|---|---|
| `estimate_id` | string | переносится (семантически) | `prc_estimates.estimate_number` (текст сметы, не суррогатный id — id теперь `prc_estimates.id`/`uuid`) |
| `type` | `EstimateType?` (local_estimate/act/resource_calculation/defect_list) | переносится в jsonb | `prc_estimates.passport->>'type'` |
| `reference` | string? | переносится в jsonb | `prc_estimates.passport->>'reference'` |
| `target` | `EstimateTarget?` (construction/renovation/medical_equipment/engineering) | переносится в jsonb | `prc_estimates.passport->>'target'` |
| `address` | string? | переносится в jsonb | `prc_estimates.passport->>'address'` |

`prc_estimates` не имеет колонок под `type`/`reference`/`target`/`address` — они не участвуют в запросах-фильтрах на старте, поэтому идут в `passport` jsonb целиком (объект `{type, reference, target, address}`), а не отдельными колонками (осознанно, YAGNI: добавить колонку — дешёвая alter-миграция, если появится фильтрация по типу сметы). `EstimateType`/`EstimateTarget` как PHP-enum'ы **не переносятся как БД-enum** — это словарь допустимых значений внутри jsonb-контракта ответа LLM (см. §3.1), валидируется на бэкенде так же, как валидировался v1 через `Data::from()`.

### 1.2 `EstimatePreview` → шаг паспорта (§3.1) + `prc_estimates.rows_expected`

| v1-поле | Судьба | v2-адрес |
|---|---|---|
| `passport` | см. §1.1 | — |
| `items_count` | переносится (переименовано) | ответ шага «паспорт» → агрегируется в `prc_estimates.rows_expected` |
| `items_count_total` | отброшено (артефакт постраничного превью, не нужен при row-range на весь документ) | — |
| `page_start` / `page_end` | переносится ТОЛЬКО в fallback-окне (§4.2, discovery-сегментация) | `discovery`-ответ, не колонка |

### 1.3 `EstimateFetchInfo` (`EstimateHeader` + `EstimateFooterItem[]`) → `prc_estimates`/`prc_estimate_totals`

| v1-поле | Судьба | v2-адрес |
|---|---|---|
| `estimate_id` | см. §1.1 | — |
| `generator` (сметная программа, напр. «Гранд-смета») | переносится в jsonb (диагностика, не участвует в бизнес-логике) | `prc_estimates.passport->>'generator'` |
| `header` (`EstimateHeader`: карта колонок pos/code/name/unit/quantity/price, каждая — `{index, name, full_name}`) | **отброшено как персистентная сущность**: v2 не хранит динамическую карту колонок — LLM возвращает уже нормализованные построчные позиции (§3.1 rows), а не координаты ячеек. Карта колонок была нужна legacy-парсеру Excel (§1A); в structured-подходе LLM сама читает таблицу и отдаёт значения полей | — (см. §2 — сами Alias-словари переносятся как правило матчинга заголовков в промте) |
| `footer` (`EstimateFooterItem[]`: `label`, `value`, рекурсивные `children`) | переносится 1:1 | `prc_estimate_totals` (дерево `parent_id`/`position`/`label`/`amount`; `EstimateFooterItem.value` → `amount`) |

### 1.4 Дерево позиций (`EstimateSection`/`EstimateSubSection`/`EstimateItem`/`EstimateItemResource`/`EstimateModifierItem`) → `prc_estimate_sections`/`prc_estimate_items`/`prc_estimate_resources`

| v1-поле | Судьба | v2-адрес |
|---|---|---|
| `EstimateSection.type='section'`, `.name` | переносится | `prc_estimate_sections.title`; `type` не хранится отдельной колонкой — раздел ВСЕГДА раздел, различие section/subsection снимается плоской моделью (см. ниже) |
| `EstimateSubSection.type='subsection'`, `.name`, `.children` | переносится через self-FK, НЕ отдельная сущность | `prc_estimate_sections.parent_id` (иерархия раздел→подраздел — тот же self-referencing tree, D12 добавляет cascade) |
| `EstimateItem.name` | переносится | `prc_estimate_items.name` |
| `EstimateItem.pos` (позиция «№ п/п») | переносится с уточнением семантики (см. §4а «якорение row_index») | `prc_estimate_items.row_index` |
| `EstimateItem.code` | переносится | `prc_estimate_items.code` (докблок в миграции: «шифр ФЕР/ТЕР») |
| `EstimateItem.unit` | переносится | `prc_estimate_items.unit` |
| `EstimateItem.quantity` | переносится | `prc_estimate_items.quantity` |
| `EstimateItem.price` | переносится (переименовано, вторично переименовано P4.5) | `prc_estimate_items.total_price_curr` (v1 `price` семантически «стоимость всего», см. Alias `price←Стоимость всего,Итого руб» — это НЕ цена за единицу; P4.5 развела два уровня цены — `total_price_curr`/`total_price_base`, `unit_price_curr`/`unit_price_base`, см. §3.1/§3.2 и `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §5.1) |
| `EstimateItem.children` (`EstimateItemResource[]`) | переносится 1:1 | `prc_estimate_resources` (FK `item_id`) |
| `EstimateItem.modifiers` (`EstimateModifierItem[]`: код-коэффициент, обоснование, значение) | переносится в jsonb (низкая частота, не оправдывает отдельную таблицу — YAGNI, ревизия после первых реальных смет); P4.5 зафиксировала схему элемента (9 ключей, §4.2) и убрала свободный `description` в пользу машиночитаемого `kind` | `prc_estimate_items.attributes->'modifiers'` (колонка `attributes` уже задокументирована в миграции как «коэффициенты/индексы» — назначение совпадает; фиксированная схема ключей — P4.5) |
| `EstimateItemResource.name`/`.resource_type`/`.code`/`.unit`/`.quantity`/`.price` | переносится 1:1 (P4.5 переименовала `price`→`price_curr`, добавила `price_base`) | `prc_estimate_resources.{name,resource_type,code,unit,quantity,price_base,price_curr}` |
| `EstimateItemResource.comment` (текст из колонки pos, если там примечание, напр. «прим.») | переносится в jsonb (редкий кейс, нет отдельной колонки) | `prc_estimate_resources` не имеет `comment`-колонки → **осознанно отброшено на уровне ресурса** (пометка ниже); если появится частый кейс — alter-миграция добавит `comment` text nullable |

Раздельная сущность `EstimateSubSection` **не переносится как отдельный тип** — v2 использует единую таблицу `prc_estimate_sections` с self-referencing `parent_id`/`position` (миграция `2026_07_04_100034`), где подраздел — это просто раздел с непустым `parent_id`. Различие «section vs subsection» из v1 было чисто структурным (глубина дерева), не семантическим полем — плоская модель с self-FK строже и проще одновременно (можно углублять дерево дальше 2 уровней без новой сущности).

### 1.5 Ресурсы: `EstimateResourceType` → `prc_estimate_resources.resource_type`

| v1-кейс (enum `EstimateResourceType`) | v2-значение (`ResourceType` PHP backed-enum, `app/Enums/Procurement/ResourceType.php`, text-колонка) |
|---|---|
| `Material` | `material` |
| `Machine` | `machine` |
| `Labor` | `labor` |
| `Overhead` | `overhead` |

`prc_estimate_resources.resource_type` — text + PHP backed-enum (не Postgres native enum, канон проекта). Пятое значение `other` уже предусмотрено в докблоке миграции (`labor/machine/material/overhead/other`) — задел под ресурсы, не попадающие в 4 классических типа v1 (решение исполнителя P2, не пересматривается здесь).

### 1.6 Легаси-контракт (§1A, `Dto/Estimates` + `SmetaParserService::parse`) — отброшен целиком

`Estimate`/`Header`/`Item` с динамической картой колонок (`schema: [{cell_index, code}]`, придуманной ИИ под конкретный файл) — заменены фиксированной схемой (`EstimateHeader` с Alias-словарями, §1.3/§2) уже в v1 (второе, «structured», семейство), а в v2 — построчным извлечением без карты ячеек вовсе. Динамическая карта колонок хрупка (нет гарантии, что ИИ придумает одинаковые коды между вызовами одного документа) и не тарифицируется LLM-провайдером иначе выгоднее — отбрасывается без сожаления, ничего не наследуется буквально, кроме идеи «Alias-словарь синонимов заголовков» (§2), которая уже была в structured-семье.

### 1.7 Таблицы-предшественники `plt_document_analysis`/`plt_document_analysis_responses`/`plt_document_analysis_logs`

Инфраструктурные поля (`file_fingerprint`, `gemini_cache_id`/`gemini_rules_hash`/`gemini_cache_expires_at`, `attempts`/`last_error`/`locked_by`/`locked_until`) уже перенесены доменно в `prc_documents`/`prc_document_versions`/`prc_ai_analyses` (см. `01-database.md` §5–6) — вне scope этой спеки (она про контракт извлечения, не про инфраструктуру пайплайна, покрытую `03-document-pipeline.md`). `plt_document_analysis_responses.data`/`.state` (дерево запросов «первичный анализ → по смете → по подвалу») заменяется на плоскую модель `prc_ai_analyses(step)` + `progress` jsonb (checkpoint, §4д) — дерево вложенных response-записей не переносится, это была ad-hoc реализация того же прогресса, что теперь явный `progress`.

## 2. Словари синонимов (`#[Alias]`)

Атрибут `App\Attributes\Alias` (`PROPERTY`/`CLASS_CONSTANT`) в v1 не выполнял матчинг сам — он был частью системной инструкции: класс-исходник (включая докблоки и атрибуты) подмешивался в промт байт-в-байт (см. §6 «Приём: PHP-исходник как system instruction»), и LLM сама сопоставляла заголовки таблицы с полями по перечисленным синонимам. В v2 этот приём сохраняется через PHP Data-классы шага «паспорт»/«позиции» (владелец реализации — P2, схема-как-код per D2) — здесь фиксируются словари ДОСЛОВНО, чтобы при написании P2-классов ни один синоним не потерялся.

### 2.1 Заголовки колонок таблицы позиций (`EstimateHeader`)

| Поле (v2: соответствующее поле `prc_estimate_items`) | Синонимы заголовков (дословно из v1; дополнены фактическими формулировками корпуса — P7.1, `plans/2026.07.26-EST-TRAIN/research/13-prompt-corpus-rules.md`) |
|---|---|
| `pos` → `row_index` | `№ п/п`, `№`, `поз`, `Номер`, `по порядку`, `Номер п/п`, `№№ п/п` |
| `code` → `code` | `Шифр`, `Обоснование`, `Код`, `Шифр расценки и коды ресурсов`, `Шифр стоимостного норматива и коды ресурсов` |
| `name` → `name` | `Наименование работ`, `Описание`, `Наименование работ и затрат`, `Наименование пусковых комплексов, этапов, объектов, видов выполненных работ, оборудования, затрат` |
| `unit` → `unit` | `Ед. изм.`, `Измеритель`, `Единица измерения` |
| `quantity` → `quantity` | `Количество`, `Объем`, `Кол-во единиц`, `всего с учетом коэффициентов` |
| `price` → `total_price` | `Стоимость всего`, `Итого руб`, `всего в текущем уровне цен` |

### 2.2 Типы ресурсов (`EstimateResourceType`)

| v2-значение (`resource_type`) | Синонимы (дословно из v1; дополнены фактическими формулировками корпуса — P7.1, `plans/2026.07.26-EST-TRAIN/research/13-prompt-corpus-rules.md`) |
|---|---|
| `material` | `МАТ`, `мат.ресурс`, `ФССЦ`, `прайс-листы`, `М` |
| `machine` | `ЭМ`, `маш.час`, `Машины и механизмы`, `ФСЭМ` |
| `labor` | `ЗП`, `ОТ`, `ЗПМ`, `Трудозатраты`, `чел.час`, `ОТ(ЗТ)`, `ОТм(ЗТм)` |
| `overhead` | `НР`, `СП`, `Накладные расходы`, `Прибыль` |

Эти два словаря — часть системной инструкции ЛЮБОГО шага, читающего построчные позиции (`extract_passport`, `extract_rows`, `extract_totals`, `extraction:discovery`), не только «шапки»: LLM обязана сопоставлять русские заголовки колонок и категории ресурсов с этими каноническими именами независимо от точной формулировки в конкретном документе (сметные программы форматируют шапки по-разному — Гранд-Смета, 1С:Смета, ручной Excel).

## 3. Контракты ответов LLM по шагам

Все контракты — PHP Data-классы (`spatie/laravel-data`), из них ОДНОВРЕМЕННО генерируется JSON Schema для structured output (`responseJsonSchema`, D2) и системная инструкция (очищенный исходник класса, приём v1, §6). Здесь — целевой JSON конкретных ответов (владелец точных PHP-классов — P2.2/P2.3); контракт ниже — обязательное покрытие полей, не окончательный код.

### 3.1 `extract_passport` (паспорт/шапка + границы; сменяет v1 `preview`+`fetch_info`)

```json
{
  "estimate_number": "string|null",
  "type": "local_estimate|act|resource_calculation|defect_list|null",
  "reference": "string|null",
  "target": "construction|renovation|medical_equipment|engineering|null",
  "address": "string|null",
  "generator": "string|null",
  "contract_number": "string|null",
  "contract_date": "string|null",
  "currency": "RUB",
  "base_year": 2001,
  "price_level": "string|null",
  "rows_expected": 0,
  "calc_method": "base_index|resource|null",
  "labor_hours_workers": "string|null",
  "labor_hours_machinists": "string|null",
  "document_number": "string|null",
  "document_date": "string|null",
  "period_start": "string|null",
  "period_end": "string|null",
  "footer": [
    {"label": "string", "value": "string|null", "kind": "overhead|profit|vat|total|additional|other|null", "chapter_number": "int|null", "rate": "string|null", "children": []}
  ],
  "work_types": []
}
```

`rows_expected` — оценка числа позиций (не разделов) во всей смете, ключ для контроля полноты (§3.4 doc-pipeline, п.4 Merge). `footer` — рекурсивная структура 1:1 с `EstimateFooterItem` (§1.3), `kind` — машиночитаемая пометка типа начисления, замкнутое множество `TotalKind` (P4.5), опционально заполняется LLM или детерминированно на бэкенде по `label`; `value` — nullable (P4.5): начисление, заданное процентом (`rate`), может не иметь собственной суммы в документе; `chapter_number`/`rate` — номер главы сводного расчёта и ставка процента (SR-11, P4.5). `contract_number`/`contract_date` — реквизиты договора подряда (SR-12, P4.5). `calc_method`/`labor_hours_workers`/`labor_hours_machinists`/`document_number`/`document_date`/`period_start`/`period_end` — поля, добавленные P4.5 по утверждённому распределению величин (`plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §3.1/§8, SR-17/SR-07/SR-12); документ не называет метод расчёта или сводные трудозатраты явно — соответствующее поле `null`, бэкенд не подставляет значение по умолчанию. `work_types` — виды работ и их иерархия (SR-08/SR-09, P4.5): заполняет ТОЛЬКО детерминированный маппер `.gsfx` (нормативы НР/СП, `parent_id`) — боевой LLM-ответ всегда несёт пустой список (исключение из D33).

### 3.2 `extract_rows` (построчный диапазон; сменяет v1 `fetch_items`)

Плоский список строк с `row_type` (D10 — вложенное дерево section→subsection→item НЕ запрашивается у модели, иерархия собирается на бэкенде из `row_type`+`section`-полей):

```json
{
  "rows": [
    {
      "row_index": 1,
      "row_type": "section_header|subsection_header|item|subtotal|summary_row",
      "section_title": "string|null",
      "code": "string|null",
      "name": "string",
      "unit": "string|null",
      "quantity": "string|null",
      "unit_price_base": "string|null",
      "unit_price_curr": "string|null",
      "total_price_base": "string|null",
      "total_price_curr": "string|null",
      "index_code": "string|null",
      "index_value": "string|null",
      "source_position_number": "string|null",
      "work_type_name": "string|null",
      "resources": [
        {"resource_type": "material|machine|labor|overhead|other", "code": "string|null", "name": "string", "unit": "string|null", "quantity": "string|null", "price_base": "string|null", "price_curr": "string|null", "index_code": "string|null", "index_value": "string|null"}
      ],
      "attributes": {
        "modifiers": [
          {"name": "string", "kind": "qty|price|correction|winter|recalculation|other|null", "code": "string|null", "value": "string|null", "value_labor": "string|null", "value_machines": "string|null", "value_machinists_wage": "string|null", "value_materials": "string|null", "level": "int|null"}
        ],
        "amount_since_start": "string|null",
        "amount_since_year_start": "string|null",
        "amount_reporting_period": "string|null"
      }
    }
  ],
  "incomplete_items": []
}
```

`row_type=section_header`/`subtotal`/`summary_row` — тоже строки, участвуют в нумерации `row_index` (D10, детали — §4а; счёт ведётся ПО СМЕТЕ — P4.3). Decimal-величины (`quantity`, `unit_price_*`, `total_price_*`, `index_value`, значения `attributes`) — строками, конверсия во float запрещена (P4.5). `unit_price_curr`/`total_price_curr` — переименованы из v1 `unit_price`/`total_price` (D93), рядом добавлены `*_base` (базисный уровень, SR-01, P4.5) — у ресурсной сметы (`calc_method=resource`) `*_base` законно пуст. `index_code`/`index_value` — индекс пересчёта позиции/ресурса (SR-03, P4.5). `source_position_number` — собственный номер позиции, напечатанный в документе (может быть дробным, «9.1»), если он есть — область ЛЮБАЯ смета, не только акт (SR-13, расширено P4.3 §5.1); отличается от `row_index` — синтетического якоря, существующего и для строк без напечатанного номера. `work_type_name` — подпись вида работ (SR-08, P4.5); нормативы НР/СП и иерархия видов работ контракт СТРОК не несёт — они уровня сметы, см. §3.1 `work_types`. `attributes` — фиксированная схема ключей (P4.5, `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §4): ключ, не перечисленный здесь, отклоняется валидацией (`RowAttributes`, D32). `modifiers[].description` (v1) убран в пользу машиночитаемого `kind` — замкнутое множество (SR-04/SR-05); `value_labor`/`value_machines`/`value_machinists_wage`/`value_materials`/`level` — составляющие коэффициента формы `.gsfx` (SR-06), в форме книг не заполняются. `amount_since_start`/`amount_since_year_start`/`amount_reporting_period` — КС-3, три временных разреза стоимости (SR-14), заполняются только строкой `row_type=summary_row`. `incomplete_items` — буфер незавершённых строк на границах ЗАПРОШЕННОГО диапазона (детали и различие семантики row-range/windowed — §4б).

### 3.3 `extract_totals` (итоги/начисления; при большой смете может дублировать/уточнять `footer` из паспорта на последних страницах)

Тот же контракт `footer`, что в §3.1 — вызывается отдельно, когда паспорт запрашивался БЕЗ полного документа (окно, §4.2) и не мог увидеть концевик, либо когда паспорт-вызов намеренно экономит токены и не возвращает `footer` вовсе (решение оркестратора P6.1: запрашивать footer в паспорте, только если документ целиком в контексте).

### 3.4 `extraction:discovery` (сегментация смет в оконном fallback-пути, D30/D37)

Новый контракт, которого не было в v1 (в v1 «несколько смет в файле» не решалось системно — см. `research/C` §5 «легко потерять», `state` json был задел без реализации). Вызывается на каждое окно страниц (та же оконная механика, что §4.2), не видит документ целиком:

```json
{
  "markers": [
    {"page": 1, "marker": "estimate_start", "title": "string|null"},
    {"page": 1, "marker": "continuation"},
    {"page": 4, "marker": "estimate_end"}
  ]
}
```

`marker`: `estimate_start` (первая страница новой сметы в окне), `continuation` (страница — продолжение текущей сметы, не начало и не конец), `estimate_end` (последняя страница текущей сметы в окне). Бэкенд детерминированно склеивает маркеры со всех окон в сегменты `{estimate_ordinal, page_start, page_end}` — сама сегментация НЕ LLM-решение, а детерминированная сборка по маркерам (см. §4.2).

### 3.5 Enum-словарь допустимых значений (для валидации ответов на бэкенде)

- `EstimateType` (`type` в §3.1): `local_estimate` (ЛСР — «Локальный сметный расчёт», «Локальная смета»), `act` (КС-2 — «Акт о приемке выполненных работ», «Форма № КС-2»), `resource_calculation` («Ведомость ресурсов», «Расчет ресурсов»), `defect_list` («Дефектная ведомость», «Опись работ»).
- `EstimateTarget` (`target` в §3.1): `construction`, `renovation`, `medical_equipment`, `engineering`.
- `ResourceType` (`resource_type` в §3.2): `material`, `machine`, `labor`, `overhead`, `other` (см. §1.5).
- `row_type` (`row_type` в §3.2): `section_header`, `subsection_header`, `item`, `subtotal`, `summary_row` (расширяет v1 `ItemType`: section/item → плоский список с явным подразделом и промежуточным итогом, D10; `summary_row` — строка свода КС-3, P4.5, SR-14).
- `CalcMethod` (`calc_method` в §3.1): `base_index` (базисно-индексный), `resource` (ресурсный) — SR-17, P4.5.
- `TotalKind` (`kind` в `footer`, §3.1/§3.3): `overhead`, `profit`, `vat`, `total`, `additional`, `other` — P4.5, D101; детерминированное доопределение по `label` этим планом не строится (долг).
- `ModifierKind` (`kind` в `attributes.modifiers[]`, §3.2): `qty`, `price`, `correction`, `winter`, `recalculation`, `other` — P4.5, SR-04/SR-05.
- Скрининговый `DocumentType` (не сметный контракт, но соседний енум из v1 — `Old/app/Process/DocumentAnalysis/Schema/DocumentType.php`, применяется на шаге `screening`, `03-document-pipeline.md` §3.4): `estimate`, `tech_spec`, `unknown` — переносится как `document_type` (text, check-constraint) в `prc_ai_analyses`, справочника нет (D16).

## 4. Алгоритм извлечения

> Sequence-диаграмма по фактическому коду (оба пути + checkpoint-requeue, D31) — `docs/03_Dev/document-pipeline.md` (Д4).

### 4.1 Основной путь: row-range по кэшированному целому документу (D3, канон)

1. **Preflight** (§4в) — файл целиком укладывается в лимиты Files API активного маршрута.
2. Upload документа целиком → context cache (implicit-first, explicit — по решению оркестратора, `04-llm-providers.md` §2.1).
3. `extract_passport` — один вызов на весь документ (кэшированный system prompt + документ), `rows_expected` + `footer`, если он виден целиком.
4. `extract_rows` — построчными диапазонами (напр. 20–50 строк/вызов, калибровка — Открытый вопрос `03` §7), каждый вызов ссылается на `cachedContentName`, `temperature=0`, явные `row_index`.
5. `extract_totals` — если `footer` не был получен на шаге 3.
6. Merge + детерминированная валидация в коде (не LLM, `03` §3.5 п.4): `rows_expected` vs фактическое число строк, дубли `row_index`, арифметика (сумма `total_price` позиций раздела == `subtotal`-строка того же раздела ± 0.01; сумма итогов ± 0.01).
7. Точечный re-run по подозрительному диапазону (аномалия/пропуск), не по всему документу.
8. По завершении — освободить/удалить explicit-кэш, если создавался.

**Границы диапазона и `incomplete_items` в этом пути** — только на краях ЗАПРОШЕННОГО диапазона (не страницы физического документа): внутри диапазона LLM видит весь документ в контексте и сама разрешает разрывы страниц (документ целиком в контексте — постраничных разрывов для модели не существует). Детали — §4б.

### 4.2 Fallback: оконный трёхстадийный путь (D30, для документов сверх лимита провайдера — триггер §4в)

Транскрипция алгоритма из `tmp/agelto2.md` («Context Window Stitching with Orphan Lines Buffer»), адаптированная под требование мульти-сметного документа без целого документа в контексте (research/L finding 17, D30):

**Стадия 1 — Discovery.** Документ режется на страничные окна (sliding window, overlap 1 страница — тот же принцип, что «Чанк 1: 1,2,3 / Чанк 2: 3,4,5»). Каждое окно → дешёвый вызов `extraction:discovery` (flash-lite-класс, покрытие §3.4). Бэкенд ДЕТЕРМИНИРОВАННО (не LLM) склеивает маркеры всех окон в сегменты `{estimate_ordinal, page_start, page_end}` — если два окна дают противоречивые маркеры на общей (overlap) странице, приоритет у более раннего окна для `estimate_start` и более позднего для `estimate_end` (сегмент не должен схлопнуться в 0 страниц). Границы сегмента переживают прогон в колонках `prc_estimates.source_page_start`/`.source_page_end` (P4.5, `plans/2026.07.26-EST-TRAIN/research/07-multi-estimate-anchoring.md` §3.1) — исключение из D33 (бэкенд заполняет из детерминированной сегментации, LLM эти колонки не порождает и в контракт ответа они не входят).

**Стадия 2 — Per-estimate извлечение.** Для каждого сегмента (=будущая строка `prc_estimates`, `ordinal` = порядковый номер сегмента, D12) — независимый прогон **того же** row-range контракта (§3.2/§4.1), но окнами СТРАНИЦ внутри сегмента (не диапазонами строк — модель не знает общего числа строк заранее без целого документа), с overlap 1 страница и буфером `incomplete_items` (постраничная семантика, §4б).

**Стадия 3 — Totals.** По последним страницам каждого сегмента — отдельный вызов `extract_totals` (§3.3), окно = хвост сегмента (последние 1–2 страницы или до маркера «Всего по смете/акту»).

Паспорт-DTO для оконного пути (`estimate_number`/`type`/`target`/…) собирается либо отдельным дешёвым вызовом на первую страницу каждого сегмента, либо переиспользует заголовочные данные, если они попали в маркер `estimate_start` (`title` в §3.4) — точное решение за P6.4, здесь фиксируется, что паспорт НЕ требует целого документа в контексте (в отличие от v1-замысла, где паспорт зависел от `fetch_info`, видевшего документ целиком — тот самый gap, зафиксированный research/L finding 17).

**Маркеры страниц.** Перед нарезкой на окна текст/изображения каждой физической страницы оборачиваются маркерами `[PAGE_START: X]`/`[PAGE_END: X]` (v1-приём, tmp/agelto2.md §3.1) — так LLM сохраняет привязку строк к странице для `incomplete_items.page_number` и для discovery-маркеров (§3.4).

**Stitcher.** Бэкенд-сервис (аналог v1-замысла `EstimateStitcherService`, не реализованного) сопоставляет `incomplete_items` смежных окон одного сегмента по составному dedup-ключу (код расценки + наименование + номер страницы на стыке) и склеивает разорванную строку в одну позицию перед персистом. Один и тот же `Stitcher`/`Validator`/`Persister` обслуживает оба пути (row-range и windowed) — оконный путь просто подаёт им данные по сегментам вместо одного документа (research/L «сводный образ целевого поведения»).

### 4а. Якорение `row_index`

Обязательное правило, отсутствовавшее в v1 явно (source gap, зафиксирован research/L, D-item явно не назначен, но фиксация здесь обязательна per задание item'а):

- **Что считается строкой.** `row_index` нумерует ЛЮБУЮ строку таблицы позиций подряд — включая `section_header`/`subsection_header` (заголовки разделов) и `subtotal` (промежуточные итоги раздела), не только `item` (D10). Это отличается от v1, где `pos`/«№ п/п» относился только к позициям с расценкой — раздел не имел номера. Причина изменения: детерминированная сверка полноты (§4.1 п.6) требует единой монотонной последовательности без пропусков независимо от типа строки; частичная нумерация («только у item») делает дедуп/сверку диапазонов недвусмысленно сложнее.
- **Строки без «№ п/п» в исходнике.** Если в документе у строки физически нет номера (частый случай для заголовков разделов, иногда — для позиций без явной нумерации в исходной таблице), `row_index` всё равно присваивается ПОСЛЕДОВАТЕЛЬНО в порядке появления строки в документе (не переносится «пусто» и не дублируется соседний номер) — правило одинаково для основного и оконного пути.
- **Идентичность нумерации между вызовами.** Паспорт-вызов (§3.1, поле `rows_expected`) и вызовы `extract_rows` ОБЯЗАНЫ считать строки одинаково (те же правила выше) — иначе `rows_expected` не сопоставим с фактическим количеством извлечённых строк, и детерминированная проверка полноты (§4.1 п.6) даёт ложные расхождения. Если паспорт получен из части документа (оконный путь, где паспорт не видит документ целиком) — `rows_expected` на этом пути не гарантированно точен и НЕ используется как строгий гейт валидации, только как ориентир (уточняется по факту извлечённых `row_index` внутри сегмента).
- Уникальность в БД: `prc_estimate_items` уже имеет `unique(estimate_id, row_index)` (миграция `2026_07_04_100035`) — правило выше обеспечивает, что LLM не порождает дублей/дыр внутри одной сметы по построению, а не только по факту БД-констрейнта.
- **Область счёта — ПО СМЕТЕ, не по документу** (P4.5, `plans/2026.07.26-EST-TRAIN/research/07-multi-estimate-anchoring.md` §5): в многосметном документе нумерация `row_index` начинается заново с 1 у КАЖДОЙ сметы — это не новое поведение (код и БД-констрейнт уже считают по смете), а явная формулировка уже существующего правила: буквальное чтение «нумерует любую строку таблицы позиций подряд» без этого уточнения допускало бы сквозной счёт по документу.

### 4б. Семантика `incomplete_items`

Буфер `incomplete_items` (флаги `split_by_page_end`/`orphan_numbers_at_page_start`, v1-приём tmp/agelto2.md §3.3) имеет РАЗНУЮ семантику границ в двух путях — обязательное разделение, не смешивать:

- **Row-range путь (§4.1).** Документ целиком в контексте LLM на каждом вызове — постраничных разрывов внутри диапазона модель не видит вовсе (весь текст перед ней). Буфер заполняется ТОЛЬКО если строка физически обрывается на границе ЗАПРОШЕННОГО ДИАПАЗОНА (например, диапазон «строки 20–50» обрывается ровно на позиции с недописанными числовыми полями, потому что следующая строка формально относится к диапазону 51–80) — не на границе печатной страницы документа. Смысл: диапазон — искусственная граница нарезки промта, страница — нет; буфер лечит первую, не вторую.
- **Оконный путь (§4.2).** Границы — физические страницы документа (overlap 1 страница), буфер заполняется по правилам v1 дословно: `split_by_page_end` — строка началась внизу страницы, есть код расценки, но числовые показатели физически перенесены на следующую страницу (запрет модели «додумывать» цифры); `orphan_numbers_at_page_start` — страница начинается с голых цифр/коэффициента без кода расценки сверху (продолжение предыдущей строки). Склейка — Stitcher (§4.2), сопоставляет `incomplete_items` окна N с окном N+1 по составному ключу.

### 4в. Transport-limits & preflight (D29)

Лимиты Files API — свойство МАРШРУТА (`document_type × purpose → provider+model`, `04-llm-providers.md` §3.1), а не константа алгоритма: `config/ai.php` несёт per-модель `max_request_file_mb`, `max_pages`, `supports_files_api` (расширение capability-матрицы кэширования D14 на транспорт). Для Gemini — 50 МБ / 1000 страниц НА ЗАПРОС, действует ДАЖЕ через Files API (upload до 2 ГБ пройдёт, `generateContent` вернёт `INVALID_ARGUMENT` при превышении) — верифицировано research/L finding 16.

Оркестратор (P6.1) обязан делать **preflight ДО первого upload**, не полагаться на ошибку провайдера как единственный сигнал:

```
preflight(file, route.capabilities):
  size_mb  = Storage::size(file) / 1MB
  pages    = qpdf --show-npages file   (для не-PDF — по факту подготовки, §4.2 doc-pipeline)
  ├─ size_mb ≤ route.max_request_file_mb && pages ≤ route.max_pages → ЦЕЛИКОМ (§4.1)
  ├─ иначе, но файл читается                                        → ОКНАМИ (§4.2)
  └─ файл не читается вовсе / защищён паролем / архив-бомба          → unprocessable (§4г)
```

Ошибка провайдера о превышении лимита в рантайме (несмотря на preflight — например, race на изменившемся конфиге маршрута) обрабатывается ТЕМ ЖЕ путём, что preflight-превышение — переключение на оконный путь для следующей попытки, не терминальный `failed`.

Резка PDF на конкретные диапазоны страниц (первые N для скрининга, `03` §3.4/D6; окна для §4.2) — через `qpdf` CLI в worker-образе (`qpdf in.pdf --pages . 1-N -- out.pdf`), НЕ через `setasign/fpdi` (бесплатная версия не читает PDF ≥1.5 с compressed cross-reference streams — массовый формат вывода Гранд-Сметы/принтеров ЕИС/MS Office, research/L finding 2, D28). Нормализация (`qpdf --stream-data=uncompress --force-version=1.4`) — только если downstream-шагу реально нужен FPDI-совместимый вход.

### 4г. `unprocessable` — терминальный исход, отличный от `failed` (D32/D38)

«Не можем обработать» — это КОНСТАТАЦИЯ (лимит провайдера, нечитаемый формат, защищённый паролем файл, zip-бомба), не СБОЙ (временная недоступность, невалидный ответ модели). Смешивание в общем `failed_permanently`/`last_error` делает исход невидимым для оператора (research/L finding 19) — новый статус `DocumentStatus::Unprocessable` (владелец enum-кейса — первый по порядку исполнения потребитель, P4.2, D38; корректирует ошибочное указание D32 «P5.1») + `UnprocessableReason` со значениями (накапливаются по потребителям):

| Причина | Кто выявляет | Когда |
|---|---|---|
| `too_large` | P4.2 (xlsx size-gate, D35) | xlsx сверх `ai.prepare.xlsx_max_rows`/`xlsx_max_mb` без fallback-конвертера |
| `zip_bomb` | P4.2 (архивная стратегия) | превышен лимит глубины/размера/числа файлов распаковки |
| `unreadable_format` | P5.1 (qpdf-слайсер) | документ физически не читается (битый PDF, неподдерживаемый формат) |
| `password_protected` | P5.1 | PDF защищён паролем |
| `provider_limit_size` | P6.1 (preflight §4в) | файл сверх `max_request_file_mb` активного маршрута ДАЖЕ в оконном пути (крайний случай — гигантский скан-документ) |
| `provider_limit_pages` | P6.1 (preflight §4в) | число страниц сверх `max_pages` |
| `other` | любой | необработанный случай, обязателен `analysis.result.message` |

Причина пишется в `prc_ai_analyses.result` соответствующего шага. `unprocessable` НЕ ретраится (не инкрементирует `attempts`) — это финальное состояние документа, требующее либо ручного вмешательства (другой формат исходника, снятие пароля), либо появления более щедрого маршрута в будущем. Алерт «новые unprocessable за окно» + счётчик в `pipeline:status` — P7.3; операторский SOP «что делать с unprocessable-документом» — P10.4.

### 4д. Checkpoint-requeue (D31/D39)

Разбор документа ЛЮБОГО размера — цепочка КОРОТКИХ запусков одного job'а (`EstimateExtractionJob`), не один долгий `handle()`. Инвариант: одиночный запуск job ограничен по времени константой строго МЕНЬШЕ job timeout (сам timeout, в свою очередь, меньше supervisor timeout/`retry_after`, канон D26).

Механика: после обработки M диапазонов/окон (config) ИЛИ T секунд с начала текущего запуска (config, `< job timeout`) — что наступит раньше — job:
1. персистит текущий прогресс в `prc_ai_analyses.progress` (jsonb: пройденные диапазоны/сегменты, накопленные `incomplete_items`, промежуточный `rows_extracted`);
2. ре-диспатчит САМ СЕБЯ (`afterCommit`, с небольшой задержкой) на продолжение с зафиксированного места;
3. штатно завершает ТЕКУЩИЙ запуск успехом (не бросает исключение, не помечает `failed`).

Следующий запуск читает `progress` и продолжает с места остановки — снаружи (со стороны Horizon/наблюдателя) это выглядит как серия коротких успешных job'ов, а не один зависающий процесс. Уникальность диспатча (`ShouldBeUniqueUntilProcessing`, а не `ShouldBeUnique` — lock снимается В НАЧАЛЕ обработки, не по её завершении, D39): под обычным `ShouldBeUnique` self-dispatch продолжения ВНУТРИ `handle()` тихо дедуплицировался бы, пока текущий запуск ещё держит lock (документ навсегда застревал бы «в extraction»). Защита от двойного диспатча ВО ВРЕМЯ обработки (окно между снятием lock в начале и завершением работы) — на lease + status-guard (`03-document-pipeline.md` §1, `01`), не на уникальности job'а. Короткие single-shot job'ы пайплайна (`PrepareDocumentJob`, `ScreeningJob`) остаются на обычном `ShouldBeUnique` — self-requeue им не нужен.

## 5. Экономика

### 5.1 Модели и цены (research/E §4, сверено на 2026-07-07)

| Роль | Модель (старт) | Цена in/out ($/1M) | Кэш ($/1M) | EOL | Преемник |
|---|---|---|---|---|---|
| Скрининг | `gemini-2.5-flash-lite` | 0.10 / 0.40 | 0.01 | 2026-10-16 | `gemini-3.1-flash-lite` (0.25/1.50, кэш 0.025) |
| Извлечение (`extract_*`, `extraction:discovery`) | `gemini-2.5-flash` | 0.30 / 2.50 | 0.03 | 2026-10-16 | `gemini-3.5-flash` (1.50/9.00, кэш 0.15) |
| Эскалация (сложность/провал валидации) | `gemini-2.5-pro` | 1.25 / 10.00 (≤200k) | 0.125 | 2026-10-16 | `gemini-3.1-pro-preview` (2.00/12.00) |

EOL всей линейки 2.5 — **2026-10-16** (ai.google.dev/gemini-api/docs/deprecations, D27) — стартуем на дешёвой линейке для тюнинга промтов СЕЙЧАС, но `config/ai.php` несёт `eol_date`/`successor` на каждую запись, P7.3 алертит по приближении даты, P7.2-сиды держат закомментированную 3.x-строку-преемника рядом с активной.

Страница PDF Gemini = 258 токенов фиксированно (скан или текст — без разницы, встроенный OCR не тарифицируется отдельно) — плановая константа для оценки объёма (research/E §3).

**Важно про кэш скрининга**: implicit caching Gemini требует стабильный префикс ≥2048 токенов (2.5-линейка) — короткая системная часть скрининга (§6, экономный промт) может НЕ дотягивать до порога, тогда implicit-хитов не будет вовсе (research/L finding 10). По деньгам это не критично (скрининг и так копеечный — 4 стр. × 258 ток. ≈ 1k ток. input, D6), но экономика §5 НЕ закладывает cache-скидку на скрининг отдельной строкой — только на `extract_*`, где системная часть (промпты+схема) заведомо больше порога.

### 5.2 Первичный анализ — только первые N страниц (D6)

Скрининг получает СРЕЗАННЫЙ PDF (первые `N=4` страницы по умолчанию, режется `qpdf` на бэкенде, §4в) — Gemini не принимает page-range параметром запроса (research E §3), резать обязаны сами. В v1 это был замысел, но реально код слал весь `fileUri` (research/B «легко потерять» — «gap док↔код») — в v2 фиксируется как обязательное поведение, не намерение.

### 5.3 Задокументированные рычаги (не реализуются в этом плане)

- **Batch API** (D7) — минус 50% от стандартной цены, turnaround до 24 ч, job истекает через 48 ч, результаты хранятся 6 недель; поддерживает Files API/structured output/caching, JSONL до 2 ГБ. Не latency-критичные партии (переобработка, датасет) — прямой кандидат, вне текущего плана: меняет модель оркестрации (24ч-цикл вместо синхронного Horizon), добавляется отдельным планом без ломки контракта `LlmProvider`.
- **LibreOffice headless** (`soffice --convert-to pdf`, D35) — более надёжная по fidelity альтернатива PhpSpreadsheet+Mpdf для конвертации xlsx→PDF (сохраняет print-setup/merged cells), появляется по профилю реальных проблемных файлов; вызовы сериализуются (не параллелится в headless-режиме).
- **Ghostscript-даунскейл** (150 dpi, JPEG, research/L finding 20) — сжатие скан-PDF перед оконным путём, иногда возвращает документ в путь «целиком» (кэш + row-range дешевле и стабильнее окон). Не обязателен сейчас, фиксируется как рычаг на случай реальных 100+ МБ скан-документов.

### 5.4 Выбор представления на вход (P6.3, D3/D52)

Какой файл версии документа уходит в LLM на стадии extraction — решает `RepresentationResolver::
forLlm()` по правилу-ДАННЫМ `config('ai.representations')` (карта `расширение исходника → упорядоченный
список представлений`, первое существующее побеждает; нет ни одного — оригинал версии). Для
`xlsx`/`xls` действующее представление — **`pdf_alt`** (PDF конвертера LibreOffice) с фолбэком на
`pdf`; для остальных форматов, не измеренных боевым прогоном (сканы, `docx`, архивы и т.д.), — `pdf`,
поведение не изменилось. Обоснование, цифры боевого прогона и условия, при которых решение
устаревает (смена линейки моделей, материальная смена набора промтов, перегенерация представлений,
новый формат вне подвыборки) — ADR `plans/2026.07.26-EST-TRAIN/root/adr-input-representation.md`
(судьба — перенос в `docs/03_Dev/adr/`, P9.6), отчёт с цифрами —
`plans/2026.07.26-EST-TRAIN/research/12-format-benchmark.md`. Числа привязаны к дате замера
(2026-07-28) и модели активного маршрута на ту дату (`gemini-3.5-flash`) — они не переживают смену
модели или набора промтов без нового замера.

## 6. Правила промтов (`Old/docs/03_Dev/LLM_Strategy.md` §10, перенесены дословно по смыслу)

Мастер-промт извлечения смет v1 (роль «система извлечения данных из строительных смет РФ», JSON-only ответ) задаёт нормативную рамку, обязательную для P8-наполнения промтов v2:

- **Нормативные базы** — распознавать и указывать код: ГЭСН (Государственные элементные сметные нормы), ФЕР (Федеральные единичные расценки), ТЕР (Территориальные единичные расценки), ФССЦпг (Федеральные сметные цены на материалы), ТСН (Территориальные сметные нормы), БД (коммерческие цены).
- **Иерархия**: раздел → подраздел → позиция (v2: `row_type=section_header`/`subsection_header`/`item`, §3.2/§4а, не вложенное дерево в ответе — иерархия собирается на бэкенде, D10).
- **Разделение стоимости** материалов/работ/механизмов, если указаны раздельно в источнике (v2: через `resources[].resource_type`, §3.2).
- **Коэффициенты** (зимнее удорожание, командировочные и т.п.) — отдельным массивом, не смешивать со строками позиций (v2: `modifiers[]` внутри строки, §3.2/§1.4).
- **Нечитаемое → `null`**, LLM НЕ придумывает данные.
- **Числа** — только цифрами без пробелов-разделителей разрядов (`1234567.89`).
- **НДС** — по умолчанию 20%, если в документе указано иное — фиксировать фактическое значение (не подставлять дефолт молча).
- **Тип позиции**: раздел (заголовок) vs позиция с расценкой различаются явно в ответе (v2: `row_type`).

Валидационный промт v1 (контрольные суммы — сумма позиций раздела = subtotal ±0.01 ₽; `total_without_vat = subtotal + overhead + estimated_profit`; `vat_amount = total_without_vat × (vat_rate/100)` ±0.01 ₽; `total_with_vat = total_without_vat + vat_amount` ±0.01 ₽; все позиции имеют непустое `name`) — переносится НЕ как отдельный LLM-вызов (v1-замысел), а как ДЕТЕРМИНИРОВАННАЯ проверка в коде на этапе Merge (§4.1 п.6) — арифметика дешевле и надёжнее как код, чем как ещё один LLM-запрос (research/E §5, «арифметический контроль — основной сигнал качества»).

Экономный промт первичного анализа v1 (§10.2 LLM_Strategy) — прообраз шага `screening` (`03-document-pipeline.md` §3.4), не сметный контракт этой спеки; сохраняется как reference для P8-наполнения промта скрининга.

**Состав системной инструкции (P6.1, D51).** Системная часть шага = правила из `llm_prompts` (композиция global → step-global → шаг) + блок схем. Блок схем несёт ТРАНЗИТИВНОЕ ЗАМЫКАНИЕ контрактов шага: от обёртки ответа (`RowsChunkResponse`, `PassportResponse`, `TotalsResponse`, `DiscoveryResponse` — §3) обход графа типов доходит до вложенных Data-классов и enum'ов по конструктору и докблоку `@param X[]`, самоссылки (`TotalLine.children`) не зацикливаются. Владелец правила обхода ОДИН — `App\Services\Llm\Schema\DataTypeGraph`, общий с генератором JSON-схемы (§ниже): схема сознательно не дублирует семантику полей, поэтому разошедшиеся носители означали бы, что семантику не несёт ни один. Словари синонимов §2, до которых обход не доходит (`EstimateHeaderMap` — «не часть JSON-ответа LLM ни одного шага», §2.1), объявляет сам тип документа одним объявлением, читаемым всеми потребителями шага; достижимое замыканием (`ResourceType`, §2.2 — через `EstimateRow.resources[]`) в нём не дублируется. Порядок печати детерминирован (сортировка по короткому имени, обёртки `*Response` последними) — системная часть является префиксом implicit-кэша провайдера, и недетерминированный порядок обнулял бы кэш молча.

### 6.1 Очередь кандидат-патчей и LLM-критик промтов (P7.4, D8/D59–D61/D64)

Носитель — таблица `prc_ai_prompt_suggestions` (`App\Models\Procurement\Ai\PromptSuggestion`), НЕ
`prc_ai_corrections`: коррекция — структурированная правка КОНКРЕТНОГО разбора, подмешиваемая
USER-частью повторного вызова (`CorrectionAwareComposer`); предложение — про ТЕКСТ системной
инструкции, живущий файлом в git (оба корня канона, `resources/ai/prompts/**` +
`resources/<домен>/ai/prompts/**`, D32), и проходит через статус/аудит
очереди, прежде чем стать файлом. Смешение обоих смыслов в одну таблицу теряет оба (D59).

**Два вида предложения** (`PromptSuggestionKind`, D60):

- `prompt_patch` — ПОЛНЫЙ новый текст тела файла промта (`proposed_content`), целится в
  конкретный `prompt_key`/`base_version` (версия, поверх которой предложен патч).
- `correction_hint` — переиспользуемая подсказка (`hint`/`document_type`) для СУЩЕСТВУЮЩЕГО
  носителя `prc_ai_corrections(is_reusable=true)` — третьей сущности не заводится.

**Статусы** (`PromptSuggestionStatus`): `pending` → `approved`/`rejected` → `applied`/`reverted`.
Решение принимает ТОЛЬКО человек (D8) — ни одна команда не переводит запись в `approved`
автоматически; экранная форма апрува — P8.4.

**Критик** — `CorePromptStep::PromptCritic` (`resources/ai/prompts/prompt-critic/0-prompt-critic.md`),
обычный шаг системы промтов (D61): версионируемый, `is_protected`, сидится `PromptSeeder` наравне с
остальными восемью. Назначение вызова — `CoreLlmPurpose::PromptCritic`, маршрут — одна строка
`(document_type=any, purpose=prompt_critic, priority=0)` на дешёвой модели в `AiRoutingSeeder`, без
эскалационной строки (повтор — на уровне job-retry, не re-ask внутри шага).

`estimate:prompts:suggest {--code=*} {--analysis=} {--step=*} {--dry-run} {--limit=}` — по коду эталона
(или `--analysis`, тот же резолв `Analysis(step=extraction)` + `estimates/gold/<CODE>.gold.json`,
что `estimate:eval:diff`) строит `EstimateDiffReport` (§8.6) и для каждого запрошенного `--step` вызывает
`App\Services\Procurement\Tuning\PromptCritic`: собирает ТЕКУЩУЮ композицию промта шага
(`PromptComposer::compose()`), вызывает критика структурированным запросом и создаёт запись
`pending` со статусом `evidence`/`rationale`. Предложение, дублирующее уже существующее
`pending`/`rejected` по паре (`prompt_key`, нормализованный текст —
`PromptSuggestion::normalizeText()`), НЕ создаётся повторно.

`ai:prompts:apply {id} {--dry-run}` — работает ТОЛЬКО со `status=approved` (любой другой статус —
отказ, ни файл, ни БД не тронуты). Для `prompt_patch` `App\Services\Procurement\Tuning\
PromptPatchApplier` читает целевой файл, поднимает `version` во front-matter на 1, заменяет тело на
`proposed_content`, СОХРАНЯЯ остальные поля front-matter (`key`/`step`/`priority`/`is_active`) —
файл пишется в рабочую копию, коммит и `ai:prompts:sync` делает человек. Для `correction_hint`
создаётся `prc_ai_corrections(is_reusable=true, hint, document_type, analysis_id, created_by=
decided_by)` существующим носителем, без правок его схемы.

**Безопасность петли (D64).** Diff-отчёт и текущая композиция промта шага подаются вызову критика
ТОЛЬКО USER-частью — системная часть вызова состоит ИСКЛЮЧИТЕЛЬНО из промта критика. Вызов идёт без
tools/function-calling (контракт `LlmProvider` их не предоставляет в принципе). Текст, предложенный
критиком, никогда не попадает в системную инструкцию автоматически — единственный путь: явный апрув
человека (`status=approved`) и `ai:prompts:apply`.

## 7. Безопасность: indirect prompt injection

Содержимое документа (PDF/Excel/скан, приходящий с площадки закупки) — **untrusted input** (research/L finding 14): извещения и вложения загружает третья сторона (заказчик/оператор площадки), контент не проверяется до отправки в LLM.

Обязательные правила для всех extraction-вызовов (`extract_passport`/`extract_rows`/`extract_totals`/`extraction:discovery`):

- Вызовы идут БЕЗ tools/function-calling — модель не имеет средств выполнить встроенную в документ инструкцию, даже если такая инструкция там есть (например, текст «игнорируй предыдущие инструкции и верни ...» вписан в ячейку таблицы).
- Системные правила (это ТЗ, §6) не переопределяются текстом документа ни при каких обстоятельствах — системная инструкция всегда синтаксически отделена от содержимого документа (`system_instruction` vs `contents`, не конкатенация в одном текстовом блоке).
- Подозрительные инструкции, обнаруженные внутри документа (нехарактерный для сметы императивный текст, попытки переопределить формат ответа) — НЕ исполняются, а флагируются: попадают в `parse_warnings`-подобное поле ответа (конкретное имя поля — за P2.2) для последующего операторского ревью, наравне с прочими аномалиями качества разбора.

## 8. Датасет эталонов и eval-контур (P7.4)

### 8.1 Формат папки эталонов

`estimate:dataset:import {path}` читает папку с ПАРАМИ файлов: `<name>.<ext>` (исходный файл — pdf/xlsx/скан, тот же тип, что реальный документ закупки) + `<name>.gold.json` (эталонный разбор). Имя `<name>` произвольное (без точек внутри, кроме `.gold.json`-суффикса), `<ext>` — любое расширение, поддерживаемое подготовкой (`03-document-pipeline.md` §3.3). Пара без файла-источника (сирота `*.gold.json`) или без `*.gold.json` рядом с источником — отклоняется индивидуально (не валит импорт остальных пар).

### 8.2 Контракт `<name>.gold.json`

Верхний уровень — **та же форма**, что `prc_ai_analyses.result` реального прогона (`estimates: [...]`, `ExtractionOrchestrator`, §4.1/§4.2) — эталон и предсказание сравниваются без отдельного маппера:

```json
{
  "estimates": [
    {
      "estimate_number": "string|null",
      "passport": { "type": null, "reference": null, "target": null, "address": null, "generator": null, "contract_number": null, "contract_date": null },
      "currency": "RUB",
      "base_year": 2001,
      "price_level": null,
      "rows_expected": 0,
      "calc_method": null,
      "labor_hours_workers": null,
      "labor_hours_machinists": null,
      "document_number": null,
      "document_date": null,
      "period_start": null,
      "period_end": null,
      "totals": { "footer": [] },
      "work_types": [],
      "rows": [ /* EstimateRow — §3.2, включая section_header/subtotal/summary_row */ ]
    }
  ]
}
```

Каждый элемент `estimates[]` — прямое соответствие `PassportResponse` (§3.1, поля `estimate_number`/`passport`/`currency`/`base_year`/`price_level`/`rows_expected`/`totals`/`calc_method`/`labor_hours_workers`/`labor_hours_machinists`/`document_number`/`document_date`/`period_start`/`period_end`/`work_types`, P4.5) ПЛЮС ключ `rows` (`EstimateRow[]`, §3.2). Импорт валидирует КАЖДЫЙ элемент этим же PHP-контрактом (`PassportResponse::validateAndCreate()` + `EstimateRow::validateAndCreate()` на каждую строку, `Data::validateAndCreate()`, тот же приём, что `StructuredResponseValidator`, P3.4) — битый эталон отклоняется с перечнем ошибок по индексу сметы/строки/поля, ничего не пишется в `prc_ai_dataset_examples` для этой пары; неизвестный ключ внутри `rows[].attributes` отклоняется ЭТИМ ЖЕ вызовом (`RowAttributes`, фиксированная схема, P4.5, D32) — «эталон, несущий поле вне утверждённого списка» тоже битый эталон. `estimates[]` сортируется по возрастанию `ordinal` (индекс в массиве = `ordinal-1`), для многосметных документов — сметы должны идти в том же порядке, в котором их находит `ExtractionOrchestrator` (паспорт-цикл row-range пути/discovery-сегментация оконного пути, §4). Пять колонок адреса сметы в источнике (`source_sheet_name`/`source_row_start`/`source_row_end`/`source_page_start`/`source_page_end`, P4.3) и ось `verified_at`/`verified_by` (D89) в этом контракте НЕ участвуют (исключение из D33, `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §6/§11, `research/07-multi-estimate-anchoring.md` §3.1) — обе группы заполняет бэкенд/оператор, а не разбор, поэтому эталон на вопрос «откуда прогон её взял» не отвечает и отвечать не обязан.

### 8.3 Персистентность и идемпотентность

`estimate:dataset:import` создаёт `Document`+`DocumentVersion` вне закупки (`procurement_id=null`, D22) — `external_id="gold:<name>"`, `status=Downloaded`, контент — в `crawler.documents_disk` (тот же путь-конвенция, что `DocumentDownloader::store()`); повторный импорт того же файла дедуплицируется по `content_hash` (та же гарантия, что у краулинга). `prc_ai_dataset_examples` — `updateOrCreate` по `(document_version_id, task, version)` (Code Guidance): повторный импорт с той же тройкой ключей ОБНОВЛЯЕТ `gold` на месте (эталон скорректировали — переимпортировали), не плодит дублей.

### 8.4 Eval-раннер и метрики

`estimate:eval:run` прогоняет `ExtractionOrchestrator` (§4, ТЕКУЩИЙ extraction-пайплайн — БЕЗ стадии скрининга, эталоны заведомо сметы) по каждому `prc_ai_dataset_examples(task=extraction)`, сравнивает `analysis.result.estimates[]` (сырой выход оркестратора, ДО `EstimatePersister` — эталонные документы вне закупки, `EstimatePersister` их сознательно пропускает, D22/P6.3) с `gold.estimates[]` (`App\Services\Procurement\Analyzers\Estimate\EvalMetrics`):

- **Сопоставление**: сметы — позиционно по индексу в `estimates[]` (совпадающий `ordinal`); строки внутри сметы — по `row_index` (уникален в рамках сметы, §4а).
- **Field-level precision/recall/F1** — на паре (строка, поле) для `code`/`name`/`unit`/`quantity`/`price`(=`total_price_curr`)/`row_type`/`unit_price_base`/`total_price_base`/`index_code`/`index_value`/`source_position_number`/`work_type_name` (P7.3, карта следствий P4.5); текстовые поля — нестрогое сравнение (trim + схлопывание пробелов + регистронезависимо), числовые (`quantity`/`price`/`unit_price_base`/`total_price_base`/`index_value`) — bcmath ±0.01 (тот же допуск, что `EstimateValidator`, §4.1 п.6).
- **Row coverage** — доля строк эталона, для которых нашлась строка предсказания с тем же `(индекс сметы, row_index)`, независимо от совпадения полей.
- **Паспорт и итоги** (P7.3) — тем же приёмом (позиционное сопоставление смет), отдельными ключами `passport`/`totals` результата `EvalMetrics::compare()`: паспорт — по скалярным полям `PassportResponse`/`EstimatePassport`; итоги — по полям `TotalLine`, строки сопоставляются между собой по `(нормализованный label, kind)` (§8.6).
- Результат — `prc_ai_eval_runs.metrics` (агрегат по всем эталонам прогона) + по одной `prc_ai_eval_results` записи (`metric=f1`, `details` — полный отчёт по примеру) на эталон; консольный отчёт — сводная строка + топ-10 худших примеров по F1.
- **CI-гейт и shadow-режим** — Scope Excluded этого item'а (Фаза 6 роадмапа, появляется вместе с реальным голденом): `ci_status` пишется информационно (`pass`), без порогового сравнения между прогонами.
- В CI/тестах реальные LLM-вызовы НЕ делаются (`--dry-run` либо пустой датасет — оба пишут EvalRun-заглушку без единого вызова); `FakeLlmProvider` для реального прогона НЕ допускается (Implementation Rules) — команда предназначена для ручного запуска владельцем с боевым провайдером.

### 8.5 Контракт gold скрининга (P5.4/P5.6, D5)

Эталон скрининга — файл `gold/<CODE>.screening.json`, РЯДОМ с extraction-gold `<CODE>.gold.json` (не вместо него: у эталона-сметы существуют оба файла). Носитель — конвенция имени, в манифест (`gold_path`, §2.4) screening-gold НЕ заводится.

Форма — дословно `App\Dto\Procurement\Ai\Screening\ScreeningResult` (§3.5):

```json
{
  "document_type": "estimate|tech_spec|contract_draft|protocol|unknown",
  "confidence": 1.0,
  "description": "string|null",
  "tags": [],
  "page_count_estimate": null,
  "ignore_reason": "string|null"
}
```

Правило вердикта: `class=est` (реестр `prc_ai_fixtures`) → `document_type=estimate`, `ignore_reason=null`; `class=neg` → `document_type` берётся из реестра (`Fixture.doc_type`) — отсутствие `doc_type` у негатива отклоняет запись индивидуально (реестр обязан его нести, P1.3/P2, не эта команда). `description`/`ignore_reason` заполняются из `Fixture.notes` (единственный текстовый носитель причины включения эталона в корпус) — пусто в реестре, значит `null` в gold, значение не сочиняется.

**Что сравнивается**: ТОЛЬКО `document_type` — и производный от него бинарный признак «смета / не смета» (`estimate:eval:run --task=screening`, §8.6). **Что информативно, но не сравнивается**: `confidence` (в эталоне всегда `1.0` — это поле уверенности МОДЕЛИ, у эталона оно бессмысленно), `description`, `tags`, `ignore_reason` — пишутся в `details` записи `EvalResult` как контекст расхождения, метрику не формируют.

**Почему негативы — главная метрика, не позитивы** (D5): ложное срабатывание скрининга («не смета» размечена как `estimate») дороже пропуска — документ уходит в дорогое извлечение впустую. Позитивы дают долю пропущенных смет, но именно доля ложных срабатываний по негативам — число, ради которого ветка вообще существует.

**Раннер** — `estimate:eval:run --task=screening` (P5.6, D44): прогоняет `ScreeningProcessor` (боевой маршрут скрининга через `LlmRouteResolver`, реальный LLM-вызов — `FakeLlmProvider` в тестах недопустим, Implementation Rules) по каждому `prc_ai_dataset_examples(task=screening)` и сравнивает `document_type` предсказания с `gold.document_type` через `App\Services\Procurement\Screening\ScreeningEvalMetrics` — независимый от `App\Services\Procurement\Analyzers\Estimate\EvalMetrics` (§8.4) класс: `accuracy`, `confusion` (ожидаемый × фактический по `AiDocumentType`), `false_positive_rate` (доля НЕ-смет, помеченных `estimate` — главное число, D5) и `false_negative_rate` (доля смет, помеченных не-`estimate`). Результат пишется в те же task-агностичные `prc_ai_eval_runs`/`prc_ai_eval_results` (`metric=screening_accuracy`), CI-гейт и пороговое сравнение — вне скоупа (как и §8.4).

### 8.6 Diff-отчёт «gold vs предсказание» (P7.3, D62)

`App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport::build(array $goldEstimates, array $predictedEstimates, ?int $limitRows = 200): DiffReport` — детерминированный (без LLM) построчный разбор расхождений поверх агрегата §8.4, вход для человека, для критика промтов (P7.4) и для экрана сравнения (P8.3). ЧИСТАЯ функция над той же формой массивов, что §8.2/`analysis.result.estimates[]` — ни БД, ни сети, ни файлов; без собственной таблицы (отчёт — производная величина, не сущность).

Состав отчёта закрыт списком:

- `summary` — результат `EvalMetrics::compare()` (§8.4) как есть, без переупаковки.
- `rows` — список расхождений построчного уровня: `estimate_index`, `row_index`, `kind` ∈ `missing` (строка есть в gold, нет в предсказании) | `extra` (есть в предсказании, нет в gold) | `field_mismatch` (обе строки есть, поле расходится), `field`, `gold`, `predicted`. Поля сравнения — та же карта `EvalMetrics::FIELD_MAP` (§8.4), расширенная P4; строки сопоставляются по `(индекс сметы, row_index)`.
- `passport` — расхождения полей паспорта (`estimate_number`/`currency`/`base_year`/`price_level`/`calc_method`/`labor_hours_workers`/`labor_hours_machinists`/`document_number`/`document_date`/`period_start`/`period_end`, вложенные `passport.type`/`.reference`/`.target`/`.address`/`.generator`/`.contract_number`/`.contract_date`) — сметы сопоставлены позиционно, каждое поле — отдельная запись `field_mismatch`.
- `totals` — расхождения строк `footer` (`TotalLine`, рекурсивно, включая `children`): строки сопоставляются между собой по `(нормализованный label, kind)`, целиком отсутствующая/лишняя строка даёт запись `missing`/`extra`, различие `value`/`kind`/`chapter_number`/`rate` внутри сопоставленной пары — отдельные `field_mismatch`.
- `arithmetic` — результат `EstimateValidator` (§4.1 п.6), вызванный ОТДЕЛЬНО по предсказанию и ОТДЕЛЬНО по gold той же сметы: базисно-индексный расчёт законно не сходится и у эталона (D42), отчёт обязан различать «модель ошиблась» от «документ такой», поэтому статусы не смешиваются в одну запись.
- `truncated` — `{shown, total}`: список `rows` режется по `$limitRows` (дефолт 200), но оба числа несутся всегда — усечение никогда не молчаливое.
- `verdict` — одна строка словами («расхождений нет» либо сводка по четырём группам).

Числа — decimal-строками на всём пути (конверсия во float запрещена, тот же канон, что DTO §3). Повторный вызов на тех же входах детерминирован (сортировка расхождений по `(estimate_index, row_index/label, field)`) — байт-идентичный JSON.

Команда `estimate:eval:diff {--code=} {--analysis=} {--format=json|md} {--out=} {--limit-rows=200}` — по коду эталона резолвит `estimates/gold/<CODE>.gold.json` (тот же путь, что `estimate:gold:build`, §config `ai.fixtures.gold_dir`) и АКТИВНЫЙ `Analysis(step=extraction)` рабочей копии эталона (`fixture:<CODE>`, конвенция `estimate:gold:import`); `--analysis` берёт анализ напрямую и резолвит код эталона обратным ходом через `external_id` документа. Команда НИ ОДНОГО LLM-вызова не делает — читает уже полученный `analysis.result`, оркестратор не запускается.

## 9. Data lifecycle: retention, MinIO ILM, бэкапы (P7.7, D33/D41)

### 9.1 Матрица «таблица → окно хранения → где → почему»

| Таблица/данные | Окно | Где (механизм) | Почему |
|:--|:--|:--|:--|
| `llm_calls` (метаданные строки) | 12 мес. (`AI_RETENTION_LLM_CALLS_MONTHS`) | Postgres, `partman.part_config.retention` (DROP партиции) | Метаданные (usage/cost/purpose) ценны дольше блобов — cost-report/аудит расхода продолжают работать по строке уже БЕЗ `request_ref`/`response_ref`-файлов (блобы expire раньше, см. ниже) |
| `llm_calls.request_ref`/`response_ref` (блобы, префикс `ai/llm-calls/`) | 90 дней (`AI_STORAGE_LLM_BLOBS_EXPIRE_DAYS`) | MinIO ILM (`ai:storage:lifecycle`, `config('partitioning.minio_lifecycle')`) | Блоб на КАЖДЫЙ LLM-вызов (вкл. re-ask/shadow) — без политики миллионы файлов без ценности после разбора завершён |
| `prc_fetches` | 6 мес. (`AI_RETENTION_FETCHES_MONTHS`) | Postgres, `partman.part_config.retention` (DROP) | Краулинг-лог попыток скачивания — расходник, ценность падает после успешного `Document.status` |
| `prc_operation_logs` | 6 мес. (`AI_RETENTION_OPERATION_LOGS_MONTHS`) | Postgres, `partman.part_config.retention` (DROP) | Операционный лог обработки — расходник (`01-database.md` §9) |
| `prc_events` | 6 мес. (`AI_RETENTION_EVENTS_MONTHS`) | Postgres, `partman.part_config.retention` (DROP) | Доменные события — трасса пайплайна, не единственный источник состояния (state — в самих `prc_documents`/`prc_ai_analyses`/`prc_estimates`) |
| `prc_revisions` | БЕЗ retention (D41) | Postgres, `part_config.retention IS NULL` (НЕ зарегистрирована в `config('partitioning.retention')`) | История редакций закупок — доменный аудит «что менялось», не эфемерный лог; DROP стирал бы аудиторский след без возможности восстановления |
| `prc_estimates` (вкл. `raw_result`/`raw_result_ref`) | Вечно | Postgres (обычная таблица, НЕ партиционирована) + MinIO (`ai/estimates/` — вне ILM-правила выше, отдельный префикс) | Распарсенные сметы — целевой продукт пайплайна, не расходник |
| `prc_ai_dataset_examples` (gold-эталоны) | Вечно | Postgres (обычная таблица) + `documents/`-префикс MinIO (вне ILM-правила выше) | Обучающий датасет (D15/D16) — фундамент локальной LLM, безвозвратная потеря недопустима |

### 9.2 Postgres: retention партиций

`partman.part_config.retention`/`retention_keep_table` — выставляется миграцией `2026_07_09_100050_set_pg_partman_retention.php` (raw SQL, идемпотентно) ТОЛЬКО для 4 таблиц из `config('partitioning.retention')`. Основной путь (`pg_partman` установлен) — `partman.run_maintenance()` (вызывается `partitions:maintain`, уже в scheduler) DROP-ит партиции сам по достижении retention-окна — никакого нового кода в команде не требуется. Fallback-путь (managed Postgres без pg_partman) — `MaintainPartitions::dropExpiredFallback()` дублирует эту логику сырым `DROP TABLE IF EXISTS` по тому же конфигу через `pg_inherits`.

### 9.3 Postgres: autovacuum hot-партиций

`llm_calls`/`prc_fetches` — наибольший объём вставок среди партиционированных таблиц. Миграция `2026_07_09_100051_set_hot_partition_autovacuum.php` понижает `autovacuum_vacuum_scale_factor`/`autovacuum_analyze_scale_factor`/`autovacuum_vacuum_insert_scale_factor` (default 0.2/0.1/0.2 → 0.01) двумя путями: (1) pg_partman template-таблица (`partman.template_public_<table>`, авто-создана `create_parent()`, 100047) — reloptions отсюда подхватываются ЛЮБОЙ НОВОЙ партицией (declarative-партиционирование само НЕ наследует reloptions родителя — задокументированное ограничение Postgres, ради которого pg_partman вообще ведёт template-таблицы); (2) уже существующие партиции альтерятся явно (template не применяется ретроактивно).

### 9.4 MinIO: ILM для LLM-блобов

`ai:storage:lifecycle` (идемпотентная artisan-команда, `mc` CLI) применяет `config('partitioning.minio_lifecycle')`-правила на бакет `s3`-диска. `mc ilm rule add` САМ по себе не идемпотентен (повтор с теми же параметрами создаёт второе правило) — команда сверяет текущие правила (`mc ilm rule ls --json`) по `Filter.Prefix` перед добавлением. Правило `llm_call_blobs` (`ai/llm-calls/`, expire 90 дней по умолчанию) — единственное на старте; `ai/estimates/`(raw_result) и `documents/`(эталоны/обычные документы) под него НЕ попадают (разные префиксы).

### 9.5 Бэкапы

`make db:backup [ENV=prod]` (`docker/postgres/backup-and-verify.sh`) — `pg_dump -Fc` в bind-mount `storage/backups` (виден на хосте) + ОБЯЗАТЕЛЬНЫЙ restore в одноразовую scratch-БД (создаётся/дропается в рамках прогона, проверяется непустой `information_schema.tables`) — бэкап без проверенного restore не считается сделанным. Off-host копирование дампа, ротация старых файлов и cron-расписание на прод-хосте — Scope Excluded (инфра-решение владельца, `plans/EST-AI/handoff.md` open_questions). MinIO — версионирование бакета/`mc mirror` для офф-сайт копии не реализованы этим item'ом (та же категория, Pending Work).

## Приложение: первоисточники (актуальны до P11.1)

`Old/app/Repositories/Prompt/PromptRuleRepository.php`, `Old/app/Services/{PromptService,GeminiService}.php`, `Old/app/Models/Prompt/PromptRule.php`, `Old/app/Dto/Prompt/Step.php`, `Old/app/Process/{DocumentAnalysis,EstimateAnalysis/Schema,DocumentPrepare}/**`, `Old/app/Attributes/Alias.php`, `Old/app/Models/DocumentAnalysis/DocumentAnalysis.php`, `Old/docs/03_Dev/LLM_Strategy.md`, `tmp/agelto2.md`. Разведка: `plans/EST-AI/research/{B,C,E,L}-*.md`.
