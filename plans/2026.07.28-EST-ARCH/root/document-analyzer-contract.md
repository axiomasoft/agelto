# document-analyzer-contract — контракт `DocumentAnalyzer`, реестр типов и конвенция каталога типа

**Контракт P2.1 плана `2026.07.28-EST-ARCH`.** Замораживается утверждением владельца (строка
в `plan.md` → `## 6. Update Log`). После заморозки правится ТОЛЬКО новой D-записью со ссылкой
`supersedes` — правка «по ходу» исполнения любой фазы запрещена (`plan-protocol` §11).

**Что этот документ решает:** форму, в которой будет описан КАЖДЫЙ тип документа. Семь решений:
состав интерфейса `DocumentAnalyzer` · замена флага `has_extractor` (D16) · сводка результата
(D27) · носитель шага композиции и назначения вызова · механизм регистрации и поведение реестра
на типе без анализатора · конвенция каталога типа и список неймспейсов ядра для arch-теста P2.6 ·
место валидации `step`/`purpose` по реестру. Вход для P2.2–P2.6, P3 (сервисный слой), P5
(витрина), P7 (документация).

**Дополнения после заморозки** (каждое — своей D-записью, исходные пункты не переписаны):
2026-07-30 — §1.5 (опциональные capability-интерфейсы типа, D106), §6.4 (структурная проверка
ответа на стороне DTO, D107), уточнение периметра в §6.3 (D105). Основание — находка item'а P2.6:
свойство §6.3 нарушали два файла ядра, то есть правило отбора §1.1 было применено не до конца.

**Якорь фактов:** весь код читан на `f4d9ab9` (ветка `main`, состояние после закрытия фазы P1).
Все `файл:строка` ниже сняты на этом коммите. Расхождения факта с `findings`/`research`/
контрактами P1 названы в §9, а не подогнаны.

**Правило чтения таблиц методов.** Колонка «Выведен из (call-site ядра)» несёт `файл:строка`
существующего кода, который метод ЗАМЕНЯЕТ или из потребности которого выведен. Метод без такой
привязки в контракт не входит (Implementation Rule 1 item'а) — единственное поимённое исключение
разобрано в §1.4.

---

## 1. Решение 1 — состав интерфейса `DocumentAnalyzer`

### 1.1. Правило отбора

В контракт входит РОВНО то, что вызывает ЯДРО. Персистер, ститчер, валидатор, метрика, промты,
DTO ответа и оркестратор анализатора сметы вызываются ЕГО ЖЕ кодом внутри его каталога (§6) — в
интерфейс они не выносятся. Широкий интерфейс здесь дороже узкого, потому что его реализует
КАЖДЫЙ будущий тип: цена ошибки умножается на число типов, а не складывается.

### 1.2. Интерфейс

Неймспейс: `App\Services\Procurement\Contracts\DocumentAnalyzer` (каталог существует —
`app/Services/Procurement/Contracts/`).

| Метод | Возврат | Выведен из (call-site ядра) | Что заменяет |
|:--|:--|:--|:--|
| `hasExtraction()` | `bool` | `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:120` | ветку `AiDocumentType::Estimate => markScreened(dispatchExtraction: true)` в `match` по типу |
| `dispatchExtraction()` | `void` | `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:135` и `app/Services/Procurement/Extraction/RerunAnalysisAction.php:62` | обе жёсткие ссылки `EstimateExtractionJob::dispatch($document->id)->afterCommit()` |
| `promptSteps()` | `list<PromptStep>` | `database/seeders/PromptSeeder.php:111` (`PromptStep::from()`) и `app/Filament/Resources/Procurement/Ai/Prompts/Schemas/PromptForm.php:34` (свободный `Textarea`) | единственный источник допустимых значений `llm_prompts.step` — общий enum |
| `purposes()` | `list<LlmPurpose>` | `app/Filament/Resources/Procurement/Ai/LlmRoutes/Schemas/AiRouteForm.php:30` (`Select::options(LlmPurpose::class)`) | перечисление кейсов общего enum'а как множества допустимых `llm_routes.purpose` |
| `summarize()` | `AnalysisSummary` | `app/Models/Procurement/Ai/Analysis.php:89` — см. §1.4 | чтение витриной предметных таблиц результата напрямую |

Сигнатуры:

```php
public function hasExtraction(): bool;
public function dispatchExtraction(int $documentId): void;
/** @return list<PromptStep> */
public function promptSteps(): array;
/** @return list<LlmPurpose> */
public function purposes(): array;
public function summarize(Analysis $analysis): AnalysisSummary;
```

### 1.3. Инварианты интерфейса (часть контракта, не комментарий)

1. `dispatchExtraction()` на анализаторе с `hasExtraction() === false` бросает `LogicException`.
   Ядро обязано спросить `hasExtraction()` раньше — `RerunAnalysisAction.php:62` попадает сюда
   только при `AnalysisStep::Extraction`, то есть по анализу, который извлечение уже проходил.
2. `dispatchExtraction()` диспатчит СВОЙ job и ничего не возвращает: контур checkpoint-requeue /
   lease / `ShouldBeUniqueUntilProcessing` (D39, D28) остаётся ВНУТРИ анализатора и ядру не виден.
3. `promptSteps()`/`purposes()` — только СОБСТВЕННЫЕ значения типа; кросс-документные значения
   анализатор не перечисляет (они принадлежат ядру, §4).
4. Метод `documentType()` в интерфейс НЕ входит: ключ реестра и есть тип (§5), спрашивать
   анализатор о том, что ядро уже знает, — лишняя поверхность.

### 1.4. Единственное исключение из правила отбора — `summarize()`

`summarize()` не заменяет существующий call-site ядра: витрины прогонов сегодня нет, её вводит
P5.3. Метод стоит в контракте по прямому мандату **D27** («витрина СПРАШИВАЕТ анализатор:
контракт `DocumentAnalyzer` несёт метод сводки результата, возвращающий DTO») и по Implementation
Rule 8 item'а. Якорь вывода — `app/Models/Procurement/Ai/Analysis.php:89`: единственный
кросс-типовой носитель результата сегодня — `Analysis::$result` (`AsCollection`), и он не несёт ни
сумм, ни числа позиций, ни ссылки на предметный экран; всё это лежит в `prc_estimate_*`.
Исключение названо ЗДЕСЬ, а не спрятано: без него P5 пришлось бы расфризивать контракт, на котором
к тому моменту стоят P3 и P7 (обоснование самого D27).

**Отвергнутая альтернатива — вынести в интерфейс «шаги, промты, DTO ответа, job, персистер,
валидатор, метрику» по `research/01-target-architecture.md` §2.** Ни у одного из них нет call-site'а
ядра: их вызывает код самого анализатора внутри своего каталога.

**Отвергнутая альтернатива — один метод `extract(int $documentId): bool` вместо пары
`hasExtraction()`/`dispatchExtraction()`.** Ядру нужен ответ ДО диспатча и НЕЗАВИСИМО от него:
`ScreeningOutcomeHandler.php:130` подписывает им событие, `:132` гейтит `ai.extraction.enabled`,
`:144` терминирует прогон (`runs->markSucceeded()`) именно на ветке «извлечения не будет».
Слитый метод вернул бы этот ответ уже после диспатча.

### 1.5. Опциональные capability-интерфейсы типа (дополнение 2026-07-30, D106)

**Правка ЗАМОРОЖЕННОГО §1 по D106** (`supersedes` заморозки §1 от 2026-07-29): §1.2–§1.4 не
меняются ни в одном пункте, добавляется этот подраздел. Основание — находка P2.6: правило отбора
§1.1 («в контракт входит РОВНО то, что вызывает ЯДРО») при написании контракта было применено не
до конца, один call-site ядра остался без члена контракта (`findings/core-arch-gate-2026-07-30.md`
§1, `findings/core-callsite-seams-2026-07-30.md` §1.2).

Не всякая потребность ядра осмысленна для КАЖДОГО типа. `summarize()` осмысленен для любого — у
любого анализа есть результат, который надо показать. «Сравни два результата этого типа» —
только у типа, чей результат структурно сравним. Такие потребности живут ОТДЕЛЬНЫМИ узкими
интерфейсами РЯДОМ с `DocumentAnalyzer`, а не его членами: ядро спрашивает `instanceof`, а тип,
которому предъявить нечего, не реализует ничего. Форма взята из действующей идиомы этого
репозитория — `App\Services\Llm\Contracts\{LlmProvider, SupportsFileUpload,
SupportsContextCaching}` (RAG:— repo-grounded: `ls app/Services/Llm/Contracts/`; та же форма
закреплена в блокирующем правиле ревью `.claude/rules/agelto-reviewer-overrides.md`).

| Интерфейс | Метод | Выведен из (call-site ядра) | Что заменяет |
|:--|:--|:--|:--|
| `App\Services\Procurement\Contracts\SupportsResultComparison` | `compareResults()` | `app/Jobs/Procurement/ShadowAnalysisJob.php:188` (`compareWithPrimary()`), инъекция `:78`/`:96`, разбор payload'а `:190-191` | инъекцию сметной `EvalMetrics` в ядерный job И знание ядра о форме `result.estimates[]` |

Сигнатура:

```php
/**
 * @param  array<string, mixed>  $primaryResult
 * @param  array<string, mixed>  $shadowResult
 * @return array<string, mixed>|null
 */
public function compareResults(array $primaryResult, array $shadowResult): ?array;
```

Инварианты (часть контракта, не комментарий):

1. **Ядро НЕ вправе требовать capability-интерфейс.** Отсутствие реализации — ШТАТНАЯ ветка
   (`null`), не ошибка: `instanceof` вернул `false` → сравнения у типа нет, и это нормально. Тем
   этот интерфейс и отличается от членов §1.2, которые обязателен реализовать каждый тип.
2. **Метод получает СЫРЫЕ payload'ы `Analysis::$result`, а не пред-извлечённые поля.** Разбор
   формы результата — знание ТИПА: ядро, достающее `['estimates']`, знает про смету ровно так же,
   как ядро, импортирующее `EvalMetrics`. Ключ payload'а за интерфейс уходит вместе с метрикой.
3. **Возврат — `array<string, mixed>|null`, не DTO.** У сравнения нет типизированного потребителя:
   оно пишется в jsonb `result.comparison` как есть — та же форма, которую уже называет
   `app/Dto/Procurement/Ai/Diff/DiffReport.php:11` («результат `EvalMetrics::compare()` как есть,
   без переупаковки»). DTO под запись в jsonb был бы типом ради типа. Отличие от §3
   (`AnalysisSummary` — DTO обязателен): там потребитель — витрина P5, читающая поля по именам.
4. **Новый capability-интерфейс заводится ТОЛЬКО с call-site'ом ядра** (правило §1.1 действует и
   здесь) **и ТОЛЬКО D-записью:** набор опциональных интерфейсов — такая же поверхность типа, как
   сам `DocumentAnalyzer`, и молча расти не имеет права.
