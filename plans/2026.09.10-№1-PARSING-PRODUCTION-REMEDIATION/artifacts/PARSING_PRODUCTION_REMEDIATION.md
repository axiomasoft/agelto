# Ремедиация непрерывного парсинга ЕИС

Дата проектирования: 2026-09-10
Master plan: `plans/2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION/`

## P4.3 controlled-production preflight — 2026-09-11

Точный owner `plan-run` материализован в bounded carrier, но production traffic не разрешён без
target-bound environment/profile/scope D10. Все шесть объявленных Compose-команд выполнены и
остановились до container exec: `COMPOSE_PROJECT_NAME` не задан, `.env.production` и
`docker/host/production-profile` отсутствуют, production Compose project/remote target недоступны.

Вердикт текущей попытки — **NO-GO, nonterminal**. 72-часовое окно не стартовало, production request,
worker interruption, storage fault и writes не выполнялись; rollback не требуется. Продолжение
остаётся в P4.3 после появления доступного production target, immutable image digest и точной
привязки crawler profile/filter, request ceiling и reversible stop action. Evidence:
`artifacts/P4-controlled-production.json` → `findings/P4-controlled-production.md`.

## P4.2 replay — 2026-09-11

Свежий независимый replay после P1.4 priority repair подтвердил GREEN для A1–A9, B1, NEW-1,
NEW-2 и условной ветви B2. Repair сохраняет dirty stats вместе с cursor через прежний
owner/lease-fenced query; focused FreshRun — 13/59, integration canaries — 9/642, targeted broad —
868/3174, full suite — 1639/7441. PHPStan, TLS guard и diff check GREEN. P4.2 не выполнял live
requests и не создал product diff.

Evidence chain: artifact `6586eab263407e0624ecaf99c184d7b64d4cf715022ccb74242e66f0d6b95095`
→ findings `04c1a65302f8314edd8935c21fcf992285a6d8d276c908bfa2f9f214dc49bac7`
→ research `bf1b303b3b457da208cd07125a1be59755b74dbf9cd46fcbe8f0decc79a2798a`.

Validation carriers исправлены напрямую по owner override: seed и worker commands самодостаточны,
acceptance публична для Task contract, Layout-v2-safe named matrix явна, Pint baseline GREEN.
Свежий read-only actor после material SHA change повторил broad/full/static/worker/seed checks без
live traffic и принял P4.2 terminal GREEN. Следующий P4.3 остаётся заблокирован до отдельной durable
owner authorization на 72-часовой controlled-production gate.

## Решение

Текущая архитектура сохраняется: PostgreSQL остаётся источником состояния, а crawling, parsing и
AI продолжают исполняться идемпотентными Horizon jobs. До controlled-production должны быть
закрыты A1–A9, B1, NEW-1 и NEW-2; B2 допускается только как явное условное решение с live gate;
B3 остаётся отдельным observability improvement и не блокирует запуск. Основание —
`artifacts/PARSING_PRODUCTION_AUDIT.md` и независимая RED-приёмка
`artifacts/PARSING_PRODUCTION_ACCEPTANCE.md` (RAG:— repo-grounded).

## Трассировка находок

| Finding | Plan item | Закрывающий результат |
|:--|:--|:--|
| A8 | P0.1 | Непрерывное историческое окно партиций на fresh install и existing install |
| A1 | P1.1 | Fresh дочитывает граничные сутки и не принимает пустой business-delta блока за конец |
| NEW-1 | P1.2 | Hard-cap оставляет resumable incomplete run и обязательство продолжения |
| A2 | P1.3 | Recovery сохраняет исходный fresh scope и не теряет недренированный интервал |
| A9 | P1.4 | Один execution lease владеет backlog/delta run; overlap и crash/expiry проверены |
| A3 | P2.1 | Typed complete/incomplete/failed snapshot; removals только по complete, children не снимаются |
| B1 | P2.2 + P4.2 | Items/parties синхронизируются только по подтверждённому полному snapshot и независимо принимаются |
| B2 | P2.3 + P4.1 + P4.2 | P4.1: `insufficient_evidence` → bounded revalidation реализована; P4.2 независимо принимает до pilot |
| A5 | P3.1 | Due `Discovered` получает единый retry route; future backoff блокирует все OR-ветви |
| A6 | P3.2 | Source/archive object write проверяется; DB не утверждает отсутствующий объект |
| NEW-2 | P3.3 | Все prepared representations fail closed на write и повторяются идемпотентно |
| A4 | P3.4 | Retryable analyses и потерянные DB→queue continuations автоматически сверяются |
| A7 | P3.5 | Длительные операции, timeout, retry_after, lease и shutdown grace согласованы |
| B3 | P4.4 | Raw CSV/HTML evidence получает immutable identity; item не входит в launch gate |

P4.4 хранит raw evidence по content-addressed key из нормализованной request identity и hash bytes.
Это предотвращает overwrite между CSV windows и HTML revisions; повтор того же fetch идемпотентно
переиспользует объект. Checked write предшествует `prc_fetches.artifact_ref`; raw retention остаётся
отдельной от этой гарантии эксплуатационной политикой.

## Условный B2 contract

Production detail-контур теперь повторно проверяет существующий `uid`: после индексации новой
ревизии `DocumentRevalidationSelector` ставит ограниченную пачку вложений в `doc_download`, а
шестичасовой low-rate sweep подбирает давно не проверявшиеся версии. Селектор исключает removal,
future backoff, permanent failure и активный lease. Тот же SHA сохраняет pipeline status, новый
SHA создаёт версию и возвращает документ в обычную подготовку.

