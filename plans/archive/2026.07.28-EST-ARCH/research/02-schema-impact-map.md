# 02 — карта ударной волны схемных правок P1: «что меняется → кто ломается → чей item»

Слой 2 (`plan-protocol` §16). Авторский синтез сессии `plan-design P1.4` от **2026-07-29**.
Стоит на трёх ЗАМОРОЖЕННЫХ контрактах слоя `root/` — `naming-canon.md` (P1.1),
`document-type-axis.md` (P1.2), `document-contour.md` (P1.3) — и на грепах по репозиторию.

**Зачем документ.** Контракты говорят, ЧТО становится в схеме. Они не перечисляют, КАКОЙ КОД
ломается от каждой такой правки — а именно этот перечень обязан стоять в `Files` исполнительского
item'а: дифф вне `Files` — материальное отклонение (`plan-protocol` §6). До этой сессии `Files`
P1.4 и P1.6 покрывали контракты частично, и обе дыры были названы в Pending Work P1.1/P1.2/P1.3.
Документ закрывает их поимённо и служит входом для DoR обоих items.

**Якорь фактов:** все перечни сняты на `4094d37` командами, названными рядом с таблицей.
RAG:— (repo-grounded: якорь и команды ниже).

---

## 1. Метод

Для каждой схемной правки контракта задаётся один вопрос: **«какой код читает или пишет ЭТУ
колонку сегодня»**. Ответ снимается грепом по имени колонки/поля, а не рассуждением. Дальше каждый
найденный файл получает закрывателя — item, в чьих `Files` он обязан стоять. Файл без закрывателя =
дыра: исполнитель либо уедет диффом вне `Files`, либо оставит красный сюит следующему item'у.

Правка делится на три класса по силе удара:

| Класс | Что происходит с потребителем | Пример |
|:--|:--|:--|
| **Ломающая (B)** | код перестаёт работать: колонка исчезла, стала `NOT NULL`, сменила имя | переезд `class` в `type_profile` |
| **Вынужденная (F)** | код работает, но становится ложью или недозаполняет строку | `EvalRun::create()` без `task` при `task NOT NULL` |
| **Косметическая (C)** | докблок/комментарий называет несуществующее | комментарий «code из `prc_document_types`» |

---

## 2. P1.4 — ось типа документа и `type_profile` (контракт `document-type-axis.md`)

### 2.1. `prc_ai_fixtures`: пять полей уезжают в `type_profile jsonb` (§6.2 контракта)

Самая широкая волна фазы. Команда инвентаря:

```
grep -rn "expected_estimates\|expected_rows\|gold_source\|FixtureClass\|GoldSource\|'form'" \
  app/ database/ --include=*.php | grep -v "^database/migrations"
```

| Файл | Строки на `4094d37` | Класс | Что именно ломается | Стоял в `Files` P1.4 ДО этой сессии |
|:--|:--|:--|:--|:--|
| `app/Models/Procurement/Ai/Fixture.php` | `:26,39-41,63,74-76,86-87,93-94` | B | `$fillable`, `casts()`, `@property` пяти полей | да |
| `app/Dto/Procurement/Ai/Fixture/FixtureManifestEntry.php` | `:16,36,39,47-48` | B | свойства DTO манифеста | да |
| `database/factories/Procurement/Ai/FixtureFactory.php` | `:25,27,38-40,51,61,64-65` | B | три states пишут поля напрямую | да (глоб `factories/Procurement/Ai/*.php`) |
| `app/Console/Commands/Ai/FixturesImportCommand.php` | `:155,166-168` | B | пишет пять полей из DTO в модель | да |
| `app/Console/Commands/Ai/FixturesManifestCommand.php` | `:120,125-126` | B | собирает манифест из этих полей | да |
| `app/Services/Procurement/Gold/ScreeningGoldBuilder.php` | `:30-40,48` | B | ветвится на `$fixture->class` | да |
| **`app/Console/Commands/Ai/GoldBuildCommand.php`** | `:195,200,204,246-248,262-264,291,303-305` | **B** | ЧИТАЕТ `class`/`gold_source`, ПИШЕТ `expected_rows`/`expected_estimates`/`gold_source` | **НЕТ — дыра** |
| **`app/Console/Commands/Ai/EvalRunCommand.php`** | `:510-513,523` | **B** | `where('gold_source', …)->orderBy('form')->get(['code','form','source_format'])` — единственный SQL-предикат из пяти (§6.2 контракта) | **НЕТ — дыра** |
| **`app/Console/Commands/Ai/FixturesVerifyCommand.php`** | `:140-141` | **B** | сверяет `form` и `gold_source` реестра с манифестом | **НЕТ — дыра** |
| **`app/Services/Procurement/Bench/FormatBenchRunner.php`** | `:156` | **B** | `filter(fn … => $fixture->gold_source === GoldSource::Deterministic)` | **НЕТ — дыра** |
| **`estimates/manifest.json`** | 24 записи | **B** | артефакт-источник импорта: ключи `doc_type`/профильных полей + новые `owner_document_type` и `title` (D90) | **НЕТ — дыра** (названа в Pending Work P1.1) |

