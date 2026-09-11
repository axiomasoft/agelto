# Независимая приёмка исправлений непрерывного парсинга

Дата: 2026-09-10

Профиль: независимое adversarial code review (`task:review`, code + documentation conformance)

Проверенный commit: `f60e98c25aa65e8ebf225e4681beca3e796a52b9` (`HEAD == origin/main`)

Вердикт: **P4.2 GREEN — controlled production требует отдельного owner-authorized P4.3**

## P4.2 independent replay — 2026-09-11

Свежая независимая offline-приёмка после priority repair и validation-carrier repair подтверждает
**terminal GREEN P4.2**. Она не выполняла live/outbound-запросов и не меняла файловую систему.
Machine verdict находится в
`plans/2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION/artifacts/P4-independent-acceptance.json`,
факты — в `findings/P4-independent-acceptance.md`, синтез — в
`research/P4-independent-acceptance.md`.

SHA chain: artifact `6586eab263407e0624ecaf99c184d7b64d4cf715022ccb74242e66f0d6b95095`
→ findings `04c1a65302f8314edd8935c21fcf992285a6d8d276c908bfa2f9f214dc49bac7`
→ research `bf1b303b3b457da208cd07125a1be59755b74dbf9cd46fcbe8f0decc79a2798a`.

| ID | P4.2 | Независимое доказательство |
|:--|:--|:--|
| A1 | GREEN | focused 13/59; dirty `stop_reason` сохраняется тем же owner-fenced cursor write |
| NEW-1 | GREEN | continuation заменяет `fresh_max_blocks_checkpoint` доказанным terminal reason |
| A2, A3, B1 | GREEN | 58 tests / 249 assertions |
| A4, A5 | GREEN | 35 tests / 118 assertions |
| A6, NEW-2 | GREEN | 23 tests / 100 assertions |
| A7, A8, A9 | GREEN | 25 tests / 134 assertions плюс exact real-worker recovery |
| B2 | GREEN conditional | D8 `insufficient_evidence`; universal uid claim отсутствует, bounded revalidation 12/39 GREEN |

P1.4 repair принят независимым review: dirty stats и cursor пишутся одним
`ownedRunningQuery()` update, прежние status/owner/lease predicates сохранены. Focused, integration,
targeted broad и full carriers GREEN; P4.2-attributable product diff пуст.

Full suite: **1639 passed, 7441 assertions**; targeted broad — **868/3174**; Pint — **1547 files**;
PHPStan — **718 files**. Seeded migration, exact worker recovery, TLS guard, JSON/SHA checks,
`git diff --check` и Task `pre_final` GREEN. P4.2 закрыт; любой production traffic по-прежнему
запрещён до отдельного durable owner authorization и исполнения P4.3.

## Краткий итог

По текущему коду не подтверждено закрытие ни одного из A1–A9, B1 и B3. B2 остаётся
техническим ограничением без прослеживаемого решения и без публично подтверждённой гарантии ЕИС;
живые продуктовые документы при этом описывают версионирование сильнее, чем его обеспечивает
оркестрация.

Это не расхождение только с отметками плана. `PARSING_PRODUCTION_REMEDIATION.md` отсутствует и в
рабочем дереве, и в доступной Git-истории; отдельный связанный с этим аудитом plan/handoff также
не найден. `plans/ACTIVE.md:3` сообщает `Active: —`. Единственная явно связанная историческая
основа — закрытый `plans/archive/2026.08.06-CRAWL-HARDEN`: его `plan.md`, `status.md` и
`handoff.md` прочитаны, но он предшествует аудиту от 2026-09-10 и не содержит приёмочных
исправлений A1–A9/B1–B3.

В parsing/application-контуре нет незакоммиченного remediation-диффа. Найденные до начала этой
приёмки чужие изменения относятся к release/docs/Filament-контуру; они не смешивались с
находками и не изменялись. Единственная запись этой приёмки — данный файл.

## Матрица приёмки

