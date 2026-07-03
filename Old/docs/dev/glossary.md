---
type: glossary
tags: [agelto, glossary, naming, dev]
created: 2026-06-27
updated: 2026-06-27
---

# Технический глоссарий и канон именования — Agelto

Источник истины для **имён**: доменные сущности, глаголы методов, нейминг тестов. Имя раскрывает намерение, а не реализацию; грань — простота ↔ точность. Бизнес-термины (ЕИС, смета, лот…) — в `docs/02_Workflow/Glossary (Agelto).md`. Общие правила сущностей PHP/Laravel — skill `naming-conventions`.

Меняем имя здесь → выравниваем код по нему.

## Доменные сущности

| Концепт | Канон | Таблица | Переменная |
|---|---|---|---|
| Площадка-источник | `Platform` | `plt_platforms` | `$platform` |
| Профиль обхода | `Crawler` | `plt_crawlers` | `$crawler` |
| Один прогон обхода | `CrawlerRun` | `plt_crawler_runs` | `$run` |
| Найденное извещение ЕИС | `Link` | `plt_crawler_links` | `$link` |
| Файл-вложение извещения | `LinkAttachment` | `plt_crawler_link_attachments` | `$attachment` |
| Сессия AI-анализа вложения | `DocumentAnalysis` | `plt_document_analysis` | `$analysis` |
| Ответ AI на шаге анализа | `DocumentAnalysisResponse` | `plt_document_analysis_responses` | `$response` |
| Распарсенная смета (DTO) | `Estimate` | — | `$estimate` |

Избегать в идентификаторах: голый `Run`/`Crawl` вместо `CrawlerRun`; `Url` вместо `Link`; голый `Document`/`Attachment` для модели вложения; `Smeta`/«Смета». В UI и комментариях бизнес-язык допустим. Стадия 5 (нормализация в `Order`/`Lot`/`PurchaseObject`) пока не подключена.

## Стадии пайплайна (`ProcessStage`)

| № | Стадия | Кейс | Точка входа |
|---|---|---|---|
| 1 | Сбор ссылок | `Crawling` | `AbstractCrawlingService::start()` |
| 2 | Сбор документов карточки | `DocParsing` | процесс `DocumentPrepare` |
| 3 | AI-скрининг | `DocAnalysis` | `PrimaryAnalysisProcessor::run()` |
| 4 | Полный парсинг позиций | `ParsingFull` | `EstimateAnalysisProcessor::run()` |

`PipelineStage` (`DocumentPrepare`/`PrimaryAnalysis`/`EstimateAnalysis`) — точка retry внутри обработки одного документа; не путать с `ProcessStage`.

## Enum'ы

- `AnalysisStatus` — статус `DocumentAnalysis` (`Preparing`…`Completed`/`Error`); предикаты `isTerminal/isFailed/isInProgress/isAwaiting`.
- `CrawlerRunStatus` — статус прогона (`Running`/`Paused`/`Completed`/`Failed`/`Cancelled`).
- `CrawlingStatus` — агрегат-статус `Link`/`LinkAttachment` (`PENDING`/`PROCESSING`/`COMPLETED`/`FAILED`).

Не трогаем (data-affecting): `UPPER_CASE`-кейсы `CrawlingStatus`; строковые значения enum (`ProcessStage::DocParsing = 'parsing_docs'`).

## Глаголы методов

| Глагол | Когда | Пример |
|---|---|---|
| `find*` | поиск по идентичности, **nullable**; без суффиксов `ByXAndY` (параметры и так говорят) | `findCrawler($id)`, `findLink($platformId, $regNumber)` |
| `get*` | вернуть определённое/вычисленное (коллекция, значение) | `getPendingLinks()`, `getDocumentForAnalysis()` |
| intent-имя без `get` | если существительное читается ясно — `get` лишний | `owner()`, `systemInstructionsFor($step)` |
| `store*` / `mark<State>` | запись / смена статуса в репозитории | `storeOrUpdateLink()`, `markFailed()` |
| `run()` / `start()` | вход процессора (один) / длительный lifecycle | `PrimaryAnalysisProcessor::run()` |
| доменные мутации | глагол+существительное | `transitionStatus()`, `recordFailure()`, `acquireLease()` |

Избегать: `fetch*`/`load*` для чтения из БД (`load` — только eager-load связей); безобъектные `handle`/`process`/`do`.

## Нейминг тестов

- Файл `tests/{Feature|Unit}/<Домен>/<Subject>Test.php` — **одна ответственность**, зеркалит `app/`.
- Только `test('<интент по-русски>', fn)` — без `it()`.
- Персистентные модели — фабрики (`X::factory()`), статусы — states (`->pending()`, `->primaryAnalysisFailed()`), цепочки — `->for()`/`->has()`. Без tuple-возвратов и boolean-флагов (вместо них states/`->throwing()`).
- Переменные — доменный канон (`$crawler`, `$run`, `$link`, `$attachment`, `$analysis`).
- Test doubles → `tests/Support/Doubles/`; общие mock-сборки — хелперы (`fakeBrowserClient()`); повторяющиеся проверки — кастомные expectations (`toHaveLoggedMessage()`).
