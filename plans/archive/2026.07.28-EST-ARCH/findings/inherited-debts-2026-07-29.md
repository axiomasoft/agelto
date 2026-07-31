# Наследуемые долги AI-контура — факты кода

Слой 1 (`plan-protocol` §16). Разведка при детализации фазы P6, сверена чтением файлов и командами
на `74f515b` (ветка `main`, рабочее дерево без правок в затронутых файлах). Повод — `findings/
repo-structure-2026-07-28.md` §12 перечисляет долги одной строкой каждый, а ТЗ items требует знать
механизм: чем именно словарь не доезжает, из чего состоит прирост baseline, где живёт позиционное
сопоставление.

Потребители — items P6.1, P6.2, P6.3.

---

## 1. Словари `#[Alias]` не доезжают: механизм

### 1.1 Ядро контекста не делает обхода — оно печатает то, что ему передали

`PromptContextGenerator::generate(array $classes)` принимает СПИСОК класс-строк и печатает
очищенный исходник каждого; ни рекурсии по типам свойств, ни разбора докблоков `@param X[]` в нём
нет — только сортировка и вырезание `<?php`/`namespace`/`use`/`declare`
(RAG:— repo-grounded: `app/Services/Llm/Schema/PromptContextGenerator.php:23-35`, `:86-106`).

`PromptComposer::compose(PromptStep $step, array $schemaClasses = [])` передаёт этот список как
есть (RAG:— repo-grounded: `app/Services/Llm/PromptComposer.php:30,42`).

### 1.2 Боевые call-site передают ровно один класс — обёртку шага

| Call-site | Что передаётся в `compose()` |
|:--|:--|
| `app/Services/Procurement/Screening/ScreeningProcessor.php:148` | `[ScreeningResult::class]` |
| `app/Services/Procurement/Extraction/RowRangeChunkSource.php:145` | `[$dataClass]` — одна Response-обёртка шага |
| `app/Services/Procurement/Extraction/WindowedChunkSource.php:224` | `[$dataClass]` — то же |
| `app/Console/Commands/Ai/DatasetExportCommand.php:103` | `[RowsChunkResponse::class]` |
| `app/Services/Procurement/Tuning/PromptCritic.php:56-57` | `[PromptSuggestionResponse::class]` / пусто |
| `app/Services/Procurement/Bench/FormatBenchRunner.php:94` | пусто (нужен только `promptVersionSet`) |

(RAG:— repo-grounded: `grep -rn "compose(" app/`)

Следствие: на шаге `extract_rows` модель получает исходник ОДНОГО класса `RowsChunkResponse`
(1170 байт, два поля-массива) — и ни одного докблока `EstimateRow`, `RowResource`,
`RowAttributes`, `IncompleteRow`.

### 1.3 JSON-схема сознательно НЕ дублирует семантику

`JsonSchemaGenerator` строит `responseJsonSchema` «плоско и компактно: **без description-дублей
(семантика уже в prompt-контексте, PromptContextGenerator)**»
(RAG:— repo-grounded: `app/Services/Llm/Schema/JsonSchemaGenerator.php:14-20`).

То есть два носителя разошлись: схема не несёт семантику, считая, что её несёт контекст; контекст
не несёт вложенные типы, считая, что ему их передадут. Модель на шаге строк видит ИМЕНА полей
`EstimateRow` без единого пояснения.

### 1.4 Обход графа типов в проекте УЖЕ есть — в другом классе

`JsonSchemaGenerator` рекурсивно обходит конструктор (`classSchema()` → `parameterSchema()` →
`namedTypeSchema()`), разрешает элементы массивов по докблоку `@param X[] $prop`
(`docblockArrayItemType()`), поддерживает enum'ы (`enumSchema()`) и самоссылки через `$defs`
(`dataClassRef()` резервирует ключ до рекурсивного вызова)
(RAG:— repo-grounded: `app/Services/Llm/Schema/JsonSchemaGenerator.php:47-58,100-111,129-140,146-158`).

Это ровно то правило «что считается вложенным типом», которого не хватает контексту промта. Второй
экземпляр этого правила разъедется с первым при первой же правке — класс дефекта «два носителя
одного факта».

### 1.5 Словарей ДВА, и достижимы они по-разному