| ID | Результат | Ключевое доказательство |
|:--|:--|:--|
| A1 | ❌ Не закрыт | `FreshRunProcessor.php:97-114,165-171`; дата без времени становится полуночью в `EisCsvRowMapper.php:136-152` |
| A2 | ❌ Не закрыт | первый archive-профиль выбирается в `BacklogRescanProcessor.php:74-78` и `DeltaScanProcessor.php:37-41`; scanner удаляет недренированный интервал в `ArchiveWindowScanner.php:75-94,151-167` |
| A3 | ❌ Не закрыт | parser возвращает обычный `[]` при ошибке в `DocumentsParser.php:24-55`, после чего indexer безусловно снимает отсутствующие документы в `DocumentIndexer.php:31-45,85-97` |
| A4 | ❌ Не закрыт | transient analysis остаётся `Running`, а retry выбирает только `Failed`: `ScreeningJob.php:99-112`, `EstimateExtractionJob.php:98-111`, `PipelineRetryFailed.php:111-129` |
| A5 | ❌ Не закрыт | download failure оставляет `Discovered`, глобального маршрута нет: `DocumentDownloader.php:213-226`, `DetailAnalysisProcessor.php:168-177`; detail backoff обходится OR-ветками в `DetailRefetchSelector.php:38-50` |
| A6 | ❌ Не закрыт | результат `Storage::put()` не проверяется, а version/status/event пишутся раздельно: `DocumentDownloader.php:75-106,229-237`, `filesystems.php:54-64` |
| A7 | ❌ Не закрыт | supervisor 120/60/300 s конфликтуют с OCR 1800 s, archive/tools 180 s и LLM 150 s: `horizon.php:222-265`, `ai.php:20-30,240-279` |
| A8 | ❌ Не закрыт | историческое окно заканчивается 2026-08-01, текущие партиции в сентябре начинаются 2026-09-01: `2026_08_05_100074_precreate_historical_partitions.php:62-70`, `2026_07_04_100013_create_prc_events_table.php:46-61`; 13 тестов воспроизвели ошибку |
| A9 | ❌ Не закрыт | backlog/delta schedule без overlap-lock: `routes/console.php:33-35,53-57`; одинаковый owner получает тот же `Running` run без execution lease: `CrawlerRunManager.php:23-35` |
| B1 | ❌ Не закрыт | financing заменяется, items и parties только upsert: `CommonInfoProcessor.php:41-58`, `OrganizationPartiesProcessor.php:31-48` |
| B2 | ⚠️ Решение не принимается как доказанное | существующий `uid` обновляет только metadata и сохраняет статус: `DocumentIndexer.php:48-62`; скачиваются только `Discovered`: `DetailAnalysisProcessor.php:168-177` |
| B3 | ❌ Не закрыт | CSV-key содержит только crawler/run/from: `CsvBlockIngestor.php:211-229`; HTML-key — только registry/procurement/tab: `CsvSourceMethod.php:299-309` |

## Дефекты, блокирующие production-обкатку

### 1. A1 — остановка fresh внутри суток теряет обновления

**Класс:** Major · issue · correctness · уверенность high.

**Сценарий.** Предыдущий прогон стартовал 10 сентября в 10:00, порог с запасом равен 09:00.
ЕИС отдаёт много страниц с `Дата обновления = 10.09.2026` без времени и не обещает тотальный
порядок внутри суток. Mapper превращает дату в `10.09.2026 00:00:00`; уже первый блок считается
достигшим порога. Альтернативная ветка `rowsNew == 0 && rowsUpdated == 0` также завершает прогон,
хотя на следующих страницах тех же суток могут быть новые строки.

**Место:** `app/Services/Procurement/Eis/EisCsvRowMapper.php:136-152`;
`app/Services/Procurement/Modes/FreshRunProcessor.php:97-114,147-171`.

**Последствие:** молчаливый постоянный пропуск части новых/обновлённых закупок; следующий прогон
может снова остановиться на той же суточной границе.

**Минимальное исправление:** учитывать точность источника: дочитывать целиком граничные сутки с
перекрытием и не считать пустую business-delta одного блока доказательством конца при неоднозначном
порядке. Добавить multi-page regression с одинаковой датой без времени и новой строкой на поздней
странице.

