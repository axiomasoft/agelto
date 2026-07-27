# Колоночный аудит схемы смет против SR-требований и структурной модели (P4.1)

> Слой 2 плана 2026.07.26-EST-TRAIN, первый item фазы P4 и вход её гейтов P4.2/P4.3.
>
> **Здесь НЕТ распределения величин по носителям.** Документ адресует уже сформулированные
> требования `SR-01…SR-17` (`research/03-schema-requirements.md`, P1.4) к сегодняшней схеме и
> называет, что теряется. Носитель каждой величины назначает P4.2 под гейтом владельца (D32/D35),
> многосметность — P4.3 (D10: схему двигает только фаза P4).
>
> Входы: `research/03-schema-requirements.md` · структурная модель ГрандСметы P3.3 — сервис
> `app/Services/Procurement/Prepare/Gsfx/GsfxReader.php`, вызванный ПРЯМО по файлу эталона
> `EST-GSFX-LSR-001/source.gsfx` с диска эталонов (D82), и DTO
> `app/Dto/Procurement/Ai/Gsfx/GsfxDocument.php` с `unmapped[]` · `artifacts/corpus-clusters.json` ·
> `findings/corpus-recon-2026-07-26.md` §3 · миграции `2026_07_04_1000{33..37}` ·
> `app/Dto/Procurement/Ai/Estimate/**` · `docs/specs/v2/08-estimate-extraction.md` §1, §3.1, §3.2,
> §4а, §8.2 · `Old/app/Process/EstimateAnalysis/Schema/**`.
>
> **Якорь дерева для всех чисел документа — коммит item'а P4.1** (дерево `agelto@main`; на момент
> сбора фактов `HEAD` = `c786849`). Команды воспроизведения — §7; числа корпуса, взятые без
> пересчёта, помечены источником (`P1.4` / `P1.2` / разведка) и в этом item'е не перепроверялись.

## 1. Как читать

- **Прямая матрица** (§2) — по строке на каждое `SR-NN`. Колонки: величина · что несёт документ ·
  адрес в текущей схеме · вердикт `покрыто|частично|нет` · что теряется · какие запрос/валидация/
  сравнение из-за этого невозможны. Вердикт `частично` всегда называет недостающую часть поимённо.
- **Обратная сверка** (§3) — по строке на КАЖДУЮ колонку пяти таблиц `prc_estimate*` и на каждое
  поле DTO `EstimateRow`/`RowResource`/`PassportResponse` (с вложенными `RowModifier`/`TotalLine`/
  `EstimatePassport`/`EstimateTotalsBlock`). Колонка, назначение которой неясно, получает вердикт
  «назначение неясно» с вопросом, а не исчезает из таблицы.
- **Структурная модель** (§4) — разбор `unmapped[]` эталона `EST-GSFX-LSR-001`: каждый элемент
  `Data.xml`, который представление P3.3 не отобразило, получает строку с вердиктом «требуется /
  не требуется схеме и почему».
- **Расхождения источников** (§5) — места, где требование P1.4 и структурная модель P3.3 говорят
  разное, и места, где докблок модели P3.3 расходится с фактическим `Data.xml`. Не сглаживаются в
  пользу одного источника: это вход P4.2.
- **Конфликты с существующими инвариантами** (§6) — шесть обязательных пунктов ТЗ item'а.
- Каждое утверждение о сегодняшней схеме — со ссылкой `файл:строка` миграции/DTO/сервиса.
- Адреса миграций сокращены: `M33` = `database/migrations/2026_07_04_100033_create_prc_estimates_table.php`,
  `M34` = `…_100034_create_prc_estimate_sections_table.php`, `M35` = `…_100035_create_prc_estimate_items_table.php`,
  `M36` = `…_100036_create_prc_estimate_resources_table.php`, `M37` = `…_100037_create_prc_estimate_totals_table.php`.

## 2. Прямая матрица: требование → адрес в схеме

