# Слой 1 — прямой разбор `Data.xml` эталона `EST-GSFX-LSR-001` (2026-07-27)

**Зачем.** Эскалация P5.1 (`🔴 Blocked`, `phases/P5.md`) назвала два дефекта `GsfxReader` (P3.3).
Перед реопеном фазы P3 факты сняты ПРЯМЫМ разбором единственного `.gsfx` корпуса — не по разведке
P1 и не по докблокам модели: и разведка (`findings/corpus-recon-2026-07-26.md` §3), и аудит
(`research/05-schema-audit.md` §5) уже расходились с реальностью в обе стороны, а против этой
модели потом считается F1 (D2).

**Файл:** `estimates/files/Администрация культуры и спорта 3.gsfx` (6.2 МБ, код `EST-GSFX-LSR-001`
в `estimates/manifest.json`). Все числа ниже сняты одним прогоном на нём.

## 0. Как воспроизвести

```bash
unzip -p "estimates/files/Администрация культуры и спорта 3.gsfx" Data.xml \
  | iconv -f cp1251 -t utf8 > /tmp/data.xml
```

Дальше — `python3` со ВСКРЫТИЕМ подмены декларации (иначе `ElementTree` читает уже-utf8 байты как
`windows-1251` и падает на `not well-formed`, line 24 — это артефакт метода, а не порча файла):

```python
import xml.etree.ElementTree as ET
s = open('/tmp/data.xml', encoding='utf-8').read().replace('encoding="windows-1251"', 'encoding="utf-8"', 1)
r = ET.fromstring(s)
```

Счёт позиций сверен со штатной командой Validation-2 P3.3:
`unzip -p … Data.xml | iconv -f cp1251 -t utf8 | grep -c '<Position'` = **110** — совпадает с
`len(r.findall('./Chapters/Chapter/Position'))` = **110**.

## 1. Верхний уровень документа

`Document` (атрибуты `ProgramVersion`, `Generator`, `DocumentType`, `MaxSysID`, `GrandSmetaSign`),
прямые потомки — 20 элементов:

```
Properties · Attachments · SimilarResGroups · GsDocSignatures · DocDates · RegionalK · TerZoneK ·
Koefficients · WinterCatalog · RegionInfo · FRSN_Info · Parameters · Indexes · AddZatrats ·
OsInfo · CennikAutoLoad · VidRab_Catalog · ImplemActs · Chapters · ReportOptions
```

`GsfxReader::tallyUnmappedTopLevel()` обрабатывает пять (`Properties`, `Attachments`,
`VidRab_Catalog`, `AddZatrats`, `Chapters`), остальные 15 — счётчиками в `unmapped`.

## 2. Каталог `<Indexes>` — есть, разобран не был

`Document/Indexes` (`@IndexesMode="None"`, `@IndexesLinkMode="Default"`) несёт ДВА раздельных
подкаталога, у каждого элементы `Index` без дочерних узлов:

| Подкаталог | Элементов | Атрибуты |
|:--|--:|:--|
| `IndexesPos` | 47 | `Caption`, `Code`, `MT` (все 47) |
| `IndexesRes` | 79 | `Caption`, `Code`; `MT` — 59, `EM` — 19, **ни того ни другого — 1** (`Code="312"`, «Изделия из бумаги и ткани») |

Дубликатов `@Code` внутри подкаталога нет ни в одном (0 и 0).

**Пространства имён РАЗДЕЛЬНЫЕ и пересекаются числами:** из 64 кодов позиций 64 находятся в
`IndexesPos` и 29 из них ТАКЖЕ существуют в `IndexesRes` (с другим смыслом); из 247 кодов ресурсов
247 находятся в `IndexesRes`, 23 из них также есть в `IndexesPos`. Разыменование по «общему»
словарю кодов дало бы молча неверные значения на 29 + 23 адресах.

**Второй слой:** `Document/ImplemActs/ImplemAct/Indexes` — СОБСТВЕННЫЙ каталог акта
(`IndexesPos` 6, `IndexesRes` 14). Разбор обязан быть привязан к ПРЯМОМУ потомку `Document`;
рекурсивный поиск склеил бы каталог сметы с каталогом акта.

**Значения — десятичные строки с ЗАПЯТОЙ** (`1,73`, `1,41`, …). Ни одного значения с точкой
в обоих подкаталогах.

## 3. `Position@IndexCode` — 64 из 110, дереференс сходится 64/64

Атрибуты позиций (110 элементов):