### 2. A2 — recovery имеет другой scope и объявляет недренированный диапазон завершённым

**Класс:** Major · issue · correctness · уверенность high.

**Сценарий 1.** Fresh-профиль видит все стадии в scope ОКПД2 43, а backlog/delta выбирает первый
активный archive-профиль того же реестра. Штатный archive-профиль ограничен `stageCompleted: true`
и окном публикации 2025 года. Пропуск fresh-потока по незавершённым/новым закупкам таким recovery
профилем не восстанавливается.

**Сценарий 2.** В плотном дне ценовой интервал достигает `max_blocks_per_window`; scanner удаляет
его со стека. При повторе signature на минимальном интервале остаток также удаляется. Пустой стек
возвращает `Drained`, после чего backlog помечается пересканированным.

**Место:** `app/Services/Procurement/Modes/BacklogRescanProcessor.php:74-78,110-145`;
`app/Services/Procurement/Modes/DeltaScanProcessor.php:37-41,81-95`;
`database/seeders/Procurement/CrawlerProfileSeeder.php:120-143,156-176`;
`app/Services/Procurement/ArchiveWindowScanner.php:75-94,116-144,151-167`.

**Последствие:** recovery выглядит успешным, но не покрывает исходную выборку либо выбрасывает
хвост плотного диапазона без повторной возможности его собрать.

**Минимальное исправление:** привязать recovery к совместимому fresh-scope (или проверять
совместимость отдельного профиля); недоказанно исчерпанный интервал сохранять как checkpoint/
операторский долг. `Drained` допустим только после доказанного конца каждой ветви. Тесты должны
проверять сохранение и последующее дочитывание остатка, а не только прекращение цикла.

### 3. A3 — ошибочный/частичный разбор документов превращается в удаления, archive children тоже снимаются

**Класс:** Major · issue · correctness · уверенность high.

**Сценарий.** ЕИС меняет selector одной строки либо отдаёт защитную/частичную страницу. Parser
возвращает `[]` или неполный список. `DocumentIndexer` не получает признака полноты и сразу помечает
все отсутствующие `external_id` удалёнными. В ту же выборку `activeExcept` попадают synthetic
archive children, потому что они имеют тот же `procurement_id` и непустой `external_id`, но не
могут присутствовать на вкладке documents.