| SR | Величина | Что несёт документ (структурная модель / корпус) | Адрес в текущей схеме | Вердикт | Что теряется | Что становится невозможно |
|:--|:--|:--|:--|:--|:--|:--|
| SR-01 | Цена позиции в двух уровнях — базисном и текущем | `.gsfx`: `Position/PriceBase` 64 и `Position/PriceCurr` 64 при 110 позициях (атрибуты значений — `@Value`, у позиций `@PZ`/`@MT`); модель P3.3 отдаёт их полями `price_base`/`price_curr` (`GsfxPosition.php:48-49`), заполнены 64/110. Книги: 63 файла с парой заголовков «в базисном уровне цен» / «в текущем уровне цен» (P1.4 SR-01) | `prc_estimate_items.unit_price` (`M35:35`) + `total_price` (`M35:36`) — ОДНА пара; `attributes` jsonb с докблоком «коэффициенты/индексы» (`M35:37`) — без объявленной схемы ключей. DTO `EstimateRow::$unit_price`/`$total_price` (`EstimateRow.php:43-44`) | частично | Второй уровень цены целиком: в схеме один слот «за единицу» и один «всего», записать можно ЛИБО базис, ЛИБО текущую. У книг двухуровневая и колонка «всего» (I26 «всего с учётом коэффициентов» против N26 «в текущем уровне цен») — второго слота «всего» тоже нет | Арифметическая сверка «базис × индекс ≈ текущая» на SQL. Field-level F1 по `price` (§8.4) сравнивает несопоставимое, если эталон и предсказание записали разные уровни. Витрина P8.3 не показывает оба уровня — оператору нечего сверять с документом |
| SR-02 | Цена ресурса тоже двухуровневая; индекс применяется именно к ресурсу | `.gsfx`: `Mat/PriceBase` 155, `Mat/PriceCurr` 153, `Mch/PriceBase` 95, `Mch/PriceCurr` 95, `Tzr/PriceCurr` 46; `Mat@IndexCode` 152, `Mch@IndexCode` 95. Модель P3.3: `GsfxResource::$price_base`/`$price_curr`/`$index_code` (`GsfxResource.php:30-32`) заполнены 250/294/247 при 389 ресурсах | `prc_estimate_resources.price` (`M36:29`) — ОДНА колонка; колонки под jsonb у таблицы нет вовсе (полный состав — `M36:24-29`). DTO `RowResource::$price` (`RowResource.php:29`) | нет | Базисная цена ресурса (250 значений на эталоне) и код индекса ресурса (247 значений). На ресурсе негде разместить даже фиксированный ключ — нет ни второй колонки цены, ни jsonb-носителя | Пересчёт «ресурс базис × индекс ≈ ресурс текущий». Группировка расхождений по индексу. Сравнение ресурсов в eval вообще (§8.4 меряет шесть полей СТРОКИ, ресурсы не сравниваются) |
| SR-03 | Индекс пересчёта — самостоятельная величина, а не производная | `.gsfx`: каталог `/Indexes/IndexesPos/Index` 47 + `/Indexes/IndexesRes/Index` 79 = 126 записей `<Index Caption Code MT EM>` (`@MT` у 121, `@EM` у 24 из 146 `Index` всего дерева, включая слой актов); ссылки — `Position@IndexCode` 64/110, `Mat@IndexCode` 152, `Mch@IndexCode` 95. Книги: колонка «индекс» в 63 файлах (P1.4 SR-03) | Отдельной колонки нет ни в одной из пяти таблиц (полные составы — §3). Докблок `M35:37` упоминает «индексы» как назначение `attributes`, схема ключей не объявлена. Модель P3.3 несёт только КОДЫ: `GsfxPosition::$indices` — агрегат из ресурсов (`GsfxPosition.php:47`, сборка — `GsfxReader.php:168-173`), `GsfxResource::$index_code` (`GsfxResource.php:32`) | частично | Значения индекса (`Index@MT`/`@EM`) и его подпись (`Index@Caption`) — каталога `<Indexes>` нет ни в схеме, ни в структурной модели (он целиком в `unmapped`, §4). Связь «код → значение» обрывается: сохраняется код, по которому нечего разыменовать | Проверка «базис × индекс ≈ текущая» в любом виде — второго множителя нет. Отчёт «какие позиции пересчитаны каким индексом». Объяснение расхождения eval: «модель ошиблась в цене» неотличимо от «применён другой индекс» |
| SR-04 | Коэффициенты позиции — ДВЕ разные величины: к количеству и к стоимости | Книги: H26 «коэффициенты» в группе «Количество» и M26 «коэффициенты» в группе «Сметная стоимость», 63 файла (P1.4 SR-04). `.gsfx`: `K@Options` — маска применения (`MatQty`/`EmQty`/`OzpTz`/`ZpmTz`/`InPos`/`Base`/`Curr`/`PzAll`), 5 элементов `K` | `prc_estimate_items.attributes->'modifiers'` (`M35:37`, спека §1.4). DTO `RowModifier` — `name`/`description`/`value` (`RowModifier.php:20-22`) | частично | Род коэффициента: контракт `RowModifier` не имеет поля рода, различие «к количеству» / «к стоимости» кодируется только свободным текстом `description`. Уровень применения (`K@Level`) и маска (`K@Options`) не имеют носителя | Сверка «количество на единицу × коэффициент = количество всего» и «стоимость × коэффициент = стоимость всего»: обе арифметики требуют знать род коэффициента. Детерминированная валидация §4.1 п.6 на формах с коэффициентами количества замолкает |
| SR-05 | Формы без пары «базис/текущий» несут другой набор коэффициентов: поправочные к позиции, зимних удорожаний, пересчёта | Корпус: 2 файла — `Выполнение 2019.04 АКТ КС-2 01-01-001.xls` (G30 «Коэф. поправочные к позиции» = 1.15, кластер `CL-13`) и `СМЕТА.xlsx` (`CL-14`: «Поправочные коэфф.», «Коэфф. зимних удорожаний», «Коэфф. пересчета»); оба кластера в `corpus-clusters.json` несут `calc_method` с явной пометкой про поправочные коэффициенты | Тот же `attributes->'modifiers'` (`M35:37`) и тот же `RowModifier` (`RowModifier.php:20-22`) | частично | Машиночитаемый род коэффициента (`kind`): «поправочный» / «зимнего удорожания» / «пересчёта» неотличимы друг от друга и от коэффициентов SR-04 — все три ложатся в один безымянный список | Отбор смет с зимним удорожанием. Групповая сверка «итог позиции = прямые затраты × Σ коэффициентов рода X». Промт P7.2 не имеет цели, по которой различать заголовки, — словаря синонимов под коэффициенты в спеке §2.1 нет |
| SR-06 | Коэффициент — не одно число: несколько значений и уровень применения | `.gsfx`: 5 элементов `K`, из них `@Value_OZ` у 5, `@Value_EM` у 5, `@Value_ZM` у 4 и `@Value_MT` у 3 — то есть значений ЧЕТЫРЕ рода, а не три, как формулировал P1.4; плюс `@Level` 5, `@Options` 5, `@Code` 2, `@AllVidRabs` 1, `@VrGroupsLinks` 1. Расположение: 3 блока `Koefficients` внутри `Position`, 1 — на корне документа | `RowModifier::$value: string` (`RowModifier.php:22`) — скаляр; `attributes->'modifiers'` (`M35:37`). Структурная модель P3.3 коэффициенты НЕ несёт: `GsfxPosition::$coefficients` остаётся пустым списком по построению (`GsfxReader.php:184`, докблок `GsfxPosition.php:24-27`) | нет | Три значения из четырёх при записи одного скаляра; уровень (`@Level`) и маска (`@Options`); связь коэффициента с видами работ (`@AllVidRabs`/`@VrGroupsLinks`). Дополнительно: gsfx-ветка не даёт ЗАПОЛНИТЬ даже один скаляр — модель P3.3 коэффициентов не отдаёт | Детерминированный gold по `.gsfx` (D2, якорь контура) не может выразить коэффициент вовсе — молчаливая потеря стала бы «эталоном» для F1. Оператор P8.3 не может исправить поле, которого нет в контракте |
| SR-07 | Трудозатраты рабочих и машинистов (чел.-ч) — величина, отдельная от денег, на двух уровнях | `.gsfx`: `Tzr` 46 (`@WorkClass` у всех 46 — разряд работы), `Tzm` 39, `Tzm2022` 81 — последний вложен ВНУТРЬ `Mch` (`/Chapters/Chapter/Position/Resources/Mch/Tzm2022`, атрибуты `@WorkClass`/`@Quantity`/`@Price`). Книги: 57 файлов со строчными `ОТ(ЗТ)`/`ОТм(ЗТм)`, 58 — со сводными «Затраты труда рабочих/машинистов», объединение 60 + `.gsfx` = 61 (P1.4 SR-07) | Строчный уровень: `prc_estimate_resources.resource_type` со значением `labor` (`M36:24`, enum `App\Enums\Procurement\ResourceType`) + `quantity` (`M36:28`). Сводного носителя на `prc_estimates` нет — полный состав колонок `M33:36-51` его не содержит | частично | (1) Сводные трудозатраты по смете — только агрегатом по ресурсам, а на скане и в формах с квирком `NO_RESOURCE_ROWS` ресурсных строк нет вовсе. (2) `Tzr@WorkClass` (разряд) не имеет носителя. (3) **Весь `Tzm2022` — 81 запись — не доходит даже до структурной модели**: `GsfxReader.php:196` знает пять тегов ресурса (`Mat`,`Mch`,`Work`,`Tzr`,`Tzm`), `Tzm2022` уходит в `unmapped` (§4) | Витрина «трудозатраты по смете» без агрегата по ресурсам. Сверка сводной величины шапки ЛСР с суммой ресурсных строк. Полнота gold по `.gsfx`: трудозатраты машинистов по методике 2022 в эталон не попадут |
| SR-08 | Вид работ — носитель нормативов НР и СП и подпись строки начисления | `.gsfx`: `Vid_Rab` 17 (`@Caption`/`@ID`/`@Category`/`@NaclMask`/`@PlanMask` у 17; `@NrCode`/`@SpCode`/`@Nacl`/`@Plan`/`@NaclCurr`/`@PlanCurr` у 15; `@NrKfsCode`/`@SpKfsCode`/`@NKB`/`@PNB`/`@NKI`/`@PKI`/`@NKR`/`@PKR` у 6; `@OsColumn` у 4). Ссылка с позиции — `Position@Vr2001`, заполнен у 110 из 110. Книги: строки НР/СП с процентом в 61 файле, 78 различных подписей (P1.4 SR-08) | Носителя вида работ нет ни в одной из пяти таблиц (составы — §3). Сумма НР/СП попадает в `prc_estimate_resources` как обычный ресурс `resource_type='overhead'` (`M36:24`, спека §2.2). Структурная модель несёт `GsfxWorkType` (`caption`/`id`/`category`/`nr_code`/`sp_code`, `GsfxWorkType.php:22-28`), но НЕ несёт ни нормативов, ни ссылки с позиции | нет | (1) Сам вид работ и его связь с позицией (`@Vr2001`, 110 ссылок) — ни колонки, ни поля DTO. (2) Проценты нормативов `@Nacl`/`@Plan` и их текущие версии `@NaclCurr`/`@PlanCurr`, база начисления `@NaclMask`/`@PlanMask` — докблок `GsfxWorkType.php:17-19` прямо оставляет их за границами модели P3.3 | Группировка НР/СП по виду работ на SQL. Пересчёт начисления от базы («НР = ФОТ × Nacl») и его сверка с суммой строки. Заполнение будущей `prc_estimate_work_types` (D90) из gsfx-ветки: нормативы неоткуда прочитать |
| SR-09 | Виды работ иерархичны: группа → вид | `.gsfx`: `VidRab_Group` 7 → `Vid_Rab` 17. Фактические группы эталона: «Ремонтно-строительные работы» (6 видов), «Общестроительные работы» (4), «Монтажные работы» (2), «Оборудование» (2), «Монтаж металлоконструкций» (1), «Реконструкция» (1), «Сантехнические работы» (1). Модель P3.3 иерархию несёт — `GsfxWorkTypeGroup::$work_types` (`GsfxWorkTypeGroup.php:19`) | Носителя нет: ни таблицы, ни колонки, ни поля DTO ответа LLM | нет | И группа, и её связь с видом работ. Приёмника для уже разобранной моделью P3.3 иерархии в схеме не существует | Отчёт «НР/СП по группам работ». Заполнение `parent_id` будущей `prc_estimate_work_types` (D34/D90): у иерархии нет адреса, куда её положить |
| SR-10 | Несколько смет в одном документе | Корпус: `ЛСР.xlsx` — 6 листов, кластер `CL-10` (`multi_estimate=true`); `Смета картинками.pdf` — 86 страниц скана, `CL-16` (`multi_estimate=true`). `.gsfx`-эталон односметный (`manifest.json`: `expected_estimates: 1`) | `prc_estimates.ordinal` `smallInteger` default 1 (`M33:34`) + `unique(analysis_id, ordinal)` (`M33:55`); `prc_estimate_items.unique(estimate_id, row_index)` (`M35:41`). Порядковый номер заполняет `EstimatePersister.php:103,110` | покрыто (порядковый номер) / нет (адрес в источнике) | Привязка сметы к листу книги или диапазону страниц: ни колонки, ни объявленного ключа `passport`. Контракт `extraction:discovery` (§3.4) отдаёт `{page, marker}` — листовая многосметность им не описывается в принципе | Оператор P8.3 не знает, к какому листу относится расхождение. Сопоставление в eval позиционное по индексу `estimates[]` (§8.4): перестановка листов даёт ложное расхождение сразу по всем полям всех строк |
| SR-11 | Дополнительные затраты и начисления сгруппированы по главам и задаются процентом | `.gsfx`: `/AddZatrats/AddZatrGlava` 8 (главы 8, 9, 10, 11, 12, 13, 14, 15; непустых — 8/9/13/14/15) → `AddZatr` 7 в слое сметы; всего в дереве `AddZatrGlava` 16 и `AddZatr` 13 — вторые 8 и 6 принадлежат слою актов `ImplemActs` (§4). Атрибуты: `@Caption` 13, `@Options` 13 (маска вида `Percent InSmr HideZero`), `@Value` 11, `@Level` 5, `@Formula` 2. Книги: те же начисления текстовыми строками концевика, 7 файлов (P1.4 SR-11) | `prc_estimate_totals`: `parent_id` (`M37:24-28`), `position` (`M37:30`), `label` (`M37:31`), `kind` (`M37:32`, докблок «НР/СП/НДС/…»), `amount` (`M37:33`). DTO `TotalLine` (`TotalLine.php:25-28`) | частично | Номер главы (`@Glava`), ставка процента (`@Value`), признак «величина задана процентом, а не суммой» (`@Options="Percent"`), формула (`@Formula`), уровень вложенности начисления (`@Level`). Два начисления эталона несут `@Formula` вместо `@Value` — для них нет ни суммы, ни носителя формулы | Пересчёт начисления от базы и сверка с суммой. Сборка сметы по главам сводного сметного расчёта. Отличить «2 %» от «2 руб.»: `amount` объявлен NOT NULL (`M37:33`), а строка-процент суммы может не иметь вовсе — см. §6 п.5 |
| SR-12 | Акт КС-2/КС-3 несёт реквизиты, которых нет у ЛСР: номер документа, отчётный период, договор подряда | Корпус: 73 файла с «Отчётный период», 52 — с «Номер документа», 46 — с «Договор подряда» (P1.4 SR-12); акт-представитель `CL-01`: K19 «Номер документа» = 3, L19 «Дата составления» = 22.12.2025, M20/N20 «с»/«по» = 09.12.2025 / 22.12.2025, J15 «Договор подряда (контракт)» № 75 от 01.07.2024 | `prc_estimates.passport` jsonb (`M33:43`). Схема ключей объявлена контрактом `EstimatePassport` — `type`/`reference`/`target`/`address`/`generator` (`EstimatePassport.php:25-29`), полей акта в нём нет. Спека §1.1 фиксирует тот же состав | частично | Номер и дата документа, границы отчётного периода, номер и дата договора. Носитель формально есть (jsonb), но величина в нём не адресуема: ключей нет ни в DTO, ни в спеке — запись пошла бы в произвольный jsonb | Отбор актов по отчётному периоду — прямой сценарий витрины P8. Различение двух актов одного объекта за разные месяцы (в корпусе 51 файл одной формы по разным периодам). Сравнение в eval: §8.4 меряет только строки, паспорт вне F1 |
| SR-13 | Акт ссылается на смету, по которой составлен; строка акта — на позицию по смете | Корпус: ссылка на ЛСР — 1 файл (`Выполнение 2019.04 АКТ КС-2 01-01-001.xls`, B23); колонка «номер позиции по смете» — у 51 акта кластера `CL-01` (в `corpus-clusters.json` она перечислена как `unmapped:Номер позиции по смете`), всего 53 файла по `research/01` §8; дробные номера материалов (`9.1`, `9.2`) — квирк `NUMBERED_MATERIAL_ITEMS`, 50 файлов | Носителя нет ни для номера ЛСР, ни для номера позиции по смете. `prc_estimate_items.row_index` (`M35:30`) — сквозной номер СТРОКИ документа по §4а, семантически другая величина; `estimate_number` (`M33:37`) — номер САМОЙ сметы, не той, на которую ссылается акт | нет | Пара «ЛСР → акт» и собственный номер позиции по смете (в 51 акте он есть, а места под него нет). Дробный номер материала не выражается `row_index` — тот целый и монотонный по построению | Автоматическое сопоставление акта с его сметой. Сверка позиции акта с позицией ЛСР по её собственному номеру. Кандидат в F1 (номер лежит прямо в строке рядом с `row_index`) не может быть измерен |
| SR-14 | КС-3 — свод без строк-позиций: стоимость в трёх временных разрезах | Корпус: 21 файл (`SUMMARY_ONLY`, кластер `CL-03`: `doc_form=КС-3`, `has_resources=false`, `has_totals=true`); `КС№3 _№1.xls` — F28/H28/I28 «с начала проведения работ» / «с начала года» / «в том числе за отчетный месяц», строк-позиций нет | Концевик покрыт `prc_estimate_totals` (`M37:30-33`). Трёх разрезов стоимости нет: `amount` (`M37:33`) — один слот. `prc_estimates.rows_expected` (`M33:49`) для такого документа = 0. `row_type` (§3.5, enum `App\Enums\Procurement\RowType`) не имеет значения под строку свода | частично | Два разреза стоимости из трёх. Плюс: у строки свода нет типа — четыре значения `row_type` (`section_header`/`subsection_header`/`item`/`subtotal`) описывают строки таблицы позиций, а не строки свода по объектам | Сравнение «за отчётный месяц» против «с начала года». Eval-splits (P7.5) не отличают «форма вне разбора» от «модель не извлекла»: 21 файл — второй по величине кластер корпуса |
| SR-15 | Единица измерения несёт множитель, количество выражено в этих единицах | `.gsfx`: `Position@Units` с множителем (`10`/`100`/`1000 …`) — у 49 из 110 позиций; `Quantity@KUnit` заполнен у 91 из 158 элементов `Quantity` (множитель вынесен отдельным числом). Книги: 65 файлов маркера `UNIT_MULTIPLIER`, 18 различных строк-множителей, в т.ч. орфографические варианты одного множителя (P1.4 SR-15) | `prc_estimate_items.unit` text (`M35:33`) — строка «100 м2» целиком; `quantity` `decimal(18,6)` (`M35:34`). DTO `EstimateRow::$unit`/`$quantity` (`EstimateRow.php:41-42`). В структурной модели множителя тоже нет: `GsfxPosition::$unit` — сырой `@Units`, а `GsfxReader.php:161` берёт из `Quantity` только `@Result`, `@KUnit` не переносится | частично | Множитель как ЧИСЛО — и в схеме, и в структурной модели. 91 значение `@KUnit` эталона до gold не доходит. Нормализация не сводится к одному регулярному выражению (18 вариантов записи) | Сравнение количества между формами, записавшими одну величину как `12.2981 «100 м2»` и как `1229.81 «м2»`. При допуске F1 ±0.01 (§8.4) такое расхождение даёт нулевой балл сразу по двум полям из шести (`unit`, `quantity`) — самый прямой риск метрики из всех SR |
| SR-16 | Комментарий у ресурса (текст из колонки номера, напр. «прим.») | Корпусом не подтверждено: 0 файлов из 96 книг (P1.4 SR-16, отдельный прогон зонда). Источник требования — v1-класс `Old/app/Process/EstimateAnalysis/Schema/EstimateItemResource.php:19` (поле `comment`) | Носителя нет: `prc_estimate_resources` (`M36:24-29`) колонки `comment` не имеет; спека §1.4 отбросила поле явно | нет | Ничего, подтверждённого корпусом: счётчик доказательств `0` | — . Строка держится в матрице, чтобы P4.2 не открывал вопрос вслепую: «пропущено» и «не покрыто» — разные факты |
| SR-17 | Метод расчёта сметы меняет состав величин позиции: ресурсный против базисно-индексного | `.gsfx`: `Properties.txt` → `TotalCalcMethod=Res`; структурная модель несёт его полем `GsfxPassport::$calc_method` (`GsfxPassport.php:29`), фактическое значение эталона — `Res`. Корпус: `corpus-clusters.json` — `CL-15` «ресурсный», `CL-01`/`CL-05`/`CL-10`/`CL-11`/`CL-13`/`CL-14` «базисно-индексный» (квирк `RESOURCE_METHOD` на одном файле) | Колонки метода нет; `prc_estimates.passport` (`M33:43`) объявленного ключа под него не имеет (`EstimatePassport.php:25-29`); в enum-словаре §3.5 метода расчёта нет | нет | Признак метода — единственное, что объясняет ЗАКОННУЮ пустоту двухуровневой цены и колонки индекса у ресурсной сметы. Структурная модель его читает, а приёмника нет | Правило «какие поля обязаны быть непустыми» для валидатора. Честный F1: ресурсная смета штрафуется за пустые базисно-индексные поля, и eval не отличает «поля нет в документе» от «модель не извлекла поле» |

