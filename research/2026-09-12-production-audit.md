# Финальный аудит устойчивости parsing/crawling pipeline

Дата: 2026-09-12. База: `941ade6d743628acca72564cd31afabd1bb9bd26`.
Профиль: Code — correctness, recovery, concurrency, resource bounds, security.
Объект: состояние после `2026.09.10-№1-PARSING-PRODUCTION-REMEDIATION`.
Автор: Codex, текущая сессия аудита. Исправления не выполнялись.

## Решение

**Не считать текущую реализацию готовой к безнадзорной длительной эксплуатации.**
Найдены 10 проблем: 2 Blocker, 7 Major, 1 Minor. Для шести воспроизведено проблемное
поведение в изолированных тестах; остальные подтверждены цепочками вызовов в коде,
но их эксплуатационная частота и влияние под реальной нагрузкой не измерялись.

Предыдущий план существенно усилил систему, но его P4.3 завершён `Done with deviations`:
production-target и 72-часовой прогон не предоставлялись. Это известное ограничение,
а не новое обнаружение или повод переписать прошлое GREEN-свидетельство.

224 существующих теста выбранных подсистем прошли (859 assertions); PHPStan и
`composer audit --locked` GREEN. Это совместимо с перечисленными дефектами: проверенные
сценарии располагаются между покрытыми переходами или вне короткого happy path.

Артефакты:

- [Воспроизводящие проверки](2026-09-12-production-audit/ProductionAuditProbeTest.php).
- [Команды, результаты и ограничения проверки](2026-09-12-production-audit/verification.md).

## Сводка

| ID | Severity / label | Проблема | Подтверждение |
|:--|:--|:--|:--|
| F01 | Blocker / issue | `prepared` фиксируется до создания run; recovery пропускает такой документ | Тест состояния в окне сбоя + код producer |
| F02 | Major / issue | Первая недоставленная загрузка `discovered, attempts=0` не имеет общего recovery | Тест селектора + бюджетная ветка downloader |
| F03 | Major / issue | Неполный detail snapshot превращается в успешный и теряет due retry | Тест processor → selector |
| F04 | Blocker / issue | Недоверенная ссылка вложения позволяет произвольный HTTP target | Тест parser → downloader → fake HTTP |
| F05 | Major / issue | Нет лимита скачиваемых байтов до загрузки тела в память | Код сетевого и prepare путей |
| F06 | Major / issue | Матрица timeout не покрывает суммарную работу detail, screening, archive | Код и арифметика бюджетов |
| F07 | Major / issue | Worker timeout не учитывается в доменном attempts; повтор может быть бесконечным | Код jobs + установленного Laravel Worker |
| F08 | Major / issue | Recovery делает полный проход истории при маленьком лимите | 61 SQL на 20 документов при limit=1 |
| F09 | Major / issue | Истёкший владелец снимает lease нового владельца | Тест двух экземпляров модели и истечения lease |
| F10 | Minor / issue | `tempnam(...).extension` оставляет файлы даже после успешного finally | Прямой разбор выделения/очистки |

Severity — шкала `quality:artifact-review`. Blocker требует устранения до приёмки;
Major — исправления или явного документированного решения об отложении. Наличие теста
подтверждает указанную локальную предпосылку, а не частоту сбоя на production.

## F01 — статус Prepared и AnalysisRun не атомарны

**Места:** `app/Jobs/Procurement/PrepareDocumentJob.php:99`,
`app/Services/Procurement/PipelineRecoveryReconciler.php:45`,
`app/Repositories/Procurement/Ai/AnalysisRunStoreRepository.php:25`.

Сначала отдельным UPDATE устанавливается `Prepared`, затем при включённом screening
создаётся run. Между ними нет транзакции. SIGKILL, отказ БД на втором запросе или
исключение при создании run оставляет подготовленный документ без активного run.
Reconciler делает `findActive()` и `continue` при null; backlog выбирает только Downloaded.
Повтор PrepareDocumentJob отклоняется status guard. Также документы, подготовленные
при выключенном screening, не получают run после включения флага.

