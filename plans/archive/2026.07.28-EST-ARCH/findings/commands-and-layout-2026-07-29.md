# Команды, DTO и репозитории AI-подомена — факты репозитория

Слой 1 (`plan-protocol` §16). Разведка при детализации фазы **P3** (2026-07-29), дерево
`74f515b`. Собрано субагентом-разведчиком (`app/Console/Commands/Ai/**`) и прямыми грепами
автора плана (DTO, репозитории, канон промтов). Всё, что ниже, — ФАКТ репозитория, а не решение;
решения — `plan.md` §5 и `phases/P3.md`.

Дополняет `findings/repo-structure-2026-07-28.md` §9–§11: там перечень и классификация команд по
префиксу, здесь — что именно внутри них живёт.

---

## 1. Логика внутри 18 команд: классификация

`ls app/Console/Commands/Ai/ | wc -l` = 18 · `wc -l app/Console/Commands/Ai/*.php` → 3552 строки.
Класс логики: **(а)** разбор аргументов → вызов сервиса → печать (выносить нечего);
**(б)** нетривиальная логика внутри `handle()`/приватных методов.

| Команда | Строк | Класс | Что уезжает в сервис (если (б)) |
|:--|--:|:--|:--|
| `ai:eval:run` | 622 | б | отбор датасета по `task/split/limit`; жизненный цикл `EvalRun`/`EvalResult`; **сброс `Analysis` до «свежего» состояния — 17 полей руками, дублируется в двух методах** (276–293, 336–349); do/while-цикл оркестратора; сборка `ScreeningResult` из `analysis.result`; агрегация метрик; правило разбиения D119 (`writeSplits`, 507–564); merge `prompt_version_set` с отказом при расхождении (593–621) |
| `ai:gold:build` | 387 | б | чтение+валидация манифеста; порядок фикстур; диспетчер `processFixture()` со skip/reject-правилами; `applyManifestFacts()`; генерация markdown-отчёта корпуса (**хардкод пути в ЧУЖОЙ план**, см. §5) |
| `ai:dataset:import` | 239 | б | `validateGold()` (обход estimates/rows контрактом P2.2); `importPair()` (резолв документа/версии + апсерт примера); sha-шардинг пути; `mimeOf()` |
| `ai:gold:import` | 236 | б | `resolveWorkingCopy()` (копирование блоба между дисками + идемпотентный резолв документа/версии); `importExample()` (ветвление screening vs extraction) |
| `ai:eval:diff` | 234 | б | резолв «код эталона → активный `Analysis(extraction)`» и обратный; загрузка/парсинг gold-файла — **см. §2** |
| `ai:prompts:suggest` | 233 | б | то же, что `ai:eval:diff` (**дубль**), плюс двойной цикл «кейсы × шаги» с `--limit` |
| `ai:fixtures:manifest` | 218 | б | построение записи манифеста + сверка sha/size; чтение существующего манифеста; mime-индекс |
| `ai:dataset:export` | 205 | б | `writeSft()` (композиция system-промта + разворот estimates); `writeDpo()` (резолв активного анализа и родителя, фильтр `source === 'corrected'`) |
| `ai:fixtures:verify` | 182 | б | `verifyEntry()` (сверка sha/size/полей реестра/наличия gold); поиск orphan'ов на диске и в реестре |
| `ai:fixtures:import` | 175 | б | `importEntry()`: правило «файл уже на диске с другим sha → отказ», сверка с источником, апсерт 17 полей реестра |
| `ai:eval:compare` | 143 | а* | логика сравнения уже в `EvalRunComparator`; в команде — резолв двух `EvalRun` + `renderMarkdown()` |
| `ai:cost-report` | 120 | б | агрегирующий `selectRaw` по `(provider, model, purpose)`; `cacheSavingsMicros()` по `config('ai.capabilities')`; форматтеры валют |
| `ai:caches` | 117 | а* | всё в `LlmCacheManager`; исключение — **фабрика провайдера по driver'у** `providerForDriver()` (103–109), см. §5 |
| `ai:storage:lifecycle` | 107 | б | обёртка `mc` CLI: разбор json правил, обработка `NoSuchLifecycleConfiguration`, сборка alias/target |
| `ai:prompts:sync` | 95 | б | `check()` — детектор дрейфа файлы↔БД (37–94); **команда вызывает СИДЕР** `Database\Seeders\PromptSeeder` (30, 39) |
| `ai:tools:doctor` | 89 | б | `VERSION_ARGS` + `probe()` — доменное знание о системных инструментах, не про CLI |
| `ai:prompts:apply` | 81 | **а** | эталон: аргументы → гварды → `PromptPatchApplier` → печать |
| `ai:bench:formats` | 69 | **а** | вся логика в `FormatBenchRunner`; в команде — путь артефакта и запись файла |