## 3. Обратная сверка: носители без требования

Формат строки: носитель · тип · кто заполняет сегодня · есть ли ссылающееся SR · вердикт.
Дамп колонок снят машинно (§7, команда 2) и покрыт таблицами ниже поимённо.

### 3.1 `prc_estimates` (20 колонок)

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `prc_estimates.id` | bigint PK | Postgres | — | служебный, требования не нужно |
| `prc_estimates.uuid` | uuid unique (`M33:19`) | модель, `Estimate.php:92-97` (`Str::uuid7()` на `creating`) | — | служебный (внешний идентификатор витрины), требования не нужно |
| `prc_estimates.analysis_id` | FK `prc_ai_analyses` (`M33:21-23`) | `EstimatePersister.php:108-110` | — | служебный (связь с прогоном) |
| `prc_estimates.document_version_id` | FK (`M33:25-27`) | `EstimatePersister.php:112` | — | служебный (связь с версией документа) |
| `prc_estimates.procurement_id` | FK NOT NULL (`M33:29-31`) | `EstimatePersister.php:113` | — | **конфликт, не мёртвый носитель**: NOT NULL закрывает запись сметы эталона — `EstimatePersister.php:85-94` пропускает документ вне закупки. См. §6 п.3 |
| `prc_estimates.ordinal` | smallint default 1 (`M33:34`) | `EstimatePersister.php:103,110` | SR-10 | покрыт требованием |
| `prc_estimates.title` | text NOT NULL (`M33:36`) | `EstimatePersister.php:114-116` — СИНТЕЗИРУЕТ строку «Смета №N» из `estimate_number` либо из `ordinal` | — | **производный носитель без требования**: из документа не извлекается, дублирует `estimate_number`/`ordinal`. Вопрос P4.2: назначение колонки — человекочитаемая подпись витрины или заголовок из документа? В первом случае она вычислима и не нужна как хранимая |
| `prc_estimates.estimate_number` | text nullable (`M33:37`) | `EstimatePersister.php:117` ← `PassportResponse::$estimate_number` (`PassportResponse.php:30`) | SR-13 (частично: номер САМОЙ сметы, не той, на которую ссылается акт) | используется |
| `prc_estimates.total_amount` | decimal(18,2) nullable (`M33:38`) | `EstimatePersister.php:118` — `grandTotal($totals)` из дерева итогов | SR-11 (косвенно) | используется |
| `prc_estimates.currency` | char(3) nullable (`M33:39`) | `EstimatePersister.php:119` ← `PassportResponse::$currency` default `'RUB'` (`PassportResponse.php:31`) | — | носитель без требования: весь корпус рублёвый, значение константно. Вердикт «низкая нагрузка», не «мёртвый» — заполняется каждым прогоном |
| `prc_estimates.base_year` | integer nullable (`M33:40`) | `EstimatePersister.php:120` ← `PassportResponse::$base_year` (`PassportResponse.php:32`) | смежно SR-01 (уровень цен), прямого SR нет | используется |
| `prc_estimates.price_level` | text nullable (`M33:41`) | `EstimatePersister.php:121` ← `PassportResponse::$price_level` (`PassportResponse.php:33`) | смежно SR-01 | используется; **назначение частично неясно** — разграничение с `base_year` описано спекой §3.1 как «если явно не сводится к base_year», проверяемого правила нет. Вопрос P4.2 |
| `prc_estimates.passport` | jsonb nullable (`M33:43`) | `EstimatePersister.php:122` ← `EstimatePassport` (5 объявленных ключей) | SR-12, SR-17 адресуют его как кандидат-носитель | используется; схема ключей объявлена контрактом только на `type`/`reference`/`target`/`address`/`generator` |
| `prc_estimates.raw_result` | jsonb nullable (`M33:46`) | `EstimatePersister.php:123,261-274` (inline при payload ≤ 1 МБ) | — | служебный (переобработка/датасет, D-решение EST-AI) |
| `prc_estimates.raw_result_ref` | text nullable (`M33:47`) | там же — путь в object storage при payload > 1 МБ | — | служебный, парный предыдущему |
| `prc_estimates.rows_expected` | integer nullable (`M33:49`) | `EstimatePersister.php:124` ← `PassportResponse::$rows_expected` | — | служебный (контроль полноты, §4.1 п.6). SR-14 называет его равным 0 для КС-3 |
| `prc_estimates.rows_extracted` | integer nullable (`M33:50`) | `EstimatePersister.php:125` + пересчёт `RerunRangeAction.php:113` | — | служебный (контроль полноты) |
| `prc_estimates.validation_status` | text NOT NULL (`M33:51`) | `EstimatePersister.php:126` ← `EstimateValidator` | — | служебный (статус детерминированной валидации) |
| `prc_estimates.created_at` | timestamp (`M33:53`) | Eloquent | — | служебный |
| `prc_estimates.updated_at` | timestamp (`M33:53`) | Eloquent | — | служебный |