**Воспроизведение:** prepared + существующая версия + отсутствие run + screening=true:
`reconcile(10)=0`, ScreeningJob не отправлен. Это воспроизведение durable-состояния
в окне падения, без фактического убийства production worker.

**Исправление:** атомарно фиксировать готовность и намерение запуска; обеспечить recovery
уже накопленных документов без run. Сохранить mode/initiated_by для ручных запусков,
уникальность активного run и семантику выключенного screening. Нельзя создавать run
для любой исторической завершённой версии или сбрасывать существующие terminal runs.

**Приёмка:** fault injection между UPDATE/INSERT через публичную job; последующий tick
создаёт ровно один допустимый run и continuation. Отдельно проверить включение screening
после prepare и конкуренцию двух recovery workers.

**Правило:** `php:production-readiness-checklist` → Atomicity & side-effects;
документированный контракт DB→queue recovery в `docs/02_Workflow/document-and-estimate-pipeline.md`.

## F02 — первая загрузка остаётся вне retry-подбора

**Места:** `app/Console/Commands/Procurement/PipelineRetryFailed.php:74` и `:87`,
`app/Services/Procurement/DocumentDownloader.php:59`,
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php:179`.

Download retry требует `attempts > 0` и при выборке, и при claim. При исчерпании
platform request budget downloader возвращает null без recordFailure. Detail processor
продолжает работу и может поставить detail_fetched_at. Остаётся Discovered с attempts=0,
не попадающий ни в download retry, ни в prepare backlog, ни в revalidation (нужна версия).
Без новой редакции/периодического detail refetch такой документ не продвинется.
Похожее состояние возникает после прерывания первой попытки до записи error-блока.

**Воспроизведение:** `pipeline:retry-failed --limit=10` не отправляет job для
Discovered/attempts=0/next_retry_at=null. Дополнительная проверка полного сценария
budget-exhaustion → detail completion требуется при исправлении.

**Исправление:** единый ограниченный claim/dispatch для всех допустимых Discovered,
включая ещё не начатые. Учитывать removed/permanent/backoff/lease/доступность source URL;
не расходовать attempts за ожидание бюджета. Согласовать с вынесением inline download (F06a).

**Приёмка:** budget исчерпан перед первым HTTP; detail tick завершён; бюджет восстановлен;
без новой редакции документ скачивается. Проверить два конкурирующих поллера и потерю dispatch.

**Правило:** `php:production-readiness-checklist` → Queue semantics, single retry owner;
инвариант восстанавливаемого идемпотентного pipeline из `.ai/guidelines/architecture.md`.

## F03 — incomplete/failed snapshot не запускает повторное снятие

**Места:** `app/Services/Procurement/Modes/DetailAnalysisProcessor.php:129`,
`app/Services/Procurement/Eis/CsvSourceMethod.php:276`,
`app/Services/Procurement/DetailRefetchSelector.php:32`.

Snapshot completeness защищает прежние данные от удаления, но не определяет успешность
снятия. Processor записывает detail_fetched_at после Incomplete/Failed parse. Selector
не использует detail_parse_outcome; Bidding с неизменённой редакцией больше не выбирается.
В результате неполнота может сохраняться до следующего внешнего изменения.

**Воспроизведение:** страницы с HTTP 200, номером закупки, но без содержательных блоков:
processor возвращает true, ставит detail_fetched_at; Bidding отсутствует в due().
Первый вариант пробы без номера был корректно отклонён common-info guard — он работает.
У не-common-info вкладок assertCardPage только пишет warning, что дополнительно расширяет
условия возникновения проблемы.

**Исправление:** определить обязательные для каждого registry/stage snapshots и сохранять
ограниченный due retry для их неполноты; частичные достоверные данные можно сохранять.
Не превращать легитимно пустую/неприменимую вкладку в вечную ошибку.

**Приёмка:** первый ответ неполный, второй полный; без новой revision второй ответ
достижим автоматически и корректно завершает retry. Проверить легитимный empty snapshot.

**Правило:** `procurement-pipeline-dev` → различие технического отказа и terminal outcome;
контракт достоверных snapshots предыдущей фазы P2.

## F04 — SSRF через ссылку вложения

**Места:** `app/Services/Procurement/Eis/Card/DocumentsParser.php:140`,
`app/Services/Procurement/DocumentDownloader.php:126`,
`app/Services/Procurement/Proxy/ProxyResolver.php:57`.

Абсолютный http(s) href принимается без ограничения target. Наличие uid используется
для распознавания вложения, но не подтверждает доверие к host. Downloader отправляет URL
как есть; ProxyResolver выбирает маршрут и не является URL security validator.
Нет ограничения redirect targets на этом шве. SourceTlsTrust управляет TLS trust,
но не запрещает внутренние адреса и не ограничивает HTTP.

**Воспроизведение:** HTML-ссылка `http://127.0.0.1/filestore?uid=audit-ssrf` проходит
parser и downloader до fake HTTP, создаётся версия с подставленными байтами.
Реальный loopback/metadata запрос не выполнялся. Для эксплуатации нужна возможность
повлиять на принятый HTML/href либо redirect; доступность внутренней сети зависит от
direct/proxy маршрута. При прокси адрес разрешается и запрашивается с его стороны.
Это не утверждение, что произвольный анонимный посетитель уже управляет HTML ЕИС.

