# Карта следствий: схема P4 → контракты ответа LLM → кто чинит (P4.5)

> Слой 2 плана 2026.07.26-EST-TRAIN, пятый (последний) item фазы P4. Документ отвечает на один
> вопрос: «что изменилось в схеме/контрактах P4.4/P4.5 → где именно это чувствуется дальше по
> пайплайну → какой item это чинит → что именно он должен сделать → как проверить, что он этого
> НЕ сделал». Таблица без прочерков в колонке «Чинящий item»: если у изменения нет владельца среди
> items плана — это названо явно, а не спрятано под дефис (Implementation Rules P4.5).
>
> Входы: `research/06-schema-decision.md` (утверждённое распределение, §7 «Долги»),
> `research/07-multi-estimate-anchoring.md` (§8 «Долги и риски»), Deliverables P4.4 (фактические
> колонки), обновлённые DTO `app/Dto/Procurement/Ai/Estimate/**` и `docs/specs/v2/
> 08-estimate-extraction.md` §1/§3.1/§3.2/§4а/§8.2 (P4.5) · контракт-блоки `phases/P5.md`,
> `P7.md`, `P8.md`.

## 1. Формат ответа LLM/gold — что изменилось (P4.5, эта же сессия)

Правки production-кода, которые СРАЗУ следовали из переименования/расширения DTO (иначе
`EstimateValidator`/`EstimateStitcher`/`EstimateToContractMapper`/`EstimatePersister`/
`RerunRangeAction` не компилировались бы против нового контракта) — исполнены В ЭТОМ ЖЕ item'е,
не переносятся на будущее. Перечислены здесь для трассируемости (грепаемый адрес правки), не как
открытое следствие:

| Что изменилось | Носитель (файл) | Чинящий item | Что сделано | Признак, что не сделано |
|:--|:--|:--|:--|:--|
| `EstimateRow::$unit_price`/`$total_price` → `*_curr` + `*_base`; `RowResource::$price` → `price_curr` + `price_base` | `EstimateValidator.php`, `EstimateStitcher.php`, `EstimatePersister.php`, `RerunRangeAction.php`, `EstimateToContractMapper.php` | P4.5 (исполнено, см. Completion Notes) | Литералы старых имён переписаны на `*_curr`; `EstimateValidator::findGrandTotalLine` получил guard на `value===null` (поле стало nullable) | `grep -n '\$row->total_price\b\|$row->unit_price\b\|$resource->price\b' app/Services/Procurement/Extraction/*.php` — непустой вывод |
| `TotalLine::$kind` `?string` → `?TotalKind`; `$value` стал nullable; `+chapter_number`/`+rate` | `EstimateToContractMapper.php`, `EstimateValidator.php` | P4.5 (исполнено) | `findGrandTotalLine` сравнивает `$line->kind === TotalKind::Total` (было `=== 'total'`, всегда false после ретайпа); маппер передаёт `$total->kind` без строкового каста (типы теперь совпадают) | `EstimateToContractMapperTest`/`EstimateValidatorTest` красные на кастинге enum |
| `EstimateRow::$modifiers` (флат) → `$attributes: ?RowAttributes` (nested, фиксированная схема) | `EstimatePersister.php`, `RerunRangeAction.php`, `EstimateToContractMapper.php` | P4.5 (исполнено) | Три файла читают `$row->attributes?->modifiers ?? []` вместо `$row->modifiers` | `grep -n '\$row->modifiers\b' app/Services/Procurement/Extraction/*.php` — непустой вывод |

## 2. Формат gold → P5.1/P5.2