### 3.2 `prc_estimate_sections` (8 колонок)

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `prc_estimate_sections.id` | bigint PK | Postgres | — | служебный |
| `prc_estimate_sections.estimate_id` | FK cascade (`M34:18-21`) | `EstimateStoreRepository.php:52-56` | — | служебный (связь) |
| `prc_estimate_sections.parent_id` | self-FK nullable (`M34:24-28`) | там же — иерархия раздел→подраздел из `sectionPaths` (`EstimatePersister.php:219-225`) | — | используется (перенос v1 `EstimateSubSection`, спека §1.4) |
| `prc_estimate_sections.position` | integer (`M34:30`) | `EstimateStoreRepository.php:55` ← счётчик `childPositionCounters` (`EstimatePersister.php:217`) | — | используется |
| `prc_estimate_sections.title` | text (`M34:31`) | там же ← `EstimateRow::$section_title` (`EstimateRow.php:39`) | — | используется |
| `prc_estimate_sections.section_number` | text nullable (`M34:32`) | **НИКТО в боевом пути**: единственный вызов передаёт `sectionNumber: null` (`EstimatePersister.php:219-225`); значение появляется только у фабрики `database/factories/Procurement/Estimate/SectionFactory.php:25` | — | **мёртвый носитель**. Ни одно SR на него не ссылается, ни один контракт ответа LLM его не отдаёт (`EstimateRow` номера раздела не несёт). Вопрос P4.2: величина «номер раздела» существует в документах — она подпадает под SR-13 (нумерация по смете) или это остаток замысла v1? |
| `prc_estimate_sections.created_at` | timestamp (`M34:34`) | Eloquent | — | служебный |
| `prc_estimate_sections.updated_at` | timestamp (`M34:34`) | Eloquent | — | служебный |