**Исправление:** единая проверка разрешённых source targets с учётом платформы и
легитимных file-hosts, схем, портов, userinfo, IP literals, IPv6, DNS rebinding и каждого
redirect. Для proxy route определить, кто обеспечивает запрет private targets после DNS.
Сохранить ProxyResolver и SourceTlsTrust как единственных владельцев их контрактов.

**Приёмка:** loopback/private/link-local/IPv6/userinfo/redirect-to-private отклоняются
до сетевого доступа; допустимые ЕИС/CDN ссылки скачиваются. Для DNS/redirect использовать
локальный тестовый транспорт, не реальные metadata endpoints.

**Правило:** `laravel-auth:laravel-security-audit` → Manual verify, input→sink;
недоверенность документов закреплена в `llm-layer-dev` §6.

## F05 — размер download не ограничен до materialization

**Места:** `app/Services/Procurement/DocumentDownloader.php:114` и `:173`,
`app/Services/Procurement/Prepare/DocumentFormatInspector.php:31`,
`config/ai.php:221`.

Download получает полный body как PHP string, повторно вызывает body для outcome,
затем хэширует/пишет содержимое. Нет streaming byte cap или раннего отказа по размеру.
Даже при транспортном spill-to-disk вызов body() материализует ответ. Форматный inspector
тоже делает get всего объекта до передачи стратегии, где расположен xlsx_max_mb.
Поэтому лимит XLSX и post-download archive limits не защищают сетевую/общую стадию.
Большой ответ способен вызвать PHP OOM либо заполнить транспортный temp storage;
memory=128 в Horizon — не per-response лимит и не замена ограничению байтов.

**Исправление:** bounded streaming во временный файл, счётчик фактических байтов независимо
от Content-Length, streaming hash/storage write; ограниченные чтения сигнатуры и ранний
size preflight. Лимит должен быть согласован с допустимыми форматами и публичным outcome.
Проверить отдельно лимиты распакованного DOCX/XLSX: размер ZIP не равен рабочему объёму.

**Приёмка:** ответы без Content-Length, chunked, неверный маленький header, oversized body;
контролируемый outcome без версии/partial ready object, ограниченные RSS/temp bytes.
OOM-проверку выполнять отдельным тестовым процессом, не на общем Horizon.

**Правило:** `procurement-pipeline-dev` §1 — too_large/zip_bomb;
`config/ai.php` prepare contract — отказ до полной загрузки сверх лимита.

## F06 — суммарные бюджеты не соответствуют реальной цепочке

**F06a / Detail:** `app/Services/Procurement/Modes/DetailAnalysisProcessor.php:179`.
Все Discovered вложения скачиваются inline, без batch/deadline/checkpoint. При timeout
supervisor-crawl-detail=300 карточка плюс, например, 20 загрузок по 20 s не помещаются.
Один download с HTTP retries и ожиданием platform budget также занимает значительную
часть job. Частичные результаты остаются, но журнал/итоги и detail_fetched_at могут
не достигаться; этот путь не берёт document lease DownloadDocumentJob.