```
Caption 110 · SysID 110 · Number 110 · Code 110 · Units 110 · PriceLevel 110 · DBComment 110 ·
Quantity 110 · PzSync 110 · Vr2001 110 · Options 66 · IndexCode 64 · DBFlags 28 · ColorIndex 8 ·
AuxMatCode 12 · AuxMat 12
```

- `@IndexCode` — у **64**; ровно у тех же **64** нет дочернего `<Resources>` (расценка прямым
  счётом по каталогу); позиций без `<Resources>` тоже 64 — множества совпадают.
- Дети таких позиций: `Quantity` 64, `PriceBase` 64, `PriceCurr` 64 (+ `Implementation_V2` 2 —
  слой акта).
- **Арифметическая проверка дереференса: 64 из 64 сошлись.** Для каждой позиции с `@IndexCode`
  `PriceCurr@MT / PriceBase@MT == IndexesPos[@Code=IndexCode]@MT` с допуском `0.005`; расхождений
  **0**. Пример: `Code="ФСБЦ-04.3.01.09-0054" IndexCode="5"`, `PriceBase@MT=3404,62`,
  `PriceCurr@MT=6741,15`, каталог `MT=1,98` → `6741,15/3404,62 = 1,9800`.

## 4. Ресурсы: `IndexCode` есть, значение — из `IndexesRes`, составляющая — по типу

Ресурсы позиций — 389: `Mat` 209, `Mch` 95, `Tzr` 46, `Tzm` 39. Атрибуты:
`Caption` 389, `Code` 389, `Units` 389, `Quantity` 376, `Options` 303, `IndexCode` **247**,
`WorkClass` 46, `SimilarResGroupID` 4.

Какую составляющую каталога использует какой тег — определяется НЕ выбором, а тем, какой атрибут
несёт запись каталога:

| Тег | Записей с `IndexCode` | Запись каталога несёт | Проверка `PriceCurr@Value / PriceBase@Value == значение` |
|:--|--:|:--|:--|
| `Mat` | 152 | `MT` у 144, ни одного значения у 8 (`Code="312"`) | **144/144 сошлись**, 8 — значения нет (законный `null`) |
| `Mch` | 95 | `EM` у 95 | **95/95 сошлись** |

Итого арифметика дереференса сошлась на **239 из 239** ресурсов, у которых запись каталога вообще
несёт значение. Цены ресурса лежат в `PriceBase@Value`/`PriceCurr@Value` (у `Mch` рядом ещё
`@ZM` — зарплата машинистов, одинаковая в базисе и текущем: 298,77 в обоих).

## 5. `Mch/Tzm2022` — 81, ВЛОЖЕН внутрь машины

`Document/Chapters/Chapter/Position/Resources/Mch/Tzm2022` — 81 элемент (у 81 из 95 `Mch`).
Атрибуты — ровно три: `WorkClass` 81, `Quantity` 81, `Price` 81.

- `@Quantity` — **всегда `1`** (81/81), то есть это НЕ количество чел.-ч.
- `@WorkClass` — разряд: `3` (31), `4` (29), `6` (13), `5` (8).
- `@Price` — `826,22` / `930,36` / … (ставка в текущем уровне по разряду).

Прямые `Tzm` (39 штук, `Caption="Затраты труда машинистов"`, `Units="чел.-ч"`, `@Quantity`
переменное: `1,27`, `0,17`, `0,09` …) — ДРУГАЯ величина: часы. Позиций, где есть и `Tzm`, и
`Tzm2022`, — **39**, то есть формы сосуществуют и наивное суммирование даст двойной счёт.
Толкование (что из этого идёт в `labor_hours_machinists`, а что — ставка) НЕ принадлежит ридеру.

Дочерние узлы ресурсов: `Mat` → `PriceBase` 155/`PriceCurr` 153; `Mch` → `PriceBase` 95,
`PriceCurr` 95, `Tzm2022` 81; `Tzr` → `PriceCurr` 46; `Tzm` → нет дочерних.

## 6. Коэффициенты: связь выражена ВЛОЖЕННОСТЬЮ

| Где | Элементов `K` | Атрибуты |
|:--|--:|:--|
| `Position/Koefficients` (3 блока) | 3 | `Caption`, `Options`, `Value_OZ`, `Value_EM`, `Value_ZM`, `Value_MT`, `Level` — все 3 несут все 7 |
| `Document/Koefficients` (корень) | 2 | `Caption`, `Code`, `Options`, `Value_OZ`, `Value_EM` (+`Value_ZM` у одного), `Level`, `AllVidRabs`/`VrGroupsLinks` у одного |