Итог: класса (а) — ровно две команды (`ai:prompts:apply`, `ai:bench:formats`), ещё две
условно (`ai:eval:compare`, `ai:caches`).

**Общего базового класса и трейтов у команд НЕТ** (`grep -n "extends" app/Console/Commands/Ai/*.php`
→ 18 совпадений, все на `Illuminate\Console\Command`; `grep "use Concerns\|trait "` → 0).
Общий код существует только копипастой (§2).

## 2. Дублирование резолва `EvalDiffCommand` ↔ `PromptsSuggestCommand`

**39 строк побайтово идентичны.** Воспроизведение:

```bash
diff <(sed -n '119,157p' app/Console/Commands/Ai/EvalDiffCommand.php) \
     <(sed -n '194,232p' app/Console/Commands/Ai/PromptsSuggestCommand.php)   # пустой вывод
```

| Что резолвится | EvalDiff | PromptsSuggest |
|:--|:--|:--|
| `latestExtractionAnalysis(string $code): ?Analysis` — код фикстуры → `FixtureStoreRepository::findByCode()` → `DocumentStoreRepository::findByExternalId(null, "fixture:{$code}")` → `findVersionByHash(…, $fixture->sha256)` → активный `Analysis(step=extraction)` | 119–144 | 194–219 |
| `fixtureCodeFor(Analysis): ?string` — обратный резолв через `documentVersion->document->external_id`, срез префикса `fixture:` | 146–157 | 221–232 |
| Загрузка/парсинг gold-файла (`config('ai.fixtures.gold_dir')."/{$code}.gold.json"`) | 85–99 | 175–189 |

Отличие, мешающее механическому выносу: неудача резолва в `EvalDiffCommand` возвращает
`self::FAILURE` из `handle()`, а в `PromptsSuggestCommand` — `null` + `$this->error()` ВНУТРИ
приватного метода с продолжением цикла. То есть резолвер сегодня печатает.

Долг опознан самим кодом: докблок `PromptsSuggestCommand:21-22` — «тот же резолв, что
`ai:eval:diff`». Числится Pending Work P7.4 плана `2026.07.26-EST-TRAIN`.

## 3. Прочие повторы между командами (≥2 раз)

