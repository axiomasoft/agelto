# P6.1 — Инвентарь носителей файловых ссылок (слой 1: факты дерева)

**Слой 1.** Здесь только факты дерева с путями и строками. Вердикты, контракты и процедура —
`research/07-storage-addressing.md` (слой 2), он ссылается сюда.

**Якорь дерева:** все числа и строки сняты на `532ba71` (`git rev-parse --short HEAD`).
Незакоммиченный чужой дифф в дереве на момент сбора: `M .env.example`, `M CLAUDE.md`,
untracked `.swissknife.json`, `.swissknife/`, `.swissknifeman/`, `config/cms.php`,
`database/seeders/User/`, `estimates/gemini-analys.md` — ни один не лежит в `app/`,
`config/` или `database/migrations/`, то есть на инвентарь не влияет.

## 1. Метод сбора (воспроизводимая команда)

```bash
# из корня репозитория agelto
python3 plans/2026.07.31-SYS-INTEGRITY/artifacts/collect-storage-carriers.py \
    > plans/2026.07.31-SYS-INTEGRITY/artifacts/storage-carriers.json
```

Скрипт делает три вещи и падает с ненулевым кодом, если инвентарь отстал от схемы:

1. **Находит все колонки-кандидаты** в `database/migrations/**` регуляркой по
   `storage_path` / `*_ref` / `*_path` — и в Eloquent-миграциях (`$table->text('…')`), и в
   СЫРЫХ SQL-миграциях партиционированных таблиц, где `$table->` не встречается вовсе
   (`llm_calls`, `prc_fetches`). Именно из-за второго случая грепа по `$table->text` было бы
   недостаточно.
2. **Собирает call-site'ы** — по имени колонки и по каждому конфиг-ключу диска, плюс все
   `Storage::disk(...)` в `app/**`.
3. **Проверяет полноту**: колонка из миграций, не описанная ни в реестре носителей, ни в
   поимённом списке «похоже на адрес, но им не является», попадает в
   `completeness.unlisted_columns`. На `532ba71` список **пуст** — `completeness.verdict:
   "полон"`, код возврата 0.

Встречные грепы Validation item'а (те же числа, независимо от скрипта):

```bash
grep -rn "Storage::disk" app/ | wc -l                      # → 39
grep -rn "storage_path\|_ref'" database/migrations/ | wc -l # → 9 (грепа Validation НЕДОСТАТОЧНО: сырой SQL он не видит — см. §1 п.1)
```

## 2. Колонки-кандидаты, найденные в миграциях (12 на `532ba71`)

Полный вывод — `artifacts/storage-carriers.json` → `completeness.declared_path_columns`.

| Колонка | Объявлена в | Класс |
|:--|:--|:--|
| `prc_document_versions.storage_path` | `2026_07_04_100019` | **носитель** |
| `prc_ai_fixtures.storage_path` | `2026_07_27_100053` | **носитель** |
| `prc_estimates.raw_result_ref` | `2026_07_04_100033` | **носитель** |
| `prc_document_representations.storage_path` (+ `disk`) | `2026_07_27_100055` | **носитель**, колонка диска ЕСТЬ |
| `llm_calls.request_ref` / `response_ref` | `2026_07_04_100026` (сырой SQL) | **носитель**, таблица партиционирована |
| `prc_fetches.artifact_ref` | `2026_07_04_100041` (сырой SQL) | **носитель**, таблица партиционирована |
| `prc_ai_dataset_examples.gold_ref` | `2026_07_04_100030` | **носитель** заведён, писателя нет |
| `prc_ai_fixtures.source_path` | `2026_07_27_100053` | не носитель: путь в корпусе-ИСТОЧНИКЕ (провенанс) |
| `prc_ai_corrections.field_path` | `2026_07_09_100052` | не носитель: JSONPath поля результата |
| `prc_events.entity_ref` | `2026_07_04_100013` (сырой SQL) | не носитель: jsonb-ссылка на сущность БД |
| `prc_document_versions.prepared_storage_path` | `2026_07_04_100019` | **колонки НЕТ на дереве** — удалена `2026_07_27_100056` |