P4.1 после отдельного owner gate проверяет revision pairs по протоколу
`plans/2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION/findings/P2-b2-live-protocol.md`. Reducer
имеет три закрытые ветви. Live-запросы P4.1 остановились после трёх последовательных transport
timeout и не дали полных replacement pairs, поэтому выбран `insufficient_evidence` и реализована
обязательная bounded revalidation. Отсутствие counterexample не является доказательством immutable
`uid`, а controlled production остаётся запрещённой до GREEN независимой приёмки P4.2.

## Порядок и гейты

1. P0 закрывает схему хранения: без неё профильные тесты и исторические события недостоверны.
2. P1 закрывает полноту и конкурентность listing/recovery.
3. P2 вводит достоверный snapshot contract и lifecycle current projections.
4. P3 замыкает retry/storage/continuation/time budgets.
5. P4 запускает live B2 verification, затем независимую приёмку и только после неё 72 часа ограниченной
   production-обкатки. B3 исполняется отдельно после launch decision и не меняет verdict P4.3.

Controlled-production запрещена до terminal P4.1 с resolved B2 и GREEN P4.2. P4.3 закрывается только
артефактом с командами, временным окном, выбранным scope, fault-injection evidence, backlog-age и
ручной сверкой изменений с ЕИС. Числовой SLA из этого плана не выводится: 72 часа — консервативное
окно первой обкатки, а не универсальный норматив (RAG:— repo-grounded:
`artifacts/PARSING_PRODUCTION_AUDIT.md`, раздел «Конечный объём работы»).

## Не входит

- Переписывание архитектуры на другой язык, Scrapy, Kafka или workflow engine.
- Ослабление test isolation, assertions, TLS/proxy guards, retry/lease invariants.
- Исправление unrelated release/docs/Filament dirty changes.
- Обещание неизменности bytes при одном ЕИС `uid` без live evidence.

## Исправления реализованного кода после аудита — 2026-09-11

Область повторного аудита и исправлений: фактически реализованный код P1–P3 и bounded
revalidation. Зафиксированное закрытие с отклонениями сохраняется; невыполненные live-работы
не являются замечаниями этого аудита.

- Для `doc_download` добавлен Horizon consumer; download/revalidation jobs имеют явный timeout.
- Полнота common-info и parties проверяется независимо. Настоящая HTML-фикстура без
  искусственного блока организаций обновляет ИКЗ, финансирование и позиции, сохраняя прежние parties.
- Pipeline recovery продолжает выборку после терминальных и отложенных записей до набора
  лимита действительно отправленных jobs; память ограничена постраничным чтением.
- Prepared objects адресуются по хешу фактического результата конвертации и проверяются
  до DB finalization: метаданные LibreOffice больше не создают ложный конфликт одинаковых исходников.
- Ошибки object storage и DB выходят в retry; Word/Spreadsheet считают терминальными только
  ошибки конвертации. OCR PDF записывается и проверяется потоками.
- Revalidation и AI получают lease под общей блокировкой строки документа; активный текущий
  analysis run защищён и между checkpoint jobs. Selector исключает такие документы до `LIMIT`.
- Возврат содержимого A → B → A снова активирует A, создаёт replacement event и допускает
  новый анализ. Повтор тех же текущих байтов сохраняет статус стадии и сбрасывает ошибки скачивания.
- Fresh run сохраняет исходный recovery scope до первого запроса; backlog использует его
  после изменения профиля. Для старых завершённых runs без достоверного scope recovery остаётся pending.
- OCR admission учитывает последовательность probe → OCR → metadata, setup и сохранение:
  job 2580 с < Horizon 2640 с < Redis visibility/lease 2700 с. Отсчёт начинается перед guard/lease;
  нехватка времени остаётся retryable, а не переводит документ в unprocessable.

Дополнительно исправлена утечка закупки из concurrency-фикстуры в тестовую БД;
архитектурный реестр дополнен зеркальными подобластями `Exceptions/Procurement/Prepare`
и `Repositories/Procurement/Crawler`. Timeout jobs оформлен штатным Laravel attribute.

Проверки выполнены в локальном `agelto_dev`, тестовая БД — изолированная `agelto_test`.
`make test-preflight` прошёл. Префикс команд ниже:
`docker compose --project-name agelto_dev --env-file .env -f docker-compose.yml exec -T app`.

- `php artisan test --compact --no-tia`: **1661 passed, 7550 assertions**.
- После последних правок writer/clock/same-hash reset повторно выполнен
  `php artisan test --compact --no-tia tests/Feature/Document tests/Feature/Procurement/Prepare tests/Feature/Procurement/Pipeline tests/Feature/Procurement/Screening tests/Feature/Procurement/Analyzers/Estimate/EstimateExtractionJobTest.php tests/Feature/Procurement/Analyzers/Estimate/WindowedExtractionTest.php tests/Feature/Procurement/Analyzers/Estimate/ExtractionOrchestratorTest.php tests/Unit/Config/QueueTimeoutInvariantTest.php`:
  **247 passed, 1131 assertions**.
- `./vendor/bin/phpstan analyse --memory-limit=1G --no-progress`: **0 errors**.
- `./vendor/bin/rector process --dry-run --no-progress-bar`: **0 изменений**.
- `./vendor/bin/pint --test` по затронутым PHP-файлам и зависимым prepare-файлам:
  **63 файла passed**, затем **8 файлов passed** после последних правок.
- `git diff --check`: **passed**.

Независимый review проверил взаимное исключение AI/revalidation и OCR budget/storage.
Найденные на нём same-hash error reset и чтение OCR PDF целиком в память исправлены.
Live ЕИС и production-проверки не выполнялись в рамках этих исправлений.