5. **`SupportsResultComparison` принадлежит периметру ядра** (`App\Services\Procurement\Contracts`
   — п. 4 списка §6.2) и потому НЕ вправе упоминать смету: `array` на входе и выходе — намеренно
   бесструктурный контракт, любая типизация полей сметы здесь вернула бы предмет в ядро.

**Отвергнута — обязательный метод сравнения в `DocumentAnalyzer` рядом с `summarize()`.** Читать
проще (одна поверхность), но реализовать обязан КАЖДЫЙ будущий тип, включая те, у которых
сравнивать нечего — прямо против §1.1 («цена ошибки умножается на число типов, а не складывается»).
Тип, вынужденный вернуть `null` из обязательного метода, — это опциональность, записанная в самом
неудобном месте.

**Отвергнуто — обобщённое сравнение в ядре** (ядро сравнивает `result` двух анализов
поле-агностично). Потеряло бы field-map и допуски `EvalMetrics` (`FIELD_MAP`,
`ROW_NUMERIC_FIELDS`, `PASSPORT_FIELD_MAP`, `TOTALS_FIELD_MAP`), то есть метрика качества
изменилась бы МОЛЧА — а по ней считается F1 всего контура тюнинга.

**Отвергнуто — убрать сравнение из shadow-контура** (`result.comparison` всегда `null`). Ядро
стало бы чистым бесплатно, но смысл теневого прогона падает до «просто второй вызов»: сравнение
качества/стоимости кандидата с боевым и есть его назначение (`ShadowAnalysisJob` докблок `:39-41`).

---

## 2. Решение 2 — замена флага `has_extractor` (D16)

Флаг жил колонкой `prc_document_types.has_extractor`; таблица снесена P1.4 (`grep -rn
"prc_document_types\|has_extractor" app/ database/` на `f4d9ab9` — пустой вывод). Факт «есть ли у
типа извлечение» переносится в код без остатка:

| Вопрос | Кто отвечает |
|:--|:--|
| у типа есть анализатор? | `DocumentAnalyzerRegistry::for($type) !== null` |
| у анализатора есть извлечение? | `DocumentAnalyzer::hasExtraction()` |
| что именно диспатчится? | `DocumentAnalyzer::dispatchExtraction($documentId)` — ядро НЕ называет класс job'а |