**F06b / Screening:** `app/Jobs/Procurement/ScreeningJob.php:29`,
`app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:44`,
`app/Services/Llm/Providers/PrismGeminiProvider.php:397`,
`app/Services/Procurement/Screening/PdfPageSlicer.php:35`.
240 s job содержит local setup, qpdf, Files start+upload+poll, generation и возможную
полную повторную классификацию routeIndex=1. Матрица 210+30 считает один request+poll,
но не весь этот путь. Два допустимых generation по 140 s уже превышают job timeout.
Files start/upload используют отдельные HTTP-вызовы; polling может начать HTTP перед
истечением своего deadline. Screening не проверяет оставшийся общий deadline.

**F06c / Archive:** `app/Services/Procurement/Prepare/ArchiveStrategy.php:92`,
`app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php:154`, `config/ai.php:243`.
Для 7z/rar отдельный процесс извлечения запускается на каждый entry; 180 s применяется
к каждому процессу. При max_files=200 суммарное разрешённое время далеко за 2580 s
PrepareDocumentJob. Для solid archive повторные старты дополнительно повторяют работу.
Deadline из DocumentPreparer передаётся только ScanStrategy, не ArchiveStrategy.

**Исправление:** отдельные атомарные задачи F06a/b/c. Для detail — bounded dispatch
DownloadDocumentJob с durable recovery. Для screening — общий deadline и сохранённая
escalation continuation либо обоснованная полная матрица. Для archives — общий бюджет,
bounded extraction/checkpoint без повторного неограниченного прогона с начала.
Проверить также windowed extraction: upload/slice/re-ask входят в единицу прогресса,
а max_next_call_seconds должен ограничивать всю единицу, не один generation.

**Приёмка:** slow fake HTTP/process/provider на верхних допустимых задержках;
job завершает полезный checkpoint раньше своего timeout. Затем real isolated worker test.
Одного теста неравенства чисел config недостаточно; увеличение timeout само по себе не fix.

**Правило:** `php:production-readiness-checklist` → Queue semantics;
`docs/02_Workflow/document-and-estimate-pipeline.md` → Бюджеты worker-операций.

## F07 — hard timeout обходит доменный счётчик ошибок

**Места:** `app/Jobs/Procurement/PipelineJob.php:35`,
`app/Models/Concerns/HasErrorBlock.php:18`,
`vendor/laravel/framework/src/Illuminate/Queue/Worker.php:292`, `config/horizon.php:208`.

recordFailure вызывается только из catch вокруг process(). Laravel SIGALRM handler
помечает queue job failed, отправляет JobTimedOut и завершает worker; исключение не
проходит через process catch. У pipeline jobs нет failed() и в app нет обработчика
JobFailed/JobTimedOut, который бы замыкал это на доменный error block.

Следствие: attempts/next_retry_at не отражают timeout. По окончании lease/unique TTL
backlog/reconciler может поставить новую job с прежними attempts. Детерминированно
слишком долгий prepare/screening снова доходит до timeout: автоматический max_attempts
не ограничивает число таких запусков. Для SIGKILL/OOM даже queue callback недостаточен:
нужно различать stale execution и штатную checkpoint continuation.

**Подтверждение:** статическая трасса по установленному Laravel и jobs. Нового реального
SIGALRM/SIGKILL эксперимента в этом аудите не было; эта граница обязательно проверяется
при исправлении. Штатные catch-based retries данным выводом не оспариваются.

**Исправление:** один идемпотентный владелец учёта проваленного execution (включая stale
lease), связанный с его идентичностью. Не включать параллельно queue retry и domain retry;
не считать один exception дважды через catch и failed callback.

**Приёмка:** real worker timeout и SIGKILL в изолированном тестовом lane; каждое неуспешное
execution учитывается один раз, retries ограничены, terminal виден оператору. Успешный
checkpoint и штатное завершение при deploy не расходуют failure attempts.