Enum'ы `FixtureClass` и `GoldSource` НЕ трогаются: значения переезжают в jsonb как есть, кастование
делает модель/анализатор. `app/Filament/Resources/Procurement/Ai/Analyses/Pages/ViewAnalysis.php`
попал в широкий греп по подстроке `'form'` (Filament-схема) и к фикстурам отношения не имеет —
исключён проверкой строк.

### 2.2. `prc_ai_fixtures`: `doc_type` → `document_type`, `+ owner_document_type`, `+ title`

```
grep -rln "doc_type" app/ database/ tests/ --include=*.php | grep -vi gsfx
```
даёт 8 файлов кода (все уже перечислены в §2.1) плюс 4 теста
(`tests/Feature/Ai/{FixtureImportTest,FixtureVerifyTest,GoldBuildTest,ScreeningGoldTest}.php`).
`GsfxPassport::$doc_type` — внешний формат, вне канона (`naming-canon.md`, «Вне карты»).

Новая колонка `title text NOT NULL` (D90) даёт четвёртого потребителя того же периметра: фабрика,
DTO манифеста, команда манифеста, сам манифест (бэкфилл — `original_filename` без расширения).

### 2.3. `prc_ai_dataset_examples`: заводится `document_type NOT NULL` (§3.5 контракта)

```
grep -rn "DatasetExample::query()->create\|DatasetExample::query()->updateOrCreate" app/ --include=*.php
```

| Писатель | Строка | Класс | Стоял в `Files` P1.4 |
|:--|:--|:--|:--|
| `app/Services/Procurement/Extraction/GoldExampleRecorder.php` | `:63` | B (`NOT NULL` без значения = отказ БД) | **НЕТ — дыра** |
| `app/Console/Commands/Ai/GoldImportCommand.php` | `:221` | B | **НЕТ — дыра** |
| `app/Console/Commands/Ai/DatasetImportCommand.php` | `:207` | B | **НЕТ — дыра** |

Три писателя, ноль в `Files`. Это самая «тихая» дыра фазы: без неё P1.4 закрывается зелёным
`--filter`, а полный сюит краснеет на первом же тесте датасета.

### 2.4. `prc_ai_corrections`: `document_type` → `NOT NULL` (§3.2 контракта)

| Писатель | Строка | Класс | Стоял в `Files` |
|:--|:--|:--|:--|
| `app/Services/Procurement/Tuning/PromptPatchApplier.php` | `:70` (`applyHint()`) | F — источник (`prc_ai_prompt_suggestions.document_type`) станет `NOT NULL` только в P1.8, между P1.4 и P1.8 может прийти NULL | да |
| `app/Filament/Resources/Procurement/Ai/Analyses/Pages/ViewAnalysis.php` | `:110-112` — `'document_type' => $record->document_type` | **B** — `prc_ai_analyses.document_type` ОСТАЁТСЯ nullable (§3.1 контракта), значит оператор, правящий анализ без вердикта скрининга, упрётся в `NOT NULL` правки | **НЕТ — дыра** |

