# План 03: Acquisition, краулинг, адаптер ЕИС, `Organization` и `Reference`

**Волна внедрения:** W2  
**Затрагиваемые слои:** `app/Services/Procurement/{Acquisition,Detail}/**`, `app/Repositories/Procurement/Crawler/**`, `app/Services/Platforms/Eis/**`, `app/Dto/Platforms/Eis/**`, `app/Services/Organization/**`, `app/Repositories/Organization/**`, `app/Services/Reference/**`, `app/Console/Commands/Reference/**`, `config/platforms/eis.php`.

*(Положительная проверка: утечек специфики ЕИС в общий `Procurement/Acquisition` и `Crawler` не выявлено.)*

---

## 1. Логика краулинга и Acquisition

### [CRITICAL] C1. Исправление 2-проходного доказательства стабильности в `ListingFinalizer`
- **Файлы:** `app/Repositories/Procurement/Crawler/ListingFinalizer.php:71-76, 154-165`
- **Проблема:**
  `$uninterrupted = ($attempts === 1)` считает общее число строк `prc_process_attempts` для блока. При нормальном сетевом pacing (`ScheduledAttemptGate` возвращает `target_cooldown` или `capacity_wait` — 4 510 раз на репетиции предпродакшена) попытка закрывается с `terminal_kind = 'deferred'`, и повторный запуск создаёт попытку #2 → `$uninterrupted = false`. На 1-м проходе это отравляет `$comparison`, на 2–3-м проходе приводит к ложному `outcome = 'incomplete', reason = 'unstable_source'` на 100% стабильном дне.
- **План исправления:** Определять `$uninterrupted` как отсутствие реально прерванных/сбойных попыток (`WHERE a.terminal_kind IN ('interrupted', 'fault')` → `count === 0`), исключая pre-send отсрочки (`terminal_kind = 'deferred'`).
- **Верификация:** Feature-тест: страница листинга получает `deferred` (`target_cooldown`) перед успешным выполнением на 1-м и 2-м проходах → `terminal_proof->>'outcome' = 'complete'`.

---

### [HIGH] H1. Ось дат для `fresh` и явный `--from` в `EisProfilePreparer`
- **Файлы:** `app/Services/Platforms/Eis/Listing/EisProfilePreparer.php:55-56, 87, 92-95`
- **Проблема:**
  1. Строка 87 жёстко задаёт `$axis = $kind === AcquisitionKind::Delta ? 'update' : 'publish'`. Для профиля `fresh` с `sortField = updated` запрос в ЕИС идёт с `publishDateFrom/To` вместо `updateDateFrom/To`, пропуская обновления ранее опубликованных закупок.
  2. Строки 92–95 всегда вызывают `resumeFrom($start)` при `$kind !== Archive`, даже когда оператор явно передал `--from=YYYY-MM-DD`, молча пропуская уже проверенные дни.
- **План исправления:** Вычислять `$axis = ($kind === AcquisitionKind::Delta || ($kind === AcquisitionKind::Fresh && $filter->sortField === ListingSortField::Updated)) ? 'update' : 'publish'`; вызывать `resumeFrom()` только при `$from === null`.
- **Верификация:** Unit-тесты в `EisProfilePreparerTest` на оба сценария.

---

### [HIGH] H2. Замена sentinel `UNBOUNDED_HIGH_CENTS` и перекрытия split
- **Файлы:** `app/Dto/Platforms/Eis/EisListingCursor.php:13`, `EisListingSource.php:73-75, 144`, `EisListingPartitioner.php:140-148`
- **Проблема:**
  - `UNBOUNDED_HIGH_CENTS = 100_000_000_000` (1 млрд ₽) молча отбрасывает явный `priceTo >= 1 млрд ₽` и не позволяет разбить переполнение >500 закупок выше 1 млрд ₽ (`width` схлопывается в `0` → `listing_cap_unresolved`).
  - `split()` возвращает `[[low, mid], [mid, high]]` с общим `$mid`, дублируя закупки с точной ценой `$mid`.
- **План исправления:** Перейти на nullable `?int $highCents`, использовать непересекающиеся копеечные границы `[[low, mid], [mid + 1, high]]`, а при разбиении правой бесконечной ветки `[low, null]` удваивать pivot.
- **Верификация:** Тесты в `EisListingPartitionerTest` и `EisListingSourceTest` для `priceTo = 2_000_000_000` и overflow выше 1 млрд ₽.

---

