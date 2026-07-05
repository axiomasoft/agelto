# Аудит v1: домен заказов/смет и операторские экраны

## Главный вывод (важно для планирования v2)

**Домен `Order/Lot/PurchaseObject/Customer/LotInfo` (`ord_*` таблицы) полностью отсоединён от остального пайплайна и не заполняется.**
- `app/Process/OrderParsing/OrderParsingProcess.php` — пустой стаб: `public function run(): void {}`.
- Нет фабрик (`Order::factory()` не существует), нет тестов, нет seeders — ни одного `use` вне самих моделей/миграций.
- Нет связи `ord_orders` → `plt_links`/`Crawler` — заказ никак не привязывается к найденному извещению.
- **Схема сметы (`EstimatePassport`, `EstimateItem`, `EstimateSection`...) тоже не подключена к реальному пайплану**: `app/Process/EstimateAnalysis/EstimateAnalysisProcessor.php` — заглушка с хардкод-промптом `'Извлеки данные сметы...'` и комментарием `// ... твой код запросов ...`; результат Gemini пишется как сырой JSON в колонку `data` (jsonb) модели `DocumentAnalysisResponse`, а не маппится в `EstimateFetchItemsResponse`/`EstimateItem`.

Вывод: из v1 в v2 стоит переносить **не код**, а **продуманную целевую схему данных** (она хорошо спроектирована и задокументирована по-русски) плюс операторские Filament-экраны. Реализацию извлечения/связывания придётся писать заново.

---

## 1. Модели заказа (`app/Models/Order/*`) — таблицы `ord_*`

Ни одного enum-статуса, ни одной связи с crawling-доменом. Взять как стартовую схему полей, но пересмотреть привязку к `Link`.

| Модель | Таблица | Поля | Замечания |
|---|---|---|---|
| `Order` | `ord_orders` | `reg_number` (номер извещения, indexed), `edition_number` (int, default 1 — редакция извещения), `name` (text), `status` (**голая строка, без enum**), `customer_id`, `platform_id`, `initial_price` (decimal 20,2), `currency` (default RUB), `order_published_at`, `order_updated_at` (используется как "контроль повторного парсинга" — сравнение с последней), `order_submission_start_at`, `order_submission_end_at`, `order_scoring_at` | Связи: `customer()`, `platform()`, `lots()` hasMany, `purchaseObjects()` hasMany. Ценно: набор дат жизненного цикла закупки (публикация/подача/итоги) и `edition_number` для отслеживания редакций извещения. |
| `Lot` | `ord_lots` | `order_id`, `lot_number` (default 1), `subject` (text), `okved` (varchar 14, indexed), `okpd2` (varchar 20, indexed), `is_centralized` (bool), `initial_price`, `dirty_data` (**json** — "сырые" неструктурированные данные лота, fallback-поле) | Паттерн `dirty_data` json-колонки для "не влезло в схему" — полезная идея для v2. |
| `PurchaseObject` | `ord_purchase_objects` | `order_id`, `okpd2`, `name` (text), `quantity` (decimal 15,3), `unit` (varchar 100), `price` (decimal 15,2), `total_cost` (decimal 15,2) | Позиция объекта закупки (не путать с позицией сметы `EstimateItem`) — из извещения ЕИС, не из сметы-документа. |
| `Customer` | `ord_customers` | `full_name` (text), `short_name`, `inn` (varchar 12, **unique+index**), `kpp` (varchar 9), `ogrn` (varchar 15), `address` (text), `phone`, `email` | `inn` — естественный ключ дедупликации заказчика. |
| `LotInfo` | `ord_lot_infos` | `lot_id`, `okved_text`, `okpd2_text`, `unit`, `quantity` (**string, не decimal!**), `unit_price` (string), `total_price` (string), `additional_info` | **Замечание для v2**: `quantity`/`unit_price`/`total_price` типизированы как `string`, не `decimal`, в отличие от `Lot`/`PurchaseObject` — непоследовательность, при переносе типизировать нормально. Нет `timestamps()` в миграции. |

## 2. Целевая схема сметы (`app/Process/EstimateAnalysis/Schema/*`) — самая ценная часть

Дерево `spatie/laravel-data` DTO, продумано под реальную структуру строительных смет (ЛСР/КС-2/ресурсные ведомости). Каждый класс с русским докблоком-описанием полей — переносить как есть, это готовая доменная модель:

- **`EstimatePassport`** — паспорт: `estimate_id`, `type` (`EstimateType`), `reference` (обоснование/шифр), `target` (`EstimateTarget`), `address`.
- **`EstimateType`** enum: `Estimate` (ЛСР), `Act` (КС-2), `ResourceCalculation` (ресурсная ведомость), `DefectList` (дефектная ведомость) — с алиасами заголовков документа для классификации.
- **`EstimateTarget`** enum: `Construction`, `Renovation`, `MedicalEquipment`, `Engineering`.
- **`EstimateHeader`** + **`EstimateHeaderColumn`** — карта колонок таблицы сметы с алиасами заголовков (`#[Alias]` на `App\Attributes\Alias`): `pos`, `code`, `name`, `unit`, `quantity`, `price`. Колонка хранит `index`, `name`, `full_name` (с учётом родительской группы ячеек — для многоуровневых шапок Excel/Word таблиц).
- **`EstimateSection`** (type='section') / **`EstimateSubSection`** (type='subsection') — рекурсивное дерево разделов/подразделов, `children` — вложенные секции/позиции.
- **`EstimateItem`** — позиция: `name`, `pos`, `code`, `unit`, `quantity`, `price`, `children` (коллекция `EstimateItemResource`), `modifiers` (коллекция `EstimateModifierItem`).
- **`EstimateItemResource`** — ресурс позиции (материал/машина/труд/накладные): `name`, `resource_type` (`EstimateResourceType`), `code`, `unit`, `quantity`, `price`, `comment`.
- **`EstimateResourceType`** enum с алиасами: `Material` (МАТ/ФССЦ), `Machine` (ЭМ/маш.час/ФСЭМ), `Labor` (ЗП/ОТ/чел.час), `Overhead` (НР/СП/накладные/прибыль) — готовая классификация ресурсов сметы под ФЕР/ТЕР-номенклатуру.
- **`EstimateModifierItem`** — коэффициент (`name` — код, `description` — обоснование, `value` — число), например К=1.15.
- **`EstimateFooterItem`** — итоговая строка с рекурсивными `children` (напр. "в том числе НДС").
- Response-обёртки (`Responses/*`): `EstimateFetchInfoResponse` (шапка+итоги), `EstimateFetchItemsResponse` (дерево позиций), `EstimatePreviewResponse` (список `EstimatePreview`: паспорт + `items_count`/`items_count_total`/`page_start`/`page_end` — пагинация по страницам большого документа).

**Не переносить**: `app/Dto/Estimates/{Estimate,Header,Item}.php` + `app/Enums/Estimates/ItemType.php` + `app/Services/SmetaParserService.php` — это более старая/параллельная и упрощённая версия той же идеи (`row_index`-based, `fields: array` без типизации), используется только сама собой (`SmetaParserService` нигде не вызывается). Мёртвый код, дублирующий `Schema/*`.

Также технический долг, не тянуть за собой: `App\Process\DocumentAnalysis\Schema\Responses\EstimateFetchItemsResponse` ссылается на несуществующие классы `App\Process\DocumentAnalysis\Schema\Estimate\{EstimateItem,EstimateSection}` (namespace `DocumentAnalysis\Schema\Estimate`, которого физически нет на диске) — битый/недописанный файл.

## 3. Enum'ы статусов, достойные переноса

- **`AnalysisStatus`** (`app/Enums/AnalysisStatus.php`) — статусная машина документа: `Preparing→Prepared→PrimaryAnalyzing→PrimaryAnalyzed/PrimaryIgnored/PrimaryAnalysisFailed→EstimateAnalyzing→EstimateAnalyzed/EstimateAnalysisFailed→Completed`, плюс `Error`. Методы `stage()` (маппинг на `PipelineStage` для retry с нужной точки), `isTerminal()`, `isFailed()`, `isInProgress()`, `isAwaiting()` — хороший паттерн опроса состояния для очередей/дашбордов, стоит перенести идею целиком.
- **`PipelineStage`**, **`ProcessStage`**, **`CrawlingStatus`**, **`CrawlerRunStatus`** — стадийная модель пайплайна (не входит в задачу впрямую, но связана через `AnalysisStatus::stage()`).

## 4. Операторские Filament-экраны — что переносить как UX-паттерны