Целевая форма `ScreeningOutcomeHandler::branchByType()` (реализует P2.3, здесь — контракт формы):

```php
if ($documentType === null || $documentType === AiDocumentType::Unknown) {
    $this->ignore($document, $analysis, reason: 'unknown');
    return;
}

$analyzer = $this->analyzers->for($documentType);
$this->markScreened($document, $analysis, analyzer: $analyzer?->hasExtraction() === true ? $analyzer : null);
```

Поведенческий паритет с `:117-123`: тип без анализатора и тип с анализатором без извлечения дают
одну и ту же ветку `screened` без диспатча — ровно сегодняшний `default`.

Строка `outcome` события (`:130`) перестаёт быть литералом `'estimate'` и становится
`$documentType->value`; отдельного метода интерфейса для неё не нужно — ядро уже держит тип.

**Рубильник `config('ai.extraction.enabled')` (`:132`) остаётся ГЛОБАЛЬНЫМ и ЯДЕРНЫМ** — он не
уезжает в анализатор и не становится по-типовым. Это аварийный стоп-кран всего контура извлечения
(деньги, квоты провайдера), и его смысл «выключить извлечение целиком» не зависит от типа. Ядро
гейтит им диспатч ПОСЛЕ ответа `hasExtraction()`, порядок сохраняется байт-в-байт. Названо здесь,
чтобы P2.3 не приняла его за восьмую точку хардкода и не начала растаскивать по анализаторам.

**Отвергнутая альтернатива — держать `bool $hasExtraction` вторым значением в строке
регистрации (§5).** Два носителя одного факта: анализатор всё равно знает про своё извлечение, а
конфиг может с ним разъехаться молча — тот же класс дефекта, который D16 и снимал.

---

## 3. Решение 3 — сводка результата (D27)

`App\Services\Procurement\Contracts\Dto\AnalysisSummary` — плоский DTO, читаемый витриной P5.3
без знания о `prc_estimate_*`:

```php
final readonly class AnalysisSummary
{
    /** @param list<SummaryMetric> $metrics */
    public function __construct(
        public string $title,
        public array $metrics,
        public ?string $detailRoute = null,
        public ?array $detailRouteParameters = null,
    ) {}
}

final readonly class SummaryMetric
{
    public function __construct(
        public string $label,
        public string|int|float $value,
        public ?string $unit = null,
    ) {}
}
```

- `title` — человекочитаемая шапка строки витрины («3 сметы, 412 позиций»); анализатор собирает её
  сам.
- `metrics` — плоский список пар «подпись → значение»; вложенности нет намеренно, иначе витрина
  начнёт разбирать структуру и снова узнает про предмет.
- **Денежные величины кладутся в `value` СТРОКОЙ** (десятичное представление как есть), не `float`:
  `prc_estimates.total_amount` — `decimal:2` (`app/Models/Procurement/Estimate/Estimate.php:76`), и
  прогон через `float` даёт классическую потерю копеек на экране, где показывают деньги. Правило
  общее для любого будущего типа, а не оговорка про смету.
- `detailRoute`/`detailRouteParameters` — ИМЯ маршрута и его параметры, а не готовый URL: генерацию
  ссылки делает презентационный слой P5. `null` — предметного экрана у типа нет.

**Запрещено (Implementation Rule 8):** возвращать HTML, Blade-разметку, Filament-компоненты или
`HtmlString`. Анализатор отдаёт данные, презентация остаётся в P5.

**Consequences (уже приняты D27):** фильтрация и сортировка витрины по содержимому результата на
уровне SQL недоступна — сводка считается кодом; P5.3 обязана это учесть при проектировании таблицы
прогонов.

**Отвергнутая альтернатива — вернуть `array<string, mixed>`.** Витрина начала бы угадывать ключи, а
опечатка в ключе давала бы пустую ячейку вместо ошибки — тот же молчаливый фолбэк, который план
вычищает из маршрутизации.

**Отвергнутая альтернатива — вернуть готовый URL строкой.** Анализатор получил бы зависимость от
роутера и от того, куда P5 положит экран; при переносе экрана ломался бы предметный слой, а не
презентационный.

---

## 4. Решение 4 — носитель шага композиции и назначения вызова

### 4.1. Форма носителя: интерфейс, реализуемый обоими enum'ами

`App\Enums\Llm\PromptStep` и `App\Enums\Llm\LlmPurpose` становятся ИНТЕРФЕЙСАМИ; кейсы разъезжаются
по двум enum'ам, реализующим их (состав — по `root/document-type-axis.md` §5):

| Носитель | Ядро (кросс-документное) | Анализатор сметы |
|:--|:--|:--|
| `PromptStep` | `App\Enums\Llm\CorePromptStep`: `Global`, `ScreeningGlobal`, `ScreeningClassify`, `PromptCritic` | `App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep`: `ExtractionGlobal`, `ExtractionDiscovery`, `ExtractionPassport`, `ExtractionRows`, `ExtractionTotals` |
| `LlmPurpose` | `App\Enums\Llm\CoreLlmPurpose`: `Screening`, `Eval`, `Shadow`, `PromptCritic` | `App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose`: `Discovery`, `ExtractPassport`, `ExtractRows`, `ExtractTotals` |

Enum'ы типа стоят в `App\Enums\Procurement\**`, а не в `App\Enums\Llm\**`, потому что владеет ими
закупочный домен (смета — документ закупки), а интерфейс, который они реализуют, принадлежит
транспорту. Это же держит `App\Enums\Llm` чистым ядром — он стоит п. 5 в списке §6.2.

```php
namespace App\Enums\Llm;

interface PromptStep
{
    public string $value { get; }

    /** @return list<PromptStep> Цепочка композиции: global → step-global → сам шаг. */
    public function compositionChain(): array;

    public function documentTypeScope(): AiDocumentType|DocumentTypeScope;
}

interface LlmPurpose
{
    public string $value { get; }

    public function defaultTemperature(): float;
}
```