### [HIGH] H3. DDL партиций `prc_events` вне транзакции и покрытие `SupplierResultsProcessor`
- **Файлы:** `app/Services/Procurement/Detail/EventJournalProcessor.php:48-60`, `SupplierResultsProcessor.php:75-81, 146-153`, `DetailFinalizer.php:101-106`
- **Проблема:**
  1. `EventJournalProcessor` вызывает `PartitionCoverage::ensure('prc_events', ...)` (`CREATE TABLE ... PARTITION OF` с `ACCESS EXCLUSIVE` lock) **внутри транзакции `DetailFinalizer`**, держащей row-locks → риск deadlock между параллельными `detail`-воркерами.
  2. `SupplierResultsProcessor::storeProtocol()/storeAward()` вставляет исторические события `ProtocolPublished`/`AwardMade` в `prc_events` без гарантии существования партиции → `SQLSTATE[23514]`.
- **План исправления:** Предварительно создавать исторические партиции `prc_events` вне воркерной транзакции (отдельное соединение до финализации или расширение `partitions:maintain`), покрывая даты обоих процессоров.
- **Верификация:** Тест `SupplierResultsProcessor` с исторической датой протокола/награждения + конкурентный тест `DetailFinalizer`.

---

## 2. Парсеры ЕИС и адаптер площадки

### [HIGH] 2.1 Пустой НМЦК в CSV роняет всю страницу из 500 закупок
- **Файлы:** `app/Services/Platforms/Eis/Csv/EisSummaryParser.php:37-40`, `EisListingSource.php:141-147, 158-159`
- **Проблема:** Пустая ячейка НМЦК (закупки по цене за единицу) бросает `UnexpectedValueException` → `SchemaMismatch` на всю 500-строчную страницу; при `null` строка 141 превращает цену в `0`, что валит проверку `$cents < $cursor->lowCents` → `FilterMismatch`.
- **Исправление:** Разрешать `initialPrice = null` для пустой ячейки и пропускать проверку `lowCents`/`highCents`, когда `initialPrice === null`.

### [HIGH] 2.2 Multi-lot `supplier-results` всегда падает как `Completeness::Partial`
- **Файлы:** `app/Services/Platforms/Eis/Card/SupplierResultsParser.php:121-123` vs `EisDetailSource.php:270, 278`
- **Проблема:** `parseBids()` читает только `->first()` таблицу участников, тогда как `EisDetailSource` считает строки по всем таблицам → несовпадение счётчиков → `Partial` → блокировка `PolicyReview`.
- **Исправление:** Итерироваться по всем подходящим таблицам через `->each(...)`.

### [HIGH] 2.3 Ошибки семантики и типов в `SupplierResultsParser`
- **Файлы:** `app/Services/Platforms/Eis/Card/SupplierResultsParser.php:153-166, 181-206`
- **Проблема:** Отклонённые и отозванные заявки записываются как `BidStatus::Admitted`; `parseAmount()` кастит деньги в `(float)` (потеря точности); `organization: null` захардкожен, из-за чего `ProcurementPartyRole::Winner` никогда не сохраняется.
- **Исправление:** Парсить статусы `отклонена`/`отозвана` в `BidStatus::Rejected`/`Withdrawn`, перевести `BidData::$price` и `AwardData::$finalPrice` на decimal `string`, заполнять организацию победителя при наличии данных.

### [HIGH] 2.4 Тихая потеря пагинированных позиций КТРУ (`> pageSize`) в `CommonInfoParser`
- **Файлы:** `app/Services/Platforms/Eis/Card/CardDomHelper.php:133-161`, `CommonInfoParser.php:163-168, 205-227`
- **Проблема:** `expectedItemCount()` возвращает `min($tableCount, $pageSize)`, молча отбрасывая позиции 11..N и ставя `Complete`; `$headerCount` берётся только из первой таблицы; смешанные явные/неявные номера позиций могут конфликтовать и перезаписывать позиции в `upsertItem()`.
- **Исправление:** Не помечать усечённый список как `Complete` (либо добирать страницы, либо явно фиксировать `Partial`/overflow), считать колонки по каждой таблице отдельно, генерировать неконфликтующие номера для неявных строк.

### [HIGH] 2.5 Хрупкость `PartiesParser::parseReal()` и расхождение с отчётом предпродакшена
- **Файлы:** `app/Services/Platforms/Eis/Card/PartiesParser.php:24-34, 94-160`, `Audits/2026-11-07-preprod-rehearsal.md` (Bugs 10–12)
- **Проблема:**
  - Любая незнакомая роль вне 9-элементной `ROLE_MAP`, отличие сокращения имени или кавычек выбрасывает `UnexpectedValueException` → перманентный `ContractRejected` (`failed_item`).
  - Строки 159–160 дублируют `ProcurementPartyRole::Placer`, когда `$role === Placer`.
  - Фиксы Bugs 10–12 из `Audits/2026-11-07-preprod-rehearsal.md` отсутствуют в текущем `main` — необходимо сверить, потерялись ли они при merge/rebase.
- **Исправление:** Убрать дубликат `Placer`, деградировать незнакомые роли/несовпадения в `Completeness::Partial` с warning-логом, восстановить и покрыть тестами подтверждённые фиксы Bugs 10–12.

