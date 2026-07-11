# P3 Deliverable — Impact-карта семантического ренейма

**Дата создания:** 2026-07-10 (design-фаза `/task:plan-design DOMLANG P3`)
**Источник фактов:** `grep` по `app/ database/ tests/` + `git cat-file -e origin/main:<файл>` — `verified:✅ 2026-07-10`
**Внешние факты (канон имён):** `RAG:✅ 2026-07-10` — см. `plan.md` D5 («реестровый номер извещения» 44-ФЗ; два номера по 223-ФЗ) и `findings/P2-eis-stages.md` («Этап закупки» — 4 значения).
**Назначение:** единственный источник скоупа для P3.1–P3.3. Токен-левел find-replace по `source_number` ЗАПРЕЩЁН (см. §2).

---

## 1. Что переименовывается (D5 + D18 + D19)

| Таблица | Было | Стало | Концепт |
|:--|:--|:--|:--|
| `prc_procurements` | `source_number` | `registry_number` | реестровый номер извещения (ЕИС) |
| `prc_procurements` | `source_guid` | `registry_guid` | GUID извещения в реестре |
| `prc_procurements` | `source_status` | `source_stage` | сырой **этап** ЕИС (не канонический статус) |
| `prc_ignored_items` | `source_number` | `registry_number` | тот же реестровый номер (denylist) — **D19** |
| `prc_status_mappings` | `source_status` | `source_stage` | ключ резолва: сырой этап → `canonical_status` — **D18** |

PHP-идентификаторы едут следом: `$sourceNumber`→`$registryNumber`, `$sourceGuid`→`$registryGuid`,
`$sourceStatus`→`$sourceStage`, `$sourceNumbers`→`$registryNumbers`,
`ignoredSourceNumbers()`→`ignoredRegistryNumbers()`.

**Не заводится ничего нового.** `etp_number` **уже существует** в схеме
(`2026_07_04_100011_create_prc_procurements_table.php:25`, nullable) и имеет полный write-path
DTO→репозиторий→колонка. Строка «`—` → `+ etp_number`» в старом Phase Context P3 была ошибкой:
в P3 колонка только **пинится round-trip-тестом** (D13, item P3.3).

---

## 2. Что НЕ переименовывается — полисемия токена (D20, D6)

`source_number` живёт на трёх таблицах и означает **два разных концепта**:

| Таблица | Концепт | Судьба |
|:--|:--|:--|
| `prc_procurements` | реестровый номер закупки | → `registry_number` (D5) |
| `prc_ignored_items` | реестровый номер закупки | → `registry_number` (D19) |
| `prc_protocols` | **собственный номер протокола**, nullable | **остаётся `source_number`** (D20) |

Зеркально: `registry_number` **уже занят третьим концептом** —
`prc_awards.contract_registry_number` (номер контракта в реестре контрактов). Он не трогается и
не «унифицируется» с `prc_procurements.registry_number`.

По D6 не трогаем: `raw_result`, `prc_eis_details`, группу `detail_*`, `prc_crawlers.source_method`.
Ренейм анкорится на **список файлов §3**, а не на голый токен (skill `namespace-refactor-safety`).

---

## 3. Пофайловый инвентарь

### 3.1 Группа R — переименовать (32 файла)

**Схема (3):**
- `database/migrations/2026_07_04_100011_create_prc_procurements_table.php` — `:24` `source_number`, `:29` `source_status`, `:61` `source_guid`, `:78` `unique(['registry_id','source_number'])`
- `database/migrations/2026_07_04_100044_create_prc_ignored_items_table.php` — `:22`, `:28` (unique)
- `database/migrations/2026_07_04_100002_create_prc_status_mappings_table.php` — `:20`, `:24` (unique)

**Модели (3):** `app/Models/Procurement/Procurement.php` (`:31`, `:36`, `:59`) ·
`app/Models/Procurement/Crawler/IgnoredItem.php` (`:27`) ·
`app/Models/Procurement/Platform/StatusMapping.php` (`:25`)

**DTO (3):** `app/Dto/Procurement/ProcurementSnapshotData.php` (`:22`, `:24`, `:44`, `:51` docblock, `:59`, `:62`, `:82`) ·
`app/Dto/Procurement/ProcurementDetails.php` (`:15`) ·
`app/Dto/Procurement/StatusResolution.php` (`:11` docblock)