### 3.3 `prc_estimate_items` (13 колонок)

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `prc_estimate_items.id` | bigint PK | Postgres | — | служебный |
| `prc_estimate_items.estimate_id` | FK cascade (`M35:19-22`) | `EstimatePersister.php:166-169` | — | служебный |
| `prc_estimate_items.section_id` | FK nullable (`M35:24-28`) | там же ← резолв раздела | — | используется |
| `prc_estimate_items.row_index` | integer (`M35:30`) | ← `EstimateRow::$row_index` (`EstimateRow.php:36`), правило §4а | SR-13 (различие с «номером по смете»), SR-10 (якорение) | используется |
| `prc_estimate_items.code` | text nullable, докблок «шифр ФЕР/ТЕР» (`M35:31`) | ← `EstimateRow::$code` | одно из шести полей F1 (§8.4) | используется |
| `prc_estimate_items.name` | text NOT NULL (`M35:32`) | ← `EstimateRow::$name` | одно из шести полей F1 | используется |
| `prc_estimate_items.unit` | text nullable (`M35:33`) | ← `EstimateRow::$unit` | SR-15 | используется, требованием оспаривается по форме записи |
| `prc_estimate_items.quantity` | decimal(18,6) nullable (`M35:34`) | ← `EstimateRow::$quantity` | SR-15, SR-04 | используется; точность обсуждается в §6 п.5 |
| `prc_estimate_items.unit_price` | decimal(18,2) nullable (`M35:35`) | ← `EstimateRow::$unit_price` | SR-01 | используется, требованием оспаривается по кратности |
| `prc_estimate_items.total_price` | decimal(18,2) nullable (`M35:36`) | ← `EstimateRow::$total_price` | SR-01, одно из шести полей F1 | используется, требованием оспаривается по кратности |
| `prc_estimate_items.attributes` | jsonb nullable, докблок «коэффициенты/индексы» (`M35:37`) | `EstimatePersister.php:177-180` — записывает РОВНО один ключ `modifiers`, и только когда список непуст; ключа под индексы не пишет никто | SR-01, SR-03, SR-04, SR-05, SR-12, SR-14, SR-15 адресуют его как кандидат | **недоиспользуемый носитель**: половина объявленного докблоком назначения («индексы») не реализована ни одной стороной. Схема ключей не объявлена — см. §6 п.4 |
| `prc_estimate_items.created_at` | timestamp (`M35:39`) | Eloquent | — | служебный |
| `prc_estimate_items.updated_at` | timestamp (`M35:39`) | Eloquent | — | служебный |

### 3.4 `prc_estimate_resources` (10 колонок)

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `prc_estimate_resources.id` | bigint PK | Postgres | — | служебный |
| `prc_estimate_resources.item_id` | FK cascade (`M36:18-21`) | `EstimatePersister.php:183-193` (`replaceResources`) | — | служебный |
| `prc_estimate_resources.resource_type` | text + PHP enum, докблок `labor/machine/material/overhead/other` (`M36:24`) | ← `RowResource::$resource_type` (`RowResource.php:24`) | SR-07 (`labor`), SR-08 (`overhead` как сумма НР/СП) | используется |
| `prc_estimate_resources.code` | text nullable (`M36:25`) | ← `RowResource::$code` | — прямого SR нет | используется; SR-08 отмечает, что коды нормативов НР/СП попадают сюда как код ресурса, а не как норматив вида работ |
| `prc_estimate_resources.name` | text NOT NULL (`M36:26`) | ← `RowResource::$name` | — | используется |
| `prc_estimate_resources.unit` | text nullable (`M36:27`) | ← `RowResource::$unit` | SR-15 (тот же множитель применим к ресурсу) | используется |
| `prc_estimate_resources.quantity` | decimal(18,6) nullable (`M36:28`) | ← `RowResource::$quantity` | SR-07 (чел.-ч) | используется; точность под чел.-ч обсуждается в §6 п.5 |
| `prc_estimate_resources.price` | decimal(18,2) nullable (`M36:29`) | ← `RowResource::$price` | SR-02 | используется, требованием оспаривается по кратности |
| `prc_estimate_resources.created_at` | timestamp (`M36:31`) | Eloquent | — | служебный |
| `prc_estimate_resources.updated_at` | timestamp (`M36:31`) | Eloquent | — | служебный |

Отдельно: у таблицы НЕТ jsonb-колонки. Это не «мёртвый носитель», а отсутствие носителя — назван в SR-02/SR-03.

### 3.5 `prc_estimate_totals` (9 колонок)

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `prc_estimate_totals.id` | bigint PK | Postgres | — | служебный |
| `prc_estimate_totals.estimate_id` | FK cascade (`M37:18-21`) | `EstimateStoreRepository.php:149-166` (`createTotalTree`) | — | служебный |
| `prc_estimate_totals.parent_id` | self-FK nullable (`M37:24-28`) | там же ← `TotalLine::$children` (`TotalLine.php:28`) | SR-11 | используется |
| `prc_estimate_totals.position` | integer (`M37:30`) | там же — порядковый счётчик | — | используется |
| `prc_estimate_totals.label` | text (`M37:31`) | там же ← `TotalLine::$label` | SR-11 | используется |
| `prc_estimate_totals.kind` | text nullable, докблок «НР/СП/НДС/…» (`M37:32`) | ← `TotalLine::$kind` как есть (`EstimateStoreRepository.php:159`). Спека §3.1 обещает «опционально заполняется LLM ЛИБО детерминированно на бэкенде по `label`» — детерминированного доопределения в коде нет | SR-11 (расширение до глав сводного расчёта) | **недоиспользуемый носитель**: половина объявленного спекой источника заполнения не реализована; при молчании модели колонка остаётся пустой |
| `prc_estimate_totals.amount` | decimal(18,2) **NOT NULL** (`M37:33`) | там же ← `TotalLine::$value` | SR-11 | используется; NOT NULL конфликтует с начислением-процентом — §6 п.5 |
| `prc_estimate_totals.created_at` | timestamp (`M37:35`) | Eloquent | — | служебный |
| `prc_estimate_totals.updated_at` | timestamp (`M37:35`) | Eloquent | — | служебный |

### 3.6 Поля DTO контрактов ответа LLM

