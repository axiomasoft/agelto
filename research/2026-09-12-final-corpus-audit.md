# Финальный аудит: корпус закупок 2025 и работа 24/7

Дата: 2026-09-12. HEAD: `941ade6d743628acca72564cd31afabd1bb9bd26`.
Объект проверки — **текущее рабочее дерево с уже существующими незакоммиченными
исправлениями** предыдущего production audit. Эти изменения сохранены без правок.
Профиль: code (correctness, recovery, data integrity, resource bounds), отдельно doc.

## Решение

**NO-GO для полного автономного сбора и обработки 2025 года.** Найдено 15 групп
продуктовых проблем: 1 Blocker и 14 Major. Дополнительно — Minor в runbook и два
падающих существующих теста. Это объём отдельной ремедиации, а не нескольких быстрых
исправлений. По условию владельца подготовлено [задание для Sol](2026-09-12-final-corpus-audit/sol-handoff.md).
Продуктовый код в этом аудите не исправлялся.

Пять новых сценариев воспроизведены изолированными проверками PostgreSQL/fake storage/HTTP:
**5 tests / 21 assertions**. Ещё один — настоящим небольшим solid 7z и кодом parser/validator.
Остальные подтверждены цепочками вызовов; реальные OOM, длительные таймауты и их частота
на production не воспроизводились. Наличие дефекта не означает, что любая закупка его вызывает.

Расширенный существующий профиль: **705 passed, 2 failed / 3012 assertions**.
PHPStan и `git diff --check` прошли. Полный проектный test suite, live ЕИС, платный LLM,
production DB и 72-часовой прогон в этой сессии не выполнялись.

## Что дали предыдущие аудиты и где остаётся разрыв

Изучены финальный CRAWL-HARDEN audit, RELEASE-READINESS P10 production-readiness,
состояние/передача PARSING-PRODUCTION-REMEDIATION и последний
`research/2026-09-12-production-audit.md` с `remediation-handoff.md`.

- CRAWL-HARDEN финальный manual audit в значительной части проверял доставку и
  непротиворечивость plan carriers. Его verdict не равен доказательству полноты годового корпуса.
- RELEASE-READINESS проверял конкретные writers, транзакции и queue invariants. Row locks
  screening обеспечивают атомарность, но сами по себе не доказывают принадлежность результата
  текущему исполнителю — C06 ниже.
- PARSING-PRODUCTION-REMEDIATION усилил cursor/recovery, snapshot completeness, storage
  finalization и локальные worker tests. P4.3 закрыт с известным отклонением: production/72h
  не предоставлялись. Этот аудит не переписывает историческое свидетельство завершения.
- Последние F01–F10 действительно внесли полезные исправления: подготовка/run, подбор первой
  загрузки, HTTPS target validation, bounded download stream, отдельные download jobs,
  screening continuation, archive deadline и уникальные lease tokens.
- Но формулировка «Fixed» не покрывает соседние ветки: F03 — supplier-results; F05 — GSFX/XLSX
  и повреждённый existing object; F06 — detail, windowed extraction и реальный timeout provider;
  F07/F09 — terminal lease exhaustion и screening Action; F10 — временные каталоги.
- Старые тесты часто проверяют короткий прямой `handle()` или сравнивают числа config.
  Это не проверяет убийство worker между двумя коммитами и суммарную продолжительность
  реальной составной операции. В detail fixture даты журнала 2025 явно заменяются текущими
  (`FetchProcurementDetailJobTest.php:65`), поэтому зелёный сценарий не доказывает backfill 2025.

## Находки

Все C01–C15: profile **code**, label **issue**, confidence **high** в наличии указанного
механизма. Для статических resource/timeout сценариев влияние под нагрузкой не измерено.
Правила: `php:production-readiness-checklist` (Atomicity, Race safety, Queue semantics),
проектный `procurement-pipeline-dev` §1 (transient ≠ terminal), классические correctness/perf/security.

### C01 — Blocker: события 2025 не помещаются в партиции и попадают под DROP retention

**Места:** `database/migrations/2026_08_05_100074_precreate_historical_partitions.php:68`,
`database/migrations/2026_09_10_100093_repair_partition_coverage.php:72`,
`app/Services/Procurement/Detail/EventJournalProcessor.php:62`,
`app/Services/Procurement/Detail/SupplierResultsProcessor.php:86`, `config/partitioning.php:62`.

