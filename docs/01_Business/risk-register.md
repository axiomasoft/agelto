---
audience: dev
type: reference
status: living
---

# Реестр рисков — что пережило переход v1 → v2

Только для разработчиков. Источник — `Old/AUDIT.md` §2 (находки по надёжности v1-пайплайна) и удалённая при слиянии документации карта переноса v1. Большинство находок v1 закрыты редизайном схемы/архитектуры v2 — они перечислены ниже кратко, для памяти. Детально расписаны только **реально ещё открытые** риски.

## Итоговая сводка по находкам `Old/AUDIT.md` §2

| ID | Находка v1 | Статус в v2 |
|---|---|---|
| C1 | Разорван конвейер Стадия 1→2 (нет прод-вызова создателя `DocumentAnalysis`) | Закрыто явным мостом `PipelineDispatcher` + раздельные error-блоки по стадиям + три гарда от двойного диспатча (ADR-0009) — **см. риск #1 ниже**, остаточное наблюдение, не открытая проблема |
| C2 | `crawler_id` — несуществующая колонка в запросе | Закрыто: `prc_crawler_runs.crawler_id` — реальный FK в схеме и в коде |
| C3 | `isGeminiCacheValid()` vs `isCacheValid()` — опечатка в вызове метода | Закрыто рефакторингом (код Фазы 4/5 AI-анализа ещё не написан с нуля — опечатка v1 неприменима) |
| C4 | Детектор зависаний сломан (нет `timestamps()` на `plt_document_analysis`) | Закрыто: `prc_ai_analyses` явно содержит `started_at/finished_at` + lease (`locked_by/locked_until`) в схеме §6 |
| C5 | Рассинхрон статусов `Link`↔`DocumentAnalysis`, retry всегда с нуля | Закрыто (нет общей таблицы `Link`, каждая стадия — свой error-блок на своей таблице) — **см. риск #1**, тот же класс проблемы был риском в мосте P3.8, закрыт ADR-0009 |
| H1 | Guard'ы не ловят in-progress (дублирующая загрузка в Gemini) | Закрыто: `prc_ai_analyses.locked_by/locked_until` — lease явно в схеме §6 |
| H2 | Краулер сбрасывает прогресс ретраев на каждом проходе | Закрыто архитектурным разделением: `CrawlerRunManager`/`prc_crawler_runs.cursor` (листинг) не трогает `detail_*` error-блок (детальная стадия) — разные владельцы состояния |
| H3 | Возобновляемость только URL-уровня, ценовое дробление не чекпойнтится | Закрыто и уже реализовано в коде: `prc_crawler_runs.cursor` хранит `day`+`priceStack`+блок (P4.1) |
| H4 | Нет dead-letter/hard-limit для документов | Закрыто: `prc_documents` — явный error-блок (`attempts/last_error/last_error_at/next_retry_at/failed_permanently`) |
| H5 | Ошибки скачивания вложений не учитываются | Закрыто тем же error-блоком `prc_documents` (единая колонка вместо второй legacy-таблицы `plt_crawler_link_attachments`) |
| M1 | Telegram-алерты молча гаснут при пустом токене | Вне периметра схемы БД — код уведомлений ещё не переписан под v2, не проверялось этим документом |
| M2 | Логи без структуры/контекста | Закрыто по схеме: `prc_operation_logs` явно типизирован (`stage/entity_type/entity_id/context jsonb/trace`) — код-дисциплина логирования по-прежнему на исполнителе |
| M3 | Нет rate-limit на Gemini (429 при параллельных воркерах) | Вне периметра схемы БД — Horizon/AI-воркеры Фазы 4/5 ещё не построены, не проверялось |
| M4 | Stuck-check слеп к инфраструктуре (Chromium/очереди/Redis/PG) | Вне периметра схемы БД — операционный мониторинг вне `docs/03_Dev/database.md` |
| M5 | Нет healthcheck на horizon/scheduler в dev | Вне периметра схемы БД — Docker-конфигурация не проверялась этим документом |
| M6 | `maxTime=3600s` копит утечки Chromium | Вне периметра схемы БД — в текущей архитектуре detail-стадия HTTP-only, браузер не используется (P3.1) |
| D1 | Имя файла миграции ≠ имя таблицы (косметика) | Снято физически: единый префикс `prc_` (см. `plans/rustling-jingling-quiche.md` §A) устраняет саму путаницу префиксов |
| D2 | Мёртвая параллельная ветка `plt_parsing_items` | Закрыто: не перенесено в v2 (мёртвый код осознанно не переносится) |
| D3 | Битый FK `ord_lots.order_id` на себя | Закрыто: `prc_lots.procurement_id` в v2 — корректный FK на `prc_procurements`, не self-reference |
| D4 | Нет индексов/unique на attachments | Закрыто: `prc_documents` имеет `unique(procurement_id, external_id)`, `prc_document_versions` — `unique(document_id, content_hash)` |
| D5 | Неоднородный учёт ошибок (`last_error+attempts` vs `process_error`) | Закрыто конвенцией: единый error-блок паттерн повторён на `prc_procurements.detail_*`, `prc_documents.*`, `prc_ai_analyses.*` |
| D6 | DTO-рассинхрон: `okved`/`okved_additional` не долетали до колонок | Закрыто и подтверждено кодом: `prc_procurements.okved_code`/`okved_additional` есть в схеме §3 И реально прокинуты через `ProcurementSnapshotData`→`ProcurementStoreRepository` (проверено грепом по `app/`) |
| D7 | `varchar(20)` слишком мал для статусов, truncation на Postgres | Закрыто: статус-колонки v2 — `text`, не `varchar(N)` |
| D8 | Тесты бьют по живой Postgres-БД (`phpunit.xml` без force sqlite) | Закрыто: текущий `phpunit.xml` использует `DB_DATABASE=agelto_test` + `tests/bootstrap.php` (см. skill `test-isolation-guard`) — явная изоляция тестовой БД до автозагрузки |