| Что изменилось | Носитель | Чинящий item | Что именно сделать | Признак, что не сделано |
|:--|:--|:--|:--|:--|
| Двухуровневая цена позиции/ресурса (`unit_price_base/curr`, `total_price_base/curr`, `price_base/curr`) | `EstimateRow`/`RowResource`, §3.2 | P5.1 | `GsfxGoldMapper` заполняет ОБА уровня из `PriceBase`/`PriceCurr` `.gsfx`; ресурсная смета (`calc_method=resource`) оставляет `*_base` пустым осознанно | `grep -c 'unit_price_base\|price_base' app/Services/Procurement/Gold/GsfxGoldMapper.php` = 0 |
| Индекс пересчёта позиции/ресурса (`index_code`/`index_value` на обоих уровнях) | `EstimateRow`/`RowResource`, §3.2 | P5.1 | Маппер разыменовывает каталог `<Indexes>` по `@IndexCode` и кладёт ЗНАЧЕНИЕ на носитель (подпись индекса не хранится, §6 п.2 research/06) | `unmappedReport` молчит про `Index`/`IndexCode`, а поля пустые на реальном эталоне |
| Коэффициенты — фиксированная схема `attributes.modifiers[]` (9 ключей, включая `value_labor`/`value_machines`/`value_machinists_wage`/`value_materials`/`level`) | `RowModifier`, `RowAttributes`, §3.2/§4.2 | P5.1 | Маппер читает `Koefficients` позиции (вложенность, не атрибут — GsfxReader-долг §4 ниже) и раскладывает по девяти полям, включая `level` | `GoldValidator::validate()` не пуст на реальном эталоне (неизвестный/недостающий ключ `modifiers`) |
| Виды работ и иерархия (`prc_estimate_work_types`, `EstimateWorkType`, `PassportResponse::$work_types`) | Новая DTO, §3.1/§8.2 | P5.1 | Маппер строит дерево `Vid_Rab`/`VidRab_Group` → `EstimateWorkType[]` (title + нормативы НР/СП + `children`), точка ссылки — `work_type_id` позиции по `Position@Vr2001` | `unmappedReport` показывает `Vid_Rab`/`VidRab_Group` как непокрытые |
| Сводные трудозатраты (`labor_hours_workers`/`labor_hours_machinists`), метод расчёта (`calc_method`) | `PassportResponse`, §3.1 | P5.1 | Маппер агрегирует ресурсы `resource_type=labor` в сводные поля паспорта и читает `TotalCalcMethod` | Сумма `labor_hours_*` не сходится с Σ ресурсных `quantity` того же `resource_type` |
| Начисления по главам (`TotalLine::$chapter_number`/`$rate`, `kind: TotalKind`) | `TotalLine`, §3.1/§8.2 | P5.1 | Маппер кладёт `AddZatrGlava`/`@Chapter` в `chapter_number`, `@Percent` в `rate`, доопределяет `kind` по `@Caption`, если распознаётся | `unmappedReport` показывает `AddZatr`/`Chapter` как непокрытые |
| Табличная книга: те же новые поля, кроме `index_code`/`index_value`/модификаторов составляющих (форма книг скалярна) | `EstimateRow`/`RowResource`, §3.2 | P5.2 | `SheetGoldMapper` заполняет `unit_price_base/curr`, `total_price_base/curr`, `source_position_number` (любая форма, не только акт — P4.3 §5.1) из колонок сетки по словарю заголовков | `GoldValidator::validate()` не пуст на табличном эталоне, прошедшем гейт сетки |

## 3. Тексты промтов и словари синонимов → P7.1/P7.2

| Что изменилось | Носитель | Чинящий item | Что именно сделать | Признак, что не сделано |
|:--|:--|:--|:--|:--|
| Правило «одна цена → заполняется текущая, вторая — если документ различает базис/текущую» (SR-01) | Докблоки `EstimateRow`/`RowResource` (уже написаны P4.5) → текст промта | P7.1/P7.2 | Промт `extract_rows`/`extract_passport` учит модель различать базисный и текущий уровень цены явно, не полагаясь только на докблок-инструкцию | Боевой прогон на ресурсной смете возвращает одинаковые значения в `*_base` и `*_curr` |
| Словарь `ModifierKind` (qty/price/correction/winter/recalculation/other) — машиночитаемый род коэффициента вместо свободного `description` | `RowModifier`, §4.2 | P7.1 | Словарь синонимов «зимнее удорожание»/«коэффициент к количеству»/… → `ModifierKind`, добавлен в системную инструкцию рядом с `EstimateHeader`/`EstimateResourceType` (§2) | Боевой прогон возвращает `kind=other` систематически на распознаваемых по названию коэффициентах |
| КС-3: три temporal-разреза (`attributes.amount_since_start`/`amount_since_year_start`/`amount_reporting_period`), значение `row_type=summary_row` | `RowAttributes`, `RowType`, §3.2/§3.5 | P7.2 | Промт `extract_rows` для форм КС-3 инструктирует модель заполнять три разреза И правильно классифицировать строку свода как `summary_row`, не `subtotal` | Боевой прогон КС-3-эталона классифицирует строку свода как `item`/`subtotal` |
| Реквизиты акта: `document_number`/`document_date`/`period_start`/`period_end`, договор `contract_number`/`contract_date` (в `passport`) | `PassportResponse`, `EstimatePassport`, §3.1 | P7.2 | Промт `extract_passport` для форм акта инструктирует извлекать реквизиты и договор подряда, если документ его называет | Боевой прогон акта оставляет все четыре поля `null` при видимых в тексте реквизитах |
| `source_position_number` — область расширена с «акт» на ЛЮБУЮ смету (P4.3 §5.1) | `EstimateRow`, §3.2 | P7.2 | Формулировка поля в промте — «номер позиции, напечатанный в документе, если он есть», не реквизит акта | Промт по-прежнему запрашивает поле только для форм с `row_type=act` |
| `work_type_name` — подпись вида работ на позиции | `EstimateRow`, §3.2 | P7.2 | Промт инструктирует модель копировать подпись вида работ/раздела нормативов, если она напечатана рядом с позицией | Боевой прогон на форме с явным делением по видам работ оставляет поле `null` |