| Кусок | Где | Идентичность |
|:--|:--|:--|
| Чтение манифеста (`is_file` → `json_decode` в `try/catch JsonException` → проверка формы → три `error()`) | `FixturesImportCommand:44-62` · `FixturesVerifyCommand:38-56` · `GoldBuildCommand:69-87` | Import↔Verify — побайтово (19 строк); GoldBuild — отличие в имени переменной |
| Отбор фикстур в порядке кода с фильтром `--code` | `GoldBuildCommand:179-185` · `GoldImportCommand:64-70` | побайтово (7 строк) |
| `mimeOf(string): string` (`finfo_open` + fallback) | `DatasetImportCommand:232-238` · `FixturesManifestCommand:211-217` | побайтово (7 строк) |
| Резолв документа+версии вне закупки (`procurement_id => null`, `external_id`, `findVersionByHash` → `createVersion`) | `DatasetImportCommand:185-205` · `GoldImportCommand:143-188` | структурно, разный источник полей |
| sha-шардинг пути блоба `'documents/'.substr($hash,0,2)."/{$hash}"` | `DatasetImportCommand:223` · `GoldImportCommand:168` | идентичное выражение |
| Апсерт `DatasetExample` по `(document_version_id, task, version)` | `DatasetImportCommand:207-217` · `GoldImportCommand:221-232` | одинаковый ключ, разный payload |
| Гвард `--format=json\|md` | `EvalDiffCommand:51-55` · `EvalCompareCommand:37-41` | побайтово (5 строк) |
| `renderJson()` (`PRETTY\|UNESCAPED_UNICODE\|UNESCAPED_SLASHES`) | `EvalDiffCommand:162-165` · `EvalCompareCommand:86-89` | идентичны до имени параметра |
| Хвост `--out` → файл → печать → вердикт | `EvalDiffCommand:107-114` · `EvalCompareCommand:71-78` | отличие 1 строка |
| Маска json-флагов россыпью | 8 файлов, 10 вхождений | общей константы нет |

## 4. Прямой Eloquent в обход репозиториев

`grep -n "::query()\|::where(\|::find(" app/Console/Commands/Ai/*.php`:

- `EvalRunCommand` — 10 мест (96, 119, 147, 170, 198, 221, 262, 329, 509, 599);
- `PromptsSuggestCommand` — 151, 214, 231; `EvalDiffCommand` — 64, 139, 156;
- `DatasetExportCommand` — 143, 152, 186; `PromptsSyncCommand` — 44, 69;
- `EvalCompareCommand` — 52, 53; `DatasetImportCommand` — 207; `GoldImportCommand` — 221;
- `CostReportCommand` — 30 (сырой `selectRaw` прямо в команде).

Правило проекта (`plan.md` §2): персистентность — только через `*StoreRepository`.

## 5. Точечные находки

1. **`GoldBuildCommand:382-385` пишет отчёт в каталог ЧУЖОГО плана** —
   `plans/2026.07.26-EST-TRAIN/research/10-gold-corpus-report.md` захардкожен в продуктовом коде.
   План `2026.07.26-EST-TRAIN` заморожен (D2/D9) и уедет в архив — путь станет мёртвым.
2. **`CachesCommand:106` строит `PrismGeminiProvider` через `$this->laravel->make()`** по имени
   драйвера (`providerForDriver()`, 103–109) — прямое обращение к конкретному провайдеру мимо
   `Contracts\LlmProvider`; назначение совпадает с уже существующим `AiRouteResolver::providerFor()`.
3. **`PromptsSyncCommand` вызывает сидер** (`Database\Seeders\PromptSeeder`, строки 30 и 39):
   разбор файлового канона промтов живёт в слое сидеров, а не сервисов.
4. Контейнер внутри команд вызывается только в двух местах (`CachesCommand:106`,
   `PromptsSyncCommand:30`); `app()`/`resolve()` не используется нигде.
5. `ini_set('memory_limit', …)` перед тяжёлой работой — `GoldBuildCommand:54` (из конфига) и
   `GoldImportCommand:54` (хардкод `'1024M'`).

## 6. Покрытие команд тестами

| Есть свой тест | Нет своего теста |
|:--|:--|
| `ai:eval:run`, `ai:gold:build`, `ai:gold:import`, `ai:dataset:import`, `ai:dataset:export`, `ai:fixtures:import`, `ai:fixtures:verify`, `ai:fixtures:manifest`, `ai:eval:compare`, `ai:cost-report`, `ai:prompts:apply`, `ai:tools:doctor`, `ai:storage:lifecycle` (в `tests/Feature/Infra/`) | **`ai:eval:diff`**, **`ai:bench:formats`**, **`ai:caches`** (тестируются только их сервисы, artisan не вызывается); `ai:prompts:sync` вызывается лишь как подготовка в чужих тестах |