Историческая миграция начинает окно с августа 2025; новая repair — с now−12 месяцев.
Январь–июль 2025 не покрыты. Processor записывает дату события/протокола источника.
Воспроизведён реальный `no partition of relation "prc_events" found for row` при записи
события 15 января 2025. Дополнительно retention событий — шесть месяцев с DROP: штатный
maintenance удаляет даже созданные партиции 2025. Просто добавить недостающие месяцы недостаточно.

**Приёмка:** события всех 12 месяцев 2025 сохраняются, переживают maintenance и повторный
detail fetch; определена политика хранения исторических доменных событий отдельно от
эфемерных логов. Не подменять дату события текущей ради прохождения INSERT.
**Правило:** correctness/data integrity.

### C02 — Major: годовой archive обход не имеет автоматического продолжения после timeout

**Места:** `app/Services/Procurement/ArchiveWindowScanner.php:25`,
`app/Services/Procurement/Modes/ArchiveRunProcessor.php:43`, `config/horizon.php:358`,
`app/Services/Procurement/CrawlerRunManager.php:492`, `routes/console.php:27`.

Archive обрабатывает дни в одном вызове без секундного checkpoint. Long lane допускает
3600 секунд по умолчанию (CRAWL_LONG_TIMEOUT), затем worker прекращается. `tries=1`;
повторная доставка после SIGKILL не обеспечивает выполнение processor: Worker отклоняет
повтор до handle из-за исчерпанных попыток. Stale sweep переводит Running в Paused; расписание
автоматически запускает Fresh, но не этот архивный run. Курсор сохранён, однако архив
остаётся на паузе до ручного resume. Конечный long lane — известное прежнее ограничение,
которое не удовлетворяет новому требованию автономного годового backfill.

**Приёмка:** ограниченные порции архивного обхода, durable continuation после потери dispatch
и SIGKILL; один владелец, сохранённые from/to/filter; явная operator pause не возобновляется.
**Правило:** Queue semantics / durable recovery.

### C03 — Major: OperatorDebt порождает бесконечную очередь без прогресса

**Места:** `app/Services/Procurement/ArchiveWindowScanner.php:101`,
`app/Services/Procurement/Modes/ArchiveRunProcessor.php:57`, `app/Jobs/Procurement/CrawlRunJob.php:92`.

При `max_blocks_per_window` scanner сохраняет прежний cursor, возвращает OperatorDebt,
оставляя run Running. Processor возвращает его; job немедленно self-dispatch. Следующий
запуск снова обнаруживает тот же лимит, обновляет heartbeat и ставит продолжение. При этой
ветке нет даже HTTP/продвижения, только бесконечная нагрузка Redis/БД. При минимальном
ценовом интервале возможны повторные бесполезные HTTP-запросы.

**Приёмка:** реальная композиция scanner → processor → job переводит debt в явный
наблюдаемый остановленный исход без self-dispatch; изменённый лимит позволяет resume без потерь.
**Правило:** correctness/perf, bounded retry.

### C04 — Major: detail timeout и request retries обходят доменное ограничение попыток

**Места:** `app/Services/Procurement/Eis/CsvSourceMethod.php:185`, `:236`, `:333`,
`app/Jobs/Procurement/FetchProcurementDetailJob.php:49`, `config/horizon.php:341`.

До четырёх последовательных вкладок, каждая с пятью попытками HTTP по 30 секунд плюс
ожидание platform budget до 60 секунд. Допустимая медленная цепочка превышает 600 секунд
при supervisor timeout 300. SIGKILL/worker timeout обходит catch processor, а detail job
не имеет durable execution accounting: `detail_attempts` не растёт, due sweep повторяет
карточку. Отдельно HTTP retry получает пять попыток за один `PlatformRequestBudget::acquire`,
поэтому квота считает логический запрос, а не фактические сетевые попытки.

**Приёмка:** единый deadline либо сохраняемые отдельные вкладки; реальный worker timeout
увеличивает попытку ровно один раз, backoff и exhaustion конечны; quota учитывает каждый
сетевой retry. Проверки с локальным транспортом и изолированным worker.
**Правило:** Queue semantics / perf.

### C05 — Major: временный отказ supplier-results теряет итоги Completed закупки

**Места:** `app/Services/Procurement/Eis/CsvSourceMethod.php:276`,
`app/Services/Procurement/Eis/Card/SupplierResultsParser.php:51`,
`app/Services/Procurement/Modes/DetailAnalysisProcessor.php:160`,
`app/Services/Procurement/DetailRefetchSelector.php:26`.