| Носитель | Тип | Кто заполняет сегодня | Ссылающееся SR | Вердикт |
|:--|:--|:--|:--|:--|
| `EstimateRow::$row_index` (`EstimateRow.php:36`) | int | LLM (§3.2) | SR-10/SR-13 | 1:1 в `row_index` |
| `EstimateRow::$row_type` (`:37`) | enum `RowType` | LLM | SR-14 (нет значения под строку свода) | **поле контракта без носителя схемы**: колонки `row_type` нет; строки `section_header`/`subsection_header` порождают запись раздела (`EstimatePersister.php:150-156`), а `subtotal` не сохраняется НИГДЕ (`:158-162`) — используется только валидатором на лету |
| `EstimateRow::$name` (`:38`) | string | LLM | F1 | 1:1 в `name` |
| `EstimateRow::$section_title` (`:39`) | ?string | LLM | — | вспомогательное: служит сборке иерархии, в `prc_estimate_sections.title` попадает опосредованно |
| `EstimateRow::$code` (`:40`) | ?string | LLM | F1 | 1:1 в `code` |
| `EstimateRow::$unit` (`:41`) | ?string | LLM | SR-15 | 1:1 в `unit` |
| `EstimateRow::$quantity` (`:42`) | ?string (decimal-строка) | LLM | SR-15 | 1:1 в `quantity` |
| `EstimateRow::$unit_price` (`:43`) | ?string | LLM | SR-01 | 1:1 в `unit_price` |
| `EstimateRow::$total_price` (`:44`) | ?string | LLM | SR-01, F1 | 1:1 в `total_price` |
| `EstimateRow::$resources` (`:45`) | `RowResource[]` | LLM | SR-02, SR-07 | 1:1 в `prc_estimate_resources` |
| `EstimateRow::$modifiers` (`:46`) | `RowModifier[]` | LLM | SR-04, SR-05, SR-06 | в `attributes->'modifiers'` |
| `RowResource::$resource_type` (`RowResource.php:24`) | enum `ResourceType` | LLM | SR-07, SR-08 | 1:1 |
| `RowResource::$name` (`:25`) | string | LLM | — | 1:1 |
| `RowResource::$code` (`:26`) | ?string | LLM | — | 1:1 |
| `RowResource::$unit` (`:27`) | ?string | LLM | SR-15 | 1:1 |
| `RowResource::$quantity` (`:28`) | ?string | LLM | SR-07 | 1:1 |
| `RowResource::$price` (`:29`) | ?string | LLM | SR-02 | 1:1; второго уровня цены и индекса в контракте нет |
| `RowModifier::$name` (`RowModifier.php:20`) | string | LLM | SR-04 | в jsonb |
| `RowModifier::$description` (`:21`) | string | LLM | SR-05 | в jsonb; сегодня единственный носитель рода коэффициента, и то свободным текстом |
| `RowModifier::$value` (`:22`) | string | LLM | SR-06 | в jsonb; скаляр против четырёх значений источника |
| `PassportResponse::$passport` (`PassportResponse.php:29`) | `EstimatePassport` | LLM | SR-12, SR-17 | 1:1 в `passport` jsonb |
| `PassportResponse::$estimate_number` (`:30`) | ?string | LLM | SR-13 | 1:1 в `estimate_number` |
| `PassportResponse::$currency` (`:31`) | string default `'RUB'` | LLM/default | — | 1:1 в `currency`; см. 3.1 |
| `PassportResponse::$base_year` (`:32`) | ?int | LLM | — | 1:1 в `base_year` |
| `PassportResponse::$price_level` (`:33`) | ?string | LLM | — | 1:1 в `price_level` |
| `PassportResponse::$rows_expected` (`:34`) | int | LLM | SR-14 | 1:1 в `rows_expected` |
| `PassportResponse::$totals` (`:35`) | `?EstimateTotalsBlock` | LLM (или отдельный `extract_totals`) | SR-11 | → `prc_estimate_totals` |
| `EstimateTotalsBlock::$footer` (`EstimateTotalsBlock.php:22`) | `TotalLine[]` | LLM | SR-11 | контейнер |
| `TotalLine::$label` / `$value` / `$kind` / `$children` (`TotalLine.php:25-28`) | string/string/?string/array | LLM | SR-11 | → `label`/`amount`/`kind`/`parent_id` |
| `EstimatePassport::$type` / `$reference` / `$target` / `$address` / `$generator` (`EstimatePassport.php:25-29`) | enum/?string | LLM | SR-12 (не покрывает реквизиты акта), SR-17 (не покрывает метод) | ключи `passport` jsonb — единственные ОБЪЯВЛЕННЫЕ |

DTO вне списка ТЗ item'а (обратная сверка их не разбирает, названы явно, чтобы не выпали молча):
`IncompleteRow` (буфер стыковки, §4б — в схему не персистится), `RowsChunkResponse`, `TotalsResponse`,
`DiscoveryResponse`/`DiscoveryMarkerLine` (§3.4), `EstimateHeaderMap`/`HeaderColumn` (карта колонок,
отброшенная как персистентная сущность спекой §1.3).

## 4. Структурная модель ГрандСметы: разбор `unmapped[]`

Дамп снят прямым вызовом `GsfxReader` по файлу эталона `EST-GSFX-LSR-001/source.gsfx` с диска
эталонов (§7, команда 3). Модель отдала: разделов 8, позиций 110, ресурсов 389, групп видов работ
7 (видов 17), глав начислений 8 (начислений 7). `unmapped` — 21 тег.