Значимо для P3: переименование и вынос логики у трёх непокрытых команд не ловится сюитом —
регресс проявится только в рантайме.

## 7. Канон промтов: два применителя, один жёсткий глоб

- `database/seeders/PromptSeeder.php:71` — `glob(resource_path('ai/prompts/*/*.md'))`;
- `app/Services/Procurement/Tuning/PromptPatchApplier.php:85` — **тот же глоб**, файл ищется
  ПО `key` перебором front-matter (`findFileByKey()`, 85–99);
- `app/Console/Commands/Ai/PromptsSyncCommand.php:30,39` — вызывает сидер и его `parseAll()`.

Глоб односегментный (`*/*.md`) — переезд части групп в другой корень (`resources/estimates/…`)
ломает ОБА места молча: сидер перестанет видеть файлы, применитель патчей не найдёт файл по `key`.

Девять групп (`ls resources/ai/prompts/`): кросс-документные `global`, `prompt-critic`,
`screening-global`, `screening-classify` · сметные `extraction-global`, `extraction-discovery`,
`extraction-passport`, `extraction-rows`, `extraction-totals`. Разбиение 4↔5 совпадает
байт-в-байт с расщеплением `PromptStep` в P2.5 (общий enum — 4 кросс-документных кейса).

## 8. `app/Dto/Procurement/Ai/**`: восемь каталогов и их потребители

`grep -rl "Dto\\Procurement\\Ai\\<Каталог>" app/ tests/ database/`:

| Каталог | Файлов | Потребители (кроме самих DTO) | Контракт LLM-вызова? |
|:--|--:|:--|:--|
| `Estimate` | 16 | `Extraction/**` (уезжает P2.4), `EstimateStoreRepository`, `DatasetImportCommand`, `DatasetExportCommand` | **да** — ответы шагов извлечения |
| `Gsfx` | 13 | `Prepare/GsfxStrategy`, `Prepare/Gsfx/GsfxReader`, `Gold/GsfxGoldMapper` | **нет** — детерминированный разбор формата ГрандСметы |
| `Gold` | 7 | `Gold/FormProfileReader`, `Gold/GridRecognitionGate`, `Gold/SheetGoldMapper` | **нет** — профили форм эталона |
| `Diff` | 2 | `Extraction/EstimateDiffReport` (уезжает P2.4) | нет |
| `Fixture` | 2 | `FixturesImportCommand`, `FixturesVerifyCommand` | нет |
| `Screening` | 1 | `Screening/ScreeningProcessor`, `Gold/ScreeningGoldBuilder`, `EvalRunCommand`, `GoldImportCommand`, 6 тестов LLM-слоя | **да** — ответ скрининга |
| `Bench` | 1 | `Bench/FormatBenchRunner` | нет |
| `Tuning` | 1 | `Tuning/PromptCritic` | **да** — ответ критика промтов |

## 9. Репозитории AI-подомена и их потребители

`grep -rl "Repositories\\Procurement\\<Имя>" app/ tests/ database/` (без самого файла):

| Репозиторий | Потребители | Предметность |
|:--|:--|:--|
| `EstimateStoreRepository` | **только** `Extraction/RerunRangeAction`, `Extraction/EstimatePersister` — обе единицы уезжают в каталог анализатора в P2.4 | сметный, потребители чисто сметные |
| `FixtureStoreRepository` | 5 команд + `Bench/FormatBenchRunner` + тест реестра | после D18 (`type_profile`) — кросс-типовой |
| `AnalysisStoreRepository` | скрининг, извлечение, jobs, Filament, `LlmCacheManager` | кросс-документный |
| `DocumentStoreRepository` | 20 потребителей: Prepare, Screening, Extraction, jobs, 4 команды | кросс-документный |
| `PromptSuggestionStore/ReadRepository` | `Tuning/PromptCritic`, `Tuning/PromptPatchApplier`, `PromptsApplyCommand` | кросс-документный (D21) |
| `RepresentationStoreRepository` | `Prepare/**`, `Bench/FormatBenchRunner` | кросс-документный |
| `Procurement*`/`ProcurementDetail*` | crawling/detail-контур | вне AI-подомена |