Примеры позиционных: `Caption="Добавить до толщины 35 мм"`, `Options="Base Curr PzAll MatQty EmQty
OzpTz ZpmTz"`, `Value_*=3`, `Level=1`. Документные несут `Options="InPos …"` (флаг применимости
внутри позиций) и один — `Disabled` в том же списке.

**Родов значений четыре** (`Value_OZ`/`Value_EM`/`Value_ZM`/`Value_MT`), а не три, как формулировал
P1.4 — расхождение в пользу источника (совпадает с `research/05-schema-audit.md` §5 п.3).

## 7. Виды работ: нормативы и ссылка с позиции

`Document/VidRab_Catalog` несёт **ДВА** элемента `Vids_Rab`:

| Блок | `@Type` | Групп | `Vid_Rab` |
|:--|:--|--:|--:|
| `Vids_Rab[0]` | `Виды работ 2001г` (+ `@CatFile`, `@KfsFiles`) | 7 | 17 |
| `Vids_Rab[1]` | `Виды работ 1984г` | 0 | 0 |

Существующий обход `$xml->VidRab_Catalog->Vids_Rab->VidRab_Group` берёт только ПЕРВЫЙ `Vids_Rab`;
сегодня это ничего не теряет (второй пуст), но факт «блоков может быть больше одного» на носителе
есть.

Атрибуты `Vid_Rab` (17 элементов): `Caption` 17, `ID` 17, `Category` 17, `NaclMask` 17,
`PlanMask` 17, `NrCode` 15, `SpCode` 15, **`Nacl` 15, `Plan` 15, `NaclCurr` 15, `PlanCurr` 15**,
`NrKfsCode`/`SpKfsCode`/`NKB`/`PNB`/`NKI`/`PKI`/`NKR`/`PKR` по 6, `OsColumn` 4.
Пример: `ID="10071"`, `Nacl="90"`, `Plan="47"`, `NaclCurr="90"`, `PlanCurr="47"`, `NaclMask="ФОТ"`.

**Ссылка позиции на вид работ: `Position@Vr2001` резолвится в `Vid_Rab@ID` — 110 из 110**
(12 различных значений, напр. `10071` у 65 позиций). Атрибут в модели позиции отсутствует.

## 8. Почему это исчезло молча — корень одинаков

`unmapped` в `GsfxReader` считает ТОЛЬКО имена непрочитанных дочерних ЭЛЕМЕНТОВ
(`tally($unmapped, $child->getName())` в `parseChapters`/`parsePosition`/`parseResources`/
`tallyUnmappedTopLevel`). Пропущенный АТРИБУТ не попадает ни в модель, ни в `unmapped` — он
невидим. Так исчезли `Position@IndexCode` (64), `Position@Vr2001` (110), `Quantity@KUnit` (49 на
этом файле), `Vid_Rab@Nacl`/`@Plan`/`@NaclCurr`/`@PlanCurr` (15×4). Элементы (`Indexes`,
`Koefficients`, `Tzm2022`) были видны счётчиками — их и назвали аудит P4.1 и research/06 §7;
атрибуты не назвал никто, пока P5.1 не уткнулся в них лично.

`Quantity@KUnit` на этом файле — 49 из 110 элементов `Quantity` (аудит `research/05` §5 п.5 даёт
91 из 158 — тот счёт включает слой актов `ImplemActs`; расхождение метода, не факта).

## 9. Сводка «величина → есть в источнике → доходит до модели сегодня»

| Величина | В источнике | В `GsfxDocument` сегодня |
|:--|:--|:--|
| Код индекса позиции | `Position@IndexCode` — 64/110 | **нет** (и не в `unmapped`) |
| Значение индекса (позиции и ресурса) | `Indexes/IndexesPos|IndexesRes` `@MT`/`@EM` | **нет** (каталог целиком в `unmapped['Indexes']`) |
| Код индекса ресурса | `Mat|Mch@IndexCode` — 247/389 | есть (`GsfxResource::$index_code`) |
| Коэффициенты позиции | `Position/Koefficients/K` — 3 | **нет** (`$coefficients` всегда `[]`, тег в `unmapped`) |
| Коэффициенты документа | `Document/Koefficients/K` — 2 | **нет** (в `unmapped`) |
| Трудозатраты машинистов 2022 | `Mch/Tzm2022` — 81 | **нет** (в `unmapped`) |
| Нормативы НР/СП | `Vid_Rab@Nacl/@Plan/@NaclCurr/@PlanCurr` — 15×4 | **нет** (читаются только `NrCode`/`SpCode`) |
| Ссылка позиция→вид работ | `Position@Vr2001` — 110/110 | **нет** |
| Множитель единицы | `Quantity@KUnit` — 49/110 | **нет** |
