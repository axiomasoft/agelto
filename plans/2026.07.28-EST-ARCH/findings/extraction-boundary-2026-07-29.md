# Граница «машинерия ↔ предметка» в слое `Extraction` — факты кода

Слой 1 (`plan-protocol` §16). Разведка при детализации фазы P2, сверена чтением файлов на
`74f515b` (ветка `main`). Повод — проверить классификацию `research/01-target-architecture.md` §4
перед тем, как писать ТЗ расщепления. **Классификация §4 фактом НЕ подтвердилась** — расхождение
разобрано ниже и является причиной D28.

Потребители — items P2.1, P2.4, P2.5, P2.6.

---

## 1. Что утверждал синтез §4 и что показывает код

`research/01-target-architecture.md` §4 (написан по `findings/repo-structure-2026-07-28.md` §6)
относил к «переиспользуемому любым типом документа» восемь классов: `ChunkSource`,
`WindowedChunkSource`, `RowRangeChunkSource`, `ExtractionOrchestrator`, `ExtractionPreflight`,
`ExtractionSession`, `SegmentAssembler`, `StitchReport`.

Шесть из восьми зависят от предметной области смет ПРЯМО — импортом DTO, типом поля или формой
контракта метода. Ниже — построчные доказательства.

### 1.1 `ExtractionOrchestrator` (759 строк) — сметный по всей длине цикла

- Импортирует пять сметных DTO: `DiscoveryMarkerLine`, `DiscoveryResponse`, `PassportResponse`,
  `RowsChunkResponse`, `TotalsResponse` (RAG:— repo-grounded:
  `app/Services/Procurement/Extraction/ExtractionOrchestrator.php:7-11`).
- Инжектит `EstimatePersister` конструктором (`:74`).
- Форма прогресса — сметная: `progress['estimates']`, `passport_done`, `rows_done`, `totals`,
  `next_row_index`, `row_range_size` (`:163-281`, `:402-506`, `:551`, `:686`).
- Читает сметный конфиг `ai.extraction.max_estimates_per_document` (`:150`).
- Оба top-level пути (`runWholeDocument()`, `runWindowed()`) — это цикл «паспорт → строки →
  итоги» конкретно сметы, а не абстрактный оконный проход.

Обобщаемое в нём — только механика чекпоинта (`checkpointDue()`/`checkpoint()`) и работа с
`ExtractionSession`; она вплетена в сметный цикл и отдельным классом сегодня не существует.

### 1.2 `ChunkSource` (интерфейс) — сметный по контракту, без единого сметного импорта

Имена и параметры методов — доменные понятия сметы:
`fetchPassport(..., int $ordinal, array $knownEstimateNumbers)`,
`fetchRows(..., int $estimateOrdinal, int $rowStart, int $rowLength)`,
`fetchTotals(..., int $estimateOrdinal)`
(RAG:— repo-grounded: `app/Services/Procurement/Extraction/ChunkSource.php:31-59`).
Возвращаемый `ChunkFetchResult` типизирован объединением четырёх сметных DTO
(`Dto/ChunkFetchResult.php:24`). Отсутствие `use`-импорта сметного DTО в самом интерфейсе ввело
синтез §4 в заблуждение: связь идёт через тип возврата.

### 1.3 Прочие четыре

| Класс | Доказательство сметной привязки |
|:--|:--|
| `RowRangeChunkSource` | `use …Estimate\{PassportResponse,RowsChunkResponse,TotalsResponse}` (`:7-9`) |
| `WindowedChunkSource` | `use …Estimate\{DiscoveryResponse,PassportResponse,RowsChunkResponse,TotalsResponse}` (`:7-10`) |
| `SegmentAssembler` | `use …Estimate\DiscoveryMarkerLine` (`:7`); собирает «сегменты смет» |
| `StitchReport` | `use …Estimate\{EstimateRow,IncompleteRow}` (`:7-8`) |

### 1.4 Что действительно не зависит от типа документа

Проверено грепом по импортам сметных DTO и по типам полей
(`grep -rLn "use App\\Dto\\Procurement\\Ai\\Estimate\\" app/Services/Procurement/Extraction/`):

- `ExtractionPreflight` — размер файла и число страниц против capability-матрицы маршрута;
  ни одного доменного понятия (`ExtractionPreflight.php:1-67`).
- `Dto/PreflightDecision` — `ExtractionRoute` + `UnprocessableReason`, закрытые состояния.
- `ExtractionSession` — `LlmFileHandle`/`LlmCacheHandle`/`LlmUsage` + `array $progress`;
  форма `progress` задаётся вызывающим, сам класс её не знает.
- `Exceptions/MaxTokensExceededException` — транспортный сигнал.

`ValidationReport` в §4 не классифицирован и по факту СМЕТНЫЙ: импортирует
`App\Enums\Procurement\EstimateValidationStatus`. `EvalMetrics` сметных импортов не имеет, но
работает над формой `{"estimates": [{…, "rows": […]}]}` (докблок `EvalMetrics.php:3-8`) — привязка
через контракт данных, а не через тип.

## 2. Восьмая точка хардкода сверх списка `repo-structure` §4

