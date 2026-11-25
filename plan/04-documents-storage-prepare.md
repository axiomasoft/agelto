# План 04: Документы, объектное хранилище (S3) и пайплайн `prepare`

**Волна внедрения:** W0 (XXE и песочница конвертеров), W1 (S3 crash-recovery, утечки резерваций и памяти), W3 (русские архивы, не-PDF стратегии, GSFX)  
**Затрагиваемые слои:** `app/Services/Procurement/{Document,Prepare}/**`, `app/Repositories/Procurement/Document/**`, `app/Actions/Procurement/Document/**`, `config/{documents,filesystems,ai}.php`.

---

## 1. Критические задачи (Безопасность и S3 Crash-Recovery)

### [CRITICAL] C1. Восстановление S3 `writing`-резерваций и repair-блобов после краша
- **Файлы:**
  - `app/Services/Procurement/Document/PrepareWorkspace.php:105-140, 202-256`
  - `app/Services/Procurement/Document/BlobStore.php:74-89, 169-200`
  - `app/Services/Procurement/Document/DocumentStorageReconciler.php:52-54, 76`
- **Проблема:**
  `recordFileWriter()` и `recordRepairWriter()` ничего не записывают, если диск не `LocalFilesystemAdapter`. При падении PHP (OOM/SIGKILL) во время заливки в S3 резервация остаётся в `status = 'writing'`. `PrepareWorkspace::cleanupOrStoppedFile()` и `BlobStore::recoverStoppedRepair()` возвращают `false` для не-локальных дисков — каждая авария на S3 **навсегда** сжигает до 256 MiB (`documents.download.max_bytes`) квоты `documents.originals` или навечно лочит repair блоба.
- **План исправления:**
  1. Привязывать S3 `writing`-резервации к lease `prc_process_attempts` (или записывать `writer_lease_until` / `attempt_id` в `prc_storage_reservations`).
  2. В `DocumentStorageReconciler` для S3-дисков считать писателя мёртвым после истечения `attempt lease + S3 HTTP timeout + grace window` (`config/filesystems.php:58`), удалять незавершённый S3-объект и переводить резервацию `writing → staged → cleaning → cleaned`.
- **Верификация:** Feature-тест с S3-диском и зависшей `writing`-резервацией от истёкшего attempt: `DocumentStorageReconciler::reconcile()` очищает объект и возвращает квоту.

---

### [CRITICAL] C2. Обход XXE и лимита размера в `XlsxResourceGuard`
- **Файлы:** `app/Services/Procurement/Prepare/XlsxResourceGuard.php:84-151`, `SpreadsheetStrategy.php:76-91`
- **Проблема:**
  1. В `entryContainsProhibitedXml()` цикл `while (! feof($stream) && $read < $maxBytes)` останавливается при достижении `$maxBytes` (128 MiB) и возвращает `false` («безопасно»), позволяя пронести `<!DOCTYPE`/`<!ENTITY` или XML-бомбу после 128 MiB padding.
  2. `assertSafeXmlEntries()` молча делает `return;` при ошибке `ZipArchive::open(..., CHECKCONS)`, после чего `SpreadsheetStrategy:90` всё равно отдаёт непроверенный/битый `.xlsx` в `LibreOfficeConverter`.
- **План исправления:**
  1. Возвращать `true` (запрет), если `$read >= $maxBytes && ! feof($stream)`.
  2. Бросать `RuntimeException` в `assertSafeXmlEntries()`, если `ZipArchive::open(..., CHECKCONS) !== true`, чтобы `SpreadsheetStrategy` маркировал файл как `UnreadableFormat` без вызова LibreOffice.
- **Верификация:** Unit-тесты: (a) XML-запись > `xlsx_max_decoded_mb` отклоняется; (b) битый ZIP `.xlsx` получает `UnreadableFormat` без вызова `LibreOfficeConverter`.

---

### [CRITICAL] C3. Перевод внешних конвертеров под `BoundedToolProcess` / `tool_supervisor.py`
- **Файлы:**
  - `app/Services/Procurement/Prepare/LibreOfficeConverter.php:38-51`
  - `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php:124-134, 215-232, 250-252`
  - `app/Services/Procurement/Prepare/OcrConverter.php:48-63, 78-93, 106-113`
  - `app/Services/Procurement/Prepare/WordStrategy.php:48-56`
