# research 03 — сейм шага/назначения: фактический периметр и четыре точки типонезависимого резолва

Слой 2 (`plan-protocol` §16). Авторский синтез захода `plan-design P2.5` 2026-07-30, снимающего
блокировку P2.5 (эскалация «конфликт правил», D89 исход «в»). Стоит на: замороженном контракте
`root/document-analyzer-contract.md` §4–§7 (вход), `findings/extraction-boundary-2026-07-29.md` §5
(предыдущая карта сейма, снята на `74f515b`), `findings/filament-dependant-select-2026-07-30.md`
(внешний факт Filament v5), фактах кода на `0d092ca`.

**Все числа ниже — на `0d092ca`** (последний коммит перед этим заходом; он документарный, код
идентичен состоянию после P2.4 `e06434c`). Команды воспроизведения — в §6.

---

## 1. Зачем этот документ

Предыдущая карта сейма (`findings/extraction-boundary-2026-07-29.md` §5) снята ДО фаз P1.5–P2.4 и
разошлась с фактом по трём осям: пути (домен `Llm`, каталог `Analyzers\Estimate`), числа
call-site'ов, состав участников. ТЗ P2.5 писалось по ней — отсюда и блокировка. Этот документ
заменяет её как вход P2.5 и отвечает на то, чего в ней не было вовсе: **как значение шага/назначения
превращается в объект-носитель там, где типа документа нет под рукой**.

---

## 2. Периметр правки — по факту, а не по перечню

| Класс | Число | Замечание |
|:--|:--|:--|
| файлы, упоминающие `PromptStep`/`LlmPurpose` в `app/` + `database/` | 31 | из них 2 — сами enum'ы (удаляются) |
| файлы, упоминающие их в `tests/` | 23 | полный перечень — §5 |
| ссылки `PromptStep::` | 88 | §3 Routing и контракт §4.5 называют 67 — снято на `74f515b`, до P1.5–P2.4 |
| ссылки `LlmPurpose::` | 116 | там же названо 105 |
| файлы, ломающиеся БЕЗ упоминания enum'а | 5 | `LlmCacheManager`, `CostReportCommand` (читают `$row->purpose->value` через каст), `PromptFactory` (дефолт `step`), `PromptForm` (контракт §7), `DocumentAnalyzerRegistry` (четыре `*For()`) |
| документы, чьё утверждение о носителе становится ложным | 8 | §4 |

Арифметика периметра кода: **36 затронутых файлов** (31 упоминающих + 5 ломающихся без упоминания),
из них **2 удаляются** и **34 правятся**; сверх них **6 новых** enum-единиц. Итого 40 файловых
операций в `app/` + `database/`, плюс 23 теста, плюс 7 документов в границах item'а (восьмой — у P7.3).

---

## 3. Четыре точки, где значение резолвится БЕЗ типа документа

Это была единственная нерешённая дыра в Pending Work P2.5 («реестр §5.2 типонезависимого резолва не
имеет; шестой метод = правка ЗАМОРОЖЕННОГО контракта»). Разбор показал: **шестой метод не нужен и
НЕ МОЖЕТ быть определён корректно.** Контракт §5.4 п.3 прямо разрешает ДВУМ анализаторам объявить
одно и то же строковое значение шага (они разведены осью `document_type` в ключе
`(document_type, key, version)`), поэтому «значение → носитель» без типа — отношение
неоднозначное ПО КОНТРАКТУ. Любой типонезависимый резолв либо угадывает первое совпадение, либо
лжёт. Отсюда четыре разных ответа на четыре разные ситуации:

| Точка | Факт кода | Ответ |
|:--|:--|:--|
| `app/Jobs/Procurement/ShadowAnalysisJob.php:96` — `LlmPurpose::from($this->purpose)` | job НЕСЁТ `$this->documentType` (`:65`, типизирован `AiDocumentType\|DocumentTypeScope` фазой P2.2) | `registry->purposeFor($this->documentType, $this->purpose)` — контракт §5.2 без правок; дыры нет вовсе, Pending Work её переоценил |
| каст `LlmCall::casts()['purpose']` (`app/Models/Llm/LlmCall.php:57`) | таблица `llm_calls` НЕ несёт `document_type` (миграция `2026_07_04_100026_create_llm_calls_table.php`) — модель физически не может знать носителя | каст СНИМАЕТСЯ; колонка остаётся строкой объединённого пространства. Оба читателя хотят ровно строку: `CostReportCommand:69` и `LlmCacheManager:237` (`$row->purpose->value` → `$row->purpose`); писатели отдают `->value` (`LlmCallRecorder:51`, `LlmCallFactory:22`) |
| каст `LlmRoute::casts()['purpose']` (`app/Models/Llm/LlmRoute.php:40`) | строка НЕСЁТ `document_type`, но ни один читатель в `app/` не пользуется enum-объектом (`grep -- "->purpose"` — три вхождения, все выше) | каст СНИМАЕТСЯ симметрично: типизированное чтение, когда понадобится, идёт через `purposeFor($route->document_type, $route->purpose)` — тип берётся из ТОЙ ЖЕ строки, а не угадывается |
| `app/Console/Commands/Ai/PromptsSuggestCommand.php:68` — `PromptStep::from($value)` по `--step=*` | шаги парсятся ДО резолва предмета, хотя предмет (`Analysis`) несёт `document_type` (колонка `prc_ai_analyses.document_type`, `Analysis.php:53`) | порядок инвертируется: сначала кейсы, затем шаги — `registry->promptStepFor($documentType, $value)`, где `$documentType` = `AiDocumentType::tryFrom($analysis->document_type) ?? DocumentTypeScope::Any` |

**Отвергнуто — шестой метод реестра `purposeFromValue(string)` / композиция поверх `purposesFor()`
по `AiDocumentType::cases()` ∪ `Any`** (собственное предложение Pending Work п.5): оба дают
«первое совпадение» на множестве, которое контракт §5.4 п.3 разрешил делать неоднозначным. Правка
замороженного §5.2 не понадобилась ни в одной из четырёх точек.

---

## 4. `PromptCritic` — три match-лесенки, которых не было ни в одной карте