Для не-common-info вкладки ошибочная HTML-страница может пройти дальше warning.
Supplier parser возвращает пустые protocol/bids/award; completeness guard проверяет
common-info/documents, после чего ставит detail_fetched_at. Completed не входит в
периодический refetch Evaluation/Awarded. При неизменяемой редакции закупки 2025
временный 503/captcha превращается в постоянный пропуск итогов.

**Приёмка:** первый supplier response технически неуспешный, второй полный — вторая попытка
достижима автоматически без revision. Не считать легитимное absent/not-applicable ошибкой.
**Правило:** correctness/completeness, pipeline §1.

### C06 — Major: устаревший screening worker может записать результат поверх нового lease

**Места:** `app/Actions/Procurement/Screening/ApplyScreeningDispositionAction.php:71`, `:89`,
`app/Repositories/Procurement/Ai/AnalysisStoreRepository.php:144`,
`app/Repositories/Procurement/Document/DocumentStoreRepository.php:54`.

Action перечитывает Analysis через lockForUpdate и теряет приватный leaseToken исходного
объекта. Repository проверяет owner только при hasLeaseToken; новый объект проходит мимо
проверки. Проба: исходный owner уже не владеет строкой, в БД replacement-token, но Action
успешно пишет Succeeded/Ignored. Row lock сериализует запись, не доказывая её владельца.
В других repository paths owner SELECT и UPDATE также не являются единым условным write.

**Приёмка:** stale owner не меняет analysis/document/event/run и не диспатчит extraction;
проверка токена атомарна с мутацией. Проверить shadow и two-process expiry/replacement.
**Правило:** Race safety / atomic state transitions.

### C07 — Major: extraction финализируется до отдельной записи статуса документа

**Места:** `app/Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php:787`, `:799`,
`app/Jobs/Procurement/Analyzers/Estimate/EstimateExtractionJob.php` (`statusGuardPassed`),
`app/Services/Procurement/PipelineRecoveryReconciler.php`.

После persist estimates отдельно фиксируются Analysis Succeeded/terminal run, затем
Document Extracted. Падение между ними оставляет документ Screened при завершённом analysis.
Job guard/recovery такой успешный анализ не продолжают. Порядок «сначала данные, затем
успех» полезен, но без общей транзакции или repair transition окно сбоя остаётся.

**Приёмка:** fault injection после успешной записи analysis и перед документом; retry/tick
доводит состояние до согласованного завершения без двойных смет. Учесть уже stranded rows.
**Правило:** Atomicity & side-effects.

### C08 — Major: истечение последней lease оставляет навсегда активный run

**Место:** `app/Models/Concerns/HasLease.php:108`.

На пятом истечении lease concern пишет attempts=5/failed_permanently=true, но Analysis
остаётся Running, AnalysisRun.finished_at=null. acquireLease возвращает false до job
recordFailure, где расположена обычная терминализация. Проба воспроизводит именно это
состояние. Recovery исключает permanent failure, а активный run мешает revalidation/новому run.

**Приёмка:** exhaustion после hard timeout атомарно завершает analysis/run с расходом и
диагностикой; операторский rerun разрешён, прежний owner не оживает. Проверить screening/extraction.
**Правило:** atomic terminal transitions / bounded retry lifecycle.

### C09 — Major: нехватка времени превращает исправный PDF в Unprocessable

**Места:** `app/Services/Procurement/Screening/ScreeningProcessor.php:109`, `:121`, `:261`.

assertBudget находится в том же catch RuntimeException, что ошибки PDF. Deadline
классифицируется как unreadable_format; документ терминально отбрасывается, Analysis
становится Skipped. Проба с настоящим валидным PDF и коротким remaining deadline
подтвердила переход без HTTP. В нормальной job его достаточно вызвать задержкой setup/storage;
аналогично трактуется deadline slicer.

**Приёмка:** deadline/timeout/временный I/O дают retry или continuation, валидный PDF не
получает терминальный format outcome; password/corrupt PDF сохраняют прежние честные исходы.
**Правило:** pipeline §1, transient failure ≠ unprocessable.

### C10 — Major: screening/extraction матрицы не ограничивают реальные составные операции

**Места:** `app/Services/Procurement/Analyzers/Estimate/WindowedChunkSource.php:222`,
`app/Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php:370`,
`config/ai.php:425`, `:434`, `app/Services/Llm/Providers/PrismGeminiProvider.php:244`.