- **Проблема:**
  В отличие от `PageBatchOcr`, эти классы вызывают `Process::timeout(...)->run(...)` напрямую, в обход `tool_supervisor.py` (нет Landlock, нет seccomp-блокировки сети, нет `PR_SET_CHILD_SUBREAPER` и rlimits). `WordStrategy` отдаёт недоверенные `.docx`/`.odt`/`.rtf`/`.doc` в `soffice` без `XlsxResourceGuard` (риск SSRF по внешним ссылкам/картинкам/DDE и осиротевших `soffice.bin`), а `LibreOfficeConverter` игнорирует `$deadlineAt` задачи.
- **План исправления:**
  1. Перевести запуск `soffice`, `7z`, `unar`, `lsar`, `ocrmypdf`, `pdftotext`, `pdfinfo` на `BoundedToolProcess` + `tool_supervisor.py` с пробросом `$deadlineAt`.
  2. Вызывать `XlsxResourceGuard::assertSafeXmlEntries()` для всех OOXML/ODF контейнеров (`.docx`, `.odt`) до запуска LibreOffice.
  3. В `tool_supervisor.py:168-183` (Low #15) добавить явную проверку архитектуры (`platform.machine()`) с поддержкой `aarch64` или понятной ошибкой `unsupported_architecture`.
- **Верификация:** Тест безопасности: `.docx`/`.odt` с внешней ссылкой `http://127.0.0.1` не может установить исходящее соединение; тест таймаута подтверждает отсутствие выживших дочерних `soffice.bin`/`7z`/`tesseract`.

---

## 2. Высокоприоритетные задачи

### [HIGH] H4. `ExpandArchiveEntryAction`: память, резервации и верификация
- **Файлы:** `app/Actions/Procurement/Document/ExpandArchiveEntryAction.php:23-72`
- **Проблема:** `file_get_contents($localPath)` грузит до 100 MiB в RAM; запись в хранилище обходит `StorageReservation`; проверка контрольной суммы при новом файле сравнивает локальный `$content` с самим собой (zero read-back verification!), а при существующем — вычитывает весь объект через `$disk->get()` в RAM.
- **Исправление:** Перевести на `BlobStore::put()` (стриминг + резервация + потоковая read-back проверка) и читать только первые 8 KiB для `MimeDetector::of()`.

### [HIGH] H5. `ArchiveExtractor::extractProcessEntry()`: буферизация stdout и CLI-флаги
- **Файлы:** `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php:100-119, 194-256`
- **Проблема:** `Process::run($cmd, $callback)` (Symfony Process) всё равно копит весь stdout (до 100 MiB) в `Process::$stdout`; в `extractRarEntry`/`listRar` нет `--` перед путями; `lsar` захардкожен; `extractZipEntry` переоткрывает ZIP с `CHECKCONS` на каждый файл ($O(N^2)$).
- **Исправление:** Стримить stdout напрямую из pipe в файл через `BoundedToolProcess`/`proc_open` без буферизации в памяти PHP; передавать `--`; вынести `lsar` в конфиг; держать `ZipArchive` открытым на весь проход.

### [HIGH] H6. Поддержка CP866 и Windows-путей (`\`) в архивах ЕИС
- **Файлы:** `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php:65-92`, `ArchiveEntryPath.php:11-16`, `ArchiveStrategy.php:75-78`
- **Проблема:**
  - `listZip()` читает имена без `ZipArchive::FL_ENC_RAW`, превращая CP866-кириллицу из Windows-архивов `zakupki.gov.ru` в кракозябры / невалидный UTF-8 (ломает PostgreSQL).
  - `ArchiveEntryPath::normalize()` отклоняет любой путь с `\` как `ZipBomb`.
- **Исправление:** Читать `FL_ENC_RAW` и конвертировать не-UTF-8 имена из `CP866` (fallback `Windows-1251`) в UTF-8, сохраняя индекс для `getStreamIndex()`; нормализовать `\` → `/` до проверки сегментов (сохраняя запрет на `^[A-Za-z]:`, `..`, ведущий `/`, NUL).

### [HIGH] H7. Единая конфигурация диска и интеграция не-PDF в `PrepareHandler`
- **Файлы:** `config/documents.php:15, 42, 50`, `PrepareHandler.php:72-74`, `FileLocationResolver.php:54-57`
- **Проблема:** `documents.disk` указывает на `'s3'`, тогда как `download.disk` и `prepare.disk` захардкожены на `'local'` без `env()`. Кроме того, `PrepareHandler` блокирует любой не-PDF через `BlockedReason::PolicyReview`, а `DocumentPreparer` (Excel/Word/Archive/GSFX) подключён только в `GoldImportCommand`.
- **Исправление:** Унифицировать диск через `DOCUMENTS_DISK` env; оформить и протестировать поэтапное подключение безопасных (после C2/C3/H4–H6) не-PDF стратегий `DocumentPreparer` в Process Core `PrepareHandler`.

### [HIGH] H8. Утечка 1 GiB в `PrepareWorkspace::open()` и неперехваченный `JsonException`
- **Файлы:** `app/Services/Procurement/Document/PrepareWorkspace.php:23-40, 71-93`, `PrepareHandler.php:96-98, 214-222`
- **Проблема:** `beginWrite()` вызывается до `makeDirectory()` / записи `owner.json`. При сбое `PrepareHandler::finally` пропускает `finish()`, а `cleanup()` при отсутствии `owner.json` навсегда возвращает `false` (утечка 1 GiB). Кроме того, `json_decode(..., JSON_THROW_ON_ERROR)` в `cleanup()` (строки 74, 89) не обёрнут в `try/catch (\JsonException)`.
- **Исправление:** В `open()` при ошибке создания директории/файла немедленно вызывать `finishStoppedWrite()` + `gc->cleanup()`; в `cleanup()` ловить `\JsonException`.

### [HIGH] H9. Буферизация в память и отсутствие валидации PDF в `WordStrategy` / `SpreadsheetStrategy` / `TextGridRenderer`
- **Файлы:** `WordStrategy.php:48,58-70`, `SpreadsheetStrategy.php:74,138,168`, `TextGridRenderer.php:79-85,176`, `ArchiveWorkspaceHelper.php:54-70`
- **Проблема:** `WordStrategy` и `SpreadsheetStrategy` грузят файлы через `$sourceDisk->get()` в память; `WordStrategy` не проверяет `%PDF` и непустоту выхода LibreOffice; `TextGridRenderer` запускает `PhpSpreadsheet` и `getCalculatedValue()` in-process (без subprocess `memory_limit` и без защиты `.xls`); `ArchiveWorkspaceHelper::copyToLocal()` имеет fallback на безлимитный `$disk->get()`.
- **Исправление:** Перевести чтение на потоковый `copyToLocal()` (без fallback на `$disk->get()`), проверять `%PDF` в `WordStrategy`, изолировать `TextGridRenderer` в ограниченный subprocess и добавить лимиты для `.xls`.

---

## 3. Средний и низкий приоритет

- **[MEDIUM] M10 (`RepresentationWriter::writeStream()` `58-99`):** при краше до `finally` остаются осиротевшие `.staging.<uuid>` объекты в S3 без учёта квоты. Перевести на `BlobStore::put()` или добавить S3 lifecycle / sweep в `DocumentStorageReconciler`.
- **[MEDIUM] M11 (`ArchiveEntryValidator` + solid 7z `53-73`):** когда `Packed Size` пустой (solid 7z), `$knownCompressedBytes === 0` и проверка коэффициента сжатия пропускается; `Hard Link` в `parseSevenZipEntryBlock()` не отклоняется. Передавать физический `filesize($localArchive)` как знаменатель и отклонять `Hard Link`.
- **[MEDIUM] M12 (`GsfxStrategy` & `GsfxReader`):** `GsfxStrategy::renderText()` (275–276) печатает цену за единицу дважды вместо `unit × qty = total`; `GsfxReader::decode()` (65–72) безусловно конвертирует из `Windows-1251`, ломая UTF-8 `.gsfx`; `GsfxXmlParser::parsePosition()` (196–197) перезаписывает несколько блоков `<Resources>`/`<Koefficients>`. Исправить и покрыть unit-тестами.
- **[MEDIUM] M13 (`DownloadHandler.php:134-142, 164-176`):** проверять возврат `rewind($stream)`; изолировать `removeBinary()` и `gc->cleanup()` в `finally` независимыми `try/catch`.
- **[MEDIUM] M14 (`StoredDocumentWriter::digestObject()` `209-223` & `ArtifactRepository::beginRepair()`):** добавить потолок байт (`documents.download.max_bytes`) при стриминге хэша; явно обработать reservation-less импортированные блобы в `beginRepair()`.
- **[LOW] L16 (`DocumentStorageReconciler` & `PrepareHandler:166`):** вынести константы батчей (`50`/`25`) вместо неявной связки с дефолтами репозиториев; защитить деление `max(0.001, $batch->seconds)` в `PrepareHandler`.