`app/Services/Procurement/Tuning/PromptCritic.php` — четвёртая протечка предмета (контракт §9 назвал
`critique()` третьим call-site'ом сейма, но не его внутренности). Три приватных метода перечисляют
сметные кейсы поимённо: `subjectDocumentType():166`, `subjectAnalysisStep():175`,
`isCrossDocumentStep():188`. Неймспейс `App\Services\Procurement\Tuning` в список ядра §6.2
намеренно НЕ входит, то есть arch-тест P2.6 этого не поймает — но код не скомпилируется/упадёт
`UnhandledMatchError` после расщепления, и «дописать `default`» означало бы оставить лесенку.

Разрешается БЕЗ новых членов интерфейса:

- `subjectDocumentType()` + `isCrossDocumentStep()` → `$step->documentTypeScope()`: метод УЖЕ в
  замороженном интерфейсе (§4.1), ровно за этим и введён. Оба приватных метода исчезают.
- `subjectAnalysisStep()` → `instanceof CorePromptStep` + исчерпывающий `match` по четырём
  ядерным кейсам; всё остальное — `AnalysisStep::Extraction`.

**Инвариант, на котором стоит вторая строка (называется в ТЗ явно):** шаг, объявленный
анализатором, принадлежит стадии ИЗВЛЕЧЕНИЯ — единственной стадии, которой владеет тип. Скрининг
кросс-документен и КЛАССИФИЦИРУЕТ тип, то есть исполняется до того, как тип известен, поэтому
типовых шагов скрининга не бывает; `AnalysisStep` при этом ровно из двух кейсов
(`app/Enums/Procurement/AnalysisStep.php:12-13`).

**Отвергнут — четвёртый член интерфейса `PromptStep::analysisStep(): ?AnalysisStep`.** Правка
замороженного §4.1 ради ОДНОГО call-site'а ОДНОГО сервиса тюнинга, с налогом на enum каждого
будущего типа. Инвариант выше даёт тот же ответ бесплатно.

---

## 5. Паритет поведения: четыре места, где механический перенос молча меняет продукт

1. **Фильтр `document_type` в выборке промтов (контракт §4.2).** Сегодня
   `PromptReadRepository::activeForSteps()` фильтрует ТОЛЬКО по `step` — колонка `document_type`
   в выборке не участвует вовсе. После правки добавляется `document_type IN ($scope->value, 'any')`.
   На сегодняшнем корпусе это **no-op**: каждое значение шага встречается ровно в одном
   `document_type` (пять `extraction:*` — `estimate`, четыре ядерных — `any`; front-matter девяти
   файлов `resources/ai/prompts/*/0-*.md`). То есть гейт паритета композиции обязан быть зелёным
   ДО и ПОСЛЕ, а новый негативный тест («промт другого типа с тем же значением шага в композицию не
   попадает») до правки был бы неисполним — его субъекта в коде нет.
2. **Дефолтная температура (контракт §4.4).** `DEFAULT_TEMPERATURE_BY_PURPOSE`
   (`AiRouteResolver.php:28-33`) перечисляет ЧЕТЫРЕ назначения со значением `0.0`, остальные
   получают `0.0` неявным `?? 0.0` на `:96`. Значит после переноса `defaultTemperature()` отдаёт
   `0.0` у ВСЕХ восьми кейсов обоих enum'ов — «осмысленные» значения не изобретаются.
3. **Дефолт `step` у `PromptFactory` невалиден уже сегодня.**
   `database/factories/Llm/PromptFactory.php:24` кладёт `AnalysisStep::Screening->value`
   (= `'screening'`), а такого значения нет ни в `PromptStep`, ни в целевом `CorePromptStep`
   (`'screening:global'`/`'screening:classify'`). Сегодня это не ловится, потому что `step` не
   валидируется на записи вообще; после введения реестровой валидации (контракт §7) фабрика — путь
   записи, и дефолт обязан стать реальным ядерным шагом. Это ФИКС существующего дефекта, а не
   следствие расщепления, и он назван, а не подогнан.
4. **`compose(Global)` даёт `[Global, Global]`** — уже названо контрактом §9 п.5: дубль глушится
   `groupBy('key')`, новая форма даёт `[Global]`, вывод побайтово тот же.

## 5а. Полный перечень тестов периметра (23)

`tests/Feature/Ai/{AiRoutingSeederTest,BenchFormatsTest,CostReportTest,DocumentTypeAxisTest,EvalRunTest,PromptCompositionTest,PromptSeederTest,PromptSuggestTest,ScreeningEvalTest}.php` ·
`tests/Feature/Llm/{AiRouteResolverTest,LlmCacheManagerTest,PromptComposerTest,ShadowDispatchTest}.php` ·
`tests/Feature/Observability/PulseCardsTest.php` ·
`tests/Feature/Procurement/Ai/{DocumentAnalyzerRegistryTest,OperatorFeedbackLoopTest}.php` ·
`tests/Feature/Procurement/Analyzers/Estimate/{ExtractionOrchestratorTest,RerunRangeTest,WindowedExtractionTest}.php` ·
`tests/Feature/Procurement/Screening/{ScreeningBranchingTest,ScreeningProcessorTest}.php` ·
`tests/Unit/Llm/{LlmCallRecorderTest,ReaskPolicyTest}.php`.

Два из них ломаются структурно, а не по имени: `PromptSeederTest:12,14,27` и
`PromptCompositionTest:27,41` перебирают `PromptStep::cases()`, чего у интерфейса нет — перебор
становится объединением `CorePromptStep::cases()` + `EstimatePromptStep::cases()` (9 значений,
столько же, сколько файлов промтов).

---

## 6. Документарный периметр и закрыватели

`grep -rn "PromptStep\|LlmPurpose" docs/ resources/ai/prompts/README.md .ai/ .claude/` на `0d092ca`
— восемь мест в семи файлах плюс зеркало в хабе:

| Файл:строка | Что утверждает | Закрыватель |
|:--|:--|:--|
| `resources/ai/prompts/README.md:8,35` | «один промт для одного шага `PromptStep` (`app/Enums/Procurement/PromptStep.php`)», «`step` ОБЯЗАН быть одним из 8 значений `PromptStep::cases()`» | **P2.5** — прямая инструкция, становится неисполнимой (пути нет, `::cases()` у интерфейса нет, и значений 9, а не 8) |
| `docs/03_Dev/ai/prompts.md:50` | «`App\Enums\Procurement\PromptStep` — 8 значений» | **P2.5** |
| `docs/03_Dev/llm-providers.md:54` | «свой промт-шаг (`PromptStep::ExtractionDiscovery`)» | **P2.5** |
| `docs/03_Dev/recipes.md:38` | «`PromptStep`-кейс + файлы в `resources/ai/prompts/`» | **P2.5** |
| `.ai/guidelines/glossary.md:29` | «`PromptStep` (enum) — шаг композиции… не путать с `AnalysisStep`… `LlmPurpose`» | **P2.5** (источник) |
| `.claude/skills/prompt-engineering-agelto/SKILL.md:3,37,46` | «8 значений `PromptStep` (`app/Enums/Procurement/PromptStep.php`)» | **P2.5** — dev-раннбук, который читают исполнители P3/P6 |
| `docs/specs/v2/08-estimate-extraction.md:387,389` | «`PromptStep::PromptCritic`… `LlmPurpose::PromptCritic`» | **P2.5** (файл уже в его Scope Included) |
| `docs/specs/v2/07-carryover-from-v1.md:107` | «`PromptStep` — 8 значений» | **P7.3** (D55/D65 — шесть файлов `docs/specs/v2/**` кроме `08`) |
| `CLAUDE.md:60` | «`PromptStep` (композиция промта) ≠ `AnalysisStep` ≠ `LlmPurpose`» | **P7.4** (D60/D64 — единственный закрыватель дрейфа хаба) |

Правило разграничения, по которому это разложено: **чинит тот item, чьё изменение сделало
утверждение ложным** — но только там, где закрывателя не назначил более ранний D#. Классы правки у
P3.6 (имена команд, пути промтов, имена таблиц), P7.3 (спека целиком) и P7.4 (хаб) остаются их
собственными; двойное владение ФАЙЛОМ при разных классах правки — уже принятая практика плана
(`phases/P3.md` Validation 3а: «двойного владения нет, гейт просто застаёт их чистыми»). P3.6 сам
отдаёт этот класс наверх: его Implementation Rule 6 велит эскалировать, если правка задевает
«значения enum'ов» — «это предмет P1.7/P2.5».

---

## 7. Проверка чистоты по arch-тесту P2.6 (сделана до написания ТЗ)

Ни один файл ядра (восемь неймспейсов §6.2) после предложенной формы не импортирует
`App\Enums\Procurement\Analyzers\Estimate\**`:

- `PromptComposer`, `PromptReadRepository`, `AiRouteResolver`, `LlmCallRecorder`, `ShadowDispatch`,
  `LlmCacheManager` (`App\Services\Llm`, `App\Repositories\Llm`) — только интерфейсы + ядерные
  enum'ы;
- `DocumentAnalyzerRegistry` (`App\Services\Procurement\Contracts`) — инвариант §5.4 п. 2 сравнивает
  значения анализатора с ядерными, получая первые ЧЕРЕЗ `$analyzer->promptSteps()`, то есть без
  импорта типового enum'а;
- `ScreeningProcessor`/`ScreeningOutcomeHandler` (`App\Services\Procurement\Screening`),
  `ShadowAnalysisJob` (`App\Jobs\Procurement`) — ядерные кейсы;
- типовой enum импортируют только `FormatBenchRunner` (`Bench`), `EvalRunCommand`/
  `DatasetExportCommand` (`Console\Commands\Ai`), сам анализатор и `database/**` — все ВНЕ списка
  §6.2 по его собственной оговорке.

То есть P2.5 в предложенной форме не оставляет P2.6 красного arch-теста.

---

## 8. Команды воспроизведения (все — на `0d092ca`)

```bash
git rev-parse --short HEAD                                              # 0d092ca
grep -rn "PromptStep::"  app/ database/ tests/ | grep -c ""             # 88
grep -rn "LlmPurpose::"  app/ database/ tests/ | grep -c ""             # 116
grep -rl "PromptStep\|LlmPurpose" app/ database/ | grep -c ""           # 31
grep -rl "PromptStep\|LlmPurpose" tests/       | grep -c ""             # 23
grep -c "case " app/Enums/Procurement/PromptStep.php                    # 9
grep -c "case " app/Enums/Procurement/LlmPurpose.php                    # 8
grep -rn -- "->purpose" app/ database/                                  # 5 строк, из них 2 через каст
grep -rn "PromptStep::cases\|LlmPurpose::cases" app/ database/ tests/   # 4 (два теста)
grep -rn "document_type" database/migrations/*create_llm_calls_table.php # пусто
for f in resources/ai/prompts/*/*.md; do sed -n 2p "$f"; done            # 5×estimate, 4×any
```