**Правило:** `php:production-readiness-checklist` → single retry owner, Queue semantics;
комментарий D135 в `config/horizon.php` — retries принадлежат домену.

## F08 — recovery сканирует историю каждую минуту

**Места:** `app/Services/Procurement/PipelineRecoveryReconciler.php:37` и `:65`,
`routes/console.php:82`.

`lazyById(chunkSize: $limit)` ограничивает размер порции, но не общее число кандидатов.
Цикл останавливается по dispatched, поэтому завершённые Screened, закрытые runs,
отложенные analyses и документы без run перечитываются целиком. Внутри — отдельные
запросы latestVersion/findActive и иногда analysis. При выключенных AI flags эти чтения
тоже уже выполнены до featureEnabled. С ростом истории scheduler получает линейную
работу независимо от объёма действительно due-задач; синхронный callback задерживает
следующие запланированные команды. withoutOverlapping не уменьшает работу.

**Воспроизведение:** 20 Prepared с версиями без run, limit=1 → 61 SQL, 0 dispatch.
Это измеренный query count, не замер времени на миллионе строк.

**Исправление:** SQL-подбор действительно actionable records с нужными indexes и/или
durable scan cursor + ограничение scanned/time; после F01 отдельный bounded orphan path.
Нельзя просто добавить LIMIT к нынешней выборке: первые terminal rows будут голодать
полезный хвост. Не терять due records и claim concurrency.

**Приёмка:** много terminal/delayed перед одним due; due гарантированно достигается,
за tick ограничены прочитанные строки и запросы. EXPLAIN на репрезентативном объёме,
проверка SQL-плана и времени scheduler; не писать тест на точное число 61 как контракт.

**Правило:** `php:production-readiness-checklist` → Data shape / cursor design;
заявленный bounded recovery контракт в workflow.

## F09 — HasLease не различает поколения владельцев

**Места:** `app/Models/Concerns/HasLease.php:18` и `:44`,
`app/Repositories/Procurement/Ai/AnalysisStoreRepository.php:35`.

Acquire атомарен, но owner — строка класса job, а release выполняет UPDATE только по id.
Если A пережил срок lease (например, остановка процесса/расхождение часов), B может
захватить его. Возобновлённый A стирает lease B в finally; C получает доступ одновременно
с B. Записи результатов также не защищены generation token. Обычный случай, где worker
гарантированно убит до истечения lease, этим тестом не опровергается.

**Воспроизведение:** A acquire 1 s, перевод часов на 2 s, B acquire 60 s,
A release → locked_by=null вместо B. Тест двух моделей, не параллельных процессов.

**Исправление:** token на каждое execution, DB clock и условный release; fencing на
запись доменного результата. У crawler уже есть образец token-based ownership:
`app/Services/Procurement/CrawlerRunManager.php:396`. Не вводить вторую competing lock-систему.

**Приёмка:** два независимых процесса, pause/resume A после takeover B; A не снимает
lease B и не фиксирует stale результат. Проверить prepare/revalidate против analysis lease.

**Правило:** `php:production-readiness-checklist` → Race safety / atomic state transitions.

## F10 — утечка временных файлов при успешной обработке

**Места:** `app/Services/Procurement/Screening/ScreeningProcessor.php:99`,
`app/Services/Procurement/Prepare/WordStrategy.php:45`,
`app/Services/Procurement/Prepare/SpreadsheetStrategy.php:69` и `:109`,
`app/Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php:127`.

tempnam создаёт настоящий пустой файл, после чего код добавляет расширение к строке
пути и пишет другой файл. finally удаляет путь с расширением, исходный пустой файл
остаётся. Каждый checkpoint extraction повторяет выделение. В долгоживущем контейнере
это рост числа inode даже при нулевом размере остатков; SIGKILL дополнительно оставляет
непустые working directories, очищаемые только в finally. Само наличие утечки подтверждено
кодом; достижение inode/disk exhaustion на production не измерялось.

**Исправление:** scoped temp directory или проверенный rename первоначального tempnam;
очищать весь принадлежащий operation набор. Для crash leftovers — безопасный age/lease-aware
sweep; не удалять файлы активной долгой job.

