# Разведка корпуса `estimates/files` (2026-07-26)

> Слой 1 (`plan-protocol` §16): фактура, снятая при авторинге плана. НЕ подменяет фазу P1 —
> это первичный срез, на котором построены контракты фаз; полный разбор (тип документа,
> генератор, структура шапки, квирки, кластеризация) — предмет P1.

## 1. Объём и состав

| Метрика | Значение | Команда |
|:--|:--|:--|
| Файлов всего (без `desktop.ini`) | 157 | `find estimates/files -type f ! -name desktop.ini \| wc -l` |
| Уникальных по sha256 | 155 | `find … -print0 \| xargs -0 sha256sum \| awk '{print $1}' \| sort -u \| wc -l` |
| Байт-идентичных дублей | 2 | то же, `uniq -c \| awk '$1>1'` |
| Суммарный объём | 155 МБ | `du -sh estimates/files` |

Расширения (весь корпус, включая мусор): `xlsx` 69 · `pdf` 54 · `ini` 28 (`desktop.ini`,
мусор Windows) · `xls` 27 · `docx` 4 · `jpg` 2 · `gsfx` 1.

**Вывод для P1:** дедупликация по хешу бесполезна (2 дубля из 157). Повторяемость корпуса —
СТРУКТУРНАЯ: десятки файлов «… - Акт по Методике 2020 (PИМ) с титулом КС-2.xlsx» по разным
объектам/периодам — один шаблон генератора с разными данными. Кластеризовать надо по признакам
формы (генератор, набор колонок, структура шапки), а не по содержимому.

## 2. Крайние случаи (обязаны попасть в эталоны)

| Файл | Чем ценен |
|:--|:--|
| `Смета картинками.pdf` — **132 МБ** | Скан-PDF, множество смет подряд в одном файле. Заведомо превышает лимит запроса Gemini (50 МБ/1000 стр., `rag-2026-07-26.md`) → единственный представитель оконного пути + discovery-сегментации + `unprocessable`-границы |
| `Администрация культуры и спорта 3.gsfx` — 6.2 МБ | ГрандСмета-контейнер, см. §3 — источник машинно-точного gold |
| `Выполнение 2019.04 АКТ КС-2 01-01-001.xls` | Старый бинарный `.xls` + форма КС-2 (акт выполненных работ), не ЛСР |
| `ЛСР.xlsx` — 1.6 МБ / `СМЕТА.xlsx` — 1.3 МБ | Крупные книги, кандидаты в многолистовые/многосметные |
| `2. Обоснование НМЦК медизделия.xlsx` | НЕ смета в сметном смысле — обоснование НМЦК; проверка скрининга на ложное срабатывание |
| `Проект контракта по Услугам_.docx`, `Изображение WhatsApp ….jpg`, `Лесное-ИС- М*.pdf` (чертежи «Объемы») | Негативные примеры скрининга: документ рядом со сметами, но смету не содержит |
| `Кабельный журнал Лесное.pdf`, `Акты скрытых работ ….xls` | Пограничные: табличные, похожи на смету по форме, но другой домен |

## 3. `.gsfx` — контейнер ГрандСметы (ключевая находка)

`file` → `Zip archive data`. Содержимое:

```text
Properties.txt   863 B    (windows-1251, key=value)
Data.xml         209 KB   (XML, encoding="windows-1251")
Attachments/…             (вложения: xlsx-приложение, png/jpg-превью)
Data.sign        49 B     (подпись)
```

`Properties.txt` — готовый паспорт сметы без единого LLM-вызова:

```text
DocType=sdtSmeta
Attribs.LocNum=ЛСР-02-01-01
Attribs.ObjName=Текущий ремонт помещения в здании МКУ "…", 292900, ЛНР, г. Кременная, …
Total=8984983.08
TotalCalcMethod=Res
RegionInfo.RegionName=ФСНБ-2022 (с Изм. 1-13)
RegionInfo.ZoneName=Базовый район
GSmetaVersion=15.3.5.55343
ModifyDate=05.02.2026 19:17:02
```

`Data.xml` — корень `<Document ProgramVersion="15.3.5.55343" Generator="GrandSmeta" …>`.
Частоты элементов (`grep -ao '<[A-Za-z][A-Za-z0-9_]*' | sort | uniq -c | sort -rn`):

```text
360 PriceCurr      314 PriceBase     209 Mat        193 Work      158 Quantity
146 Index          110 Position       95 Mch         81 Tzm2022    70 Item
48 Implementation_V2  46 WorksList    46 Tzr         46 Resources  39 Tzm
29 SimilarResGroup    17 Vid_Rab      16 AddZatrGlava 13 AddZatr     8 Chapter
7 VidRab_Group        5 K             4 Koefficients  2 Indexes
```

**Что это даёт плану:**

