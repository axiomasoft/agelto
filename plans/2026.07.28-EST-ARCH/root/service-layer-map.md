# service-layer-map — контракт сервисного слоя AI-контура

**Контракт P3.1 плана `2026.07.28-EST-ARCH`.** Замораживается утверждением владельца (строка
в `plan.md` → `## 6. Update Log`). После заморозки правится ТОЛЬКО новой D-записью со ссылкой
`supersedes` — правка «по ходу» исполнения любой фазы запрещена (`plan-protocol` §11).

**Что этот документ решает:** куда уезжает логика КАЖДОЙ из 18 консольных команд AI-контура, где
проходит граница «команда ↔ сервис ↔ Action ↔ репозиторий», каким механизмом длинная операция
сообщает прогресс трём потребителям (консоль, очередь, админка), какова единая форма отказа, куда
уезжают общие куски, кто владеет разбором файлового канона промтов, где живёт фабрика провайдера,
чем заменяется запись артефакта в каталог чужого плана, как классифицируется `ai:prompts:suggest`
и в каком каталоге лежит класс каждой команды (D59). Вход для P3.2–P3.4 и P5.2.

**Правка карты P1.1 по решению владельца (D108, 2026-07-30):** `ai:prompts:suggest` признана
СМЕТНОЙ и уезжает в `estimate:prompts:suggest` — строка класса 4 `root/naming-canon.md` изменена
со ссылкой `supersedes`, обоснование в §10. Разбивка команд стала 6 кросс-документных ↔ 12 сметных.

**Якорь фактов:** весь код читан на `70aa998` (ветка `main`, состояние после закрытия фазы P2).
Все `файл:строка` ниже сняты на этом коммите. Расхождения факта с
`findings/commands-and-layout-2026-07-29.md` (собран на `74f515b`, до P1.5–P2.7) названы в §13,
а не подогнаны.

**Правило чтения карты §3.** «Класс» — по классификации findings §1: **(а)** аргументы → вызов
сервиса → печать (выносить нечего), **(б)** нетривиальная логика внутри `handle()`/приватных
методов. «Уезжает» — целевой FQCN и метод; строка без FQCN нечитаема как вход для P3.2/P3.3.
«Остаётся в команде» — то, что после выноса законно живёт в классе команды. «Каталог класса» —
целевой путь файла команды (D59). Имена команд в первой колонке — СЕГОДНЯШНИЕ (`ai:*`);
переименование в `estimate:*` — предмет карты P1.1 и item'а P3.3, здесь не переоткрывается.

---

## 0. Границы слоёв: команда ↔ сервис ↔ Action ↔ репозиторий

| Слой | Вправе | НЕ вправе |
|:--|:--|:--|
| **Команда** (`app/Console/Commands/**`) | объявлять `$signature`/`$description`; разбирать аргументы и опции; приводить их к типам домена; получать сервис конструкторной инъекцией; печатать (`info`/`error`/`table`/прогресс-бар); писать файл по пути, который дал ОПЕРАТОР (`--out`); возвращать код возврата | считать, ходить в БД, читать конфиг предметной области, знать про провайдера/SDK, держать доменные правила (валидации, порядок, идемпотентность) |
| **Сервис** (`app/Services/**`) | читать конфиг; звать репозитории и другие сервисы; вычислять; возвращать DTO/отчёт; бросать `OperationFailed` (§1); сообщать прогресс через `ProgressReporter` (§2) | печатать, знать `Command`/`OutputInterface`/`$this->info()`; писать в БД мимо репозитория; открывать транзакцию вокруг ЧУЖОЙ оркестрации |
| **Action** (`*Action` рядом со своим сервисом) | ОДНА изменяющая состояние операция с транзакционной границей и статусными guard'ами (образцы — `App\Services\Procurement\Extraction\RerunAnalysisAction`, `App\Services\Procurement\Analyzers\Estimate\RerunRangeAction`) | быть «сервисом с одним методом» без транзакции; вызывать другой Action ради композиции без общей транзакции |
| **Репозиторий** (`app/Repositories/**`) | чтение и запись моделей своей сущности; узкие агрегаты своей таблицы | бизнес-правила, конфиг, файловая система, знание о типе документа |

**Почему это не порождает «сервис поверх сервиса».** Три правила, каждое проверяемо:

1. **Сервис заводится только под НАЗВАННУЮ команду-источник.** Каждая строка §3/§4 несёт
   команду, из которой логика вынута; сервиса без сегодняшнего вызывающего в карте нет.
2. **Deletion test** (скилл `codebase-design`): если удалить сервис и сложность НЕ появится у
   нескольких вызывающих, а просто переедет обратно в одну команду — сервис не нужен. Именно по
   этому критерию `ai:prompts:apply`, `ai:bench:formats`, `ai:eval:compare` и `ai:caches` НЕ
   получают новых сервисов: их логика уже за сеймом, обёртка была бы сквозной.
3. **Направление вызова — только вниз по предметности:** тип → ядро → транспорт. Ядро НЕ зовёт
   сервис типа иначе как через `DocumentAnalyzerRegistry` (контракт P2.1 §5.2); сервис типа зовёт
   ядерный напрямую. Обратного вызова (ядро → `Analyzers\Estimate\**`) карта не вводит ни в одной
   строке.

**Action'ов эта фаза не заводит — и это ЯВНАЯ строка, а не умолчание.** Ни одна из 18 команд
сегодня не открывает транзакцию (`grep -rn "DB::transaction" app/Console/Commands/Ai/*.php` — пустой
вывод на `70aa998`), а фаза P3 не меняет поведение (Phase Context «Границы»). Обернуть
многомодельную запись (`WorkingCopyProvisioner`, `FixtureRegistrar`, `GoldExampleImporter`) в
`DB::transaction` — правка семантики частичной записи при ошибке; она НЕ входит в P3 и названа
отложенной в §12.

**Транзакции у вынесенных сервисов остаются ровно там, где были сегодня** — внутри уже
существующих сервисов (`EstimatePersister`, `ExtractionOrchestrator`, `GsfxStrategy` и т. д.),
которых карта не трогает.

---

## 1. Единая форма отказа

Сегодня форм три: `self::FAILURE` из `handle()`; `null` + `$this->error()` ИЗ ПРИВАТНОГО МЕТОДА
с продолжением цикла (`PromptsSuggestCommand:150,166,174,190` на `70aa998`); `throw new
RuntimeException` из приватного метода (`StorageLifecycleCommand:99-106`,
`CachesCommand:107`). Контракт сводит их к ДВУМ, по природе отказа:

| Природа | Форма | Кто бросает/возвращает | Что делает команда |
|:--|:--|:--|:--|
| **Операция невозможна** (нет манифеста, нет gold-файла, эталон не найден, пул пуст, неизвестный драйвер, неизвестный `--format`) | **исключение** `App\Exceptions\Procurement\OperationFailed` (абстрактная база) и его конкретные наследники | сервис (или команда — на гварде своих же опций) | ОДИН `catch (OperationFailed $e)` в `handle()`: `$this->error($e->getMessage()); return self::FAILURE;` |
| **Элемент партии отклонён** (эталон не прошёл валидацию, у пары нет файла-источника, gold не парсится) | **типизированный исход элемента** `App\Dto\Procurement\Fixture\ItemOutcome` в составе отчёта партии | сервис — собирает исходы, НЕ печатает | печатает исходы (через `ProgressReporter`, §2) и выводит код возврата из отчёта |