Это единственное место плана, где два решения контракта сталкиваются: «анализ может не знать тип»
(§3.1) и «правка всегда знает тип» (§3.2). Разрешение — на границе записи, а не в схеме: см.
Implementation Rules P1.4 п. 12.

### 2.5. `prc_ai_eval_runs`: заводятся ОБЕ оси — `document_type NOT NULL` + `task NOT NULL` (§3.4)

`EvalRun::$fillable` — восемь полей, ни `task`, ни `document_type`
(`app/Models/Procurement/Ai/EvalRun.php:23-33`). Писателей три, все в одном файле:

| Точка | Строка | Значение `task` | Значение `document_type` |
|:--|:--|:--|:--|
| dry-run извлечения | `EvalRunCommand:119` | `extraction` | `estimate` |
| боевой прогон извлечения | `EvalRunCommand:147` | `extraction` | `estimate` |
| прогон скрининга | `EvalRunCommand:198` | `screening` | сентинел `any` (§3.4 контракта: прогон скрининга кросс-документный) |

Источник значения `task` УЖЕ есть — опция `--task` валидируется на `:80`, но в строку не пишется.
`EvalRunCommand` попадает в `Files` P1.4 дважды: здесь и по §2.1.

### 2.6. `llm_prompts`: ключ `(key, version)` → `(document_type, key, version)` (§4.1)

| Файл | Класс | Что делает item |
|:--|:--|:--|
| `resources/ai/prompts/*/*.md` — девять файлов | B | обязательное поле `document_type` во front-matter; `version` НЕ поднимается |
| `database/seeders/PromptSeeder.php` | B | `parseFile()` `:104` (список обязательных) + `:111` (жёсткая валидация), `run()` `:33-34` (upsert по тройке), `:45-46` (деактивация группируется по паре) |
| `app/Services/Procurement/Tuning/PromptPatchApplier.php` | B | `:120` требует РОВНО `['key','step','version','priority','is_active']` — второй применитель того же канона |
| **`app/Filament/Resources/Procurement/Ai/Prompts/Schemas/PromptForm.php`** | **F** | `document_type` обязан стать `Select` по `AiDocumentType` + сентинел, иначе check-constraint ловит оператора отказом БД (§4.1 контракта) | **НЕТ — дыра** |

### 2.7. `llm_routes`: `+ shadow` в unique (§4.2)

| Файл | Класс | Что делает item |
|:--|:--|:--|
| `database/seeders/AiRoutingSeeder.php` | F | одиннадцать строк, три с сентинелом; ключ меняется — upsert обязан сойтись |
| `database/factories/Procurement/Ai/AiRouteFactory.php` | F | заводит теневой маршрут с тем же `priority = 0` |
| `tests/Feature/Llm/ShadowDispatchTest.php` | B | хелпер `seedShadowRoute()` `:35`, вызовы `:51,79,94,137` — на засеянной БД теневой маршрут сегодня не создаётся вовсе |
| **`app/Filament/Resources/Procurement/Ai/AiRoutes/Schemas/AiRouteForm.php`** | **F** | `document_type` — свободный `Textarea` `:19`, тот же довод, что в §2.6 | **НЕТ — дыра** |

### 2.8. Новые единицы, которых нет в репозитории вовсе

| Единица | Основание | Класс |
|:--|:--|:--|
| `App\Enums\Llm\DocumentTypeScope` (единственный кейс `Any = 'any'`) | §1.2 контракта — «каталог `app/Enums/Llm/` заводится этим решением» | новая |
| `AiDocumentType::values(): list<string>` | §1.3 правило 1 — единственный носитель списка значений для восьми check-constraint'ов | правка существующего enum'а |