## 4. Поля сравнения и diff → P7.3

| Что изменилось | Носитель | Чинящий item | Что именно сделать | Признак, что не сделано |
|:--|:--|:--|:--|:--|
| Поле F1 `price` переадресуется на `total_price_curr` (было `total_price`) | `EvalMetrics::FIELD_MAP`, §8.4 | P7.3 | Переписать `'price' => 'total_price'` на `'price' => 'total_price_curr'`; синхронно проверить `numbersEqual`/`valuesMatch` (без изменений — работают по значению, не по имени) | `EvalMetrics::compare()` на реальном предсказании даёт `per_field.price.f1` тождественно 1.0 на явно расходящихся строках (оба поля молча отсутствуют — vacuous match) |
| Новые поля-кандидаты в расширенный F1: `unit_price_base`/`total_price_base`, `index_code`/`index_value`, `source_position_number`, `work_type_name` | `EstimateRow`, §8.4 | P7.3 | Расширить `EvalMetrics::FIELD_MAP` (или отдельную карту) полями из этого документа — приоритет: `total_price_curr`/`total_price_base` (деньги), `source_position_number` (адрес расхождения человеку) | `research/08` цитируется в докблоке `EvalMetrics`, но карта полей не тронута |
| Паспорт-уровень: `calc_method`/`labor_hours_*`/`document_number`/`document_date`/`period_start`/`period_end`, `passport.contract_number`/`.contract_date` | `PassportResponse`, `EstimatePassport`, §3.1/§8.6 | P7.3 | `EstimateDiffReport.passport` включает эти поля в сравнение паспорта (наравне с `estimate_number`/`currency`/…) | Diff по акту с расходящимся `document_number` не показывает записи `field_mismatch` для этого поля |
| Итоги: `TotalLine.chapter_number`/`.rate`, `kind: TotalKind` (замкнутое множество вместо свободного текста) | `TotalLine`, §3.1/§8.6 | P7.3 | `EstimateDiffReport.totals` сопоставляет строки по `(label-нормализация, kind)`, различие `chapter_number`/`rate` — отдельные `field_mismatch` | Diff сопоставляет начисления только по `label`, `chapter_number`/`rate` не сравниваются вовсе |
| `§8.4` спеки всё ещё называет поле `price(=total_price)` — текст не синхронизирован с переименованием P4.5 (эта правка §8.4 НЕ входит в Scope P4.5: раздел не в списке §1/§3.1/§3.2/§4а/§8.2) | `docs/specs/v2/08-estimate-extraction.md` §8.4 | P7.3 | Обновить упоминание на `price(=total_price_curr)` синхронно с правкой `EvalMetrics::FIELD_MAP` | `grep -n 'total_price)' docs/specs/v2/08-estimate-extraction.md` находит §8.4 без правки |

## 5. Экран сравнения и витрина → P8.3

| Что изменилось | Носитель | Чинящий item | Что именно сделать | Признак, что не сделано |
|:--|:--|:--|:--|:--|
| Форма правки gold должна показывать ОБА уровня цены (`*_base`/`*_curr`) и новые поля строки (`index_code`/`index_value`, `source_position_number`, `work_type_name`) | `CompareFixtureGold`, §8.2 | P8.3 | Репитер строк формы правки несёт колонки под все поля `EstimateRow` из этого документа, не только шесть исходных полей F1 | Ручная правка не может изменить `unit_price_base`/`work_type_name` — они не отображены |
| Форма паспорта должна показывать `calc_method`/`labor_hours_*`/реквизиты акта/договор | `CompareFixtureGold`, §3.1 | P8.3 | Блок правки паспорта несёт поля из этого документа §3.1 | Оператор не видит и не может поправить `document_number` на экране сравнения |
| `attributes` (модификаторы + КС-3) — правка через `GoldValidator`, неизвестный ключ обязан отклоняться формой так же, как бэкендом | `RowAttributes`, §3.2 | P8.3 | Форма модификаторов ограничена девятью полями §4.2, свободное текстовое поле `description` не восстанавливается (удалено P4.5) | Форма позволяет ввести произвольный JSON в `attributes` без валидации `GoldValidator` |

