# Glossary & Naming Canon

Единый канон имён: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию. Грань — простота ↔ техническая точность.

Полный справочник (термины, enum'ы, таблицы, исключения): `docs/02_Workflow/glossary.md`. Общие правила сущностей PHP/Laravel: skill `naming-conventions`. Меняем имя в глоссарии → выравниваем код по нему.

## Доменные сущности (канон → переменная)

- `Platform` → `$platform` — площадка-источник.
- `Crawler` → `$crawler` — профиль обхода (не «Profile»).
- `CrawlerRun` → `$run` — один прогон (не голый `Run`/`Crawl`).
- `Link` → `$link` — найденное извещение ЕИС (не `Url`).
- `LinkAttachment` → `$attachment` — файл-вложение (не голый `Document`/`Attachment` для модели).
- `DocumentAnalysis` → `$analysis` — сессия AI-анализа вложения.
- `DocumentAnalysisResponse` → `$response` — ответ AI на шаге.
- `Estimate` → `$estimate` — распарсенная смета (DTO); `Smeta`/«Смета» в идентификаторах не используем.
- `Order` / `Lot` / `PurchaseObject` — заказ / лот / позиция.

В UI и комментариях допустим бизнес-язык («извещение», «вложение», «смета»); в идентификаторах — технический канон.

## Короткий префикс таблицы ↔ полное доменное имя

Правило: папка = полное доменное имя, соответствующее короткому префиксу таблицы. Внутри домена подпапки — по смыслу, не по бывшему префиксу (`Ai`/`Crawler`/`Document`/`Estimate`/`Platform`/`Sys` — устаревшее деление, всё это теперь один домен).

| Префикс таблицы | Домен | Папка (`app/{Models,Services,Dto,Enums,Repositories}/...`) |
|---|---|---|
| `prc_` | Procurement (закупки) — единый домен: заказы, документы, сметы, AI-пайплайн, crawling-инфраструктура, площадки, логи операций | `Procurement/` |
| `ref_` | Reference (справочники) — общие классификаторы ОКПД2/КТРУ/ОКВЭД2/КБК | `Reference/` |
| `org_` | Organization (организации) — общий домен, не специфичен для закупок | `Organization/` |

## Глаголы методов

- `find*` — поиск по идентичности, **nullable**, без суффиксов `ByXAndY` (`findCrawler($id)`, `findLink($platformId, $regNumber)`).
- `get*` — вернуть определённое/вычисленное (`getPendingLinks()`); если существительное читается ясно — без `get` (`owner()`, `systemInstructionsFor($step)`).
- `store*`/`storeOrUpdate*` — запись через репозиторий. `mark<State>` — смена статуса.
- `run()` — точка входа процессора (одна на класс). `start()` — длительный lifecycle.
- Доменные мутации — глагол+существительное: `transitionStatus()`, `recordFailure()`, `acquireLease()`.
- **Избегать:** `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`; `get`-префикс на тривиальных аксессорах.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php`, **одна ответственность на файл**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики `X::factory()`; статусы — states (`->pending()`, `->primaryAnalysisFailed()`); цепочки — `->for()`/`->has()` или именованный билдер.
- **Без** tuple-возвратов и boolean-флагов в хелперах (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$link`, `$attachment`, `$analysis`).
- Test doubles → `tests/Support/Doubles/` (`Tests\Support\Doubles`); общие mock-сборки — хелперы (`fakeBrowserClient()`).
- Повторяющиеся проверки — кастомные expectations, не копипаста запросов.

Зафиксированные исключения (не трогаем, data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).
