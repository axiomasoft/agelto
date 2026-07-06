# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию. Грань — простота ↔ техническая точность.

Полный справочник (термины, enum'ы, таблицы, исключения): `docs/02_Workflow/glossary.md`. Табличные префиксы и доменная структура: `docs/03_Dev/architecture.md`. Общие правила сущностей PHP/Laravel: skill `naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

Актуально на дату этого файла (сверено с `app/`, после переименования v1→v2 — см. `docs/02_Workflow/glossary.md#изменения-терминов`):

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»).
- `CrawlerRun` → `$run` — один прогон (не голый `Run`/`Crawl`).
- `Procurement` → `$procurement` — закупка (в v1 называлась `Link`, термин больше не используется).
- `Document` → `$document`/`$documents` — документ закупки (в v1 — `LinkAttachment`).
- `DocumentVersion` → `$documentVersion` — конкретный скачанный файл документа.
- `Ai\Analysis` → `$analysis` — сессия AI-анализа (в v1 — `DocumentAnalysis`).
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Lot` / `PurchaseItem` (`prc_items`, не путать с `Estimate\Item`/`prc_estimate_items` — позицией сметы) — лот / позиция объекта закупки.

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Глаголы методов

- `find*` — поиск по идентичности, **nullable**, без суффиксов `ByXAndY` (`findCrawler($id)`, `findProcurement($platformId, $sourceNumber)`).
- `get*` — вернуть определённое/вычисленное (`getPendingProcurements()`); если существительное читается ясно — без `get` (`owner()`, `systemInstructionsFor($step)`).
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса.
- `run()` — точка входа процессора (одна на класс). `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- **Избегать:** `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`; `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, **одна ответственность на файл**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`, `->primaryAnalysisFailed()`); цепочки — `->for()`/`->has()` или именованный билдер.
- **Без** tuple-возвратов и boolean-флагов в хелперах (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$procurement`, `$document`, `$analysis`, `$estimate`).
- Test doubles → `tests/Support/Doubles/` (`Tests\Support\Doubles`); общие mock-сборки — хелперы (`fakeBrowserClient()`).
- Повторяющиеся проверки — кастомные expectations, не копипаста запросов.

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).