**Приёмка:** серии success/failure/checkpoint сохраняют исходное число temp files;
после убийства тестового процесса sweeper удаляет только stale paths.

**Правило:** Code profile performance/reliability (`quality:artifact-review`);
production invariant ограниченного потребления временного хранилища.

## Долгая эксплуатация: отдельные непроверенные условия

Это не дополнительные подтверждённые уязвимости и не основание автоматически менять
retention, тарифы провайдеров или бизнес-политику.

- Raw CSV пишется в `raw/csv/{crawler}/{run}/...`
  (`app/Services/Procurement/CsvBlockIngestor.php:240`); репозиторный MinIO lifecycle
  покрывает только `ai/llm-calls/` (`config/partitioning.php:99`). Нужны измерение роста,
  ёмкость и решение retention raw evidence, согласованное с P4.4 immutable addressing.
  Наличие внешних bucket policies в production не проверено. Не удалять evidence вслепую.
- Измерить arrival rate и throughput отдельно для crawl_detail, doc_download, doc_prepare,
  ai_screening/extraction. Рост очереди определяется тем, превышает ли входной поток
  обслуживающую способность, а не только числом maxProcesses. Конкретные workers/RSS/CPU
  для production по локальным тестам не подобраны.
- Проверить alerts по возрасту oldest due/queued, attempts без прогресса, orphan states,
  scheduler duration, свободным bytes/inodes, Redis AOF и storage errors. Heartbeat
  процесса сам по себе не доказывает продвижение документов.
- Внешние tools (LibreOffice/OCR/7z/unar), ingress, ACL, backups/restore и фактический
  immutable production image требуют проверки в целевом окружении. Composer audit
  относится только к PHP dependency lock, не к ОС и бинарным конвертерам.

## Передача Sol

В этой сессии согласованы **только аудит и команда**, не запуск исправлений.
Следующий запуск Sol должен прочитать этот файл и verification.md, сверить HEAD,
создать successor remediation plan через установленный native Task workflow и работать
по AGENTS.md. Прежний terminal plan не переписывать под новые находки.

Порядок: F01/F02/F03 (durable lifecycle), F04/F05 (untrusted downloads),
F06a/b/c + F07 (полные budgets и failure accounting), F08/F09/F10.
При тесной зависимости допустимо переставить пункты с записью причины в successor plan.
Каждая семантическая правка отдельно, с регрессией по поведению и точными результатами.

Characterization-пробы намеренно ожидают баг. **Их GREEN не является acceptance GREEN.**
При исправлении заменить ожидания на корректный контракт и перенести в профильные suites;
исходный audit artifact сохранить как историческое свидетельство. Полезные проверки
full worker lifecycle не заменять прямым `handle()` или Queue::fake().

Definition of done: по каждому F-ID — исправление и проверка либо доказательное опровержение;
новые findings не замалчивать, Major не откладывать молча. Записать итог в successor plan/handoff.
Перед production повторить длительный controlled run в целевом окружении; локальные
проверки не отменяют прежнее ограничение P4.3. Не выполнять deploy/live ЕИС/платные LLM probes
как побочный эффект исправлений.

Команда для новой сессии, модель Sol, reasoning high (синтаксис CLI проверен локально):

```bash
codex -C /home/vostrikov/projects/agelto/agelto -m gpt-5.6-sol -c 'model_reasoning_effort="high"' 'Прочитай research/2026-09-12-production-audit.md и связанный verification.md. Выполни раздел «Передача Sol»: создай successor remediation plan через native Task workflow и последовательно исправь подтверждённые F01–F10, включая F06a/b/c. Сохрани audit evidence, добавь поведенческие регрессии, проверь real worker recovery в изолированном тестовом окружении и выполни проектные quality gates. По каждому ID запиши проверенный результат в plan/handoff. Production deploy и реальные ЕИС/LLM запросы в эту задачу не входят.'
```

Команда подготовлена, но не запускалась. Стоимость/доступность модели определяется
настройками аккаунта; этот аудит не устанавливает бесплатность запуска.