Замена ГОЛЫХ литералов `'any'` на call-site'ах — предмет **P2.2** (§3 Routing: «замена литералов
по уже утверждённой форме носителя»), НЕ P1.4. P1.4 заводит носитель и ставит check'и.

### 2.9. Снос `prc_document_types` (D16)

```
grep -rln "prc_document_types\|Platform\\\\DocumentType\|has_extractor" app/ database/ tests/ docs/ .ai/
```
— 6 файлов кода/миграций (все в `Files`) и 3 документа `docs/specs/v2/{01-database,08-estimate-extraction,03-document-pipeline}.md`,
у которых закрыватель — **P7.3** (D55/D65), а не P1.4. Периметр греп-гейта P1.4 поэтому и
ограничен `app/ database/ tests/` (D62).

---

## 3. P1.6 — единый контур после D91/D92/D93 (контракт `document-contour.md`)

ТЗ P1.6 писалось под ПЕРВУЮ редакцию контракта и говорит про «ось происхождения», «enum
происхождения» и «признак усечения». Владелец отверг всё три (D91/D92/D93). Ниже — что фактически
заказывает замороженный контракт.

### 3.1. Ось контура — `prc_documents.platform_id` (§1, D91)

| Единица | Что делает P1.6 | Стояла в `Files` |
|:--|:--|:--|
| `database/migrations/2026_07_04_100000_create_prc_platforms_table.php` | `+ is_public boolean NOT NULL DEFAULT true`; `base_url` → nullable | **НЕТ — дыра** |
| `app/Models/Procurement/Platform/Platform.php` | `$fillable`/`casts()`/`@property` двух колонок | **НЕТ — дыра** |
| `database/factories/Procurement/Platform/PlatformFactory.php` | state служебной площадки | **НЕТ — дыра** |
| новый сидер площадок + `database/seeders/DatabaseSeeder.php` | строки площадки `training` в чистой БД; сегодня площадки НЕ сидятся вовсе (`DatabaseSeeder` зовёт только `AiRoutingSeeder`/`PromptSeeder`/`StatusMappingSeeder`) | **НЕТ — дыра** |
| `database/migrations/2026_07_04_100018_create_prc_documents_table.php` | `+ platform_id bigint NOT NULL` FK + index | да |
| `app/Services/Procurement/DocumentIndexer.php` | `:51` — единственная точка создания боевого документа, обязана проставить площадку закупки | **НЕТ — дыра** |

`prc_platforms` — это crawling-контур, объявленный неприкосновенным в `## 2. Execution Rules`.
Запрет снят D91 в границах РОВНО двух колонок и одной строки данных; текст правила §2 приведён в
соответствие этой же сессией.

### 3.2. Связь «документ ↔ эталон» — `prc_ai_fixtures.document_id` (§2)

| Единица | Что делает P1.6 | Стояла в `Files` |
|:--|:--|:--|
| миграция фикстур | `+ document_id bigint NULL UNIQUE` FK `ON DELETE SET NULL` | да |
| `app/Console/Commands/Ai/GoldImportCommand.php` | `resolveWorkingCopy` `:142-143` пишет связь; `:152` — площадка «Обучалка» | да |
| `app/Console/Commands/Ai/DatasetImportCommand.php` | `:186` — площадка «Обучалка» | да |
| `app/Console/Commands/Ai/EvalDiffCommand.php` | `:127` — `findByExternalId(null, "fixture:{code}")` → `$fixture->document` | **НЕТ — дыра** |
| `app/Console/Commands/Ai/PromptsSuggestCommand.php` | `:202` — то же | **НЕТ — дыра** |
| `app/Services/Procurement/Bench/FormatBenchRunner.php` | `:104-112` мост через `DatasetExample`; `:114` гвард; `:45` докблок гварда | да |

### 3.3. Прогон `AnalysisRun` (§3, D93)