## Риски, которые ещё реально открыты

### Риск 1 — класс проблемы C1/C5 (рассинхрон стадий): закрыт тремя гардами + TTL-локом (ADR-0009)

**Что было в v1 (C1, C5):** конвейер между Стадией 1 и 2 был разорван физически (никто не вызывал точку создания `DocumentAnalysis` в проде), а три джобы ставили статус `DocumentAnalysis`, не трогая `Link.status`, из-за чего retry-команда (читающая `Link.status`) не находила упавшие документы.

**Что сделано в v2:** структурно проблема невозможна в прежнем виде — нет общей таблицы `Link`, у каждой стадии свой явный error-блок на своей же таблице (`prc_procurements.detail_*`, `prc_documents.*`, `prc_ai_analyses.*`), мост между детальным анализом и подготовкой документов — явный класс `PipelineDispatcher` (не implicit-вызов из теста). `PipelineDispatcher` НЕ мутирует статус документов и не обязан: owner перехода `downloaded → prepared` — сам `PrepareDocumentJob` (ADR-0009, `docs/03_Dev/adr/0009-job-owns-document-transition.md`), а защиту от повторной постановки в очередь держат три независимых гарда — `ShouldBeUnique` (`PrepareDocumentJob:24,35`), status-guard (`PipelineJob:34-36`), атомарный лиз (`HasLease:24-42`). G1 запинен `tests/Feature/Document/PipelineDispatcherTest.php:26`.

**Остаточное наблюдение (не открытая проблема):** до `plans/archive/DOMLANG` P5.1 `uniqueFor()` не был объявлен — на Redis (прод) unique-лок брался без TTL, и убитый воркер (SIGKILL/OOM/рестарт) навсегда запирал документ в `downloaded`. Закрыто `PipelineJob::uniqueFor() = config('ai.pipeline.unique_lock_minutes', 60) * 60` — протухший лок теперь самовосстанавливается не позже часа, следующий тик `dispatchBacklog()`/`pipeline:retry-failed` забирает документ штатно.

### Риск 2 — единый префикс `prc_` для инфраструктурных таблиц текущего модуля (осознанный компромисс, зафиксировано в `plans/rustling-jingling-quiche.md` §A)

Решение переименовать все таблицы модуля закупок под единый префикс `prc_` (вместо префиксов по типу данных `doc_/est_/ai_/crw_/sys_`) — правильный выбор на сегодня (весь текущий код — один bounded context «закупки»), подтверждённый ресёрчем по DDD/модульному монолиту (`nwidart/laravel-modules`, Milan Jovanović, kgrzybek/modular-monolith-with-ddd — префикс по типу сущности «rarely helpful», префикс по модулю — приемлемый fallback, отдельная Postgres-схема на bounded context — правильная практика).

**Осознанный компромисс:** когда появится второй модуль (внутренний документооборот, CRM и т.п.), инфраструктурные таблицы текущего модуля — AI-пайплайн (`prc_ai_*`), краулер (`prc_crawlers`/`prc_fetches`/…), операционные логи (`prc_operation_logs`) — скорее всего понадобятся как переиспользуемые несколькими модулями. На тот момент единый префикс `prc_` придётся **разбирать на отдельные Postgres-схемы по bounded context** (`procurement.*`, `dms.*` и т.п. — рекомендация Perplexity-ресёрча из плана) либо на модульные префиксы заново.

Это принятая ради простоты сейчас цена, зафиксированная явно, **не оверсайт**: следующий разработчик, который решит завести второй модуль и наткнётся на `prc_ai_llm_calls`/`prc_operation_logs`, должен знать — это была осознанная развилка (см. таблицу вариантов префиксов и раздел «Не переименовываются» в `plans/rustling-jingling-quiche.md` §A: `ref_*` и `org_organizations` уже вынесены как общий shared-слой именно по этой причине, инфраструктурные таблицы AI/crawler/logs — нет, целенаправленно отложено).

## Не проверялось этим документом (вне периметра схемы БД)

M1 (Telegram-алерты), M3 (Gemini rate-limit), M4 (инфраструктурный stuck-check), M5/M6 (Docker/Horizon конфигурация) — все касаются кода/инфраструктуры Фазы 4/5 (AI-анализ, скрининг, извлечение), которая **ещё не реализована** в v2 (только краулер, P1–P4, готов). Схема `docs/03_Dev/database.md` даёт для части из них заготовки (`prc_ai_analyses` lease, `prc_operation_logs` структура), но код воркеров/уведомлений/Docker-профилей для этих стадий не существует — рано делать вывод «закрыто» или «открыто», перепроверить при реализации Фазы 4/5.