Транспортные (`AiRouteReadRepository`, `PromptReadRepository`, `AiCallStoreRepository`) в этой
таблице не значатся: их переезд в `App\Repositories\Llm\**` исполняет **P1.5** (D22).

## 10. Остаточные каталоги сервисов AI-подомена, НЕ классифицированные P2.4

P2.4 расщепляет только `app/Services/Procurement/Extraction/**`. Рядом остаются:

| Каталог | Файлы | Предметность по факту кода |
|:--|:--|:--|
| `Bench` | `FormatBenchRunner` | сметный: несёт литерал `'estimate'` (`:93`, `:259` — список P2.2), гоняет форматы СМЕТНЫХ файлов |
| `Gold` | `GoldBuilder`, `GoldValidator`, `FormProfileReader`, `GridRecognitionGate`, `SheetGoldMapper`, `GsfxGoldMapper`, `ScreeningGoldBuilder` | смешанный: шесть — эталон СМЕТЫ, `ScreeningGoldBuilder` — эталон СКРИНИНГА (кросс-документный) |
| `Tuning` | `PromptCritic`, `PromptPatchApplier`, `EvalRunComparator` | кросс-документный (D21: `kind` — форма предложения) |
| `Prepare` | 15 единиц, включая `GsfxStrategy` и `Gsfx/GsfxReader` | стадия подготовки кросс-документная, но два файла знают формат ГрандСметы |

Список неймспейсов ЯДРА для arch-теста P2.6 (контракт P2.1, п.6) обязан покрывать
`App\Services\Procurement\Screening`, машинерию `App\Services\Procurement\Extraction` и
`App\Services\Llm`; `Bench`/`Gold`/`Prepare` в него по контракту НЕ входят.

## 11. Цитирование имён команд вне кода

`grep -rl "ai:eval\|ai:gold\|ai:fixtures\|ai:bench\|ai:dataset" docs/ .ai/ .claude/ CLAUDE.md resources/`
(строк с совпадением на файл):

`docs/specs/v2/08-estimate-extraction.md` 7 · `.claude/skills/prompt-engineering-agelto/SKILL.md` 4 ·
`docs/03_Dev/commands.md` 3 · `.claude/skills/estimate-extraction-dev/SKILL.md` 3 ·
`resources/ai/prompts/README.md` 2 · `docs/03_Dev/database.md` 2 ·
`docs/03_Dev/ai/feature-card-estimate-extraction.md` 2 · `docs/03_Dev/recipes.md` 1 ·
`docs/03_Dev/ai/prompts.md` 1 · `docs/03_Dev/ai/feature-card-screening.md` 1 · `CLAUDE.md`.

Три доменных скилла (`estimate-extraction-dev`, `llm-layer-dev`, `prompt-engineering-agelto`) —
ЛОКАЛЬНЫЕ: маркера `swissknifeman:managed` в них нет (`grep -c` → 0), правятся напрямую.
`CLAUDE.md` — сгенерированный хаб, вручную не правится (правится `.ai/guidelines/**`).

## 12. Планировщик на `ai:*` командах не стоит

`grep -n "Schedule::command" routes/console.php` — шесть строк: `partitions:maintain`,
`crawler:run-fresh`, `crawler:fetch-details`, `crawler:rescan-backlog`, `pipeline:retry-failed`,
`pipeline:stuck-check`. Ни одной `ai:*`. Факт подтверждает D12 (deprecated-алиасы не нужны):
внешних вызывателей у переименовываемых команд нет.