| Единица | Что делает P1.6 | Стояла в `Files` |
|:--|:--|:--|
| новая миграция `prc_ai_analysis_runs` | таблица §3.2 контракта + partial unique `(document_version_id) WHERE finished_at IS NULL` | да |
| `App\Enums\Procurement\{RunMode,AnalysisRunStatus}` | `auto`/`manual`; `pending`/`running`/`succeeded`/`failed` | да (обобщённо) |
| модель `AnalysisRun` + фабрика + `AnalysisRunStoreRepository` | по карте `naming-canon.md` класс 7 | да (обобщённо) |
| `database/migrations/2026_07_04_100025_create_prc_ai_analyses_table.php` | `+ analysis_run_id bigint NOT NULL` FK + index; partial unique НЕ трогается | да |
| **миграция таблицы вызовов** (`…_100026_…`, имя файла — по итогу P1.5) | `+ analysis_run_id bigint NULL` + index; FK на партиционированную не строится (D93) | **НЕТ — дыра** |
| `app/Repositories/Procurement/AnalysisStoreRepository.php` | `firstOrCreate` резолвит активный прогон и бросает `NoActiveAnalysisRun`; `createShadow` берёт прогон у основного; `createRerun` — уже после нового прогона | да |
| **шесть точек создания прогона** | `PrepareDocumentJob:82`, `PipelineRetryFailed:131`, `RerunAnalysisAction:53-54`, `FormatBenchRunner`, `EvalRunCommand:262,329` (+ экран P5.2 — вне P1.6) | **НЕТ — дыра** |
| **точки терминации прогона** | переход `succeeded`/`failed` — иначе partial unique навсегда запрёт версию (§3.6 контракта) | **НЕТ — дыра** |
| `app/Console/Commands/Procurement/PipelineStuckCheck.php` | видит «зависший» контур; после прогона обязан видеть незакрытый прогон | **НЕТ — дыра** |

**Точка терминации — критичная дыра.** Контракт §3.6 прямо говорит: P1.6 заводит колонки И переходы
и вызывает их в терминальных точках; P4.1 в ТЕХ ЖЕ точках дописывает значения слепка. Item, который
создаёт прогон, но не финиширует его, оставляет схему, в которой ВТОРОЙ прогон версии невозможен
никогда — и обнаруживается это не тестом, а на второй попытке разбора.

### 3.4. Правило показа (§4)

| Единица | Что делает P1.6 | Стояла в `Files` |
|:--|:--|:--|
| `database/migrations/2026_07_04_100033_create_prc_estimates_table.php` | `+ platform_id bigint NOT NULL` FK + index | да |
| `app/Services/Procurement/Extraction/EstimatePersister.php` | `:102` рядом с `procurement_id` — `platform_id` документа | да |
| `App\Models\Scopes\PublicPlatformScope` (каталога `app/Models/Scopes/` НЕТ) | единственное определение фильтра + именованный `->publicPlatform()` на `Document`/`Estimate`/`Procurement` | **НЕТ — дыра** (модель `Procurement` не названа) |
| `app/Models/Procurement/Procurement/*` | третий потребитель скоупа по §4.1 контракта | **НЕТ — дыра** |

Греп-гейт §4.3 п.2 (`app/Http/` — прямых обращений к моделям нет) на `4094d37` зелен по построению:
`app/Http/` содержит только `Controllers`/`Middleware`, а `routes/web.php` — один маршрут `/` с
`view('welcome')`. Публичной витрины ещё нет вовсе. Это ЗНАЧИМО для D89: гейт, зелёный на `<base>`,
ничего не измеряет — поэтому он остаётся в Validation как СТОРОЖ (не даёт витрине родиться мимо
скоупа), но фальсифицируемость P1.6 доказывается тестом §4.3 п.3, а не им.

### 3.5. Наследование площадки дочерними документами архива (§1.6)