| Словарь | Носитель | Достижим рекурсией от Response-обёртки шага? |
|:--|:--|:--|
| `EstimateHeaderMap` | класс-`Data`, 6 свойств с `#[Alias]` (заголовки колонок) | **Нет.** Собственный докблок: «НЕ часть JSON-ответа LLM ни одного шага» (RAG:— repo-grounded: `app/Dto/Procurement/Ai/Estimate/EstimateHeaderMap.php:10-12`); ни одно поле ни одного DTO его не типизирует |
| `ResourceType` | enum, 4 кейса с `#[Alias]` (виды ресурсов) | **Да.** `RowsChunkResponse` → `@param EstimateRow[] $rows` → `@param RowResource[] $resources` → `RowResource::$resource_type: ResourceType` (RAG:— repo-grounded: `RowsChunkResponse.php:21-26`, `EstimateRow.php:50,68`, `RowResource.php:29`) |

Отсюда: рекурсия одна словари не чинит (`EstimateHeaderMap` она не достаёт), явная регистрация
одна не чинит семантику вложенных DTO. Нужны обе половины — это основание D51.

`grep -rn "EstimateHeaderMap" app/ resources/` даёт РОВНО одно вхождение — собственное объявление
(RAG:— repo-grounded, подтверждает находку F4 аудита P7 EST-TRAIN).

### 1.6 Существующий тест словарей зелен ПО ПОСТРОЕНИЮ

`tests/Feature/Ai/PromptCompositionTest.php:49` проверяет, что синонимы присутствуют в блоке, —
но передаёт `EstimateHeaderMap::class` и `ResourceType::class` в генератор РУКАМИ
(RAG:— repo-grounded). То есть тест доказывает, что генератор умеет напечатать словарь, а не что
словарь доезжает до боевого промта. Тот же класс, что «arch-тест на несуществующем неймспейсе»
(D33): гейт выглядит рабочим и не защищает ничего.

Тест бюджета `PromptCompositionTest.php:38` («часть правил каждого шага ≤ 12000 символов») меряет
`compose(step: $step)` БЕЗ `schemaClasses` — то есть только правила из БД; ростом блока схем он не
затрагивается (RAG:— repo-grounded).

### 1.7 Цена в байтах (вход для решения владельца)

`wc -c app/Dto/Procurement/Ai/Estimate/*.php` (RAG:— repo-grounded, на `74f515b`):

| Класс | Байт |
|:--|--:|
| `EstimateRow` | 6866 |
| `PassportResponse` | 4103 |
| `EstimateHeaderMap` | 2615 |
| `RowModifier` | 2566 |
| `TotalLine` | 2255 |
| `IncompleteRow` | 2173 |
| `RowResource` | 2159 |
| `RowAttributes` | 2031 |
| `RowsChunkResponse` | 1170 |
| весь каталог (16 файлов) | 34745 |

Замыкание шага `extract_rows` (`RowsChunkResponse` + `EstimateRow` + `IncompleteRow` +
`RowResource` + `RowAttributes` + `RowModifier` + enum'ы + явно зарегистрированный
`EstimateHeaderMap`) ≈ **+16 КБ** к системной части против сегодняшних 1.2 КБ блока схем.
Системная часть — префикс implicit-кэша провайдера (`PromptComposer` докблок), поэтому прирост
оплачивается по кэш-тарифу со второго вызова, но `rules_hash` меняется и кэш инвалидируется
единожды на всех шагах.

---

## 2. `phpstan-baseline.neon`: из чего состоит и чем растёт

### 2.1 Текущий размер

На `74f515b`: **280 записей / 366 ошибок**
(RAG:— repo-grounded: `grep -c "message:" phpstan-baseline.neon` = 280;
`grep -oP "count: \K\d+" phpstan-baseline.neon | paste -sd+ | bc` = 366).

### 2.2 Распределение по `identifier` — один класс съедает 84%

| identifier | Записей |
|:--|--:|
| `property.notFound` | 236 |
| `argument.type` | 18 |
| `nullsafe.neverNull` | 7 |
| `return.type` | 3 |
| `larastan.noUnnecessaryCollectionCall` | 2 |
| `class.notFound` | 2 |
| `arrayValues.list` | 2 |
| прочие (по 1) | 8 |