**Механика проверена на боевом рантайме проекта, а не предположена:** PHP 8.4.23 (`docker exec
agelto_app php -r …`) — abstract property `public string $value { get; }` в интерфейсе
удовлетворяется backed-enum'ом без единой строки кода в enum'е, `instanceof` истинен для обоих
enum'ов. Строковые ЗНАЧЕНИЯ шагов и назначений при расщеплении не меняются (`root/
document-type-axis.md` §5) — миграции данных и пересева промтов у P2.5 нет.

### 4.2. Что встаёт в сигнатуры

| Сигнатура | Было | Стало |
|:--|:--|:--|
| `PromptComposer::compose()` — `app/Services/Llm/PromptComposer.php:30` | `compose(PromptStep $step, array $schemaClasses = [])`, где `PromptStep` — enum | та же арность; `PromptStep` — интерфейс. Тип документа НЕ добавляется параметром: его несёт сам шаг (`$step->documentTypeScope()`) |
| `PromptReadRepository::activeForSteps()` — `app/Repositories/Llm/PromptReadRepository.php:25` | `activeForSteps(array $steps)` | `activeForSteps(array $steps, AiDocumentType\|DocumentTypeScope $documentType)`; `$stepValues` собираются тем же `array_map(fn (PromptStep $s) => $s->value, …)` |
| `AiRouteResolver::{resolve,candidatesFor,shadowRoute}()` — `app/Services/Llm/AiRouteResolver.php:48` | `(string $documentType, LlmPurpose $purpose)` | `(AiDocumentType\|DocumentTypeScope $documentType, LlmPurpose $purpose)`, `LlmPurpose` — интерфейс (типизацию первого аргумента исполняет P2.2) |
| `PromptCritic::critique()` — `app/Services/Procurement/Tuning/PromptCritic.php:57` | `critique(PromptStep $step, …)` — enum | `PromptStep` — интерфейс; ТРЕТИЙ call-site сейма, в `findings` не названный (§9) |

Выборка промтов после составного ключа `(document_type, key, version)`: `activeForSteps()` дополняет
`whereIn('step', …)` условием `document_type IN ($documentType->value, 'any')` — ровно тот приём,
который уже работает у маршрутов (`app/Repositories/Llm/LlmRouteReadRepository.php:32`). Единый
приём на обе таблицы, а не второй способ выразить то же самое.

### 4.3. Кто задаёт цепочку композиции

**Сам шаг** (`compositionChain()`), не `PromptComposer`. Match-лесенка
`PromptComposer::stepsForComposition()` (`app/Services/Llm/PromptComposer.php:63-75`) —
УДАЛЯЕТСЯ: именно она сегодня заставляет ядро перечислять сметные кейсы.

| Шаг | Цепочка |
|:--|:--|
| `CorePromptStep::Global` | `[Global]` |
| `CorePromptStep::ScreeningGlobal` | `[Global, ScreeningGlobal]` |
| `CorePromptStep::ScreeningClassify` | `[Global, ScreeningGlobal, ScreeningClassify]` |
| `CorePromptStep::PromptCritic` | `[Global, PromptCritic]` |
| `EstimatePromptStep::ExtractionGlobal` | `[CorePromptStep::Global, ExtractionGlobal]` |
| `EstimatePromptStep::{Discovery,Passport,Rows,Totals}` | `[CorePromptStep::Global, ExtractionGlobal, сам шаг]` |

Паритет с сегодняшним поведением полный, с одной названной поправкой: сегодня
`compose(PromptStep::Global)` уходит в ветку `default` и даёт `[Global, Global]` — дубль, который
глушится `groupBy('key')` в `PromptReadRepository:33`. Новая форма даёт `[Global]`; вывод
`activeForSteps()` побайтово тот же, порядок задаёт `array_search` по первому вхождению
(`PromptReadRepository:38`).

### 4.4. Дефолтная температура уезжает на назначение

`AiRouteResolver::DEFAULT_TEMPERATURE_BY_PURPOSE` (`app/Services/Llm/AiRouteResolver.php:28-33`,
читается на `:96`) перечисляет ТРИ сметных назначения (`extract_passport`, `extract_rows`,
`extract_totals`) внутри ядра. Это восьмая точка протечки предмета, не попавшая ни в семь литералов
`'estimate'`, ни в `findings` (§9). Константа снимается, значение отдаёт
`LlmPurpose::defaultTemperature()`; `:96` читается как `$options['temperature'] ??
$purpose->defaultTemperature()`.

**Паритет при переносе обязателен.** Константа перечисляет ЧЕТЫРЕ назначения (`screening`,
`extract_passport`, `extract_rows`, `extract_totals`); остальные получают `0.0` неявным `?? 0.0`
на `:96`. После переноса КАЖДЫЙ кейс обоих enum'ов объявляет значение явно, и у четырёх сегодня
неперечисленных (`eval`, `shadow`, `prompt_critic`, `discovery`) это ровно `0.0` — не «на
усмотрение P2.5». Иначе механический перенос тихо поменял бы температуру боевых вызовов.

### 4.5. Отвергнутые альтернативы

**Отвергнута — «шаг остаётся строкой» (разбор цены обязателен по Implementation Rule 4).** Потеря
compile-time-проверки уже принята для БД (D7), но в PHP-сигнатурах она не даровая: `compose()`,
`activeForSteps()`, `critique()` и оба `ChunkSource` типизированы на носитель, и опечатка
`'extraction:rowz'` доехала бы до выборки промтов, вернув пустую композицию вместо ошибки —
молчаливый фолбэк ровно того сорта, что план вычищает из маршрутизации. Цена интерфейса взамен —
потеря `match`-исчерпаемости и `::cases()`; она компенсируется реестром (§7), который и есть новый
источник полного множества.

**Отвергнута — оставить ОДИН общий enum и не расщеплять.** Прямое противоречие D7: второй тип
раздувает общий enum и вынуждает править его потребителей.

**Отвергнута — назвать общим именем `PromptStep`/`LlmPurpose` ЯДЕРНЫЕ enum'ы, а интерфейсам дать
новые имена** (минимальная поверхность правки — 172 case-ссылки остались бы нетронутыми, изменились
бы только ~10 type-hint'ов). Критерий минимальной поверхности `root/naming-canon.md` перебит более
сильным аргументом: enum из четырёх кейсов с именем `PromptStep` читается как исчерпывающий, и
следующий читатель добавит сметный шаг в него — то есть имя само воспроизводит дефект, который
расщепление снимает. Цена названа: 172 case-ссылки (`PromptStep::` 67 + `LlmPurpose::` 105,
`findings/extraction-boundary-2026-07-29.md` §5) переезжают на `CorePromptStep::`/`CoreLlmPurpose::`
— но эти же 172 call-site'а уже объявлены периметром P2.5 в `plan.md` §3 Routing, то есть цена
внутри заявленного периметра, а не сверх него.

**Отвергнута — держать цепочку композиции в `config/ai.php`.** Данные о композиции разъехались бы с
кейсами enum'а, а `config:cache` заморозил бы несогласованность до следующего деплоя.

---

## 5. Решение 5 — механизм регистрации и поведение реестра

### 5.1. «Одна строка регистрации» (D14) — буквально одна строка конфига

`config/ai.php` получает ключ `analyzers`:

```php
'analyzers' => [
    AiDocumentType::Estimate->value => EstimateAnalyzer::class,
],
```

Значения — ИМЕНА КЛАССОВ строками, замыканий нет: массив обязан переживать `php artisan
config:cache` (скилл `config-driven-abstraction`, правило 2 — замыкание в конфиге ломает кэш
конфига молча). Инстанс резолвит контейнер, поэтому конструкторная инъекция анализатора работает
как у любого сервиса.

Приёмка D14 проверяется диффом буквально: добавление типа = одна строка здесь + каталог типа (§6).

### 5.2. Реестр

`App\Services\Procurement\Contracts\DocumentAnalyzerRegistry` — `final readonly`, неизменяемый,
собирается из конфига при первом резолве и живёт синглтоном; мутации после сборки нет.

| Метод | Возврат | Выведен из (call-site ядра) |
|:--|:--|:--|
| `for()` | `?DocumentAnalyzer` | `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:119` — `match` по типу |
| `promptStepsFor()` | `list<PromptStep>` | `app/Filament/Resources/Procurement/Ai/Prompts/Schemas/PromptForm.php:34` — свободный `Textarea` вместо перечня |
| `promptStepFor()` | `PromptStep` (бросает) | `database/seeders/PromptSeeder.php:111` — `PromptStep::from()` |
| `purposesFor()` | `list<LlmPurpose>` | `app/Filament/Resources/Procurement/Ai/LlmRoutes/Schemas/AiRouteForm.php:30` — `Select::options(LlmPurpose::class)` |
| `purposeFor()` | `LlmPurpose` (бросает) | `database/seeders/AiRoutingSeeder.php:52` — `updateOrCreate` без проверки `purpose` |

`*For()`-методы принимают `AiDocumentType|DocumentTypeScope`: для `DocumentTypeScope::Any` отдают
только ядерные значения, для конкретного типа — ядерные ∪ собственные значения его анализатора.

### 5.3. Тип без анализатора и `AiDocumentType::Unknown`

- `for()` на типе без анализатора возвращает `null` — реестр остаётся ЧИСТЫМ поиском и никаких
  решений не принимает.
- Поведение «неизвестный тип → `ignored`» остаётся **в ЯДРЕ** (`ScreeningOutcomeHandler`, §2), а не
  переезжает в реестр: причина ветки пишется в `analysis.result.branch_reason` (`:165`) и является
  фактом ветвления скрининга, а не фактом реестра.
- `AiDocumentType::Unknown` зарегистрировать НЕЛЬЗЯ: сборка реестра падает на такой строке конфига
  явной ошибкой. Заводить «анализатор неизвестного типа» ради единообразия запрещено
  (Implementation Rule 3) — это вернуло бы ветвление по типу в ядро через чёрный ход.

### 5.4. Инварианты сборки реестра

Реестр собирается один раз (первый резолв из контейнера, синглтон) и на сборке ОБЯЗАН проверить
три условия; нарушение — исключение на месте, не отложенный дефект:

1. **Ключ — значение `AiDocumentType`, и это не `unknown`** (§5.3).
2. **Значения шагов анализатора НЕ пересекаются с ядерными** (`CorePromptStep`), и то же для
   назначений (`CoreLlmPurpose`). Без этой проверки анализатор, объявивший шаг со значением
   `'global'`, ЗАТЕНИЛ БЫ ядерный промт молча: `activeForSteps()` выбирает
   `step IN (…) AND document_type IN ('<тип>', 'any')`, а `groupBy('key')`
   (`app/Repositories/Llm/PromptReadRepository.php:33`) оставляет одну запись на `key` — вторая
   исчезает без ошибки, и системная часть шага меняется незаметно. Класс дефекта тот же, что
   молчаливый фолбэк маршрута, только дороже: он меняет ВХОД модели.
3. **Значения шагов и назначений РАЗНЫХ анализаторов пересекаться МОГУТ** — они разведены осью
   `document_type` в ключе `(document_type, key, version)` (`root/document-type-axis.md` §4.1), и
   второй тип вправе иметь свой `extraction:rows`. Проверка п. 2 касается только пары
   «анализатор ↔ ядро».

Проверка исполняется конструктором реестра; фальсифицируемость её обеспечивает прогон фиктивного
типа в P2.6 — он проходит через ту же сборку.

### 5.4. Отвергнутые альтернативы

**Отвергнута — регистрация из сервис-провайдера через мутируемый статический реестр
(`DocumentAnalyzer::register()`).** Зависит от порядка загрузки провайдеров и мутирует после boot
(скилл `config-driven-abstraction`, правило 1); состав реестра нельзя увидеть, не подняв
приложение.

**Отвергнута — автодискавери сканированием `app/Services/Procurement/Analyzers/*`.** Регистрация
становится неявной, «одной строки регистрации» D14 физически не существует — приёмке не на что
указать пальцем; плюс каталог-скан на каждом boot.

**Отвергнута — атрибут `#[AnalyzerFor(AiDocumentType::Estimate)]` на классе анализатора.** Та же
цена дискавери (рефлексия по каталогу на boot) при том же результате, что у явной строки конфига.

---

## 6. Решение 6 — конвенция каталога типа и неймспейсы ядра

### 6.1. Каталог типа — сегмент пути `Analyzers\<Type>` в КАЖДОМ слое

Проект уже умеет разносить предмет, не ломая слои: домен транспорта `Llm` (D22, P1.5) живёт как
`App\Models\Llm`, `App\Services\Llm`, `App\Enums\Llm`, `App\Repositories\Llm` — то есть канон
раскладки здесь **слой → домен → файлы**, и домен присутствует сегментом пути внутри каждого слоя.
Тип документа встаёт по тому же канону, добавляя один сегмент:

| Единица | Неймспейс |
|:--|:--|
| точка входа | `App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer` |
| 16 единиц D28 | `App\Services\Procurement\Analyzers\Estimate\**` (раскладку внутри выбирает P2.4) |
| job извлечения | `App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob` |
| enum'ы типа | `App\Enums\Procurement\Analyzers\Estimate\{EstimatePromptStep,EstimateLlmPurpose}` |
| DTO разбора | `App\Dto\Procurement\Analyzers\Estimate\**` — **только если так решит P3.5**; P2 их не двигает (Implementation Rule 7, D19) |

**Конвенция задаётся ЯВНО и целиком, одним правилом:** всё, что принадлежит типу `<Type>`, лежит по
пути, содержащему сегмент `Analyzers\<Type>\`, в том слое, которому по смыслу принадлежит.
Слой определяется как для любого другого класса проекта — job'ы в `App\Jobs\**`, enum'ы в
`App\Enums\**`, сервисы в `App\Services\**`.

Приёмка D14 из этого следует одним предикатом, а не перечнем:

> дифф добавления типа не выходит за пределы путей, матчащих `**/Analyzers/<Type>/**`,
> плюс ОДНА строка `config('ai.analyzers')` (§5.1).

Предикат работает и по слоям, которых тип ещё не касался, поэтому список поддерживать не нужно —
он выводится из пути. Deletion test (скилл `codebase-design`) проходит одной командой:
`find app -path '*/Analyzers/Estimate/*'` перечисляет ВЕСЬ тип.

**Почему не «один корень под `app/Services/`».** Форма
`App\Services\Procurement\Analyzers\Estimate\{Jobs,Enums}\**` дала бы приёмку по одному префиксу,
но ценой каталога с именем `Services`, внутри которого лежат queued job и enum'ы — то есть слой в
имени пути перестал бы соответствовать содержимому. Локальность, купленная ложью про слой, дороже
локальности, купленной одним предикатом по пути: первую читатель обнаруживает через полгода, вторая
записана здесь.

Каталоги DTO не перепланируются (Implementation Rule 7, D19): `App\Dto\Procurement\Ai\Estimate\**`
остаётся на месте до P3.5 и для arch-теста P2.6 считается частью поверхности ТИПА, а не ядра —
иначе P2.6 покраснеет на состоянии, которое фаза сознательно не чинит. Строка `App\Dto\Procurement\
Analyzers\Estimate\**` в таблице выше — ЦЕЛЕВАЯ форма на случай, если P3.5 решит двигать DTO;
настоящий контракт за P3.5 не решает и её не предписывает.

### 6.2. Неймспейсы ЯДРА для arch-теста P2.6 — поимённый список

1. `App\Services\Procurement\Screening`
2. `App\Services\Procurement\Extraction` — 5 единиц машинерии, остающихся после P2.4 (D28)
3. `App\Services\Llm` (включая `Contracts`, `Dto`, `Providers`, `Schema`, `Validation`)
4. `App\Services\Procurement\Contracts`
5. `App\Enums\Llm`
6. `App\Repositories\Llm`
7. `App\Models\Llm`
8. `App\Jobs\Procurement`

**Правило вычитания (обязательное, иначе гейт неверен).** Ни один неймспейс списка НЕ включает свои
подпространства, содержащие сегмент `Analyzers`. Поимённо это задевает п. 8: `App\Jobs\Procurement`
— ядро (`ScreeningJob`, `PipelineJob`, `ShadowAnalysisJob`), а `App\Jobs\Procurement\Analyzers\**` —
поверхность типа. Без вычитания arch-тест ловил бы собственный анализатор и не имел бы зелёного
исхода ни при каком исполнении (`plan.md` §2, класс D89).

### 6.3. Что arch-тест утверждает (форма гейта для P2.6)

Класс из списка §6.2 (за вычетом §6.2 «Правило вычитания») не имеет права:

- (а) импортировать что-либо из неймспейса с сегментом `Analyzers` (`**\Analyzers\**`);
- (б) импортировать что-либо из `App\Dto\Procurement\Ai\Estimate\**`;
- (в) импортировать что-либо из `App\Models\Procurement\Estimate\**`;
- (г) нести идентификатор `Estimate` или литерал `'estimate'` — **кроме** `AiDocumentType::Estimate`,
  который является SSOT оси типа (D16) и в ядре законен.

(а)–(в) машинно проверяются `pest-plugin-arch` (`expect(...)->not->toUse(...)`, плагин уже стоит —
`findings/extraction-boundary-2026-07-29.md` §6); (г) — отдельным греп-гейтом с исключением
`AiDocumentType::Estimate`. Разделение названо здесь, чтобы P2.6 не изобретал форму на месте.

**Уточнение 2026-07-30 (D105) — утверждение ниже было НЕ ПРОВЕРЕНО при написании контракта.** На
`11a9fe8` (состояние после P2.5) периметр §6.2 нёс ДВА живых нарушителя свойства §6.3:
`Services\Llm\Validation\StructuredResponseValidator` (импорт `RowsChunkResponse` + сметное
структурное правило внутри общего каскада) и `Jobs\Procurement\ShadowAnalysisJob` (инъекция
сметной `EvalMetrics` + разбор ключа `'estimates'`) — факты и цена каждого:
`findings/core-arch-gate-2026-07-30.md` §1, `findings/core-callsite-seams-2026-07-30.md` §1.
**Периметр НЕ сужается** (сужение выхолостило бы приёмку D14 ровно там, где течёт): их развязывает
новый исполнительский item **P2.7** сеймами §1.5 и §6.4, после чего утверждение становится
истинным, а arch-тест P2.6 — зелёным на честном периметре. **Форма гейта (г) при этом НЕ
ужесточается** (прецедент D49: ужесточение — расфриз, а не исполнение): регистронезависимый
шаблон дал бы девять ложных срабатываний на законных докблочных ссылках ядра на нормативную спеку
`docs/specs/v2/08-estimate-extraction.md` и на историческую v1-ссылку
`config('platform.estimate.ignore_rules')` — гейт без достижимого зелёного, класс D89
(`findings/core-callsite-seams-2026-07-30.md` §2, обе пробы с выводами).

**Список §6.2 — ровно периметр, который фаза P2 действительно вычищает.** В него намеренно НЕ
входят `App\Services\Procurement\{Bench,Gold,Tuning,Prepare,Detail,Eis,Modes}` и
`App\Console\Commands\Ai`: их предметность снимают P3 (сервисный слой и переименование команд) и
P3.5 (раскладка DTO). Расширить список сейчас значило бы завести гейт без достижимого зелёного
исхода в границах Scope фазы — запрещённый класс по `plan.md` §2 (D89).

**Отвергнут — корень `App\Analyzers\<Type>\**` вне домена `Procurement`.** Тип документа — понятие
закупочного домена (анализ висит на версии документа закупки); вынос в корень `app/` завёл бы
четвёртый доменный корень рядом с `Procurement`/`Reference`/`Organization` ради одного каталога.

**Отвергнут — ОДИН корень `App\Services\Procurement\Analyzers\<Type>\**` со вложенными `Jobs\`/
`Enums\`.** Приёмка стала бы однопрефиксной, но проект получил бы первое модульное поддерево в
плоском слоёвом `app/` (прямое отступление от `CLAUDE.md` «структура плоская, без модулей»), а слой
в пути перестал бы соответствовать содержимому. Предикат §6.1 покупает ту же локальность без этой
цены.

**Отвергнут — ЖЁСТКИЙ ПЕРЕЧЕНЬ префиксов типа, поддерживаемый руками** (форма, при которой приёмка
D14 перечисляет `App\Services\…`, `App\Jobs\…`, `App\Enums\…` списком). Как только типу понадобится
слой, в перечень не попавший, гейт перестанет ловить утечку МОЛЧА — тот же класс «гейт без красного
исхода», против которого написаны D66/D69/D81/D89. Предикат по сегменту пути этой болезни не имеет.

### 6.4. Структурная проверка ответа: сейм на стороне DTO типа (дополнение 2026-07-30, D107)

Второй нарушитель §6.3 развязывается НЕ через `DocumentAnalyzer`, и это не удобство, а
необходимость: у `StructuredResponseValidator::validate(LlmStructuredResponse $response, string
$dataClass)` (`app/Services/Llm/Validation/StructuredResponseValidator.php:27`) типа документа НЕТ
— ни один вызывающий его не передаёт. Единственная доступная ядру в этой точке идентичность
предмета — сам класс DTO ответа, поэтому маршрутизация через `DocumentAnalyzerRegistry` здесь
невозможна ПО ПОСТРОЕНИЮ. Сейм — инверсия зависимости: ЯДРО объявляет интерфейс, DTO ТИПА его
реализует.

| Интерфейс | Метод | Реализует | Что заменяет |
|:--|:--|:--|:--|
| `App\Services\Llm\Contracts\StructurallyValidatable` | `structuralError(): ?string` | `App\Dto\Procurement\Ai\Estimate\RowsChunkResponse` | ветку `$data instanceof RowsChunkResponse` и сметное правило монотонности `row_index` внутри `StructuredResponseValidator::structuralError()` (`:76-99`) |

Инварианты:

1. **Каскад валидации, порядок его шагов и семантика исходов не меняются.** Структурная ошибка
   по-прежнему `retryable`, по-прежнему проверяется ПОСЛЕ гидрации Data-класса. Сообщения об
   ошибке переезжают БУКВАЛЬНО, до байта: `tests/Unit/Llm/StructuredResponseValidatorTest.php`
   `:107`/`:123`/`:139` проверяют эти три исхода ЧЕРЕЗ валидатор и обязаны остаться зелёными БЕЗ
   правки — это и есть машинное доказательство паритета (`findings/core-callsite-seams-2026-07-30.md`
   §1.1).
2. **Интерфейс несёт СТРУКТУРНУЮ проверку, не арифметическую.** Сверка сумм остаётся доменным
   уровнем (P6.2) — ровно то, что уже сказано докблоком `:72-75`. Расширять `structuralError()` до
   «любых доменных проверок» запрещено: иначе транспортный каскад станет местом, где живёт бизнес.
3. **Реализуют его DTO ОТВЕТА, а не анализатор.** У ответа проверка своя; анализатор о ней не
   спрашивается — иначе ядерный валидатор начал бы резолвить тип документа по классу DTO, то есть
   заводить вторую, теневую ось типа рядом с `AiDocumentType` (D16).
4. **Интерфейс принадлежит ядру** (`App\Services\Llm\Contracts` — п. 3 списка §6.2) и потому не
   вправе упоминать смету: `?string` наружу, никаких сметных типов в сигнатуре.

**Почему не член `DocumentAnalyzer`.** Валидатор в интерфейс типа не входит по правилу отбора §1.1
(«персистер, ститчер, валидатор, метрика … вызываются ЕГО ЖЕ кодом»), и — главное — у ядра в этой
точке нет типа документа, чтобы спросить реестр (см. выше). Метод на анализаторе потребовал бы
протаскивать `AiDocumentType` через всю транспортную сигнатуру ради одной ветки.

**Отвергнуто — оставить правило в ядре, добавив его в §6.2 как «исключение».** Именованное
исключение внутри гейта — это и есть выхолащивание приёмки D14: следующий тип со своей структурной
проверкой попросит второе исключение, и §6.3 станет списком того, что ядру знать можно.

---

## 7. Решение 7 — место валидации `step`/`purpose` по реестру типа

Путей записи четыре, store-репозиториев промтов и маршрутов не существует
(`root/document-type-axis.md` §5). Валидация ставится ОДНИМ сеймом — реестром (§5.2), и вызывается
из каждого пути:

| Путь записи | Как закрывается |
|:--|:--|
| `database/seeders/PromptSeeder.php:111` | `PromptStep::from()` → `registry->promptStepFor($documentType, $front['step'])` — та же fail-fast-семантика, но по паре «тип + шаг» |
| `database/seeders/AiRoutingSeeder.php:52` | `registry->purposeFor(...)` перед `updateOrCreate` — сегодня `purpose` не проверяется вовсе |
| `app/Filament/…/Prompts/Schemas/PromptForm.php:34` | свободный `Textarea` → `Select` с опциями `registry->promptStepsFor(...)` |
| `app/Filament/…/LlmRoutes/Schemas/AiRouteForm.php:30` | `Select::options(LlmPurpose::class)` (интерфейс `::cases()` не имеет) → опции из `registry->purposesFor(...)` |
| фабрики `PromptFactory`/`AiRouteFactory` | дефолты берутся из ядерных enum'ов; тест на реестр — предмет P2.5 |

Реализацию делает **P2.5** (сейм назван контрактом P1.2, здесь названо МЕСТО). До P2.5 `step`
машинно не проверяется — это не регресс, а сегодняшнее состояние (`root/document-type-axis.md` §5).

**Отвергнута — валидация в `casts()` модели.** Сидеры, фабрики и прямой `INSERT` идут мимо моделей
— тот же аргумент, которым `root/document-type-axis.md` §1.3 п.3 обосновал check-constraint.

**Отвергнута — check-constraint на `llm_prompts.step` / `llm_routes.purpose` в БД.** Множество
допустимых значений задаёт РЕЕСТР КОДА, а не стабильный enum: каждый новый шаг типа требовал бы
миграции. D17 принял эту цену только для оси типа документа, где значений пять и они стабильны.

**Отвергнута — завести `PromptStoreRepository`/`LlmRouteStoreRepository` и повесить валидацию
туда.** Репозиториев записи нет, их введение — предмет P3 (сервисный слой), а гейт нужен уже в
P2.5; реестр всё равно остался бы SSOT, и репозиторий стал бы вторым слоем без собственного
решения.

---

## 8. Сводка контракта одним взглядом

| Что | Где живёт |
|:--|:--|
| интерфейс типа | `App\Services\Procurement\Contracts\DocumentAnalyzer` — 5 методов (§1.2) |
| опциональные способности типа | `App\Services\Procurement\Contracts\SupportsResultComparison` — спрашивается `instanceof`, отсутствие = штатный `null` (§1.5, D106) |
| структурная проверка ответа | `App\Services\Llm\Contracts\StructurallyValidatable` на DTO ответа типа, не на анализаторе (§6.4, D107) |
| реестр | `App\Services\Procurement\Contracts\DocumentAnalyzerRegistry` — 5 методов (§5.2) |
| регистрация | `config('ai.analyzers')`: `тип → ::class`, строки, `config:cache`-безопасно (§5.1) |
| сводка результата | `App\Services\Procurement\Contracts\Dto\{AnalysisSummary,SummaryMetric}` (§3) |
| носитель шага/назначения | интерфейсы `App\Enums\Llm\{PromptStep,LlmPurpose}` + ядерные и типовые enum'ы (§4.1) |
| цепочка композиции | `PromptStep::compositionChain()` — у шага, не у `PromptComposer` (§4.3) |
| каталог типа | сегмент пути `Analyzers\<Type>` в каждом слое; приёмка D14 — предикат `**/Analyzers/<Type>/**` + одна строка конфига (§6.1) |
| ядро для arch-теста | восемь неймспейсов §6.2 за вычетом `**\Analyzers\**`, форма гейта §6.3 |
| валидация `step`/`purpose` | реестр, вызываемый из четырёх путей записи; реализация P2.5 (§7) |

---

## 9. Расхождения факта с планом и `findings`, найденные при написании контракта

Названы, а не подогнаны (Code Guidance item'а).

1. **`PromptCritic::critique()` — ТРЕТИЙ call-site сейма носителя шага.**
   `findings/extraction-boundary-2026-07-29.md` §5 назвал два (`PromptComposer::compose()`,
   `PromptReadRepository::activeForSteps()`). `app/Services/Procurement/Tuning/PromptCritic.php:57`
   принимает ПРОИЗВОЛЬНЫЙ `PromptStep` — включая сметные — и композирует его (`:60`), находясь
   при этом в кросс-документном по D4 контуре тюнинга. Без интерфейса-носителя (§4.1) этот метод
   расщепление enum'а не пережил бы.

2. **Восьмая точка протечки предмета в ядро — `AiRouteResolver::DEFAULT_TEMPERATURE_BY_PURPOSE`**
   (`app/Services/Llm/AiRouteResolver.php:28-33`). Три сметных назначения перечислены литералами
   внутри ядерного резолвера. В списке семи литералов `'estimate'`
   (`findings/repo-structure-2026-07-28.md` §4) её нет — там искали `'estimate'`, а здесь протекают
   значения `LlmPurpose`. Закрывается §4.4; периметр — P2.5.

3. **Формы Filament уже не те, что описаны в `root/document-type-axis.md` §5.** Контракт P1.2
   писался на `dc0815c` и называл `AiRouteForm` `document_type` свободным `Textarea` (`:19`);
   на `f4d9ab9` это `Select` по `AiDocumentType` + сентинел (`AiRouteForm.php:21-28`) — правку внёс
   P1.4. Свободным `Textarea` остались `PromptForm` `step` (`:34`) и `key` (`:31`), и именно `step`
   закрывает решение §7. Расхождение не меняет ни одного вывода контракта P1.2, но цитировать его
   номера строк дальше нельзя.

4. **`prc_document_types` и `has_extractor` в коде уже отсутствуют** (`grep -rn` по `app/
   database/` на `f4d9ab9` — пустой вывод): D16 разряжен фазой P1. Настоящий контракт фиксирует
   ЗАМЕНУ факта (§2), а не задачу сноса.

5. **`compose(PromptStep::Global)` сегодня даёт дублирующую цепочку `[Global, Global]`**
   (`PromptComposer.php:65,73` — ветка `default`). Дубль глушится `groupBy('key')`
   (`PromptReadRepository.php:33`), поэтому регресса нет и не было; новая форма §4.3 даёт `[Global]`
   с побайтово тем же выводом. Факт назван, чтобы P2.5 не принял разницу за поломку.

---

## 10. Как проверены факты кода

Контракт писался против файлов, а не против пересказа. Команды на `f4d9ab9`:

- ветвление скрининга и обе жёсткие ссылки на job — чтение
  `app/Services/Procurement/Screening/ScreeningOutcomeHandler.php` целиком и
  `app/Services/Procurement/Extraction/RerunAnalysisAction.php` целиком;
- периметр литералов — `grep -rn "'estimate'" app/ --include=*.php` (9 вхождений, из них 1 —
  объявление кейса enum'а), `grep -rn "'any'" app/ --include=*.php`,
  `grep -rn "EstimateExtractionJob" app/ --include=*.php`;
- call-site'ы сейма шага — `grep -rn -e "->compose(" app/ tests/ --include=*.php` (7 в `app/`),
  `grep -rn "activeForSteps" app/ tests/ --include=*.php`;
- call-site'ы маршрутизации — `grep -rn "routes->resolve\|routes->candidatesFor\|routes->shadowRoute"
  app/ --include=*.php` (11);
- состав каталога `Extraction` — `ls app/Services/Procurement/Extraction/{,Dto/,Exceptions/}` (21
  единица, совпадает с `findings/extraction-boundary-2026-07-29.md` §3);
- снятость реестра типов — `grep -rn "prc_document_types\|has_extractor" app/ database/` (пусто);
- составной ключ промтов — чтение
  `database/migrations/2026_07_04_100029_create_llm_prompts_table.php:31`;
- версия PHP и работоспособность abstract property в интерфейсе на backed-enum'ах —
  `docker exec agelto_app php -r '…'` → `8.4.23`, `instanceof` истинен, `->value` читается.

---

## Гейт владельца

Контракт замораживает форму, в которой будет описан КАЖДЫЙ будущий тип документа, и является
входом для P2.2–P2.6, P3, P5 и P7. До письменного «утверждаю» владельца, зафиксированного строкой
в `plan.md` → `## 6. Update Log`, ни один item фазы P2 не вправе ссылаться на него как на контракт.

Дальше контракт правится ТОЛЬКО новой D-записью со ссылкой `supersedes`.