| Экран | Файл | Ценные фичи |
|---|---|---|
| **Links** (`plt_links`) | `app/Filament/Resources/Link/Tables/LinksTable.php` | Фильтр по площадке (`SelectFilter` + `relationship` + `preload`), клик по URL в новой вкладке, `recordTitle` fallback на `"Ссылка #{id}"`. |
| **LinkAttachments** | `.../LinkAttachment/Tables/LinkAttachmentsTable.php` | **Комбинированный фильтр по расширению**: `Select` из константного списка (`EXTENSION_SUGGESTIONS`: 7z/doc/pdf/xls/...) + свободный `TextInput` "через запятую" в одном `Filter::make()` с кастомным `->query()` — паттерн стоит перенести. `defaultPaginationPageOption(50)`. |
| **DocumentAnalyses** | `.../DocumentAnalysis/Tables/DocumentAnalysesTable.php` | Полиморфный источник (`model_type`/`model_id` через `documentAnalysable`), badge-статус с ручным маппингом текста, фильтр по типу источника. |
| **DocumentAnalysisForm** | `.../DocumentAnalysis/Schemas/DocumentAnalysisForm.php` | `FileUpload` с автозаполнением поля `extension` через `->live()->afterStateUpdated()`, секция "Системная информация" видна только `->visibleOn('edit')`. |
| **CrawlerRuns** | `.../CrawlerRun/Tables/CrawlerRunsTable.php` + `RelationManagers/LogsRelationManager.php` | `->poll('30s')` живое обновление, вычисление "устаревшего heartbeat" (`isHeartbeatStale`, цвет danger), человекочитаемый курсор прогона (`cursorLabel`: день/страница/диапазон цены), read-only лог прогона как `RelationManager` над `sys_logs` ("упал тут / продолжил оттуда"). |
| **PromptRule** | `app/Filament/Resources/PromptRule/*` | **Управление системными промптами из админки**: `key` (unique), `step` (enum `App\Dto\Prompt\Step`), `priority` (сортировка правил внутри шага), `version`, `is_active` toggle, `content` — `MarkdownEditor`. Таблица с фильтрами по шагу/активности, `defaultSort('priority')`. Это готовый механизм подмены/версионирования промптов для AI-стадий без деплоя — сильная фича, переносить. Есть заготовка превью (`Concerns/HasRulesPreview.php` → `PromptRuleRepository::systemInstructionsFor($step)`), но кнопки предпросмотра нет — только трейт с методом. |
| **Platform / Crawler** | `Platform/*`, `Crawler/*` | Стандартные CRUD-ресурсы, ничего специфичного (список площадок, JSON-фильтр краулера через `formatStateUsing`). |
| **Sys: Proxy / Service** (`Modules/Sys`) | `Modules/Sys/app/Filament/Resources/{Proxy,Service}/*` | Простые CRUD для прокси (IP/порт/логин/`is_active`) и сервисов (`name`/`code`) — инфраструктурные справочники, минимальная ценность вне контекста ЕИС-прокси. |

Нет ни одного Filament-ресурса для `Order/Lot/Customer/PurchaseObject` — операторского экрана заказов/смет в v1 просто не существует, весь UX ограничивается crawling/анализом документов.

## Рекомендация для v2

1. Взять `Schema/*` (смета) как основу целевой модели данных — она продумана лучше, чем что-либо реализованное; писать под неё реальный extraction-код, а не с нуля.
2. Пересмотреть модели `Order/Lot/Customer/PurchaseObject/LotInfo`: добавить FK на `Link`, определить enum для `Order.status`, унифицировать типы (`LotInfo` — decimal вместо string), решить, нужен ли отдельно `PurchaseObject` (из извещения) от `EstimateItem` (из сметы-документа) — в v1 это два независимых, никак не связанных набора позиций.
3. Из Filament переносить UX-паттерны (комбинированный фильтр расширений, poll+heartbeat-индикация, PromptRule-версионирование промптов), а не код 1:1 — под новую доменную модель понадобятся новые ресурсы, в первую очередь для `Order`/`Estimate`, которых в v1 нет вообще.
4. Удалить как мёртвый груз: `app/Dto/Estimates/*`, `app/Enums/Estimates/ItemType.php`, `app/Services/SmetaParserService.php`, `app/Process/OrderParsing/OrderParsingProcess.php` (пустой стаб), битый `App\Process\DocumentAnalysis\Schema\Responses\EstimateFetchItemsResponse`.