(RAG:— repo-grounded: подсчёт по `identifier:` в `phpstan-baseline.neon`.)

`property.notFound` — это «Access to an undefined property `App\Models\…::$field`» и «…
`Illuminate\Database\Eloquent\Model::$field`» (второе — relation-chain, где larastan потерял
конкретный класс модели).

### 2.3 Корень класса `property.notFound` и механический рычаг

`phpstan.neon` не задаёт `databaseMigrationsPath`; в проекте всего ТРИ модели с `@property`-блоком
(`PromptSuggestion`, `DocumentRepresentation`, `Fixture`) — остальные не объявляют свойств ни
докблоком, ни схемой (RAG:— repo-grounded: `phpstan.neon` целиком; `grep -rl "@property" app/Models/`).

larastan v3.10.0 объявляет параметр `databaseMigrationsPath` (тип `listOf(string())`, дефолт `[]`,
пробрасывается в `databaseMigrationPath` расширения моделей)
(RAG:— repo-grounded: `vendor/larastan/larastan/extension.neon:20,45,469`;
`composer.lock` → `larastan/larastan v3.10.0`, `phpstan/phpstan 2.2.6`).

То есть у крупнейшего класса записей есть механический рычаг снятия (сканирование миграций либо
`@property`-блоки), а не только «объяснить и оставить». Риск рычага: миграции проекта содержат
`DB::statement` (партиционирование `pg_partman`) — сканер может их не разобрать; проверяется
прогоном, а не предположением.

### 2.4 Прирост, названный `repo-structure` §12.2, — не воспроизводится в заявленном числе

Baseline по коммитам (RAG:— repo-grounded: `git show <ref>:phpstan-baseline.neon`, те же две
команды подсчёта):

| Коммит | Item плана `2026.07.26-EST-TRAIN` | Записей | Ошибок |
|:--|:--|--:|--:|
| `7485520` | подключение phpstan | 235 | 290 |
| `3f015a7` | P6.1 (последний ДО items P7) | 271 | 348 |
| `09b843c` | P7.3 | 272 | 349 |
| `ebd0a1f` | P7.4 | 273 | 350 |
| `b9b30d5` | P7.5 | 280 | 365 |
| `ee8382f` | P6.3 (ADR формата входа) | 280 | 366 |

Итог по трём items P7 (`3f015a7` → `b9b30d5`): **+9 записей / +17 ошибок**; с учётом `ee8382f` —
**+9 записей / +18 ошибок**. Заявленные `findings/repo-structure-2026-07-28.md` §12.2 «19 записей»
ни одним из подсчётов не получаются. Расхождение зафиксировано здесь и не является целью item'а:
P6.3 считает число САМ на своём дереве и не подгоняет его под §12.2.

Что именно приросло (RAG:— repo-grounded, диff множеств `(message, identifier, path)`):

| Файл | Прирост ошибок |
|:--|--:|
| `app/Services/Procurement/Tuning/EvalRunComparator.php` | 12 (6 записей) |
| `app/Console/Commands/Ai/EvalRunCommand.php` | 2 |
| `app/Console/Commands/Ai/EvalDiffCommand.php` | 1 |
| `app/Console/Commands/Ai/PromptsSuggestCommand.php` | 1 |
| `app/Services/Procurement/Prepare/RepresentationResolver.php` | 1 |

Все — `property.notFound` (11 из 12 в `EvalRunComparator`) плюс один `return.type`. То есть прирост
принадлежит ТОМУ ЖЕ классу, что и 84% baseline: `@property` на моделях и relation-chain.

### 2.5 Пути записей и фазы P1–P5

Каждая запись baseline привязана к абсолютному пути (`/var/www/html/app/…`). Переименования и
переезды фаз P1–P3 (домен `Llm`, каталог анализатора, раскладка DTO) делают эти пути
недействительными — при `reportUnmatchedIgnoredErrors` (дефолт `true` в phpstan 2.x) непопавшая
запись сама становится ошибкой. Значит baseline правится ВНУТРИ фаз P1–P3 как часть их переездов,
а P6 получает уже переписанные пути. Это coupling-факт, а не задача P6.

---

## 3. Позиционное сопоставление смет: где именно живёт

### 3.1 Правило объявлено докблоком и реализовано ключом