Последняя строка — подтверждение поправки 1 из `repo-recon-2026-07-31.md` §12.3: скелет фазы
называл носитель, которого не существует.

## 3. Writer- и reader-пути по каждому носителю

Разделение по носителям сделано **чтением кода**, а не грепом: имя `storage_path` носят три
разные таблицы сразу, и грепом их не различить (в JSON тот же общий греп лежит как
`column_name_sites` — надмножество, честно помеченное).

### 3.1 `prc_document_versions.storage_path` — **пять** writer-путей, а не один

Разведка (`repo-recon` §12.1) называла один — `DocumentDownloader::store()`. Фактически:

| # | Writer | Строки | Что пишет |
|:--|:--|:--|:--|
| 1 | `app/Services/Procurement/DocumentDownloader.php` | `:72`, `:181-191` | боевое скачивание документа закупки |
| 2 | `app/Services/Procurement/Fixture/WorkingCopyProvisioner.php` | `:71-90` | рабочая копия эталона корпуса |
| 3 | `app/Services/Procurement/Analyzers/Estimate/DatasetImporter.php` | `:131-160` | импорт обучающего датасета |
| 4 | `app/Services/Procurement/Prepare/ArchiveStrategy.php` | `:196-206` | дочерняя версия, распакованная из архива |
| 5 | `app/Services/Procurement/Prepare/GsfxStrategy.php` | `:293-303` | дочерняя версия, распакованная из `gsfx`-zip |

Все пять кладут файл на `config('crawler.documents_disk')` и НЕ записывают имя диска — колонки
для него нет. Путь у всех пяти контент-адресован: `DocumentDownloader:184` собирает
`'documents/'.substr($hash,0,2)."/{$hash}"` вручную, остальные — через `ShardedBlobPath::for()`.

Reader-пути: `RepresentationResolver:66-71` (фолбэк на оригинал версии), пять Prepare-стратегий
(`SpreadsheetStrategy:73`, `WordStrategy:51`, `ArchiveStrategy:66`, `GsfxStrategy:55,77`,
`ScanStrategy:82`), `FormatBenchRunner:429`.

### 3.2 `prc_ai_fixtures.storage_path`

| Роль | Файл | Строки |
|:--|:--|:--|
| writer | `Fixture/FixtureUploader.php` | `:59-66` (загрузка через Filament) |
| writer | `Fixture/FixtureRegistrar.php` | `:75-81` (импорт манифеста; диск приходит АРГУМЕНТОМ из `FixturesImportCommand:40`) |
| reader | `Fixture/FixtureVerifier.php` | `:72-79`, `:107` |
| reader | `Fixture/WorkingCopyProvisioner.php` | `:74` |
| reader | `Gold/GoldCorpusBuilder.php` | `:162-165` |
| построитель пути | `app/Models/Procurement/Ai/Fixture.php` | `:142` (`storagePathFor()` — единственный) |

Диск — `config('ai.fixtures.disk')`, дефолт `estimates` (`config/ai.php:518`).

### 3.3 `prc_estimates.raw_result_ref`

Writer один: `Analyzers/Estimate/EstimatePersister.php:344-356` — при `strlen($encoded) >
RAW_RESULT_INLINE_MAX_BYTES` кладёт JSON на `config('crawler.raw_disk')` по пути
`ai/estimates/{analysisId}/{ordinal}-{uuid}.json`. Читателя в дереве **нет**: колонка живёт
как провенанс, потребитель ещё не написан (`EstimateToContractMapper:27` упоминает её в
докблоке, но не читает).

### 3.4 `prc_document_representations.storage_path` + `disk` — колонка есть, реальное имя пишет один call-site

Прецедент `100055:33` (`$table->text('disk')->nullable()->comment('null = диск документов
config(crawler.documents_disk)')`) применён верно **в схеме**, но не в писателях:

| Writer | Строки | Что пишет в `disk` |
|:--|:--|:--|
| `Prepare/SpreadsheetStrategy.php` | `:165`, `:186` | литеральный `null` |
| `Prepare/ScanStrategy.php` | `:141`, `:154` | литеральный `null` |
| `Prepare/GsfxStrategy.php` | `:146`, `:158` | литеральный `null` |
| `Prepare/WordStrategy.php` | `:61` | литеральный `null` |
| `Bench/FormatBenchRunner.php` | `:440` | **реальное имя** (`$fixturesDisk`) — единственный |

`grep -rn "'disk' => null" app/ | wc -l` → **7** (на `532ba71`). То есть на дереве все боевые
представления имеют `disk IS NULL`, и приём «диск в записи» сегодня не работает даже там, где
колонка есть: место чтения по-прежнему выводится из конфига.

Reader-пути: `RepresentationResolver:44,56` (`$representation->disk ?? $documentsDisk`) и
`Analyzers/Estimate/DatasetInputBuilder.php:95` — **тот же фолбэк, скопированный в обход
резолвера**. Разведка §12.3 называла четырёх потребителей контракта резолвера
(`ExtractionOrchestrator:109`, `ScreeningProcessor:87`, `RerunRangeAction:173`,
`FormatBenchRunner:199`); `DatasetInputBuilder` — пятый call-site, который берёт диск сам.

### 3.5 Отладочные носители партиционированных таблиц

| Носитель | Writer | Reader |
|:--|:--|:--|
| `llm_calls.request_ref` / `response_ref` | `app/Services/Llm/LlmCallRecorder.php:117-125` (`storeRaw()`, `ai/llm-calls/Y/m/d/…`) | `app/Services/Llm/LlmPayloadReader.php:26-49` (витрина Filament, лимит чтения 256 КБ) |
| `prc_fetches.artifact_ref` | `app/Services/Procurement/CsvBlockIngestor.php:143-152` (`raw/csv/{crawlerId}/{runId}/{from}.csv`) | читателя в дереве нет |

Обе таблицы созданы СЫРЫМ SQL (`CREATE TABLE … PARTITION BY RANGE (…)`, `100026:13-38`,
`100041:13-30`) — добавление колонки означало бы правку партиционированной схемы.

### 3.6 `prc_ai_dataset_examples.gold_ref` — носитель без писателя

`app/Services/Procurement/Analyzers/Estimate/GoldExampleRecorder.php:78` пишет литеральный
`'gold_ref' => null`; фабрика (`DatasetExampleFactory:26`) — тоже `null`. Конфиг-ключ для
этого носителя не определён нигде. Колонка объявлена «на вырост»
(`100030:29`, комментарий «object storage — для крупных эталонов»).

## 4. Файлы БЕЗ записи-носителя

- **Сырые HTML-снапшоты** — `Eis/CsvSourceMethod.php:202-209`, диск
  `config('crawler.raw_html_disk', config('crawler.raw_disk'))`, путь
  `raw/html/{registryId}/{registryNumber}/{tab}.html.gz`, gzip по
  `crawler.raw_html_gzip`. Запись в БД не создаётся; писатель за флагом
  `crawler.raw_html_retention_enabled` — на дереве `false` (`config/crawler.php:194`).
- **Экспорт датасета** — `Console/Commands/Procurement/Analyzers/Estimate/DatasetExportCommand.php:65-78`;
  диск — опция `--disk` либо `crawler.raw_disk`. Продукт команды, не персистентная ссылка.
- **Рабочие копии эталонов** — `Fixture/WorkingCopyProvisioner.php:71-90`. **Поправка к §12.2
  разведки:** это НЕ «файл без записи-носителя». Провижионер копирует файл эталона на
  `documents_disk` по контент-адресованному пути И СОЗДАЁТ строку `prc_document_versions`
  (`:82-90`) — то есть попадает в носитель §3.1 пятым writer-путём.

## 5. Конфиг-ключи дисков и их значения на дереве