## 6. Принятые долги — БЕЗ item'а плана (уже названы и одобрены владельцем в P4.2/P4.3, не переоткрываются здесь)

| Что изменилось / что не заполняется | Где живёт факт | Чинящий item | Почему без item'а плана |
|:--|:--|:--|:--|
| `GsfxReader::$knownTypes` не знает `Tzm2022` (81 запись эталона); `GsfxPosition::$coefficients` пуст по построению (связь коэффициента с позицией — вложенность, не атрибут); `Position@IndexCode`/`Quantity@KUnit` — расхождение докблока/переноса | `research/06-schema-decision.md` §7 | P3.3 (реопен через эскалацию при первом столкновении — Code Guidance P5.1: «маппер не компенсирует чужой дефект своим `if`, правка идёт в P3.3 через эскалацию») | Item P3.3 уже закрыт 🟠; правка ЕГО кода — не Scope ни одного item'а P5, входит только через эскалацию по факту, а не заранее |
| Детерминированное доопределение `prc_estimate_totals.kind` по `label`, когда LLM/маппер не распознали | `research/06-schema-decision.md` §7 | нет (план явно НЕ закрывает — «долг, план его не закрывает») | Решение владельца зафиксировано текстом research/06 §7 при апруве P4.2; не входит ни в один item P5–P9 |
| Общий межсметный справочник видов работ (`ref_work_types`) | `research/06-schema-decision.md` §5.5, D34/D90 | нет (отклонено владельцем как отдельный вариант) | D90 — прямое решение владельца «за» per-estimate таблицу и «против» общего справочника; не долг к закрытию, а отклонённая альтернатива |
| Писатель `verified_at`/`verified_by` (экран боевого просмотра, промоушен боевой сметы в датасет) | `research/06-schema-decision.md` §5.10, D89 п. в | нет (долг ВНЕ плана — явно записано в D89) | Владелец сознательно вынес поток «доверенная боевая смета → gold» за пределы плана |
| Windowed-путь не обеспечивает §4а п.3 (нумерация `row_index` не переиндексируется на стыке окон одной сметы — модель может начать счёт заново, старые строки молча перезаписываются) | `research/07-multi-estimate-anchoring.md` §5.3/§8 п.1 | нет — кандидат ОТДЕЛЬНОГО fix-item вне состава P5–P9 текущего плана (правка `ExtractionOrchestrator::runWindowed()`, детерминированная переиндексация при дописывании окна) | Дефект реализации, а не контракта (§4а остаётся верным правилом); named явно, чтобы не всплыл незамеченным на `CL-16` — единственном подтверждённом многостраничном скане корпуса |

## 7. Ещё принятые долги — С item'ом плана (носитель появится позже, риск принят владельцем)

| Что не заполняется сегодня | Чинящий item | Когда появится писатель |
|:--|:--|:--|
| `source_sheet_name`/`source_row_start`/`source_row_end` (листовая ветка адреса) | P6.3 (ADR по формату входа) **или** P5.2 (мапер книг, если ADR выберет `text_grid`) | После выбора входа P6.3 либо прямого чтения `.xlsx` мапером P5.2 |
| Устойчивое сопоставление смет между прогонами (`ordinal` не стабилен, правило `estimate_number → адрес → позиция`) | P7.3 (метрика/diff читает правило сопоставления, а не схема) | Реализуется как часть расширения `EvalMetrics`/`EstimateDiffReport` |
| `PassportResponse::$work_types` на боевом (не-gsfx) потоке — всегда пуст: LLM его не порождает, book/scan-маппер (P5.2) тоже не строит иерархию (только `work_type_name` строки, если она видна в тексте) | нет (осознанное исключение из D33, research/06 §5.5/§6 п.3) | Не появится в этом плане — риск «таблица видов работ заполняется только gsfx-веткой» принят владельцем |

## 8. Проверка полноты

- [x] Формат gold адресован: §2 (P5.1/P5.2).
- [x] Тексты промтов и словари адресованы: §3 (P7.1/P7.2).
- [x] Поля сравнения и diff адресованы: §4 (P7.3).
- [x] Экран сравнения и витрина адресованы: §5 (P8.3).
- [x] Ни одной строки с прочерком в колонке «Чинящий item» — §6/§7 называют ЛИБО конкретный item,
      ЛИБО явную причину отсутствия item'а (отклонённая альтернатива D90, долг вне плана D89,
      кандидат отдельного fix-item вне состава P5–P9).
- [x] Имена items — везде `Pn.m`, ни одной формулировки «в фазе P7» без номера item'а.
