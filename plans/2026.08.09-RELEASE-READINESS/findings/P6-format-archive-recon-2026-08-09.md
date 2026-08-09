# P6 format/archive recon — 2026-08-09

## Scope and method

Проверены текущие download→prepare call sites, стратегии форматов, archive adapters, схема
`Document`/`DocumentVersion`, storage resolver, очередь дочерней обработки и существующие тесты.
Внешние security/runtime утверждения проверены только по первичным OWASP, PHP, IANA и Laravel
источникам. Репозиторные выводы относятся к текущему dirty authoring tree и не подменяют
будущие terminal P0.5/P0.6 products.

## Repo evidence

- `DocumentDownloader` сохраняет extension из имени без lowercase normalization, а MIME получает
  через `finfo_buffer`; `DocumentVersion` уже хранит extension, MIME, content hash, disk и nullable
  `parent_version_id`. RAG:— (repo-grounded: `app/Services/Procurement/DocumentDownloader.php`,
  `app/Models/Procurement/Document/DocumentVersion.php`).
- `DocumentPreparer` перебирает ordered config classes, а каждая стратегия сама сравнивает raw
  extension. `PassthroughStrategy::canProcess()` всегда возвращает true, поэтому неизвестный или
  подменённый формат сегодня становится `prepared`, а не получает terminal unsupported/mismatch
  outcome. RAG:— (repo-grounded: `app/Services/Procurement/Prepare/DocumentPreparer.php`,
  `app/Services/Procurement/Prepare/PassthroughStrategy.php`, `config/ai.php`).
- Форматные allowlists продублированы в Archive/Gsfx/Spreadsheet/Word/Scan/Image strategies; единого
  typed registry extension→MIME→signature→handler нет. GSFX, XLSX/DOCX и ZIP являются container-
  родственниками, поэтому один общий `PK`-prefix недостаточен для выбора handler. RAG:—
  (repo-grounded: `app/Services/Procurement/Prepare/*Strategy.php`).
- `DocumentStatus::Unprocessable` существует, но причина кодируется только строковым префиксом
  `last_error`; отдельного castable/indexable machine-readable carrier нет. RAG:— (repo-grounded:
  `app/Enums/Procurement/Document/UnprocessableReason.php`,
  `database/migrations/2026_07_04_100018_create_prc_documents_table.php`).
- `ArchiveStrategy` уже поддерживает ZIP/7z/RAR, проверяет depth/count/declared total bytes и
  отбрасывает `/`, NUL и literal `..` slash-segments. Но он извлекает весь allowlisted набор в temp,
  не отвергает backslash/drive paths, symlink/special entries, duplicates и declared-vs-actual
  divergence; у ZIP listing не переносит `comp_size`/external type. RAG:— (repo-grounded:
  `app/Services/Procurement/Prepare/ArchiveStrategy.php`,
  `app/Services/Procurement/Prepare/Archive/ArchiveExtractor.php`).
- Архив читается с `currentDocumentsDisk()`, а не через `forDocumentVersion()`, что обходит
  записанный disk/fallback contract. Child identity кодируется как `<parent version id>:<path>`;
  отдельной unique `(parent_version_id, archive_entry_path)` гарантии нет. RAG:— (repo-grounded:
  `app/Services/Procurement/Prepare/ArchiveStrategy.php`,
  `app/Services/Storage/FileLocationResolver.php`, current schema).
- Child `Document`+`DocumentVersion` и procurement event создаются транзакционно, но child
  `PrepareDocumentJob` не dispatch-ится. После обработки parent job может отправить сам архив в
  `ScreeningJob`, хотя archive strategy не создала представление. RAG:— (repo-grounded:
  `app/Services/Procurement/Prepare/ArchiveStrategy.php`,
  `app/Jobs/Procurement/PrepareDocumentJob.php`).
- Fixture probe (`file --mime-type` и PHP `finfo`) подтверждает PDF/PNG/XLSX/ZIP на имеющемся
  corpus, но corpus не покрывает Word/legacy Office/7z/RAR/GSFX aliases. RAG:— (repo-grounded:
  `tests/Fixtures/Documents/`; команды выполнены 2026-08-09).

## Official security and runtime evidence