| Ключ | Значение | Объявлен | Диск в `filesystems.php` | Драйвер |
|:--|:--|:--|:--|:--|
| `crawler.documents_disk` | `s3` | `config/crawler.php:228` | `s3` | `s3` |
| `crawler.raw_disk` | `s3` | `config/crawler.php:217` | `s3` | `s3` |
| `crawler.raw_html_disk` | `s3` | `config/crawler.php:192` | `s3` | `s3` |
| `ai.fixtures.disk` | `estimates` (env `AI_FIXTURES_DISK`) | `config/ai.php:518` | `estimates` | **`local`** |

В `config/filesystems.php` объявлено пять дисков: `local`, `public`, `s3`, `estimates`
(driver `local`, root `storage_path('app/private/estimates')`, `:67-72`), `estimates_s3`
(driver `s3`, бакет `AWS_ESTIMATES_BUCKET`, `:74-85`). Диск `estimates_s3` объявлен, но **ни
одним конфиг-ключом не адресуется** — на него сегодня никто не пишет и не читает.

Прямые чтения конфиг-ключей диска в `app/**` — **25** исполняемых строк (без докблоков):
`crawler.documents_disk` — 12, `crawler.raw_disk` — 7, `ai.fixtures.disk` — 7 (из них 1 в
докблоке `Fixture.php:140`), `crawler.raw_html_disk` — 1. Поимённо — в
`artifacts/storage-carriers.json` → `config_disk_keys`.

## 6. Retention и ILM — числа, на которых стоят вердикты

Из `config/partitioning.php` (на `532ba71`):

| Носитель | Retention СТРОКИ | ILM ФАЙЛА |
|:--|:--|:--|
| `llm_calls` | 12 мес, `keep_table: false` (`:50-53`) | префикс `ai/llm-calls/`, expire **90 дней** (`:99-104`) |
| `prc_fetches` | 6 мес, `keep_table: false` (`:54-57`) | правила НЕТ — блоб `raw/csv/` живёт вечно |
| `prc_estimates` | **`eternal`** (`:80-84`) | правила НЕТ — комментарий `:94-95` называет это явно: «`prc_estimates.raw_result_ref` (префикс `ai/estimates/`) и gold-документы (префикс `documents/`) под ЭТО правило НЕ попадают» |
| `prc_ai_dataset_examples` | **`eternal`** (`:80-84`) | правила нет |
| `prc_document_versions`, `prc_ai_fixtures`, `prc_document_representations` | в `retention` не входят | правила нет |

Единственное ILM-правило проекта — `llm_call_blobs`, применяет `MinioLifecycleManager`
(`app/Services/Storage/MinioLifecycleManager.php`) через `mc` CLI; SDK S3 в проекте не
установлен (`aws-sdk-php`/`league/flysystem-aws-s3-v3` отсутствуют — докблок `:18-19`).

## 7. Прочие факты, названные явно

- **`GoldCorpusBuilder:162` вызывает `Storage::disk($disk)->path($fixture->storage_path)`** —
  метода `path()` у S3-драйвера нет. Диск `estimates` имеет драйвер `local`
  (`filesystems.php:67-72`), поэтому построение gold работает; перевод корпуса эталонов на S3
  (`estimates_s3`) сломает его НЕЗАВИСИМО от адресации. Заодно факт порядка: `path()`
  вызывается ДО проверки `exists()` (`:162` против `:164`).
- **Слой `app/Services/Storage/` существует** и содержит ровно один файл —
  `MinioLifecycleManager.php`. `app/Console/Commands/Storage/` не существует.
- **Гейт раскладки P3.4** (`tests/Unit/Architecture/DomainLayoutTest.php:24-49`) судит ровно
  четыре слоя: `App\Enums\Procurement`, `App\Exceptions\Procurement`, `App\Dto\Procurement`,
  `App\Repositories\Procurement`. `App\Services\**` и `App\Console\**` в периметр НЕ входят.
- **`prc_ai_fixtures` не имеет колонки `disk`**, а диск приходит в `FixtureRegistrar::import()`
  аргументом (`:26`) из команды — то есть один и тот же корпус технически можно импортировать
  на разные диски, и запись об этом не сохранится.