| Элемент (`unmapped`) | Счёт | Где лежит в `Data.xml` | Требуется схеме и почему |
|:--|--:|:--|:--|
| `Tzm2022` | 81 | `/Chapters/Chapter/Position/Resources/Mch/Tzm2022` (`@WorkClass`, `@Quantity`, `@Price`) | **требуется**: это трудозатраты машинистов по методике 2022 — прямое тело SR-07. `GsfxReader.php:196` знает пять тегов ресурса, `Tzm2022` среди них нет, поэтому величина не доходит ни до модели, ни до gold |
| `Implementation_V2` | 48 | `/Chapters/Chapter/Position/Implementation_V2` → `Item` 48 → `Quantity` 48, `PriceCurr` 2 | **не требуется схеме СМЕТ**: слой выполнения (акт по позиции). Класс актов представлен в корпусе своими эталонами (`EST-XLS-KS2-001`/`EST-XLS-KS3-001`), а P3.3 сознательно производит представление сметы, не акта (`GsfxDocument.php:14-18`). Вопрос P4.2/P4.3 — не здесь |
| `WorksList` | 46 | `/Chapters/Chapter/Position/WorksList` → `Work` 193 (единственный атрибут `@Caption`) | **не требуется**: состав работ позиции текстом, без величин. Ни одно SR его не запрашивает; носителя строкового состава работ в схеме нет и требование его не порождает |
| `Koefficients` | 4 | 3 блока внутри `/Chapters/Chapter/Position/Koefficients` (`K` 3) + 1 на корне (`/Koefficients`, `K` 2) | **требуется**: тело SR-06 и часть SR-04. Существенно: 3 из 5 `K` вложены В ПОЗИЦИЮ — связь выражена вложенностью. Это расходится с докблоком `GsfxPosition.php:22-27` (§5 п.1) |
| `Indexes` | 1 | `/Indexes` → `IndexesPos/Index` 47 + `IndexesRes/Index` 79 (`@Caption`, `@Code`, `@MT`, `@EM`) | **требуется**: каталог значений индексов — тело SR-03. Без него сохранённые коды (`GsfxPosition::$indices`, `GsfxResource::$index_code`) неразыменовываемы |
| `SimilarResGroups` | 1 | `/SimilarResGroups` → `Folder/SimilarResGroup` 29; ссылки `Mat@SimilarResGroupID` 1, `Mch@SimilarResGroupID` 3 | **не требуется**: группировка похожих ресурсов — редакторское удобство программы, величины не несёт. Ни одно SR не ссылается |
| `GsDocSignatures` | 1 | `/GsDocSignatures` → `Item` 7 → `Attributes/Item` 4 | **не требуется**: подписанты документа. Требования по подписантам корпус не породил; смежно к SR-12 (реквизиты акта), но именно подписей ни один SR не запрашивает — фиксируется как кандидат-вопрос P4.2, не как пробел |
| `ImplemActs` | 1 | `/ImplemActs` → `ImplemAct` со СВОИМИ `Indexes` (20 `Index`), `AddZatrats` (8 глав, 6 начислений), `GsDocSignatures` (7) | **не требуется схеме смет** по той же границе, что `Implementation_V2`. Важно для счёта: полное дерево несёт `AddZatrGlava` 16 и `AddZatr` 13, из них смете принадлежат 8 и 7 — модель берёт именно их (`GsfxReader.php:271`) |
| `RegionalK` | 1 | `/RegionalK` | **не требуется** (в текущем составе SR): региональный коэффициент документа. Ни одно SR его не называет; смежно SR-04/SR-06 по природе величины — кандидат-вопрос P4.2 |
| `TerZoneK` | 1 | `/TerZoneK` | **не требуется** (там же): зональный коэффициент. Тот же кандидат-вопрос |
| `WinterCatalog` | 1 | `/WinterCatalog` | **не требуется** прямо: каталог зимних удорожаний. SR-05 запрашивает КОЭФФИЦИЕНТ зимнего удорожания у позиции, а не каталог — связь через каталог фиксируется как вопрос P4.2 |
| `RegionInfo` | 1 | `/RegionInfo` | **не требуется отдельно**: нормативная база уже читается из `Properties.txt` в `GsfxPassport::$normative_base` (`GsfxPassport.php:28`) — дубль того же смысла, потери нет |
| `FRSN_Info` | 1 | `/FRSN_Info` | **не требуется**: сведения ФРСН (сметно-нормативная база). Ни одно SR не запрашивает; назначение элемента фазой не документировано — вопрос P4.2, не пробел схемы |
| `Parameters` | 1 | `/Parameters` | **назначение неясно**: параметры документа в терминах программы. Ни одно SR не ссылается, состав не разобран. Вопрос P4.2 — разбирать ли элемент до решения о носителях |
| `OsInfo` | 1 | `/OsInfo` | **назначение неясно** (смежно с `Vid_Rab@OsColumn`, заполнен у 4 из 17). Тот же вопрос |
| `CennikAutoLoad` | 1 | `/CennikAutoLoad` | **не требуется**: настройка автозагрузки ценников — конфигурация редактора |
| `ReportOptions` | 1 | `/ReportOptions` | **не требуется**: настройки печатных форм |
| `DocDates` | 1 | `/DocDates` | **требуется частично**: даты документа — смежно SR-12 (номер/дата документа, отчётный период). У ЛСР это дата составления; состав элемента не разобран P3.3 — вопрос P4.2 |
| `Attachments` | 1 | `/Chapters/Chapter/Attachments` (корневой `/Attachments` моделью обработан — `GsfxReader.php:300`) | **не требуется**: вложения раздела уходят дочерними документами через `GsfxStrategy` (P3.1/P3.3), не через схему смет |
| `Header` | 1 | `/Chapters/Chapter/Header` | **назначение неясно**: шапка РАЗДЕЛА. Ни одно SR не ссылается; `prc_estimate_sections` несёт только `title`/`section_number`. Вопрос P4.2 — не остаток ли это того же смысла, что мёртвая колонка `section_number` (§3.2) |
| `Comment` | 1 | `/Chapters/Chapter/Comment` | **назначение неясно**: комментарий раздела. Смежно отброшенному SR-16 (комментарий РЕСУРСА, счётчик 0) — но это другой уровень, и подтверждение у него ровно одно вхождение |

Пустым `unmapped` не является — это зафиксировано явно: 21 тег, суммарно 196 вхождений
(сумма счётчиков колонки выше; дамп — §7, команда 3).

## 5. Расхождения между источниками

Не сглаживаются в пользу одного источника — вход P4.2.

1. **`GsfxPosition::$coefficients` против фактического `Data.xml`.** Докблок
   (`GsfxPosition.php:22-27`) утверждает: «в разобранном корпусе `<Koefficients>` существует
   ГЛОБАЛЬНЫМ каталогом без атрибута, однозначно связывающего запись с конкретной позицией».
   Фактически 3 блока `Koefficients` из 4 вложены прямо в `Position`
   (`/Chapters/Chapter/Position/Koefficients/K` — 3 элемента `K`), и связь с позицией выражена
   вложенностью, а не атрибутом. Глобальным является 1 блок из 4 (`K` 2). Следствие: поле
   `$coefficients` остаётся пустым (`GsfxReader.php:184`) не потому, что связи нет, а потому, что
   её ищут атрибутом. Это делает SR-06 невыразимым по gsfx-ветке сегодня — и это дефект модели
   P3.3, а не схемы. Правка принадлежит P4.2/P5.1, здесь только факт.
2. **`GsfxPosition::$indices` против `Position@IndexCode`.** Докблок (`GsfxPosition.php:19-21`)
   утверждает: «в самом `Data.xml` индекс не хранится на позиции напрямую, только на ресурсе». В
   эталоне `Position@IndexCode` заполнен у 64 из 110 позиций — то есть индекс на позиции есть
   прямым атрибутом; агрегация из ресурсов даёт непустой список у 43 позиций, что меньше. Оба
   числа сняты одним прогоном (§7, команды 3 и 4). Вход SR-03.
3. **SR-06 говорит «три значения», источник даёт четыре.** P1.4 формулирует коэффициент как
   «оплата труда / эксплуатация машин / зарплата машинистов» (`Value_OZ`/`Value_EM`/`Value_ZM`). В
   эталоне присутствует ещё `Value_MT` (у 3 из 5 элементов `K`). Расхождение в пользу источника:
   родов значений четыре.
4. **SR-08: нормативы НР/СП есть в источнике и вне модели P3.3.** Требование опирается на
   `Vid_Rab@NrCode`/`@SpCode`/`@Nacl`/`@Plan`; модель переносит только первые два
   (`GsfxWorkType.php:26-27`), а докблок `GsfxWorkType.php:17-19` прямо оставляет `Nacl`/`Plan`/
   маски «не додуманными». То есть даже после решения P4.2 о таблице `prc_estimate_work_types`
   (D90) gsfx-ветка не сможет заполнить нормативы без правки модели P3.3.
5. **SR-15: множитель в источнике есть числом, в модели его нет.** `Quantity@KUnit` заполнен у 91
   из 158 элементов `Quantity`, но `GsfxReader.php:161` берёт из `Quantity` только `@Result`.
   Требование выведено из корпуса верно, машинный носитель существует, до модели не доходит.
6. **SR-11: счёт глав зависит от слоя.** P1.4 приводит `AddZatrGlava` 16 и `AddZatr` 13 (счёт
   `grep` по всему файлу). Слою СМЕТЫ принадлежат 8 глав и 7 начислений; остальные 8 и 6 — слою
   актов `ImplemActs`. Числа P1.4 не ошибочны, но относятся к другому множеству — при переносе
   требования в носитель это различие существенно.
7. **Ресурс `Work` в модели отсутствует как ресурс.** `GsfxReader.php:196` перечисляет `Work` среди
   известных тегов ресурса, но в `Data.xml` `Work` лежит НЕ в `Resources`, а в
   `/Chapters/Chapter/Position/WorksList/Work` (193 элемента, только `@Caption`). Итог: разобранных
   ресурсов 389 — `Mat` 209, `Mch` 95, `Tzr` 46, `Tzm` 39, `Work` 0. Ни одно SR состав работ не
   запрашивает, но расхождение «объявлено известным / не встречается» фиксируется, чтобы P5.1 не
   ожидал этой ветки.

## 6. Конфликты с существующими инвариантами