- OWASP требует allowlist business-required extensions, запрещает доверять заявленному
  Content-Type и рекомендует сочетать extension, content type и signature; ни одна техника не
  является достаточной отдельно. Source:
  https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html — checked
  2026-08-09, confidence high. RAG:✅ 2026-08-09 official OWASP File Upload Cheat Sheet.
- Перед распаковкой OWASP требует проверять target path, compression level и estimated expanded
  size; лимит должен учитывать размер после decompression. Тестовый гайд отдельно называет archive
  symlink attacks, которые не требуют `../`. Sources:
  https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html,
  https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/09-Test_Upload_of_Malicious_Files — checked
  2026-08-09, confidence high. RAG:✅ 2026-08-09 official OWASP validation/testing guides.
- PHP Fileinfo угадывает type по magic byte sequences и прямо описан как не bullet-proof. Поэтому
  `finfo` — evidence component, не единственный router. Source:
  https://www.php.net/manual/en/book.fileinfo.php — checked 2026-08-09, confidence high.
  RAG:✅ 2026-08-09 official PHP Fileinfo manual.
- PHP 8 ZipArchive предоставляет read-only/consistency flags, `statIndex()` с `comp_size`, external
  attributes для entry type и read-only entry stream через `getStreamName()`. Эти primitives
  позволяют проверять metadata и ограничивать фактически прочитанные bytes без blind `extractTo()`.
  Sources: https://www.php.net/manual/en/ziparchive.open.php,
  https://www.php.net/manual/en/ziparchive.statindex.php,
  https://www.php.net/manual/en/ziparchive.getexternalattributesindex.php,
  https://www.php.net/manual/en/ziparchive.getstreamname.php — checked 2026-08-09, confidence high.
  RAG:✅ 2026-08-09 official PHP 8 ZipArchive manual.
- IANA остаётся registry канонических media types, но registry MIME не доказывает соответствие
  конкретных bytes заявленному extension. Source:
  https://www.iana.org/assignments/media-types/media-types.xhtml — checked 2026-08-09,
  confidence high. RAG:✅ 2026-08-09 official IANA media type registry.
- Locked Laravel 13 queue API содержит after-commit dispatch support; child job обязан становиться
  видимым только после commit rows. Source:
  https://api.laravel.com/docs/13.x/Illuminate/Queue/DatabaseQueue.html — checked 2026-08-09,
  confidence medium (один primary API source). RAG:✅ 2026-08-09 official Laravel 13 API.

## Verdict

P6 не строит второй document pipeline. Она заменяет dispersed extension checks одним typed registry
и evidence inspector, сохраняет стратегии как handlers, но делает результат handler'а typed:
`ready_for_screening|expanded_container|unprocessable`. Unknown extension и known extension с
неприемлемым MIME/signature получают отдельные machine-readable reasons; generic passthrough
перестаёт быть catch-all. RAG:— (repo-grounded synthesis: evidence above and owner brief §11).

Archive path должен стать format-neutral: metadata listing → reject unsafe/special/colliding
entries → budgets on declared count/size/ratio/depth → bounded per-entry stream/copy → actual byte
and hash verification → Action transaction for child rows/event/after-commit job. Parent archive
получает terminal technical status `expanded` и не уходит в screening. RAG:— (repo-grounded
synthesis: current gaps plus official evidence above).

## Risks to preserve in execution

1. P0.5/P0.6 ещё не terminal; несовместимый official/frozen contract должен supersede D30/D31 до
   application mutation.
2. MIME aliases legacy Office и container-based formats нельзя выдумывать из extension: P6.1
   запечатывает matrix по official P0 evidence и representative fixtures.
3. `finfo`/magic, declared sizes и subprocess exit code — независимые evidence layers; любой один
   не может greenlight archive extraction.
4. Existing object storage I/O не переносится внутрь long DB transaction; database uniqueness и
   after-commit dispatch обязаны выдержать concurrent retry.
5. RAR child happy-path не должен имитироваться несуществующим encoder. Shared validator покрывается
   format-neutral corpus, adapter — captured listing/process contract и runtime Doctor.

## Verification status

- RAG: official Web primary sources ×6 fact classes OK; repo inspection and fixture MIME probe OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: terminal P0.5/P0.6 products and full legacy-format MIME corpus;
  P6.1 is the exact reconcile/freeze carrier.
- Обходки/[POSSIBLE-DEFECT]: host has no `lsar`; no host-only RAR execution claim is made.