**Место:** `app/Services/Procurement/Eis/Card/DocumentsParser.php:24-75`;
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php:101-104`;
`app/Services/Procurement/DocumentIndexer.php:31-45,85-97`;
`app/Repositories/Procurement/Document/DocumentStoreRepository.php:61-68`;
`app/Actions/Procurement/Document/ExpandArchiveEntryAction.php:47-58`.

**Последствие:** ложные `removed_at` и `document_removed`, потеря актуальности исходных вложений и
неверный lifecycle распакованных документов.

**Минимальное исправление:** передавать типизированный parse outcome `complete / incomplete /
failed`; reconcile удалений выполнять только для подтверждённого полного snapshot. Ограничить
сверку исходными platform attachments, исключив children по явному origin/parent-признаку. Добавить
тесты на selector drift, одну испорченную строку и archive child.

### 4. A4 — retry состояния и потерянные продолжения стадий не восстанавливаются

**Класс:** Major · issue · correctness/reliability · уверенность high.

**Сценарий 1.** Screening/extraction получает retryable ошибку. `recordFailure()` ставит attempts и
`next_retry_at`, но до исчерпания попыток оставляет `Analysis.status = Running`. Команда
`pipeline:retry-failed` отбирает только `Failed`, поэтому наступивший retry никогда не диспатчится.
Существующий тест прямо фиксирует `Running` после retryable MAX_TOKENS.

**Сценарий 2.** Worker сохраняет `Document=Prepared` и `AnalysisRun`, но падает до Redis dispatch;
либо коммитит `Document=Screened` и падает в after-commit dispatch extraction. Backlog dispatcher
отбирает только `Downloaded`; stuck-check только уведомляет. Гарантированного восстановителя этих
переходов нет.

**Место:** `app/Models/Concerns/HasErrorBlock.php:18-31`;
`app/Jobs/Procurement/ScreeningJob.php:59-112`;
`app/Jobs/Procurement/Analyzers/Estimate/EstimateExtractionJob.php:64-111`;
`app/Console/Commands/Procurement/PipelineRetryFailed.php:111-129`;
`app/Jobs/Procurement/PrepareDocumentJob.php:76-95`;
`app/Actions/Procurement/Screening/ApplyScreeningDispositionAction.php:72-115`;
`app/Services/Procurement/PipelineDispatcher.php:64-69`;
`tests/Feature/Procurement/Screening/ScreeningProcessorTest.php:148-164`.

**Последствие:** документы навсегда остаются в промежуточных состояниях после обычного transient
отказа или узкого окна между DB commit и отправкой в Redis.

**Минимальное исправление:** согласовать retryable status с selector/guard и добавить ограниченный
reconciler потерянных продолжений по активной версии/run, lease, backoff и сохранённому screening
решению. Проверить fault-injection в обеих DB→queue границах и реальный transient путь, а не
искусственно созданный `Failed` fixture.

### 5. A5 — повтор скачивания документа не имеет гарантированного маршрута, detail backoff обходится

**Класс:** Major · issue · reliability · уверенность high.

**Сценарий.** Скачивание вложения падает, документ остаётся `Discovered` с будущим
`next_retry_at`. Повтор скачивания возможен только если закупка снова попадёт в detail processing;
глобальный pipeline dispatcher начинает с `Downloaded`. При `detail_fetched_at IS NULL` либо новой
revision другие OR-ветки selector остаются истинными и обходят ещё не наступивший
`detail_next_retry_at`.

**Место:** `app/Services/Procurement/DocumentDownloader.php:213-226`;
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php:168-177`;
`app/Services/Procurement/DetailRefetchSelector.php:38-56`;
`app/Services/Procurement/PipelineDispatcher.php:64-69`.

**Последствие:** одно вложение может не скачаться никогда; detail endpoint может одновременно
получать запросы раньше backoff.

**Минимальное исправление:** добавить ограниченный due-selector/job для `Discovered` документов
либо эквивалентный гарантированный маршрут. Если задан future retry, сделать его общим гейтом над
OR-причинами detail refetch. Проверить due/future/dead-letter сценарии end-to-end.

### 6. A6 — отказ object storage фиксируется как успешная версия документа

**Класс:** Major · issue · correctness/reliability · уверенность high.

**Сценарий.** S3-compatible storage возвращает `false` на `put()` (диск настроен с
`throw => false`). Код всё равно создаёт `DocumentVersion`, переводит документ в `Downloaded` и
пишет событие. Если retry позже получает те же bytes, ранний return по hash не проверяет наличие
объекта и не лечит частичное состояние. Archive child имеет тот же дефект.

**Место:** `config/filesystems.php:54-64`;
`app/Services/Procurement/DocumentDownloader.php:75-106,229-237`;
`app/Actions/Procurement/Document/ExpandArchiveEntryAction.php:38-72,112-121`.

**Последствие:** база указывает на отсутствующий объект; дальнейшая подготовка падает, история и
события утверждают неслучившееся скачивание.