**Репозитории (2):** `app/Repositories/Procurement/ProcurementStoreRepository.php` (`:20`, `:25`, `:43`, `:62`, `:79`) ·
`app/Repositories/Procurement/ProcurementReadRepository.php` (`:13`, `:17`)

**Сервисы (7):** `app/Services/Procurement/CsvBlockIngestor.php` (`:44`, `:65`, `:76`, `:112`, `:134`, `:138`, `:139`, `:153`, `:160` + метод `ignoredSourceNumbers`) ·
`app/Services/Procurement/ProcurementIngestProcessor.php` (`:33`, `:39`, `:218` docblock) ·
`app/Services/Procurement/StatusMappingService.php` (`:13` docblock, `:18` сигнатура, `:23`) ·
`app/Services/Procurement/Eis/EisCsvRowMapper.php` (`:16` docblock, `:50`, `:52`, `:68`, `:70`) ·
`app/Services/Procurement/Eis/CsvSourceMethod.php` (`:107`, `:111`, `:132`, `:137`, `:151`, `:157`, `:173`, `:179`, `:182`) ·
`app/Services/Procurement/Eis/EisCardUrlBuilder.php` (`:40`, `:54`) ·
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php` (`:66`)

**Контракт (1):** `app/Services/Procurement/Contracts/SourceMethod.php` (`:22` — `collectDetails(string $sourceNumber, …)`)

**Filament (1):** `app/Filament/Resources/Procurement/Procurements/Schemas/ProcurementInfolist.php` (`:19`, `:32`, `:92`)

**Сидер (1):** `database/seeders/StatusMappingSeeder.php` (`:21` docblock, `:42`, `:53` phpdoc-shape, `:58`–`:61`)

**Фабрики (3):** `database/factories/Procurement/ProcurementFactory.php` (`:23`, `:28`, `:51`) ·
`database/factories/Procurement/Crawler/IgnoredItemFactory.php` (`:22`) ·
`database/factories/Procurement/Platform/StatusMappingFactory.php` (`:23`)

**Test support (4):** `tests/Support/Procurement/SnapshotFactory.php` (`:24`, `:26` + ключи overrides `sourceNumber`/`sourceStatus`) ·
`tests/Support/Doubles/Crawler/BrokenSourceMethod.php` (`:27`) ·
`tests/Support/Doubles/Crawler/QueueSourceMethod.php` (`:42`) ·
`tests/Support/Doubles/Crawler/FakeSourceMethod.php` (`:29`)

**Тесты (11):** `tests/Feature/Crawler/{FreshRunProcessorTest,CardCollectDetailsTest,ArchiveWindowScannerTest,ArchiveRunProcessorTest,CsvBlockIngestorTest,DetailAnalysisProcessorTest}.php` ·
`tests/Feature/Console/FetchDetailsTest.php` ·
`tests/Feature/Procurement/{DiscoveryTest,StatusMappingTest,StatusMappingSeederTest,PlatformOnboardingTest,RevisionTest}.php` ·
`tests/Unit/Crawler/{EisCardUrlBuilderTest,EisCsvRowMapperTest}.php`

> Счётчик «11» — по группе `source_?number`; `StatusMappingTest`/`StatusMappingSeederTest`/`PlatformOnboardingTest`/`RevisionTest` попадают в группу `source_?status`. Итог по всем трём токенам — **32 уникальных файла** (см. §5).

### 3.2 Группа K — оставить `source_number` (8 файлов, 18 вхождений, концепт «номер протокола»)

`app/Dto/Procurement/Details/ProtocolData.php` · `app/Models/Procurement/Protocol.php` ·
`app/Services/Procurement/Eis/Card/SupplierResultsParser.php` ·
`app/Services/Procurement/Detail/SupplierResultsProcessor.php` (включая ключ event-payload `'source_number'`) ·
`app/Repositories/Procurement/ProcurementDetailStoreRepository.php` ·
`database/factories/Procurement/ProtocolFactory.php` ·
`database/migrations/2026_07_04_100020_create_prc_protocols_table.php` ·
`tests/Unit/Crawler/SupplierResultsParserTest.php`

### 3.3 Группа D — документация, вне P3 (фаза P6, D7)

`docs/specs/v2/01-database.md` · `docs/specs/v2/02-crawling.md`

---

## 4. Preflight-диагностика `origin/main` (D14)

`verified:✅ 2026-07-10` — все четыре целевых файла миграций **присутствуют** на `origin/main`:

```text
PRESENT database/migrations/2026_07_04_100011_create_prc_procurements_table.php
PRESENT database/migrations/2026_07_04_100002_create_prc_status_mappings_table.php
PRESENT database/migrations/2026_07_04_100044_create_prc_ignored_items_table.php
PRESENT database/migrations/2026_07_04_100020_create_prc_protocols_table.php
```

Это **диагностика, не блокер** (D14): проект нигде не развёрнут, persistent/CI-БД поверх `main` нет,
применённой схемы не существует → edit-in-place создающих миграций + `migrate:fresh` разрешены.
Forward alter-миграции (`renameColumn`) **не заводить**.
**Триггер отката к D11:** первая persistent-среда, применившая миграции.

---

## 5. Ожидаемые счётчики (gate-числа фазы)

Команды считают регистронезависимо и ловят camelCase/plural (`sourceNumber`, `$sourceNumbers`,
`ignoredSourceNumbers`) — `\b`-варианты этого НЕ делают, использовать только форму ниже.

| Команда | ДО P3 | ПОСЛЕ P3 |
|:--|:--:|:--:|
| `grep -rniEo 'source_?number' app/ database/ tests/ \| wc -l` | 126 | **18** (только группа K) |
| `grep -rlniE 'source_?number' app/ database/ tests/ \| wc -l` | 40 | **8** (только файлы группы K) |
| `grep -rniEo 'source_?guid' app/ database/ tests/ \| wc -l` | 11 | **0** |
| `grep -rniEo 'source_?status' app/ database/ tests/ \| wc -l` | 50 | **0** |
| `grep -rniEo 'registry_?number' app/ database/ tests/ \| wc -l` | 17 ¹ | > 17 |
| `grep -rniEo 'source_?stage' app/ database/ tests/ \| wc -l` | 0 | ≥ 50 |

¹ 17 доP3-вхождений `registry_?number` **не относятся к ренейму** и не должны быть тронуты:
`prc_awards.contract_registry_number` (модель `Award`, `AwardFactory`, миграция `100022:41`) —
номер контракта в реестре контрактов, **третий концепт**; константы заголовка CSV
`REGISTRY_NUMBER_COLUMN` (`EisCsvRowMapper:20`, `CsvSourceMethod:35`) и флаг запроса
`registryNumberCsv` (`EisCsvUrlBuilder`, тесты). Поэтому «после» задан как строгое неравенство,
а не точным числом: `registry_?number` — не gate-счётчик, gate'ят нулевые остатки `source_*`.

---

## 6. Preflight verification (заполняет P3.1)

`verified:✅ 2026-07-10` (exec-сессия P3.1). Все 6 счётчиков сошлись с §5 ровно — расхождений нет.

| Счётчик | Ожидание (§5, ДО P3) | Факт (P3.1) |
|:--|:--:|:--:|
| `grep -rniEo 'source_?number' app/ database/ tests/ \| wc -l` | 126 | 126 |
| `grep -rlniE 'source_?number' app/ database/ tests/ \| wc -l` | 40 | 40 |
| `grep -rniEo 'source_?guid' app/ database/ tests/ \| wc -l` | 11 | 11 |
| `grep -rniEo 'source_?status' app/ database/ tests/ \| wc -l` | 50 | 50 |
| `grep -rniEo 'registry_?number' app/ database/ tests/ \| wc -l` | 17 | 17 |
| `grep -rniEo 'source_?stage' app/ database/ tests/ \| wc -l` | 0 | 0 |
| `source_?number` в 8 файлах группы K (§3.2) | 18 | 18 |

`git cat-file -e origin/main:<файл>` по 4 целевым миграциям:

```text
PRESENT database/migrations/2026_07_04_100011_create_prc_procurements_table.php
PRESENT database/migrations/2026_07_04_100002_create_prc_status_mappings_table.php
PRESENT database/migrations/2026_07_04_100044_create_prc_ignored_items_table.php
PRESENT database/migrations/2026_07_04_100020_create_prc_protocols_table.php
```

Совпадает с §4 (design-сессия) — диагностика, не блокер (D14). Edit-in-place создающих миграций
разрешён, форвард-`renameColumn` не заводится.

**Вердикт: карта актуальна, drift отсутствует.** P3.2 исполняется по неизменному списку §3.1
(32 файла) без правок §1–§5.