### [MEDIUM] Прочие улучшения парсеров и листинга
- **M1 (`ListingHandler.php:52-54`):** передавать фактическое число аллоцированных партиций вместо захардкоженной `1` (сейчас `EisListingPolicy::$maxPartitions` не работает).
- **M2 (`DocumentsParser.php:78,113-125`):** считать уникальные filestore `uid`/нормализованные `href` внутри контейнера документов вместо сырого числа `<a>` (устраняет ложный `Partial` на дублирующих ссылках иконка+текст/`.sig`).
- **M3 (`EventJournalParser.php:25,114-131` & `EisSourceClock.php:81-92`):** принимать оба формата даты `['!d.m.Y H:i:s', '!d.m.Y H:i']`, логировать warning при неизвестной метке таймзоны.
- **M4 (`EisDetailSource.php:61-70`):** возвращать `FetchItemRejected` + warning вместо необработанного `InvalidArgumentException` на неизвестный тип процедуры 44-ФЗ.
- **M5 (`EisCsvParser.php:43-53`):** заменить побайтовый `fgetc()` на чтение чанками 64 KiB, чтобы большие CSV не превышали 5-секундный `parserSeconds` (что ошибочно классифицируется как `Overflow`).
- **L1 (`EisDetailSource.php:161`):** заменить PHP `new DateTimeImmutable('+60 seconds')` на относительный `RetryDelay` / DB `clock_timestamp()`.
- **L2 (`config/platforms/eis.php:87,125`):** сменить `charset_default` для HTML-эндпоинтов `card` и `diagnostic` с `Windows-1251` на `UTF-8`.

---

## 3. Домен `Organization`

### [HIGH] 3.1 Отказ при обработке филиалов (одинаковые `INN` + `OGRN`, разные `KPP`)
- **Файлы:** `app/Services/Organization/OrganizationResolver.php:68-101`, `OrganizationStoreRepository.php:19-30`
- **Проблема:** `findByOgrn()` находит первый филиал (`inn=X, kpp=K1, ogrn=O`) при обработке второго (`inn=X, kpp=K2, ogrn=O`), после чего `updateChangedAttributes()` бросает `RuntimeException('Противоречивые идентификаторы...')`, блокируя `detail`. Кроме того, переход `(inn, null)` → `(inn, kpp)` без OGRN создаёт дубликат записи.
- **План исправления:** Ограничить fallback `findByOgrn()` совпадением `kpp` или `kpp IS NULL` при непустом `$data->kpp`, а при `(inn, kpp)` проверять возможность обогащения существующей `(inn, null)` записи.
- **Верификация:** Тесты в `OrganizationResolverTest` на два филиала с общими `(INN, OGRN)` и разными `KPP`, и на обогащение `(INN, null)` → `(INN, KPP)`.

### [MEDIUM / LOW] 3.2 Защита от перезаписи свежих атрибутов архивным краулом и retry-цикл
- **Файлы:** `app/Services/Organization/OrganizationResolver.php:36-43, 113-121`
- **Исправление:** Передавать `observedAt` в `resolve()` и обновлять мутабельные поля (`full_name`, `address_raw`, `phone`, `email`) только если входящее наблюдение новее `attributes_observed_at` (заполняя `null` всегда); заменить одиночный catch `UniqueConstraintViolationException` на ограниченный цикл (до 3 попыток) или advisory lock по `(inn, kpp)`.

---

## 4. Домен `Reference`

### [HIGH] 4.1 Валидация и PHP 8.4 совместимость `ClassifierImporter`
- **Файлы:** `app/Services/Reference/ClassifierImporter.php:32, 60, 107-126`
- **Проблема:** Пропускает пустой `code` (при непустом `name`), молча кастит нечисловой/отрицательный `level` в `int`, не валидирует `valid_from`/`valid_to` и UTF-8 (падение Postgres `22021` на Windows-1251), вызывает `fgetcsv($handle)` без `$escape` (deprecated в PHP 8.4).
- **План исправления:** Валидировать `trim($code) !== ''`, `level` по `/^\d+$/D`, даты по `!Y-m-d`, `mb_check_encoding(..., 'UTF-8')` с номером строки до записи; передавать `escape: ''` в `fgetcsv()`.

### [MEDIUM] 4.2 Транзакционность и дубликаты в `LoadClassifiers`
- **Файлы:** `app/Console/Commands/Reference/LoadClassifiers.php:70-100`
- **Проблема:** Чанки по 500 строк коммитятся в autocommit (ошибка на 5001-й строке оставляет справочник наполовину обновлённым); `->keyBy('code')` молча отбрасывает дубликаты внутри чанка.
- **План исправления:** Обернуть цикл чанков в `DB::transaction()` (сохраняя стриминг `LazyCollection`) и выдавать предупреждение/ошибку при дублях `code`.