**Минимальное исправление:** считать запись успешной только после проверенного `put`/исключения;
идемпотентно финализировать version/status/event после объекта и уметь долечить same-hash partial
state. Добавить storage-failure regression. Это соответствует официальной семантике Laravel:
при `throw=false` неуспешный `put` возвращает `false` —
[Laravel 13, Failed Writes](https://laravel.com/docs/13.x/filesystem#failed-writes).

### 7. A7 — штатная длительная операция длиннее worker timeout

**Класс:** Major · issue · reliability/performance · уверенность high по конфигурации, medium по
частоте на реальной нагрузке.

**Сценарий.** `doc_prepare` worker убивает job через 120 s, хотя OCR разрешён 1800 s, archive и
обычные tools — 180 s. Screening worker имеет 60 s при одном LLM request до 150 s плюс Files API
poll до 60 s. Extraction проверяет checkpoint 240 s только до очередного вызова; вызов до 150 s
может закончиться после supervisor timeout 300 s.

**Место:** `config/horizon.php:222-265`; `config/ai.php:20-30,240-279`;
`app/Services/Procurement/Prepare/OcrConverter.php:33-47`;
`app/Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php:169-203`;
`config/queue.php:76-84`.

**Последствие:** легитимные операции принудительно обрываются, затем попадают в дефектный retry
контур A4; при несогласованном увеличении timeout возможна повторная выдача той же job.

**Минимальное исправление:** для каждой стадии согласовать worst-case остаток операции,
job/supervisor timeout, lease, `retry_after` и shutdown grace; перед длинным вызовом оставлять
резерв либо checkpoint/requeue. Тест `QueueTimeoutInvariantTest` проверяет только
`timeout < retry_after` и `checkpoint < timeout`, но не максимальный следующий вызов. Horizon
также требует, чтобы supervisor timeout превышал job-level timeout и оставался короче
`retry_after`: [Laravel Horizon, Job Timeout](https://laravel.com/docs/13.x/horizon#job-timeout).

### 8. A8 — fresh install в сентябре не имеет партиций августа 2026

**Класс:** Blocker · issue · database/correctness · уверенность high; воспроизведено тестами.

**Сценарий.** На текущую дату baseline migrations создают текущий и два будущих месяца начиная с
2026-09-01, а историческая migration создаёт ровно `[2025-08-01, 2026-08-01)`. Август 2026 остаётся
дырой. Fallback maintenance создаёт только текущие/будущие партиции и её не закрывает.

**Место:** `database/migrations/2026_07_04_100013_create_prc_events_table.php:46-61`;
`database/migrations/2026_08_05_100074_precreate_historical_partitions.php:62-70`;
`app/Console/Commands/MaintainPartitions.php:60-76`.

**Последствие:** insert августовских `prc_fetches`, `prc_events`, `prc_revisions` падает. Профильный
прогон воспроизвёл 13 таких ошибок.

**Минимальное исправление:** сделать конец исторического окна динамическим относительно начала
текущего месяца либо явно обеспечить непрерывное смыкание historical/current для всех
партиционированных таблиц. На frozen `2026-09-10` проверить `migrate:fresh --seed`, запись в август
и отсутствие дыр по каталогу партиций.

### 9. A9 — два scheduler/worker могут одновременно мутировать один backlog/delta cursor

**Класс:** Major · issue · concurrency/correctness · уверенность high.

**Сценарий.** Два scheduler tick или два хоста одновременно запускают backlog/delta. Оба
`startOrResume()` с одинаковым purpose/subject получают один существующий `Running` run. Блокировка
crawler действует только внутри короткой transaction создания/поиска; execution lease на весь
scan отсутствует. Оба процесса читают и перезаписывают один cursor и делают повторные запросы.

**Место:** `routes/console.php:33-35,53-57`;
`app/Services/Procurement/CrawlerRunManager.php:23-74`.

**Последствие:** lost cursor updates, повторные/пропущенные окна, лишняя нагрузка на ЕИС и
недостоверный статус завершения.

**Минимальное исправление:** поставить schedule overlap guard и, независимо от него, distributed
execution lease/owner token на весь processor run с безопасным истечением/heartbeat. Проверить
двумя отдельными процессами/соединениями, что второй исполнитель не входит в scanner.

### 10. B1 — удалённые позиции и участники остаются в текущей проекции

**Класс:** Major · issue · correctness · уверенность high.

**Сценарий.** В новой редакции ЕИС позиция или сторона исчезает. Financing заменяется полным
набором, но purchase items и organization parties лишь upsert-ятся. Старые строки продолжают
выглядеть действующими.

**Место:** `app/Services/Procurement/Detail/CommonInfoProcessor.php:41-58`;
`app/Services/Procurement/Detail/OrganizationPartiesProcessor.php:31-48`;
`app/Repositories/Procurement/ProcurementDetailStoreRepository.php:58-66`.

**Последствие:** current projection противоречит актуальной карточке; фильтры, UI и downstream
логика используют несуществующие позиции/роли.

**Минимальное исправление:** на подтверждённом полном snapshot reconcile-ить active set в той же
transaction (delete/soft-delete отсутствующих либо эквивалентный versioned-current признак).
Добавить shrink-тесты `N -> N-1` отдельно для items и parties. Если эти поверхности явно исключены
из первой обкатки, B1 можно вынести из launch gate только с зафиксированным ограничением продукта;
сейчас такого ограничения нет.

### 11. NEW-1 — fresh hard-cap закрывает заведомо неполный прогон как Completed

**Класс:** Major · issue · correctness · уверенность high. Существенный пропуск исходного аудита.

**Сценарий.** Все `fresh_max_blocks` блоков непусты, signatures различаются, временная/known
граница не достигнута. После цикла processor только пишет warning и переводит run в `Completed`,
хотя cursor содержит `reachedKnown=false`. Следующего обязательства продолжить нет.

**Место:** `app/Services/Procurement/Modes/FreshRunProcessor.php:117-135`;
`tests/Feature/Crawler/FreshRunProcessorTest.php:260-274`.

**Последствие:** хвост выдачи молча теряется, а операционная поверхность показывает успешный
прогон. Тест прямо требует это поведение вместо обнаружения дефекта.

**Минимальное исправление:** на hard-cap сохранять resumable checkpoint/видимый incomplete outcome
и гарантировать продолжение; `Completed` допустим только после доказанного stop condition. Тест
должен доказать дочитывание следующего блока или явный операторский долг.

### 12. NEW-2 — подготовленные representations имеют тот же unchecked-write дефект, что A6

**Класс:** Major · issue · correctness/reliability · уверенность high. Существенный пропуск
исходного аудита.

**Сценарий.** Запись OCR/PDF/text-grid/GSFX representation возвращает `false`. Strategy всё равно
создаёт/обновляет `DocumentRepresentation` и возвращает `ReadyForScreening`; job переводит документ
в `Prepared`. Screening затем читает отсутствующий объект и попадает в невосстанавливаемый A4.

**Место:** `app/Services/Procurement/Prepare/ScanStrategy.php:128-155`;
`app/Services/Procurement/Prepare/WordStrategy.php:46-69`;
`app/Services/Procurement/Prepare/SpreadsheetStrategy.php:156-182`;
`app/Services/Procurement/Prepare/GsfxStrategy.php:138-153`;
`app/Repositories/Procurement/Document/RepresentationStoreRepository.php:24-29`;
`app/Jobs/Procurement/PrepareDocumentJob.php:76-86`;
`config/filesystems.php:54-64`.

**Последствие:** phantom representation и ложный `Prepared`, расход попыток/LLM-контура без
доступного входного файла.

**Минимальное исправление:** проверять каждый write/включить fail-closed исключения для рабочего
диска; сохранять representation и `Prepared` только после подтверждённой записи, с идемпотентным
повтором. Покрыть общий failure-контракт для всех strategy.

## Ограничения и улучшения, не являющиеся самостоятельным launch blocker

### 13. B2 — решение о стабильном `uid` условно разумно, но сейчас не прослеживается и описано нечестно

**Класс:** Minor · issue/question · correctness/documentation · уверенность high по коду и docs;
поведение ЕИС — unknown.

**Сценарий.** ЕИС меняет bytes по прежнему `uid`. Повторный index обновляет title/kind/url и
сохраняет `Downloaded/Prepared/...`; downloader вызывается только для `Discovered`. Новая версия
возникнет лишь при ручном повторном вызове downloader. Тест `DocumentDownloaderTest` доказывает
hash-versioning двумя прямыми вызовами, но не доказывает, что production orchestration сделает
второй вызов.

**Место:** `app/Services/Procurement/DocumentIndexer.php:48-62`;
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php:168-177`;
`tests/Feature/Document/DocumentDownloaderTest.php:69-89`;
`docs/02_Workflow/crawling-and-discovery.md:44-50`;
`docs/03_Dev/database.md:110-117`.

**Последствие:** при mutable bytes за тем же `uid` история вложения и анализ остаются устаревшими.
Кроме того, пользовательская документация без оговорки утверждает, что замена файла создаёт новую
версию, хотя production path этого не гарантирует.

**Оценка решения.** Для ограниченной обкатки defer может быть обоснован: постоянный redownload всех
вложений дорог и нагружает госплощадку. Но приемлемое решение должно явно назвать допущение,
evidence и границу риска. Отдельного remediation/decision carrier нет. Поиск в доступных
официальных материалах Казначейства — [страница ЕИС](https://roskazna.gov.ru/gis/eis-zakupki-gov-ru)
и [публичное руководство пользователя ЕИС](https://roskazna.gov.ru/uploads/migrate/roskaznagovru/documents/gis/eis-zakupki-gov-ru/12c/rukovodstvo-polzovatelya-eis.pdf) —
не обнаружил контракта неизменности bytes для одного filestore `uid`. Отсутствие найденного
контракта не доказывает изменяемость; оно означает, что на гарантию нельзя опираться.

**Минимальное исправление:** до pilot либо (а) на живой выборке заменённых вложений подтвердить,
что замена всегда меняет `uid`, записать метод/дату/ограничения и исправить docs, либо (б) добавить
bounded revalidation (ETag/Last-Modified/hash по сроку или revision-trigger) для существующих uid.
Без одного из этих пунктов ограничение нельзя называть честно принятым.

### 14. B3 — raw evidence перезаписывается между окнами и редакциями

**Класс:** Minor · issue · operability/auditability · уверенность high.

**Сценарий.** В одном archive/delta/backlog run каждый день и ценовой поддиапазон снова начинает с
`from=1`; все эти ответы пишутся в один key. Несколько `Fetch` указывают на один и тот же уже
перезаписанный artifact. Повторное снятие вкладки карточки также заменяет прежний HTML snapshot.
`put()` raw-артефактов дополнительно не проверяется.

**Место:** `app/Services/Procurement/CsvBlockIngestor.php:100-106,211-229`;
`tests/Feature/Crawler/CsvBlockIngestorTest.php:113-134`;
`app/Services/Procurement/Eis/CsvSourceMethod.php:299-309`.

**Последствие:** нельзя достоверно восстановить, какой ответ породил конкретный fetch, и сравнить
разные редакции карточки при расследовании полноты.

**Минимальное исправление:** включить в immutable key request identity (день, price interval,
offset и/или hash/fetch id) и проверять write; для HTML хранить revision/fetched-at/hash, если
историческое доказательство входит в retention contract. Это важное улучшение наблюдаемости, но
само по себе не блокирует узкую обкатку после исправления Major/Blocker.

## Новые регрессии и пропуски исходного аудита

Регрессии, которые можно честно приписать remediation-диффу, оценить невозможно: самого диффа и
`PARSING_PRODUCTION_REMEDIATION.md` нет. В текущем baseline найдены два существенных пропуска
исходного аудита: NEW-1 (fresh hard-cap закрывает incomplete как `Completed`) и NEW-2 (unchecked
storage writes всех prepared representations). Новых style-only или speculative замечаний в
приёмку не включено.

## Выполненные проверки

1. Прочитаны `AGENTS.md`, `PARSING_PRODUCTION_AUDIT.md`, исторические
   `plans/archive/2026.08.06-CRAWL-HARDEN/{plan.md,status.md,handoff.md}` и профильные правила
   procurement pipeline, тестов, test isolation, production readiness и БД. Запрошенный
   `PARSING_PRODUCTION_REMEDIATION.md` и отдельный новый plan/handoff отсутствуют.
2. Проверены `HEAD`, `origin/main`, Git status/history и отсутствие parsing remediation diff.
   Исходные чужие dirty changes не тронуты и не использованы как доказательство исправлений.
3. `make test-preflight` — **PASS**, целевая БД `agelto_test` подтверждена.
4. Профильный широкий прогон:

   ```text
   docker compose --project-name agelto_dev exec -T \
     -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app \
     php artisan test --compact --no-tia \
     tests/Feature/Crawler tests/Unit/Crawler tests/Feature/Procurement \
     tests/Feature/Health tests/Feature/Document
   ```

   Результат: **743 passed, 13 failed, 2690 assertions, 51.41 s**. Все 13 failures относятся к
   отсутствующим августовским партициям 2026 в `prc_fetches`/`prc_events`/`prc_revisions`.
5. Узкий прогон ключевых существующих carriers A1–A7/B1/B2:

   ```text
   docker compose --project-name agelto_dev exec -T \
     -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app \
     php artisan test --compact --no-tia \
     tests/Feature/Crawler/FreshRunProcessorTest.php \
     tests/Feature/Crawler/ArchiveWindowScannerTest.php \
     tests/Feature/Crawler/DetailAnalysisProcessorTest.php \
     tests/Feature/Document/DocumentIndexerTest.php \
     tests/Feature/Document/DocumentDownloaderTest.php \
     tests/Feature/Procurement/Screening/ScreeningProcessorTest.php \
     tests/Feature/Procurement/Pipeline/PipelineRetryFailedTest.php \
     tests/Feature/Procurement/CommonInfoProcessorTest.php \
     tests/Feature/Organization/OrganizationPartiesProcessorTest.php \
     tests/Unit/Config/QueueTimeoutInvariantTest.php
   ```

   Результат: **57 passed, 217 assertions, 3.62 s**. Зелёный результат не закрывает находки:
   тесты hard-cap, archive cap и transient screening прямо закрепляют описанное дефектное
   поведение; retry test вручную создаёт `AnalysisStatus::Failed`, которого обычный transient path
   не создаёт.
6. Статически прослежены listing → ingest → detail → documents → prepare → screening → extraction,
   recovery selectors, schedule/ownership, storage writes, partition bootstrap/maintenance и
   соответствующие тесты.
7. Внешне сверены только несущие framework/source факты: официальная семантика Laravel storage
   failed writes и Horizon timeout; по официальным публичным материалам ЕИС guarantee одного uid
   не подтверждена. Живых запросов к ЕИС не выполнялось.

## Что обязательно проверить на реальном worker/источнике после исправлений

- На живой выдаче ЕИС: несколько страниц одного boundary-day без времени, включая новую строку на
  поздней странице; подтвердить сортировку и отсутствие пропуска.
- Плотный день/ценовой интервал: cap/checkpoint/resume до доказанного drain с инвариантом scope
  fresh ↔ recovery.
- Карточки с удалённым/заменённым вложением и archive children; selector drift/частичная страница
  не должны инициировать ложные удаления.
- Реальная замена attachment: меняется ли filestore `uid`; если нет — проверить bounded
  revalidation и новую `DocumentVersion`.
- Fault injection worker kill после `Prepared` до screening dispatch и после `Screened` до
  extraction dispatch; transient LLM failure должен автоматически возобновиться после backoff.
- Object storage failure/timeout на source и prepared writes; в БД не должно быть version/
  representation/status/event, утверждающих отсутствующий объект, а повтор должен долечивать.
- Реальные длительности OCR, Files API, screening и extraction под Horizon; проверить graceful
  shutdown, lease и отсутствие одновременной обработки одной job.
- Fresh install на PostgreSQL с frozen датой сентября 2026: вставка августа во все
  партиционированные таблицы, затем `partitions:maintain` и проверка непрерывности границ.
- Два scheduler/worker процесса для backlog и delta: второй не должен войти в тот же execution
  lease; после kill/expiry первый run должен безопасно возобновляться.

## Финальный вердикт

**Сначала нужны конкретные исправления.** Минимальный launch gate: закрыть A1–A9, NEW-1 и NEW-2;
закрыть B1 либо явно исключить current items/parties из поверхности первой обкатки; по B2 записать
проверяемое решение и честное ограничение. B3 допустимо отложить как observability improvement,
но не выдавать текущие raw paths за неизменяемый audit trail.

Существенные находки есть; утверждать готовность к контролируемой production-обкатке по текущему
дереву нельзя.