`ArchiveStrategy:186` и `GsfxStrategy:284` копируют `procurement_id` родителя — обязаны копировать и
`platform_id`. Оба файла в `Files` P1.6 были, но по другому поводу (карта call-site'ов).

### 3.6. Что ТЗ P1.6 заказывало и чего контракт больше не заказывает

| Формулировка ТЗ до этой сессии | Судьба |
|:--|:--|
| «ось происхождения», enum происхождения, check по D17 на ней | СНЯТО D91: ось — FK `platform_id`, целостность держит внешний ключ |
| «ограничение объёма и признак усечения», тест «усечённый лимитом прогон помечен усечённым» | СНЯТО D92: лимита нет вовсе |
| «именованный/глобальный скоуп показа на `Document` и `Estimate`» | УТОЧНЕНО §4.1: ОДИН именованный скоуп на ТРЁХ моделях, глобальный отвергнут владельцем |
| Validation 3: греп `procurement_id` = «число структурных call-site'ов» | УТОЧНЕНО §5/§7 контракта: ровно **6** (из восьми строк уходят `FormatBenchRunner:114` и докблок `:45`) |

---

## 4. Сводка дыр, закрытых этой сессией

| Item | Файлов добавлено в `Files` | Класс дыры |
|:--|:--|:--|
| P1.4 | 10 (`GoldBuildCommand`, `EvalRunCommand`, `FixturesVerifyCommand`, `FormatBenchRunner`, `GoldExampleRecorder`, `GoldImportCommand`, `DatasetImportCommand`, `ViewAnalysis`, `PromptForm`, `AiRouteForm`) + артефакт `estimates/manifest.json` + две новые единицы (`DocumentTypeScope`, `AiDocumentType::values()`) | B/F — красный сюит либо отказ БД |
| P1.5 | целевой путь Filament-ресурса маршрута (`LlmRoutes/`, `LlmRouteResource`) | дыра карты P1.1, названная ею самой |
| P1.6 | миграция и модель `Platform`, фабрика площадки, сидер + `DatabaseSeeder`, `DocumentIndexer`, миграция вызовов (D93), шесть точек создания прогона и точки терминации, `PipelineStuckCheck`, `EvalDiffCommand`, `PromptsSuggestCommand`, `PublicPlatformScope` + модель `Procurement` | B — часть даёт неработающую схему, часть — молчаливую потерю |
| P3.5 | `AiRouteResolver` → `LlmRouteResolver` и 22 файла-потребителя (39 вхождений) | строка карты P1.1 без отражения в ТЗ исполнителя |

---

## 5. Как воспроизвести

Все команды — на `4094d37`, из корня репозитория:

1. Профильные поля фикстур: `grep -rn "expected_estimates\|expected_rows\|gold_source\|FixtureClass\|GoldSource" app/ database/ --include=*.php | grep -v "^database/migrations"`
2. Второе имя типа: `grep -rln "doc_type" app/ database/ tests/ --include=*.php | grep -vi gsfx` — 11 файлов.
3. Писатели датасета: `grep -rn "DatasetExample::query()->" app/ --include=*.php` — 3.
4. Писатели правок: `grep -rn "Correction::query()->create" app/ --include=*.php` — 1 (плюс `PromptPatchApplier::applyHint`).
5. Писатели прогонов оценки: `grep -n "EvalRun::query()->create" app/Console/Commands/Ai/EvalRunCommand.php` — 3 (`:119`, `:147`, `:198`).
6. Точки диспатча: `grep -rn "ScreeningJob::dispatch\|EstimateExtractionJob::dispatch\|ShadowAnalysisJob::dispatch" app/ --include=*.php` — 6 строк в 5 файлах.
7. Резолв рабочей копии по конвенции: `grep -rn "findByExternalId" app/ --include=*.php` — 3 вызова с `null` вне `GoldImportCommand`.
8. Отсутствие сидера площадок: `grep -rn "Platform::" database/seeders/` — пусто.
9. Отсутствие каталога скоупов: `ls app/Models/Scopes/` — `No such file or directory`.
10. Пустота публичного слоя: `ls app/Http/` — `Controllers`, `Middleware`; `cat routes/web.php` — один маршрут.