1. **`unique(prc_estimate_items.estimate_id, row_index)`** (`M35:41`). Инвариант держится на правиле
   §4а: `row_index` сквозной, монотонный, присваивается любой строке подряд. Конфликты, которые
   ставит корпус: (а) SR-13 — у 51 акта есть СОБСТВЕННЫЙ номер позиции по смете, дробный у
   материалов (`9.1`, `9.2`, квирк `NUMBERED_MATERIAL_ITEMS`, 50 файлов); он не помещается в
   целочисленный `row_index` и не имеет второго носителя; (б) SR-14 — у КС-3 строк-позиций нет
   вовсе, `unique` не нарушается, но и записывать в таблицу нечего. P1.4 §4 проверил инвариант на
   многосметности и на дробных номерах и признал его целым — этот пункт его не оспаривает, а
   называет отсутствие ВТОРОГО носителя.
2. **`unique(prc_estimates.analysis_id, ordinal)`** (`M33:55`). Инвариант привязывает набор смет к
   ПРОГОНУ, а не к документу: повторный анализ той же версии документа создаёт новый `analysis_id`
   и новый набор смет (удаление предыдущего — только по явному `supersededAnalysisId`,
   `EstimatePersister.php:63-73`). Конфликт с SR-10: устойчивого ключа сметы между прогонами нет —
   а сопоставление в eval позиционное по индексу `estimates[]` (§8.4). Смена порядка листов в
   книге меняет `ordinal` и даёт расхождение по всем полям всех строк.
3. **`prc_estimates.procurement_id` объявлен БЕЗ `nullable()`** (`M33:29-31`). Обучающий контур
   работает с документами ВНЕ закупки (`procurement_id=null`, D19/§8.3), и `EstimatePersister`
   такие документы сознательно пропускает с warning'ом (`EstimatePersister.php:85-94`). Прямое
   следствие: разобранная смета эталона сегодня физически не записывается в `prc_estimate*`, и
   витрина P8.3 не имеет что показывать оператору по эталону. Это самый жёсткий из шести
   конфликтов — он блокирует не отдельную величину, а весь контур на уровне первой же вставки.
4. **`attributes jsonb` без объявленной схемы ключей** (`M35:37`). Докблок обещает «коэффициенты/
   индексы»; фактически пишется ровно один ключ `modifiers` и только при непустом списке
   (`EstimatePersister.php:177-180`), ключа под индексы не пишет никто. Доктрина D32 требует
   ЗАФИКСИРОВАННОЙ схемы (имя, тип, заполняющая сторона) для всего, что остаётся в jsonb, — на
   сегодня зафиксированного контракта ключей `attributes` не существует ни в спеке, ни в DTO.
   Пока его нет, любая величина, отправленная в `attributes`, невидима для eval (§8.4 сравнивает
   шесть полей строки) и не адресуема запросом.
5. **Точности `decimal(18,2)` под деньги и `decimal(18,6)` под количества.** Проверка против
   требований: (а) SR-03 — индексы в корпусе двух-трёхзначные дробные (`1.25`, `1.4`, `1.23`);
   `decimal(18,2)` под них избыточно грубо, если индекс попадёт в денежную колонку, и достаточно,
   если получит собственную; (б) SR-07 — трудозатраты в чел.-ч ложатся в `quantity`
   `decimal(18,6)` (`M36:28`) без потерь; (в) SR-11 — `prc_estimate_totals.amount` объявлен
   **NOT NULL** (`M37:33`), тогда как начисление-процент суммы может не иметь вовсе: в эталоне 2 из
   13 `AddZatr` несут `@Formula` вместо `@Value`, а `@Value` отсутствует у 2. Запись такого
   начисления сегодня невозможна без подстановки фиктивного нуля; (г) SR-06 — значения
   коэффициентов в источнике записаны с запятой-разделителем (`Value_OZ="1,3"`), то есть требуют
   нормализации до любого числового типа.
6. **Носителя границ сметы в многосметном документе нет** (SR-10). Пять таблиц не содержат ни
   `sheet_name`, ни `page_start`/`page_end`, ни объявленного ключа `passport` под них (составы
   колонок — §3, состав `EstimatePassport` — `EstimatePassport.php:25-29`). Контракт
   `extraction:discovery` (§3.4) оперирует страницами и в принципе не описывает многосметность по
   ЛИСТАМ книги — а именно она подтверждена корпусом (`CL-10`, 6 листов). Следствие: `ordinal`
   есть, адреса в источнике нет, и оператор P8.3 не может привязать расхождение к месту документа.

## 7. Воспроизведение

Все команды — из корня репозитория; контейнеры поднимаются с ДВУМЯ env-файлами (иначе падает на
`MINIO_ROOT_PASSWORD`).

```bash
# 1. Счёт требований (обе стороны Validation п.1)
grep -c '^| SR-' plans/2026.07.26-EST-TRAIN/research/03-schema-requirements.md
grep -c '^| SR-' plans/2026.07.26-EST-TRAIN/research/05-schema-audit.md

# 2. Дамп колонок пяти таблиц
docker compose --env-file .env --env-file .env.docker exec -T app php artisan tinker \
  --execute="foreach (['prc_estimates','prc_estimate_sections','prc_estimate_items','prc_estimate_resources','prc_estimate_totals'] as \$t) { echo \$t.': '.implode(',', Schema::getColumnListing(\$t)).PHP_EOL; }"

# 3. Структурная модель эталона ПРЯМЫМ вызовом GsfxReader (без прогона через пайплайн).
#    Фактическая сигнатура ридера — parse(string $propertiesRaw, string $dataXmlRaw), не read($path):
#    контейнер распаковывается на месте, рабочая копия эталона не создаётся (D82).
docker compose --env-file .env --env-file .env.docker exec -T app php artisan tinker --execute="
\$p = Storage::disk(config('ai.fixtures.disk'))->path(App\Models\Procurement\Ai\Fixture::storagePathFor('EST-GSFX-LSR-001','gsfx'));
\$z = new ZipArchive; \$z->open(\$p); \$props=\$z->getFromName('Properties.txt'); \$data=\$z->getFromName('Data.xml'); \$z->close();
\$doc = app(App\Services\Procurement\Prepare\Gsfx\GsfxReader::class)->parse(\$props, \$data);
echo json_encode(\$doc->unmapped, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;"

# 4. Пути и атрибуты элементов Data.xml (числа §2, §4, §5) — по файлу-источнику корпуса,
#    байт-идентичному эталону (sha256 совпадает с manifest.json).
python3 - <<'PY'
import zipfile, collections
from xml.etree import ElementTree as ET
src = "estimates/files/Администрация культуры и спорта 3.gsfx"
r = ET.fromstring(zipfile.ZipFile(src).read("Data.xml").decode("cp1251").split("?>", 1)[1])
paths = collections.Counter()
def walk(node, path):
    for ch in node:
        p = path + "/" + ch.tag
        paths[p] += 1
        walk(ch, p)
walk(r, "")
for p, n in sorted(paths.items(), key=lambda kv: -kv[1])[:45]:
    print(f"{n:5d}  {p}")
for tag in ("Position", "Quantity", "Vid_Rab", "Index", "K", "AddZatr", "Mat", "Mch", "Tzr", "Tzm", "Tzm2022"):
    c = collections.Counter()
    n = 0
    for el in r.iter(tag):
        n += 1
        c.update(el.attrib.keys())
    print(tag, n, dict(c.most_common()))
PY

# 5. Отсутствие формулировок-решений (Validation п.5 ТЗ item'а). Паттерн собран конкатенацией
#    строк: иначе сама команда воспроизведения попадала бы в собственную выдачу.
pat='дела''ем|реш''ено|выбир''аем|доб''авим колонку'
grep -nE "$pat" plans/2026.07.26-EST-TRAIN/research/05-schema-audit.md
```

Границы воспроизводимости: числа корпуса (счётчики файлов у `SR-01…SR-17`) взяты из
`research/03-schema-requirements.md` §3/§5 без пересчёта — их зонд принадлежит P1.4 и здесь не
запускался; числа по `.gsfx`, по колонкам таблиц и по коду сняты в этом item'е командами 2–4.