Windowed unit содержит slice → upload → generation. Матрица предполагает максимум следующей
операции `max_next_call_seconds=150`, а runtime проверяет только checkpoint перед всей единицей.
Начавшись на 119-й секунде, она может превысить
job timeout 300 до checkpoint. Screening matrix считает generation=90 секунд, тогда как
Gemini transport использует request_timeout_seconds, по умолчанию 150 секунд в config/ai.php;
значение допускает env override, production effective config не проверялся. Per-call override не передан.
Равенство чисел config в тесте не доказывает соответствия transport.

**Приёмка:** общий deadline проходит через каждый реальный сетевой/процессный вызов;
длительность start/upload/poll/generate ограничена остатком; сохранение прогресса укладывается
в reserve. Проверять slow fake transport и worker, без платного LLM.
**Правило:** Queue semantics / correctness.

### C11 — Major: обычный solid 7z ошибочно считается zip-бомбой

**Места:** `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php:148`,
`app/Services/Procurement/Prepare/Archive/ArchiveEntryValidator.php:47`.

В solid 7z Packed Size присутствует у первого файла блока, у следующего пуст. Parser
превращает отсутствие в 0, validator запрещает положительный Size/нулевой compressedSize.
Настоящий архив composer.json + package.json: 1878 bytes, Solid=+, второй файл Size=903,
Packed Size пуст; настоящий parser/validator бросает ArchiveValidationException.
ArchiveStrategy переводит это в терминальный zip_bomb. Успешный single-file тест этого не ловит.

**Приёмка:** solid multi-file и non-solid ZIP/7z корректно обрабатываются; compression ratio
учитывает блок/архив без выдуманного размера отдельной записи, actual byte limits сохранены.
**Правило:** correctness.

### C12 — Major: GSFX разжимает и парсит Data.xml до archive validation

**Места:** `app/Services/Procurement/Prepare/GsfxStrategy.php:77`, `:85`, `:88`, `:99`.

getFromName(Data.xml/Properties.txt) и полный XML parser выполняются раньше
extractAttachments, который впервые проверяет общий archive listing. Небольшой ZIP может
развернуться в большой XML до max_entry/max_ratio gate и вызвать OOM. Это статически
подтверждённый неограниченный путь; OOM на общем worker намеренно не запускался.

**Приёмка:** limits всего контейнера проверяются до чтения; bounded actual reads и
ограниченный parser. Большой decoded XML тестируется в отдельном процессе с memory cap.
**Правило:** security/perf, pipeline §1 — zip_bomb.

### C13 — Major: XLSX metadata/text-grid загружают распакованные данные без раннего memory bound

**Места:** `app/Services/Procurement/Prepare/TextGridRenderer.php:75`, `:78`,
`app/Services/Procurement/Prepare/SpreadsheetStrategy.php` (`countRows`),
`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Reader/Xlsx.php:270`, `:415`.

TextGridRenderer вызывает полный load до max_cells. «Дешёвый» metadata preflight
listWorksheetInfo в установленной зависимости тоже получает весь worksheet XML через
getFromZipArchive/getFromName. Малый compressed size и число строк не ограничивают ширину,
количество ячеек и decoded XML. Изоляция PDF subprocess не защищает эти проходы в Horizon.

**Приёмка:** до полной загрузки ограничены decoded bytes/cells; metadata/grid работают
с предсказуемым memory budget или в изолированном процессе. Wide/compressed XLSX возвращает
контролируемый outcome без OOM общего worker. Vendor вручную не править.
**Правило:** perf/resource bounds.

### C14 — Major: правильный повтор download не восстанавливает существующий повреждённый object

**Места:** `app/Services/Procurement/DocumentDownloader.php:283`, `:320`.

Если hash path существует, запись пропускается. Проверка хеша обнаруживает partial/corrupt
object, бросает исключение и оставляет его. Проба: correct HTTP bytes, заранее записанный
`partial` по ожидаемому hash path → version отсутствует, download failed, object всё ещё partial.
Повтор снова пойдёт по той же ветке. Это возможно, например, после прерывания local writeStream.

**Приёмка:** атомарная публикация/безопасное восстановление повреждённого объекта;
same-hash retry без дубля версии/события. Конкурирующий проигравший writer не удаляет
объект победителя. Проверить false write, exception, interruption и mismatch.
**Правило:** correctness/idempotent recovery.

### C15 — Major: cleanup не охватывает временные каталоги после SIGKILL/OOM