`findings/repo-structure-2026-07-28.md` §4 перечислил семь литералов `'estimate'`. Кроме них есть
жёсткая привязка к КЛАССУ job'а извлечения в двух местах ядра:

| Call-site | Что делает |
|:--|:--|
| `ScreeningOutcomeHandler.php:12,131` | `use …EstimateExtractionJob` + `EstimateExtractionJob::dispatch($document->id)` |
| `RerunAnalysisAction.php:9,54` | `AnalysisStep::Extraction => EstimateExtractionJob::dispatch(...)` — операторский re-run стадии |

`RerunAnalysisAction` при этом по СМЫСЛУ машинерия (перевод анализа в `superseded_at`, создание
нового `pending`, погашение коррекций, откат статуса документа, передиспатч job'а стадии — всё
типо-независимо, `RerunAnalysisAction.php:20-60`), сметная в нём ровно одна строка — имя job'а.
То есть §4 отнёс его к предметке ошибочно: после развязки диспатча через реестр он остаётся в ядре.

## 3. Итоговая классификация (вход P2.4)

**Ядро (остаётся в общем каталоге), 5 единиц:** `ExtractionPreflight`, `Dto/PreflightDecision`,
`ExtractionSession`, `Exceptions/MaxTokensExceededException`, `RerunAnalysisAction` (после
развязки диспатча через реестр).

**Предметка (уезжает в каталог анализатора сметы), 16 единиц:** `ExtractionOrchestrator`,
`ChunkSource`, `RowRangeChunkSource`, `WindowedChunkSource`, `Dto/ChunkFetchResult`,
`SegmentAssembler`, `StitchReport`, `EstimateStitcher`, `EstimatePersister`, `EstimateValidator`,
`ValidationReport`, `EstimateToContractMapper`, `EstimateDiffReport`, `EvalMetrics`,
`GoldExampleRecorder`, `RerunRangeAction`.

Итого 21 файл каталога (`ls app/Services/Procurement/Extraction/**` — 19 в корне + 2 в `Dto` +
1 в `Exceptions`; `ChunkSource` и `Dto/ChunkFetchResult` считаются отдельными единицами).

## 4. Следствие, которое обязана назвать фаза

Обобщение механики чекпоинта в ядро потребовало бы переписать цикл `ExtractionOrchestrator` —
ровно тот код, который контракт-блок P2 запрещает трогать (checkpoint-requeue, lease,
`ShouldBeUniqueUntilProcessing`, D39 проекта). Плюс это была бы спекулятивная генерализация по
ЕДИНСТВЕННОЙ реализации: правильный сейм виден, когда реализаций две (канон скилла
`codebase-design`: «one adapter is a hypothetical seam, two is a real one»).

Отсюда граница расщепления — «тонкая»: перемещение без обобщения, механика чекпоинта уезжает
вместе со сметным оркестратором. Цена названа явно: второй тип документа, которому понадобится
чекпоинт-цикл, пишет свой оркестратор В СВОЁМ каталоге (приёмка D14 при этом держится — правок
вне каталога типа не требуется). → **D28**.

## 5. Размер enum-расщепления (вход P2.5)

`PromptStep` — 9 кейсов, 4 кросс-документных + 5 сметных; `LlmPurpose` — 8 кейсов, 4
кросс-документных + 4 сметных (RAG:— repo-grounded: `app/Enums/Procurement/{PromptStep,LlmPurpose}.php`).
Call-site'ов: `PromptStep::` — 67, `LlmPurpose::` — 105 (`grep -rn "PromptStep::" app/ database/ tests/ | wc -l`
и то же для `LlmPurpose::`, на `74f515b`).

Сейм композиции промтов сегодня типизирован ЖЁСТКО на общий enum и несёт сметную match-лесенку:

- `PromptComposer::compose(PromptStep $step, array $schemaClasses)` и
  `stepsForComposition()` с веткой `ExtractionDiscovery|ExtractionPassport|ExtractionRows|ExtractionTotals
  => [Global, ExtractionGlobal, $step]` (RAG:— repo-grounded: `app/Services/Llm/PromptComposer.php:31,64-75`);
- `PromptReadRepository::activeForSteps(array<PromptStep> $steps)` (`:25-27`).

То есть расщепление enum'а невозможно без сейма носителя шага — его форму обязан задать контракт
P2.1, иначе P2.5 изобретёт его на месте.

Строковые ЗНАЧЕНИЯ шагов и назначений (`extraction:rows`, `extract_rows`) при расщеплении НЕ
меняются — меняется только PHP-носитель. Это снимает с P2.5 миграцию данных и пересев промтов.

## 6. Инструмент для машинной защиты (вход P2.6)

`pestphp/pest-plugin-arch` уже установлен как зависимость Pest 3.8 — новых пакетов заводить не
нужно (RAG:— repo-grounded: `ls vendor/pestphp/` → `pest-plugin-arch`;
`vendor/pestphp/pest-plugin-arch/src/Expectations/`). Arch-тестов в репозитории сегодня нет
(`grep -rn "^arch(\|    arch(" tests/` — пусто; голый `grep "arch("` даёт только ложные
совпадения на `array_search(` в `Prepare`-тестах).