1. **Бесплатный точный gold** для gsfx-эталонов (D2): `Position`/`Item` → строки, `Resources`
   (`Mat`/`Mch`/`Work`/`Tzr`/`Tzm`) → ресурсы позиции, `Chapter`/`Vid_Rab` → разделы и виды
   работ, `AddZatr` → дополнительные затраты/начисления, `Total` → концевик.
2. **Красный флаг по схеме БД (вход в фазу P4).** В XML цена позиции существует В ДВУХ
   уровнях — `PriceBase` (базовая, ФСНБ-2022) и `PriceCurr` (текущая), плюс `Index`
   (индексы пересчёта) и `Koefficients`. Текущая `prc_estimate_items` несёт ОДНУ пару
   `unit_price`/`total_price` и `attributes jsonb` под коэффициенты, а
   `prc_estimate_resources` — одну `price`. Двухуровневая цена + индекс + трудозатраты
   (`Tzr`/`Tzm` — затраты труда рабочих и машинистов) в нормализованном виде не выражены.
   Является ли это потерей — решает P4 по фактам всего корпуса, не по одному файлу.
3. **Вид работ (`Vid_Rab`/`VidRab_Group`)** — то, что владелец назвал «основной вид работ, у
   них есть подэлементы»: носитель нормативов НР/СП. В схеме сейчас нет.

## 4. Мусор, подлежащий отсеву в P1

`desktop.ini` (28 шт.) · превью-картинки из контейнеров · дубликаты по структуре (`(1)`,
`(2)`, `+`, `++`, `!` в именах — рабочие итерации одного и того же акта) · вложенное дерево
`1 (3)/1 (N)/…` — это выгрузка чужого рабочего каталога, а не курированный набор.

## 5. Состояние уже существующей инфраструктуры (repo-grounded)

Проверено чтением кода на 2026-07-26 — планировать надо ПОВЕРХ этого, не заново:

| Есть | Где |
|:--|:--|
| Импорт пар `<name>.<ext>` + `<name>.gold.json` → Document (`procurement_id=null`) + `prc_ai_dataset_examples` | `app/Console/Commands/Ai/DatasetImportCommand.php`, контракт — `docs/specs/v2/08-estimate-extraction.md` §8 |
| Eval-раннер: field-level precision/recall/F1 + row coverage, отчёт в `prc_ai_eval_runs/_results` | `app/Console/Commands/Ai/EvalRunCommand.php`, `app/Services/Procurement/Extraction/EvalMetrics.php` |
| Промты как файлы + сид + `is_protected` | `resources/ai/prompts/**` (8 файлов), `ai:prompts:sync` |
| Корректировки оператора + hints в композиции промта | `prc_ai_corrections`, `app/Services/Llm/CorrectionAwareComposer.php` |
| Экспорт SFT-датасета | `app/Console/Commands/Ai/DatasetExportCommand.php` |
| Подготовка: zip → дочерние документы, xls/xlsx → PDF (PhpSpreadsheet+Mpdf, изолированный процесс), картинки as-is, passthrough | `app/Services/Procurement/Prepare/*Strategy.php` |
| Извлечение: preflight, row-range по кэшу, оконный путь, stitcher, валидатор, persister | `app/Services/Procurement/Extraction/**` |
| Filament: Analyses, DatasetExamples, Prompts, AiRoutes, Providers | `app/Filament/Resources/Procurement/Ai/**` |

| Нет | Следствие для плана |
|:--|:--|
| Самого корпуса в системе (файлы лежат в `estimates/` вне git-конвенции) | P1–P2 |
| `.gsfx`, `.doc/.docx`, RAR/7z, OCR | P3 |
| Решения по токен-оптимальному представлению входа | P6 (в спеке §5.3 LibreOffice/Ghostscript помечены как «рычаги, не реализуются») |
| Боевых текстов промтов (файлы-заглушки) | P7 — это и есть «следующий шаг владельца» из EST-AI §1 |
| Diff-отчёта «gold vs предсказание» и предложений по промту | P7 |
| Обучалки в админке (загрузить смету → прогнать → сравнить → апрувить) | P8 |
| Полей под двухуровневую цену/индексы/виды работ (если P4 подтвердит потерю) | P4 |

## 6. Команды воспроизведения

```bash
find estimates/files -type f ! -name desktop.ini | wc -l
find estimates/files -type f ! -name 'desktop.ini' -print0 | xargs -0 sha256sum | awk '{print $1}' | sort -u | wc -l
du -sh estimates/files
find estimates/files -type f | sed 's/.*\.//' | tr 'A-Z' 'a-z' | sort | uniq -c | sort -rn
unzip -l "estimates/files/Администрация культуры и спорта 3.gsfx"
unzip -p "estimates/files/Администрация культуры и спорта 3.gsfx" Properties.txt | iconv -f cp1251 -t utf8
unzip -p "estimates/files/Администрация культуры и спорта 3.gsfx" Data.xml | iconv -f cp1251 -t utf8 \
  | grep -ao '<[A-Za-z][A-Za-z0-9_]*' | sort | uniq -c | sort -rn | head -35
```