`EvalMetrics` докблок: «Сметы сопоставляются ПОЗИЦИОННО (индекс в списке `estimates`,
соответствующий возрастающему `ordinal`) — эвристика сопоставления по номеру/заголовку не входит в
Scope этого item'а (Pending Work при появлении многосметных эталонов)»
(RAG:— repo-grounded: `app/Services/Procurement/Extraction/EvalMetrics.php:22-24`).

Реализация — индекс списка как префикс ключа во ВСЕХ трёх flatten-методах:

| Метод | Ключ |
|:--|:--|
| `flattenRows()` | `"{$estimateIndex}:{$rowIndex}"` (`EvalMetrics.php:233-242`) |
| `flattenPassport()` | `$estimateIndex` (`:257-259`) |
| `flattenTotals()` → `flattenTotalLines()` | `$estimateIndex.'::'.normalize(label).'::'.kind` (`:300-303`, `:311-315`) |

Сопоставление gold ↔ предсказание нигде не выражено явно: обе стороны сплющиваются НЕЗАВИСИМО, и
пара образуется только тем, что префиксы ключей совпали. Сдвиг на одну смету расклеивает все три
измерения разом.

### 3.2 Единственный владелец правила и его цитирующий

`EstimateDiffReport` переиспользует `flattenRows()`/`flattenPassport()`/`flattenTotals()`/
`fieldsMatch()` как ОДИН носитель правил сопоставления (собственный докблок,
`EstimateDiffReport.php:18-23`), разбирает ключ обратно `splitRowKey()` (`:262-268`) и кладёт
позицию в `DiffEntry::$estimateIndex`. Отдельно `diffArithmetic()` спаривает сметы прямым
индексом цикла `for ($estimateIndex = 0; …)` (`:196-200`) — четвёртое место позиционной адресации.

`DiffEntry` докблок называет поле прямо: «estimateIndex: позиция сметы в списке `estimates` (та же
позиционная адресация, что `EvalMetrics`)»
(RAG:— repo-grounded: `app/Dto/Procurement/Ai/Diff/DiffEntry.php:11`).

### 3.3 Поля, на которых можно построить устойчивый ключ

`flattenPassport()` уже выкладывает и `estimate_number`, и `passport.address` в плоскую карту
(`EvalMetrics.php:261-262`, `:273`) — то есть оба поля эвристики `estimate_number → адрес →
позиция` доступны в той же форме данных, без нового входа.

### 3.4 Регрессионный якорь: весь существующий сюит односметный

`tests/Feature/Ai/EvalMetricsTest.php` строит вход хелпером `estimatesOf(array $rows, …)`,
возвращающим РОВНО одну смету (`:25`); все кейсы файла — односметные
(RAG:— repo-grounded: `grep -n "estimatesOf" tests/Feature/Ai/EvalMetricsTest.php`). Следствие: на
односметном входе новая эвристика обязана давать БАЙТ-В-БАЙТ те же числа, и существующие
ожидания тестов правиться не должны. Правка ожидания = изменение поведения на односметном входе,
то есть дефект реализации, а не «обновление теста».

### 3.5 Потребители метрики (кого затрагивает смена правила)

`EvalMetrics` инжектится в `EvalRunCommand` (`:65`) и в `ShadowAnalysisJob`
(RAG:— repo-grounded: `tests/Feature/Llm/ShadowDispatchTest.php:32` — `handle(..., app(EvalMetrics::class))`);
`EstimateDiffReport` — вход `estimate:eval:diff`, критика промтов (P7.4 EST-TRAIN) и, после этого
плана, экрана сравнения P5.4. Форма выхода `compare()` (`per_field`/`overall`/`row_coverage`/
`passport`/`totals`) читается всеми ими — менять её P6 не должен.

### 3.6 Куда классы уедут к моменту P6

По классификации `findings/extraction-boundary-2026-07-29.md` §3 `EvalMetrics` и
`EstimateDiffReport` входят в 16 единиц предметки и переезжают в каталог анализатора сметы (P2.4);
`Dto/Procurement/Ai/Diff/**` переезжает туда же в P3.5 (D31). Значит ТЗ P6 обязано называть пути
через конвенцию каталога типа (контракт P2.1), а не через сегодняшние.