**Инварианты формы (часть контракта, не комментарий):**

1. **Сервис никогда не возвращает `null` в значении «не получилось».** `null` допустим только как
   «этого объекта нет и это штатно» (например `FixtureStoreRepository::findByCode()`).
2. **Текст сообщения переезжает БУКВАЛЬНО.** Сообщение, которое сегодня печатает команда, становится
   сообщением исключения/исхода до байта — иначе меняется наблюдаемое поведение, запрещённое
   Implementation Rules P3.2 п.2 и P3.3 п.4.
3. **Наследники — без суффикса `Exception`,** по действующему прецеденту репозитория
   (`app/Exceptions/Procurement/NoActiveAnalysisRun.php`): `FixtureCaseNotFound`,
   `GoldFileUnreadable`, `ManifestUnreadable`, `SplitPoolEmpty`, `UnknownProviderDriver`,
   `UnknownReportFormat`. База `OperationFailed extends RuntimeException` — чтобы существующие
   `catch (RuntimeException)` (`PromptsApplyCommand:64`) не перестали ловить.
4. **Разная реакция двух вызывающих на ОДИН отказ достигается на стороне вызывающего, а не двумя
   формами.** Сегодня общий резолв печатает и продолжает в `ai:prompts:suggest`, но возвращает
   `FAILURE` в `ai:eval:diff`. После выноса резолвер (`FixtureCaseResolver`) БРОСАЕТ всегда;
   `PromptSuggestionRunner` ловит его ПОКЕЙСНО и записывает `ItemOutcome(rejected)` — цикл
   продолжается, как сегодня; `ai:eval:diff` исключение не ловит — `FAILURE`, как сегодня.
   Наблюдаемое поведение обеих команд сохраняется при ОДНОМ резолвере (Implementation Rules
   P3.3 п.3).

**Отвергнуто — Result-DTO вместо исключения на операционном отказе** (`Result{ok, error}` из
каждого метода). Заставляет проверять `->ok` на каждом уровне; забытая проверка молча продолжает
исполнение с пустым значением — ровно тот класс дефекта, который сегодня даёт `null` + печать.
Исключение нельзя не заметить.