**Места:** `app/Support/Files/PipelineTemporaryFile.php:72`,
`app/Services/Procurement/Prepare/ArchiveStrategy.php:46`, `:94`,
`app/Services/Procurement/Prepare/GsfxStrategy.php:65`,
`app/Services/Procurement/Prepare/LibreOfficeConverter.php:26`,
`app/Services/Procurement/Prepare/WordStrategy.php:46`,
`app/Services/Procurement/Prepare/SpreadsheetStrategy.php:143`.

Sweeper обходит только файлы собственного agelto-pipeline каталога. Archive/GSFX временные
входы, storage/app/tmp_extract, soffice_profile и word_pdf/xlsx_pdf output directories
удаляются лишь finally. После убийства worker они остаются; один archive execution допускает
сотни MB. Регулярные неудачи на годовом корпусе накапливают bytes/inodes без cleanup этих путей.

**Приёмка:** общий управляемый temporary workspace для файлов и каталогов; возрастная
очистка после максимальной lease, active work не удаляется; kill→sweep test с изолированными путями.
**Правило:** reliability/resource lifecycle.

### D01 — Minor / issue / high / doc: archive runbook расходится с запуском деталей

`docs/03_Dev/runbooks/archive-run.md:56` говорит о ручной detail стадии без scheduler и
`<registry_number_or_id>`, а `routes/console.php` планирует её каждые пять минут;
`FetchDetails` принимает numeric DB id. Оператор может ошибочно ожидать отсутствия
автоматических details либо передать реестровый номер как id.
**Приёмка:** сверить текущую signature, расписание и асинхронный download в примерах.
**Правило:** doc profile — staleness against code.

## Проверки и ограничения

Перед каждым прогоном probes и расширенным suite выполнен `make test-preflight`:
exit 0, `Test database ready: agelto_test`. PostgreSQL tests запускались последовательно.
Проба January сначала имела TypeError из-за CarbonImmutable вместо требуемого Illuminate Carbon;
исправлен только diagnostic carrier, затем все пять сценариев воспроизвелись.

Расширенный профиль, exit 1, 705 passed / 2 failed, 3012 assertions, 48.31 s:

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/Crawler tests/Feature/Document tests/Feature/Procurement/Pipeline tests/Feature/Procurement/Prepare tests/Feature/Procurement/Screening tests/Feature/Procurement/Analyzers/Estimate tests/Unit/Crawler tests/Unit/Procurement tests/Unit/Config/QueueTimeoutInvariantTest.php tests/Unit/Support
```

- `BudgetExhaustionTest.php:210`: expected attempts=2, actual=3. Fixture `https://eis.test`
  отклоняется новой target policy до budget gate. Для проверки именно quota сценария нужна
  допустимая платформа/URL, с сохранением отдельных тестов запрета произвольного target.
- `FetchProcurementDetailJobTest.php:106`: ожидает inline Downloaded и PrepareDocumentJob,
  хотя новый processor сохраняет Discovered и ставит DownloadDocumentJob. Следует проверять
  новую асинхронную цепочку, а не удалять assertion без замены проверки результата.

Диагностические probes: первоначальный PHPUnit exit 0, 5 tests / 21 assertions, 0.804 s;
финальный Pest replay после переноса — exit 0, 5 passed / 21 assertions, 0.86 s:

```bash
make test-preflight
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app vendor/bin/pest research/FinalCorpusAuditProbeTest.php --no-tia --compact --colors=never
```

Carrier `research/FinalCorpusAuditProbeTest.php` расположен вне обычного test suite.
После первоначального PHPUnit прогона перенесён в Pest с русскими intent; датированная
папка отклонялась Pest как невалидный PHP namespace, поэтому replay-файл лежит в research/.
Его GREEN означает **наличие дефектов**;
он не должен стать production acceptance test с прежними ожиданиями. При исправлении
перенести поведенческие регрессии в обычные Pest suites с правильными ожиданиями.

Прочие проверки:

```bash
docker compose -p agelto_dev exec -T app vendor/bin/phpstan analyse --no-progress --error-format=table --memory-limit=1G
git diff --check
docker compose -p agelto_dev exec -T app vendor/bin/pint --test research/FinalCorpusAuditProbeTest.php
php -l research/FinalCorpusAuditProbeTest.php
```

Все exit 0. Solid 7z создан локально из двух небольших файлов, inspected через 7z и
проверен настоящим parser/validator. Production параметры, throughput/RSS, EIS/CDN allowlist
и точность извлечения на репрезентативной живой выборке 2025 остаются непроверенными.
Даже устранение C01–C15 не является заменой контролируемого длительного прогона.