**Отвергнуто — `ValidationException::withMessages()`** (форма из скилла `dependency-injection` для
Action'ов). Она рассчитана на HTTP-контур и валидацию пользовательского ввода; в консоли даёт
чужую по смыслу трассу, а в P5 (админка) её перехват означал бы показ формы валидации там, где
отказала операция.

---

## 2. Сейм прогресса и печати

**Механизм:** узкий контракт `App\Services\Procurement\Contracts\ProgressReporter` с ЕДИНСТВЕННЫМ
методом, передаваемый ОПЦИОНАЛЬНЫМ параметром МЕТОДА (не конструктора):

```php
interface ProgressReporter
{
    public function report(ProgressTick $tick): void;
}
```

`App\Services\Procurement\Contracts\Dto\ProgressTick` — `{int $done, int $total, string $label,
?string $note}` (каталог существует, в нём уже живут `AnalysisSummary`/`SummaryMetric`, контракт
P2.1 §3).

Сигнатура длинной операции: `run(..., ?ProgressReporter $progress = null): <Отчёт>`; внутри —
`$progress?->report(...)`. Null-объекта нет: `?->` делает его лишним классом.

**Почему параметр метода, а не конструкторная зависимость.** Репортёр — свойство ВЫЗОВА (кто
запустил), а не сервиса; конструкторная инъекция заставила бы контейнер знать вызывающего и
сломала бы одновременный вызов одного сервиса из очереди и из консоли.

**Три потребителя — и что каждый передаёт:**

| Потребитель | Что передаёт | Что видит человек |
|:--|:--|:--|
| **консоль** | `App\Console\Support\ConsoleProgressReporter` — единственный класс проекта, который печатает прогресс (обёртка над `OutputStyle` команды) | построчные сообщения и итоговую сводку ровно там, где они печатаются сегодня |
| **очередь** | job передаёт СВОЙ репортёр либо не передаёт ничего (`null`) — прогресс боевого контура уже персистится штатно (`Ai\Analysis::$progress`, `AnalysisRun`), и второго носителя карта не заводит | статус/чекпоинт в существующих таблицах |
| **админка (P5)** | экран НЕ вызывает сервис напрямую: кнопка «Прогнать» диспатчит штатный job (P5.2 Scope), а job передаёт репортёр; витрина читает персистированное состояние | статус прогона и результат на экране |

**Что этот сейм НЕ обещает.** Живого пер-элементного прогресса батча в админке сегодня нет и
карта его не вводит: у P5 нет экрана, запускающего `ai:eval:run`/`ai:gold:build` (P5.1–P5.6 — про
ОДИН документ обучения). Сейм даёт админке точку расширения (свой класс, реализующий
`ProgressReporter`) без правки сервиса — и именно это требует Implementation Rule 3 («механизм,
работающий только для консоли, отвергается»). Прямая цитата обязательства P5.2: «проброс уже
существующего сейма прогресса (P3.1) в очередь».

**Печать, стоящая СЕГОДНЯ В СЕРЕДИНЕ вычисления, переезжает так:**

| Место (на `70aa998`) | Сегодня | После |
|:--|:--|:--|
| `GoldImportCommand:98` (`dry-run` по эталону) | `$this->info()` внутри цикла | `ItemOutcome(skipped, 'dry-run')` → `$progress?->report()` |
| `GoldImportCommand:202,211,219` (gold не парсится / не прошёл валидацию) | `$this->error()` внутри приватного метода | `ItemOutcome(rejected, <тот же текст>)` в отчёте |
| `DatasetImportCommand:78,86-89,99` (нет источника / ошибки контракта / «импортирован») | `$this->error()`/`$this->line()`/`$this->info()` в цикле | `ItemOutcome` + `$progress?->report()` |
| `EvalRunCommand:337,418` (`dataset_example #N: <ошибка>`) | `$this->error()` в `catch` приватного метода | `ItemOutcome(rejected, <тот же текст>)`; агрегат по-прежнему считает такой пример неуспешным |
| `GoldBuildCommand` (строки отчёта по эталонам) | накопление строк + `table()` в команде | сервис возвращает строки отчёта, `table()` остаётся в команде |

**Отвергнуто — замыкание `callable $onProgress`.** Работает, но не типизировано, не подменяется
контейнером, и каждый потребитель изобретает свою сигнатуру; в P5 это означало бы третью форму.

**Отвергнуто — события Laravel + слушатели.** В проекте НЕТ слоя событий
(`find app/Events app/Listeners` — пусто на `70aa998`); заводить его ради прогресса значит купить
асинхронную доставку и потерю порядка там, где нужен синхронный тик в том же процессе.

**Отвергнуто — «печатать по-старому, просто из команды»** (вернуть `Command` в сервис как
`OutputInterface`). Прямо запрещено Implementation Rule 2 и делает сервис невызываемым из очереди
и админки.

---

## 3. Карта «команда → сервис» — все 18 команд поимённо

| Команда | Класс | Что уезжает → куда | Что остаётся в команде | Каталог класса (D59) |
|:--|:--|:--|:--|:--|
| `ai:prompts:sync` | б | разбор канона → `App\Services\Llm\PromptCanonReader::read()`; апсерт и деактивация протухших версий → `App\Services\Llm\PromptCanonSynchronizer::sync()`; детектор дрейфа `check()` (37–94) → `PromptCanonSynchronizer::drift()`; `Prompt::query()` (44, 70) → `App\Repositories\Llm\{PromptReadRepository,PromptStoreRepository}` | флаг `--check`, печать строк дрейфа, код возврата | `app/Console/Commands/Procurement/Ai/PromptsSyncCommand.php` |
| `ai:prompts:apply` | а | ничего — логика уже в `App\Services\Procurement\Tuning\PromptPatchApplier` (эталон класса «а») | всё, что есть сегодня: гварды статуса, `--dry-run`, печать | `app/Console/Commands/Procurement/Ai/PromptsApplyCommand.php` |
| `ai:prompts:suggest` | б | двойной цикл «кейсы × шаги» с `--limit` → `App\Services\Procurement\Analyzers\Estimate\PromptSuggestionRunner::run()` (команда признана сметной, D108 — §10); резолв кейса и загрузка gold (187–244) → `App\Services\Procurement\Fixture\FixtureCaseResolver` (ОБЩИЙ с `ai:eval:diff`, §5); `Analysis::query()`/`Fixture::query()` (163, 226, 243) → `App\Repositories\Procurement\AnalysisReadRepository` + `FixtureStoreRepository::findByCode()` | разбор `--code`/`--analysis`/`--step`/`--dry-run`/`--limit`, печать исходов | `app/Console/Commands/Procurement/Analyzers/Estimate/PromptsSuggestCommand.php` |
| `ai:caches` | а | только фабрика провайдера `providerForDriver()` (103–109) → `App\Services\Llm\LlmProviderFactory::forDriver()` (§8); остальное уже в `App\Services\Llm\LlmCacheManager` | действие `list\|prune`, `--hits`, `--window`, обе таблицы | `app/Console/Commands/Procurement/Ai/CachesCommand.php` |
| `ai:cost-report` | б | агрегирующий `selectRaw` (30) → `App\Repositories\Llm\LlmCallReadRepository::spendByProviderModelPurpose()`; `cacheSavingsMicros()` по `config('ai.capabilities')` → `App\Services\Llm\LlmSpendReporter::report()` | `--days`, форматтеры валют (`formatUsd`/`formatRub`), таблица и итоги | `app/Console/Commands/Procurement/Ai/CostReportCommand.php` |
| `ai:storage:lifecycle` | б | обёртка `mc` CLI целиком (`ALIAS`, `existingRulePrefixes()`, `mc()`, обработка `NoSuchLifecycleConfiguration`) → `App\Services\Storage\MinioLifecycleManager::apply()` | вызов сервиса и печать строк отчёта | `app/Console/Commands/Procurement/Ai/StorageLifecycleCommand.php` |
| `ai:tools:doctor` | б | `VERSION_ARGS` + `probe()` → `App\Services\Procurement\Prepare\ToolchainProbe::probeAll()` | `--json`, выбор «таблица или JSON», код возврата по «все ли найдены» | `app/Console/Commands/Procurement/Ai/ToolsDoctorCommand.php` |
| `ai:eval:run` | б | отбор датасета → `App\Repositories\Procurement\DatasetExampleReadRepository`; жизненный цикл `EvalRun`/`EvalResult` → `App\Repositories\Procurement\EvalRunStoreRepository`; сброс `Analysis` (17 полей, 294–311 и 371–385) → `App\Repositories\Procurement\AnalysisStoreRepository::resetForRerun()`; цикл оркестратора + агрегация + `prompt_version_set` → `App\Services\Procurement\Analyzers\Estimate\ExtractionEvalRunner::run()`; ветка `--task=screening` → `App\Services\Procurement\Screening\ScreeningEvalRunner::run()` (§13 п.4); правило разбиения D119 (`writeSplits`, 545–605) → `App\Services\Procurement\Analyzers\Estimate\EvalSplitPlanner::plan()` | гварды `--task`/`--split`, `--dry-run`, `--limit`, `--dataset-version`, `renderReport()`/`renderScreeningReport()` | `app/Console/Commands/Procurement/Analyzers/Estimate/EvalRunCommand.php` |
| `ai:eval:diff` | б | резолв кейса и gold (85–99, 119–157) → `App\Services\Procurement\Fixture\FixtureCaseResolver`; построение отчёта уже в `App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport` | гвард `--format`, `renderMarkdown()`, хвост `--out`, печать вердикта | `app/Console/Commands/Procurement/Analyzers/Estimate/EvalDiffCommand.php` |
| `ai:eval:compare` | а | резолв двух прогонов `EvalRun::query()->find()` (52, 53) → `App\Repositories\Procurement\EvalRunReadRepository::find()`; сравнение уже в `App\Services\Procurement\Tuning\EvalRunComparator` | гвард `--format`, `renderMarkdown()`, хвост `--out` | `app/Console/Commands/Procurement/Analyzers/Estimate/EvalCompareCommand.php` |
| `ai:gold:build` | б | чтение манифеста (69–87) → `App\Services\Procurement\Fixture\FixtureManifestReader::read()`; порядок фикстур (179–185) → `FixtureStoreRepository::inCodeOrder()`; `processFixture()`/`handleBuilt()`/`handleDowngraded()`/`applyManifestFacts()`/`writeReport()` → `App\Services\Procurement\Gold\GoldCorpusBuilder::build()` (§4 «неоднозначность 1»); ветка `--screening` → существующий `App\Services\Procurement\Gold\ScreeningGoldBuilder` | все флаги, `table()` отчёта, печать сводки | `app/Console/Commands/Procurement/Analyzers/Estimate/GoldBuildCommand.php` |
| `ai:gold:import` | б | `resolveWorkingCopy()` (141–195) → `App\Services\Procurement\Fixture\WorkingCopyProvisioner::provision()` (ЕДИНСТВЕННЫЙ сейм рабочей копии, D41 — его же зовёт P5.2); `importExample()` (198–240) → `App\Services\Procurement\Fixture\GoldExampleImporter::import()`; порядок фикстур (64–70) → `FixtureStoreRepository::inCodeOrder()`; апсерт `DatasetExample` (227) → `App\Repositories\Procurement\DatasetExampleStoreRepository::storeOrUpdate()` | `ini_set('memory_limit')`, все флаги, печать счётчиков и `unprocessable` | `app/Console/Commands/Procurement/Analyzers/Estimate/GoldImportCommand.php` |
| `ai:dataset:import` | б | `validateGold()`/`importPair()`/`store()` → `App\Services\Procurement\Analyzers\Estimate\DatasetImporter::import()`; `mimeOf()` (245–251) → `App\Support\Files\MimeDetector::of()`; sha-шардинг (233–243) → `App\Support\Files\ShardedBlobPath::for()`; апсерт `DatasetExample` (219) → `DatasetExampleStoreRepository::storeOrUpdate()` | разбор `path` и четырёх опций, печать исходов и итога | `app/Console/Commands/Procurement/Analyzers/Estimate/DatasetImportCommand.php` |
| `ai:dataset:export` | б | `writeSft()`/`writeDpo()`/`userTurn()` → `App\Services\Procurement\Analyzers\Estimate\DatasetExporter::export()`; `Analysis::query()` (143, 152) → `App\Repositories\Procurement\AnalysisReadRepository`; `goldExamples()` (186) → `DatasetExampleReadRepository` | `--format`/`--task`/`--disk`/`--path`, печать пути и счётчиков | `app/Console/Commands/Procurement/Analyzers/Estimate/DatasetExportCommand.php` |
| `ai:fixtures:import` | б | чтение манифеста (44–62) → `App\Services\Procurement\Fixture\FixtureManifestReader::read()`; `importEntry()` (100–…) → `App\Services\Procurement\Fixture\FixtureRegistrar::import()` | `--manifest`/`--source-dir`/`--dry-run`, `table()` и итог | `app/Console/Commands/Procurement/Analyzers/Estimate/FixturesImportCommand.php` |
| `ai:fixtures:manifest` | б | построение записи, сверка sha/size, `readExistingManifest()`, `mimeIndex()` → `App\Services\Procurement\Fixture\FixtureManifestBuilder::build()`; `mimeOf()` (220–226) → `App\Support\Files\MimeDetector::of()`; запись файла манифеста → `App\Services\Procurement\Fixture\FixtureManifestWriter::write()` | `--from-shortlist`/`--inventory`/`--manifest`/`--force`, печать сводки | `app/Console/Commands/Procurement/Analyzers/Estimate/FixturesManifestCommand.php` |
| `ai:fixtures:verify` | б | чтение манифеста (38–56) → `FixtureManifestReader::read()`; `verifyEntry()` + `findDiskOrphans()` → `App\Services\Procurement\Fixture\FixtureVerifier::verify()` | `--manifest`/`--json`, `table()` либо JSON, код возврата | `app/Console/Commands/Procurement/Analyzers/Estimate/FixturesVerifyCommand.php` |
| `ai:bench:formats` | а | ничего — логика уже в `App\Services\Procurement\Bench\FormatBenchRunner` (эталон класса «а»); меняется только ДЕФОЛТ `--out` (§9) | все флаги, запись артефакта по `--out`, печать отклонений и сводки | `app/Console/Commands/Procurement/Analyzers/Estimate/BenchFormatsCommand.php` |

Строк в таблице — 18, по числу файлов `app/Console/Commands/Ai/` (`ls app/Console/Commands/Ai/ |
wc -l` → 18 на `70aa998`).

---

## 4. Новые единицы сервисного слоя и их неймспейсы

**Кросс-документные (ядро).** Предметность — «работает при любом типе документа»:

| Единица | Ответственность | Источник (команда) |
|:--|:--|:--|
| `App\Services\Llm\PromptCanonReader` | разбор файлового канона промтов (§7) | `ai:prompts:sync` |
| `App\Services\Llm\PromptCanonSynchronizer` | `sync()` — апсерт + деактивация; `drift()` — сверка файлы↔БД | `ai:prompts:sync` |
| `App\Services\Llm\LlmProviderFactory` | `forDriver(string): LlmProvider` — единственный `match` по драйверу (§8) | `ai:caches` |
| `App\Services\Llm\LlmSpendReporter` | агрегат расхода + экономия кэша по `config('ai.capabilities')` | `ai:cost-report` |
| `App\Services\Procurement\Prepare\ToolchainProbe` | наличие и версия системных бинарей фабрики представлений | `ai:tools:doctor` |
| `App\Services\Storage\MinioLifecycleManager` | идемпотентное применение ILM-правил через `mc` | `ai:storage:lifecycle` |
| `App\Services\Procurement\Fixture\FixtureManifestReader` | чтение и проверка формы манифеста корпуса | `ai:fixtures:import`, `ai:fixtures:verify`, `ai:gold:build` |
| `App\Services\Procurement\Fixture\FixtureManifestBuilder` | построение записей манифеста, сверка sha/size, mime-индекс | `ai:fixtures:manifest` |
| `App\Services\Procurement\Fixture\FixtureManifestWriter` | запись файла манифеста | `ai:fixtures:manifest` |
| `App\Services\Procurement\Fixture\FixtureRegistrar` | импорт записи реестра, правило «файл на диске с другим sha → отказ» | `ai:fixtures:import` |
| `App\Services\Procurement\Fixture\FixtureVerifier` | сверка реестра с диском и gold, поиск orphan'ов | `ai:fixtures:verify` |
| `App\Services\Procurement\Fixture\FixtureCaseResolver` | «код эталона ↔ активный `Analysis(step=extraction)`» + загрузка gold-файла | `ai:eval:diff`, `ai:prompts:suggest` |
| `App\Services\Procurement\Fixture\WorkingCopyProvisioner` | рабочая копия эталона (`external_id = "fixture:<CODE>"`, перенос блоба, идемпотентный резолв версии) — ЕДИНСТВЕННЫЙ сейм (D41) | `ai:gold:import`, далее P5.2 |
| `App\Services\Procurement\Fixture\GoldExampleImporter` | ветвление screening/extraction при импорте эталонного примера | `ai:gold:import` |
| `App\Services\Procurement\Screening\ScreeningEvalRunner` | прогон оценки качества по `--task=screening` | `ai:eval:run` |

**Сметные — каталог анализатора по конвенции P2.1 §6.1** (`**\Analyzers\Estimate\**`):

| Единица | Ответственность | Источник |
|:--|:--|:--|
| `App\Services\Procurement\Analyzers\Estimate\ExtractionEvalRunner` | цикл оркестратора по примеру, агрегация метрик, `prompt_version_set` прогона | `ai:eval:run` |
| `App\Services\Procurement\Analyzers\Estimate\EvalSplitPlanner` | правило разбиения `tune`/`holdout` (D119) | `ai:eval:run --write-splits` |
| `App\Services\Procurement\Analyzers\Estimate\DatasetImporter` | контракт gold сметы, импорт пары «файл + gold» | `ai:dataset:import` |
| `App\Services\Procurement\Analyzers\Estimate\DatasetExporter` | SFT/DPO-выгрузка сметного датасета | `ai:dataset:export` |
| `App\Services\Procurement\Analyzers\Estimate\PromptSuggestionRunner` | цикл «кейсы × шаги» с `--limit`: сметное свидетельство (`EstimateDiffReport`) → кросс-документный `PromptCritic` | `ai:prompts:suggest` (D108) |

**Контракты, трейты и хелперы:**

| Единица | Зачем |
|:--|:--|
| `App\Services\Procurement\Contracts\ProgressReporter` + `Contracts\Dto\ProgressTick` | сейм прогресса (§2) |
| `App\Console\Support\ConsoleProgressReporter` | единственная печатающая реализация |
| `App\Console\Concerns\RendersReport` | гвард `--format`, `renderJson()`, хвост `--out` (§5); каталог трейтов команд уже существует — `app/Console/Concerns/GracefullyStoppable.php` |
| `App\Support\Files\MimeDetector`, `App\Support\Files\ShardedBlobPath` | технический слой, доменов не знает (скилл `laravel-structure`, ось «Technical axis») |
| `App\Exceptions\Procurement\OperationFailed` + наследники | единая форма отказа (§1) |

**Названные неоднозначности предметности (Implementation Rule 5 — молчаливая приписка запрещена):**

1. **`GoldCorpusBuilder` кладётся в `App\Services\Procurement\Gold\`, а НЕ в каталог анализатора,**
   хотя строит СМЕТНЫЙ эталон. Причина: его единственный исполнитель — `Gold\GoldBuilder`, а
   каталоги сервисов `Gold`/`Bench`/`Prepare` эта фаза сознательно НЕ переклассифицирует (D31
   Consequences, Phase Context «Scope Excluded»). Положить оркестратор в каталог анализатора значило
   бы развести его с исполнителем по разным корням — заплатить цену D31 дважды. Условие снятия:
   решение о переезде `Gold`/`Bench`/`Prepare` (новый D#, вне P3).
2. **Реестр эталонов кросс-документен, а его команды переименовываются в `estimate:*`.**
   `prc_ai_fixtures` несёт `document_type` и `type_profile` (D18) и обслуживает screening-эталоны
   тоже (`ai:gold:build --screening`), поэтому сервисы `Fixture\**` — ядро; имена команд при этом
   заморожены картой P1.1 как сметные. Это расхождение НАЗВАНО, а не устранено: переоткрывать
   карту P1.1 значит расфризить контракт ради каталога.
3. **`ai:eval:run` — одна команда с ДВУМЯ предметностями** (`--task=extraction` сметный,
   `--task=screening` кросс-документный). Сервисы разведены по предметности, класс команды следует
   ИМЕНИ (P1.1 → `estimate:eval:run`, каталог анализатора). Направление зависимости законно: класс
   типа зовёт ядерный сервис, обратного вызова нет.

---

## 5. Общие куски (findings §3) — поимённые адреса

| Кусок | Сегодня (на `70aa998`) | Куда уезжает |
|:--|:--|:--|
| Чтение манифеста | `FixturesImportCommand:44-62` · `FixturesVerifyCommand:38-56` · `GoldBuildCommand:69-87` | `App\Services\Procurement\Fixture\FixtureManifestReader::read()` |
| Отбор фикстур в порядке кода с фильтром `--code` | `GoldBuildCommand:179-185` · `GoldImportCommand:64-70` | `App\Repositories\Procurement\FixtureStoreRepository::inCodeOrder(array $codes)` |
| `mimeOf()` | `DatasetImportCommand:245-251` · `FixturesManifestCommand:220-226` | `App\Support\Files\MimeDetector::of()` |
| sha-шардинг пути блоба | `DatasetImportCommand:239` · `GoldImportCommand:168` | `App\Support\Files\ShardedBlobPath::for()` |
| Резолв документа+версии вне закупки | `DatasetImportCommand:190-231` · `GoldImportCommand:141-195` | **НЕ объединяется** (D57): gold-путь → `WorkingCopyProvisioner`, датасетный остаётся внутри `DatasetImporter`; общей становится только формула пути блоба (строка выше) |
| Апсерт `DatasetExample` по `(document_version_id, task, version)` | `DatasetImportCommand:219` · `GoldImportCommand:227` | `App\Repositories\Procurement\DatasetExampleStoreRepository::storeOrUpdate()` (ключ общий, payload — параметр) |
| Гвард `--format=json\|md` | `EvalDiffCommand:51-55` · `EvalCompareCommand:37-41` | трейт `App\Console\Concerns\RendersReport::guardFormat()` |
| `renderJson()` | `EvalDiffCommand:162-165` · `EvalCompareCommand:86-89` | трейт `RendersReport::renderJson()` |
| Хвост `--out` → файл → печать | `EvalDiffCommand:107-114` · `EvalCompareCommand:71-78` · `BenchFormatsCommand:45-53` | трейт `RendersReport::writeArtifact()` |
| Резолв «код эталона ↔ `Analysis`» — 39 строк побайтово | `EvalDiffCommand:119-157` · `PromptsSuggestCommand:206-244` | `App\Services\Procurement\Fixture\FixtureCaseResolver` — ОДИН сервис, оба потребителя зовут его (Implementation Rule 7) |
| Маска json-флагов россыпью | 8 файлов, 10 вхождений | **не объединяется** этой фазой: периметр шире `Files` любого item'а P3; названо в §12 как отложенное |
| `ini_set('memory_limit', …)` | `GoldBuildCommand:54` (из конфига) · `GoldImportCommand:55` (хардкод `'1024M'`) | **остаётся в команде** — процессный лимит CLI не является доменным знанием; расхождение «конфиг против хардкода» названо в §12 |

**Почему дубль устраняется вверх, а не вбок** (Implementation Rule 7): `FixtureCaseResolver`
объявляется РОВНО в одном файле, и это машинно проверяется Validation 5 item'а P3.3
(`grep -rl "<имя метода резолва>" app/ | wc -l` = 1). Копия «почти такого же» метода в двух новых
сервисах засчитывается как неисполненный item.

---

## 6. Прямой Eloquent из команд → репозитории

На `70aa998` прямых обращений **26** в девяти командах
(`grep -n "::query()\|::where(\|::find(" app/Console/Commands/Ai/*.php | wc -l` → 26; findings §1
называет «22 места», см. §13 п.1). Каждое место закрыто:

| Модель и места | Репозиторий | Новый метод? |
|:--|:--|:--|
| `EvalRun` — `EvalCompareCommand:52,53` | `App\Repositories\Procurement\EvalRunReadRepository` | новый репозиторий: `find(int): ?EvalRun` |
| `EvalRun`/`EvalResult` — `EvalRunCommand:125,155,180,208,233` | `App\Repositories\Procurement\EvalRunStoreRepository` | новый репозиторий: `start(array): EvalRun`, `finish(EvalRun, array): void`, `storeResult(array): EvalResult` |
| `DatasetExample` — `EvalRunCommand:102`, `DatasetExportCommand:186` | `App\Repositories\Procurement\DatasetExampleReadRepository` | новый репозиторий: `forTask(string $task, ?array $holdoutCodes, ?int $limit): Collection`, `goldExamples(string $task): Builder` |
| `DatasetExample` — `DatasetImportCommand:219`, `GoldImportCommand:227` | `App\Repositories\Procurement\DatasetExampleStoreRepository` | новый репозиторий: `storeOrUpdate(int $versionId, string $task, int $version, array $attributes): DatasetExample` |
| `Analysis` — `DatasetExportCommand:143,152`, `EvalDiffCommand:64,139`, `PromptsSuggestCommand:163,226` | `App\Repositories\Procurement\AnalysisReadRepository` | новый репозиторий чтения рядом с существующим `AnalysisStoreRepository`: `find(int): ?Analysis`, `findByIdAndStep(int, AnalysisStep): ?Analysis`, `activeForVersion(int, AnalysisStep): ?Analysis`, `parentOf(Analysis): ?Analysis` |
| `Analysis` — сброс 17 полей, `EvalRunCommand:294-311,371-385` | `App\Repositories\Procurement\AnalysisStoreRepository` | `resetForRerun(Analysis $analysis, int $analysisRunId, ?AiDocumentType $documentType): void` — ОДИН метод вместо двух копий (Implementation Rule 5 P3.3) |
| `Fixture` — `EvalDiffCommand:156`, `PromptsSuggestCommand:243` | `App\Repositories\Procurement\FixtureStoreRepository` | существующий `findByCode()` |
| `Fixture` — пул разбиения, `EvalRunCommand:550` | `App\Repositories\Procurement\FixtureStoreRepository` | `deterministicGoldPool(): Collection` (jsonb-путь `type_profile->gold_source`, сортировка `form`+`code` — переносится как есть) |
| `Document` — `EvalRunCommand:280,364` | `App\Repositories\Procurement\DocumentStoreRepository` | `findOrFail(int): Document` — семантика `findOrFail` сохраняется, чтобы текст исключения не изменился |
| `LlmCall` — `CostReportCommand:30` | `App\Repositories\Llm\LlmCallReadRepository` | новый репозиторий: `spendByProviderModelPurpose(CarbonInterface $since): Collection` — `selectRaw` переносится БЕЗ переписывания |
| `LlmCall` — `EvalRunCommand:640` | `App\Repositories\Llm\LlmCallReadRepository` | `forAnalysesSince(array $analysisIds, CarbonInterface $since): Collection` |
| `Prompt` — `PromptsSyncCommand:44,70` | `App\Repositories\Llm\PromptReadRepository` | `findVersion(string $documentType, string $key, int $version): ?Prompt`, `activeProtected(): Collection` |
| запись `Prompt` — `PromptSeeder:35,61` | `App\Repositories\Llm\PromptStoreRepository` | новый репозиторий: `storeOrUpdate(array $record): Prompt`, `deactivate(Prompt): void`. Введение store-репозитория промтов прямо отнесено к P3 замороженным контрактом P2.1 §7 («их введение — предмет P3 (сервисный слой)»), поэтому запретом Phase Context «не трогает домен `Llm` и его репозитории (P1.5)» не покрывается: тот запрет о переезде P1.5, а не о новых единицах |

**Единственное объединение двух сбросов `Analysis` считается безопасным по проверенному факту:**
экстракционный сброс дополнительно обнуляет `cache_id`/`cache_expires_at`, скрининговый — нет.
Кэш регистрируется ТОЛЬКО контуром извлечения: `LlmCacheManager::create()` вне самого
`app/Services/Llm/LlmCacheManager.php` не вызывается нигде
(`grep -rn "LlmCacheManager" app/ --include=*.php | grep -v "app/Services/Llm/LlmCacheManager.php"`
→ 3 строки, все в `CachesCommand` на `70aa998`), а `Analysis::$cache_id` пишется только внутри
этого сервиса. Значит два лишних обнуления на скрининговом пути — no-op; P3.3 обязан подтвердить
это тестом, а не чтением.

---

## 7. Владелец разбора файлового канона промтов

Сегодня разбор живёт в слое сидеров: `database/seeders/PromptSeeder.php:72` (`loadFiles()`,
статический), глоб `resource_path('ai/prompts/*/*.md')` на строке 74; апсерт — `:35`; деактивация
протухших версий — `:61`. Команда `ai:prompts:sync` вызывает СИДЕР (`:30` — `make(PromptSeeder)`,
`:39` — `PromptSeeder::loadFiles()`). Второй читатель того же глоба —
`app/Services/Procurement/Tuning/PromptPatchApplier.php:85` (`findFileByKey()`).

**Решение:**

| Кто | Что делает после P3.2 |
|:--|:--|
| `App\Services\Llm\PromptCanonReader` | ЕДИНСТВЕННЫЙ владелец обхода файлов канона и разбора front-matter. Домен — транспортный: инструкция принадлежит транспорту (D22, `llm_prompts`, `PromptComposer` уже в `app/Services/Llm/`) |
| `App\Services\Llm\PromptCanonSynchronizer` | `sync()` — апсерт через `PromptStoreRepository` + деактивация протухших; `drift()` — сверка файлы↔БД (сегодняшний `check()`) |
| `database/seeders/PromptSeeder` | ТОНКИЙ вызыватель: `run()` = `$this->synchronizer->sync()`. Статический `loadFiles()` снимается; его вызывающие переводятся на читатель |
| `app/Console/Commands/…/PromptsSyncCommand` | разбор `--check`, вызов `sync()`/`drift()`, печать. Ссылки на `Database\Seeders\PromptSeeder` в `app/` не остаётся (Validation 4 item'а P3.2) |
| `PromptPatchApplier` | берёт файлы у `PromptCanonReader` вместо собственного глоба — так D32 (два корня) ставится в ОБОИХ применителях правкой ОДНОГО места (исполняет P3.4) |

**Отвергнуто — оставить разбор в сидере, а сервису дать обёртку.** Направление зависимости
`app/` → `database/seeders/` сохраняется, то есть продуктовый код продолжает зависеть от слоя
сидов; Validation 4 item'а P3.2 такой исход не пропускает.

**Отвергнуто — положить читатель канона в `App\Services\Procurement\Tuning\`** (рядом с
применителем патчей). Канон промтов нужен сиду и композиции, а не только контуру тюнинга; в
`Tuning` он стал бы зависимостью «снизу вверх» для `PromptComposer`.

---

## 8. Фабрика провайдера по драйверу

Факт: `CachesCommand:103-109` строит `PrismGeminiProvider` через `$this->laravel->make()` по строке
драйвера и знает ОДИН драйвер; `App\Services\Llm\AiRouteResolver:100-110` держит ВТОРОЙ `match` по
драйверу — приватный, по модели `Provider`, и знает ДВА (`gemini`, `openai_compatible`).

**Решение:** `App\Services\Llm\LlmProviderFactory::forDriver(string $driver): LlmProvider` —
единственный `match` в проекте. `LlmCacheManager::prune()` получает фабрику конструкторной
инъекцией и ТЕРЯЕТ параметр-замыкание `callable $providerResolver`; `AiRouteResolver::providerFor()`
делегирует фабрике. Команда после этого не знает ни одного класса провайдера.

**Почему оба call-site'а в одном item'е (P3.2), а не по одному в разных.** Ввести фабрику и
оставить второй `match` в резолвере — это ровно «устранение дубля вбок», запрещённое Implementation
Rule 7. Правка `AiRouteResolver` — три строки в файле, который фаза и так трогает (P3.5
переименовывает его в `LlmRouteResolver` по карте P1.1, класс 6).

**Отвергнуто — перенести `providerForDriver()` внутрь `LlmCacheManager`.** Формально снимает
нарушение из команды (менеджер — транспортный слой, знать провайдеров вправе), но оставляет два
`match` по драйверу в одном домене; при появлении второго провайдера в `llm_providers` разъедутся
молча.

**Отвергнуто — резолвить провайдера через `AiRouteResolver::resolve()`.** Ему нужны тип документа и
назначение вызова, которых у `prune()` нет по построению: он идёт по осиротевшим регистрациям кэша,
где известен только драйвер.

---

## 9. Запись артефактов и хардкод пути в каталог чужого плана

`base_path()`-запись — четыре места на `70aa998`
(`grep -n "base_path(" app/Console/Commands/Ai/*.php` → `BenchFormatsCommand:53`,
`EvalCompareCommand:74`, `EvalDiffCommand:110`, `GoldBuildCommand:383`).

**Решение по `--out`:** путь, пришедший ОТ ОПЕРАТОРА, резолвится и пишется КОМАНДОЙ (трейт
`RendersReport::writeArtifact()`), а не сервисом. Репо-относительный путь — часть CLI-контракта,
не доменное знание; сервис возвращает готовое содержимое отчёта.

**Решение по хардкоду пути в замороженный план:** ни один артефакт не пишется в каталог чужого
плана. Целевой корень — `config('ai.fixtures.reports_dir')` с дефолтом `base_path('estimates/reports')`
(рядом с уже существующими `estimates/manifest.json`, `estimates/gold`, `estimates/eval-splits.json`
— тот же корень корпуса, тот же конфиг-блок `ai.fixtures`).

| Место | Сегодня | Целевое |
|:--|:--|:--|
| отчёт корпуса, `GoldBuildCommand:383` | `base_path('plans/2026.07.26-EST-TRAIN/research/10-gold-corpus-report.md')` | `config('ai.fixtures.reports_dir')."/gold-corpus-report.md"`; путь остаётся переопределяемым сервисом-вызывающим |
| дефолт `--out`, `BenchFormatsCommand:23,47` | `plans/2026.07.26-EST-TRAIN/artifacts/bench-formats-<режим>-<дата>.json` | `estimates/reports/bench-formats-<режим>-<дата>.json` |

План `2026.07.26-EST-TRAIN` заморожен (D2/D9) и уедет в архив — обе ссылки станут мёртвыми.
**Внимание исполнителю P3.3:** его Implementation Rule 4 называет ЕДИНСТВЕННЫМ намеренным
изменением поведения путь отчёта корпуса; второй путь (`BenchFormatsCommand`) найден этим
контрактом и в findings §5 не значится. Исполнять оба в P3.3 без правки его ТЗ — конфликт правил;
синхронизация ТЗ вынесена в Pending Work item'а P3.1 (см. `phases/P3.md`).

**Отвергнуто — писать отчёты на диск хранилища (`Storage::disk`).** Отчёт корпуса и артефакт
бенчмарка живут в git рядом с манифестом и gold (`ai.fixtures.*` — тот же принцип «SSOT корпуса в
git», D1/D20); уход на s3-диск сделал бы их невоспроизводимыми в ревью.

**Отвергнуто — оставить путь аргументом без дефолта** (обязательный `--out`). Меняет CLI-контракт
обеих команд и ломает существующие тесты — прямое нарушение «поведение не меняется».

---

## 10. `ai:prompts:suggest` — пограничный случай, решение явное

**Выбран вариант (б): команда признаётся СМЕТНОЙ и уезжает в `estimate:prompts:suggest`.**
Решение владельца от 2026-07-30, зафиксировано **D108** со ссылкой `supersedes` на строку
`root/naming-canon.md` класса 4 (та строка сама отсылала решение к этому контракту). Формулировка
владельца: «если это относится конкретно к сметам, промпты — это должно быть именно с префиксом
закупки смет, по доменной системе, чтобы всё было предсказуемо и логично».

Разбор предметности по факту кода на `70aa998` — что именно в команде сметное:

| Часть команды | Предметность | Почему |
|:--|:--|:--|
| резолв «код эталона → активный `Analysis(step=extraction)`» (`:206-231`) | **кросс-документная** | опирается на `Fixture` (кросс-типовой после D18), `Document`/`DocumentVersion` и `AnalysisStep::Extraction` — ядерный enum (`app/Enums/Procurement/AnalysisStep.php`, два кейса, ни одного сметного) |
| загрузка gold-файла (`:187-201`) | **кросс-документная** | `config('ai.fixtures.gold_dir')` обслуживает и `*.screening.json` (`GoldBuildCommand --screening`) |
| резолв шага по типу документа (`:95-108`) | **уже кросс-документный** | идёт через `DocumentAnalyzerRegistry::promptStepFor()` — исполнено P2.5 |
| критик и очередь предложений (`PromptCritic`) | **кросс-документная** | D21/D4 |
| построение diff-отчёта (`:90`, инъекция `:40`) | **сметная** | `EstimateDiffReport` |

То есть сметен ВХОД команды — свидетельство, по которому она вообще работает: без сметного
diff-отчёта у неё нет предмета критики. Кросс-документны её КОЛЛАБОРАТОРЫ, а не она сама.

**Как это исполняется:**

| Единица | Куда | Почему |
|:--|:--|:--|
| класс команды | `app/Console/Commands/Procurement/Analyzers/Estimate/PromptsSuggestCommand.php`, сигнатура `estimate:prompts:suggest` | каталог следует префиксу (§11), префикс — предмету (D108) |
| цикл «кейсы × шаги» | `App\Services\Procurement\Analyzers\Estimate\PromptSuggestionRunner` | раннер держит сметную зависимость `EstimateDiffReport` — значит живёт в каталоге типа, а не рядом с ядром |
| `PromptCritic`, `PromptPatchApplier`, `EvalRunComparator` | остаются в `App\Services\Procurement\Tuning\**` | принимают ЛЮБОЙ `PromptStep` и бесструктурный `array $diff` (`PromptCritic::critique()`), знания о смете не несут (D21) |
| команды `prompts:apply` и `prompts:sync` | остаются под префиксом `ai` (§3) | применение патча идёт ПО КЛЮЧУ промта, сверка канона — по всем девяти группам; тип документа в обеих не участвует |
| резолв кейса и gold | `App\Services\Procurement\Fixture\FixtureCaseResolver` — кросс-документный | не содержит ни одного сметного понятия (первые две строки таблицы выше); оба его сегодняшних потребителя сметные ПО ИМЕНИ, но не по составу — второй тип с эталонным корпусом переиспользует резолвер, а не копирует его |

**Почему это НЕ «разъезд имён внутри одной петли».** Сейм петли тюнинга проходит по природе
операции, а не по её месту в цепочке: **генерация** предложения питается ТИПОВЫМ свидетельством
(diff результата конкретного типа), **применение** предложения адресуется ключом промта и типа не
знает. Очередь `prc_ai_prompt_suggestions` уже несёт ось `document_type` (D21) — то есть
per-типовая генерация и тип-агностичное применение это ровно та форма, под которую очередь
спроектирована.

**Что это даёт приёмке D14.** Второй тип документа заводит СВОЮ команду
`<тип>:prompts:suggest` внутри своего каталога `**/Analyzers/<Type>/**` — ядро при этом не
правится ни одной строкой. При варианте (а) единственная кросс-документная команда обязана была бы
либо обрасти ветвлением по типу (правка вне каталога типа — прямое ослабление предиката §6.1
контракта P2.1), либо ждать нового capability-интерфейса, то есть расфриза P2.1.

**Отвергнут вариант (а) — оставить команду `ai:*` с явной типовой зависимостью раннера.**
Аргумент «сметно только одно звено» верен по объёму кода, но неверен по существу: это звено —
ВХОД команды, без него команда не работает. Цена варианта (а) названа выше: либо ветвление по
типу в кросс-документной команде (ослабляет D14), либо новый capability-интерфейс через
`DocumentAnalyzerRegistry`. Последний, к тому же, сегодня недостижим без изменения поведения:
ближайший член контракта `SupportsResultComparison::compareResults()`
(`root/document-analyzer-contract.md` §1.5) возвращает результат `EvalMetrics::compare()`
(`app/Services/Procurement/Analyzers/Estimate/EstimateAnalyzer.php:116-126`) — агрегат
precision/recall/F1, тогда как критик получает ПОСТРОЧНЫЙ отчёт
`EstimateDiffReport::build(...)->toArray()` (`PromptCritic` докблок `:50-53`); подмена одного
другим изменила бы вход критика, что запрещено Implementation Rules P3.2 п.2 / P3.3 п.4.

**Отвергнуто — переименовать заодно `ai:prompts:apply` и `ai:prompts:sync`** «чтобы вся петля
была в одном префиксе». Применитель ищет файл по `key`, сверка канона обходит ВСЕ девять групп
промтов (четыре кросс-документные + пять сметных) — у обеих команд нет предмета, к которому
привязывать префикс; сметный префикс сделал бы их именами ЛОЖНЫМИ ровно в момент появления второго
типа.

---

## 11. Раскладка каталогов команд (D59) и судьба общего базового класса

**Правило:** каталог класса команды повторяет её префикс, а доменная ось повторяет раскладку
остальных слоёв (`App\Models\Procurement\Ai\**`, `App\Enums\Procurement\**`,
`App\Jobs\Procurement\**`) — принцип «mirror rule» скилла `laravel-structure`.

| Группа | Каталог | Число |
|:--|:--|--:|
| кросс-документные, префикс `ai` | `app/Console/Commands/Procurement/Ai/` | 6 |
| сметные, префикс `estimate` | `app/Console/Commands/Procurement/Analyzers/Estimate/` | 12 |

Разбивка 6↔12, а не 7↔11 карты P1.1: `prompts:suggest` переведён в сметные решением владельца
D108 (§10). Остальные шесть кросс-документных — `prompts:sync`, `prompts:apply`, `caches`,
`cost-report`, `storage:lifecycle`, `tools:doctor`.

Целевой каталог КАЖДОГО из 18 классов назван построчно в §3 (последняя колонка) — умолчания
«остальные остаются где были» в карте нет.

Сегодня все 18 лежат в `app/Console/Commands/Ai/` — единственном месте проекта, где AI-подомен стоит
БЕЗ доменного уровня `Procurement` (`find app/Console -type f -name "*.php"` на `70aa998`: соседи —
`Commands/Procurement/**`, `Commands/Reference/**`). Это и есть «нарушена доменная система» из
брифа §7.2.

Сегмент `Analyzers/Estimate` у сметных команд обязателен по конвенции P2.1 §6.1: приёмка D14 —
предикат «дифф добавления типа не выходит за пределы `**/Analyzers/<Type>/**` плюс одна строка
конфига», и класс команды нового типа обязан в него попадать.

**Судьба общего базового класса и трейта команд.** Общего базового класса СЕГОДНЯ нет
(`grep -n "extends" app/Console/Commands/Ai/*.php` → 18 совпадений, все на
`Illuminate\Console\Command`), и он НЕ заводится: базовый класс стал бы местом, куда со временем
стекается сквозная логика восемнадцати несвязанных команд, — тот самый дефект, который эта фаза
лечит. Общий код команд живёт ТРЕЙТАМИ на техническом слое: существующий
`App\Console\Concerns\GracefullyStoppable` и новый `App\Console\Concerns\RendersReport` (§5).
Трейт команд не знает предметки и не держит состояния.

**Отвергнут — базовый класс `AiCommand` с общими гвардами и печатью.** Наследование связывает 18
команд одной иерархией ради трёх повторяющихся кусков; трейт даёт то же переиспользование без
иерархии и без соблазна «положить сюда ещё одну общую штуку».

**Отвергнут — каталог `app/Console/Commands/Llm/` для транспортных команд** (`ai:caches`,
`ai:cost-report`, `ai:prompts:*`). По существу они действительно транспортные (D22), но D4 закрепил
префикс по кросс-документности, а не по домену: каталог разошёлся бы с префиксом, и правило «имя
команды читается из пути» перестало бы работать.

---

## 12. Что эта фаза сознательно НЕ делает (названо, а не умолчано)

- **Транзакции вокруг перенесённой многомодельной записи** — меняют семантику частичной записи при
  ошибке; кандидат в отдельный item после P3.
- **Полный read/store-split `FixtureStoreRepository`** — новые методы чтения добавляются на
  существующий store-репозиторий (в нём уже живут `findByCode`/`findBySha256`/`all`); расщепление
  задело бы шесть вызывающих вне периметра фазы.
- **Объединение датасетного и gold-путей создания рабочей копии** — D57 прямо оставляет эту
  дупликацию открытой; карта объединяет только формулу пути блоба.
- **Общая константа маски json-флагов** (10 вхождений в 8 файлах) — периметр шире `Files` любого
  item'а P3.
- **`ini_set('memory_limit', '1024M')` хардкодом в `GoldImportCommand:55`** против конфига в
  `GoldBuildCommand:54` — расхождение остаётся, правка меняла бы поведение вне названного скоупа.
- **Переезд каталогов сервисов `Gold`/`Bench`/`Prepare`** — D31 Consequences, вне границ фазы.

---

## 13. Расхождения факта кода с `findings` (названы, а не подогнаны)

Разведка `findings/commands-and-layout-2026-07-29.md` снята на `74f515b` — ДО P1.5, P1.7, P2.4,
P2.5, P2.7. Сверка на `70aa998`:

1. **«22 места» прямого Eloquent (§1, §4) — на самом деле 26.** Собственный перечень findings §4
   тоже даёт 26 (10+3+3+3+2+2+1+1+1); расходится прозаическое число, не перечень.
   Воспроизведение: `grep -n "::query()\|::where(\|::find(" app/Console/Commands/Ai/*.php | wc -l`
   → 26 (на `70aa998`).
2. **`EvalRunCommand` — 663 строки, не 622** (`wc -l` → 663 на `70aa998`); суммарно по каталогу
   3642, а не 3552. Причина — правки P1.5/P2.5 (переезд `LlmCall`, типизированные enum'ы).
   Блоки логики, названные findings, на месте; сместились номера строк, поэтому карта цитирует
   строки по `70aa998`.
3. **Дубль резолва — строки другие:** `EvalDiffCommand:119-157` против
   `PromptsSuggestCommand:206-244` (findings называет `194-232`). Побайтовая идентичность 39 строк
   подтверждена: `diff <(sed -n '119,157p' …EvalDiffCommand.php) <(sed -n '206,244p'
   …PromptsSuggestCommand.php)` → пустой вывод (на `70aa998`).
4. **`ai:eval:run` кросс-документен наполовину.** findings §1 относит команду целиком к сметным;
   по факту ветка `--task=screening` (`:204-257`) работает через `ScreeningProcessor`/
   `ScreeningEvalMetrics` и `DocumentTypeScope::Any` — тип документа не участвует. Следствие для
   карты — §4 «неоднозначность 3».
5. **Хардкод пути в замороженный план — ДВА места, а не одно.** findings §5 называет только
   `GoldBuildCommand`; `BenchFormatsCommand:23,47` несёт дефолт
   `plans/2026.07.26-EST-TRAIN/artifacts/…`. Следствие — §9 и Pending Work item'а.
6. **`ai:prompts:suggest` уже частично развязан:** резолв шага идёт через
   `DocumentAnalyzerRegistry::promptStepFor()` (`:101`), чего на `74f515b` не было (исполнено P2.5).
   Это меняет вход решения по Implementation Rule 6 — см. §10. Строки дубля резолва в этой команде
   тоже сместились: `206-244` вместо `194-232`.
7. **Трейт для команд в проекте есть** — `app/Console/Concerns/GracefullyStoppable.php`; findings
   §1 («трейтов 0») считал только по `app/Console/Commands/Ai/*.php`. Следствие — §11: общий код
   команд идёт в существующий каталог трейтов, новый уровень не заводится.

---

## 14. Гейт владельца

Карта считается замороженной с момента, когда владелец написал «утверждаю» и это зафиксировано
строкой в `plan.md` → `## 6. Update Log`. До этой строки ни один item фазы P3 (P3.2–P3.6) и
item P5.2 не вправе ссылаться на карту как на контракт.
