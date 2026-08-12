# P6 format/archive reconcile — 2026-08-12

## Scope and evidence chain

P6.1 reconciled the terminal P0.5/P0.6 evidence with the current production prepare contour and
the committed document corpus before any schema or application mutation. The exact upstream
products were recovered from append-only journal/Git provenance because the generated terminal
states name no product paths and the P6.1 bundle declares only those states:

- P0.5 product: `findings/P0-rag-domain-security.md`, product commit `f1cb83dd` and closure anchor
  `4c8ec806`.
- P0.6 products: `research/02-P0-target-contracts.md`,
  `root/contracts/release-readiness-v1.md`, D57–D61 and
  `findings/P0-contract-self-audit.md`, product commit `52fb69c6`; bookkeeping anchor
  `bf234d23`.
- P0.5 DOM-03/DOM-04/DOM-06 and accepted D61 strengthen the evidence boundary already selected by
  D30/D31: literal extension, detected MIME, format-specific content/container proof and explicit
  terminal disposition are independent signals.

This read-only provenance recovery did not change either frozen decision. It is recorded as a
process deviation because the mandatory v2 bundle should have declared the exact upstream products.
RAG:— (repo-grounded: `journal.jsonl`, named commits and files above).

## Current production contour and corpus

- `DocumentDownloader` persists the raw filename extension and Fileinfo MIME. The extension is not
  lowercased yet. `DocumentPreparer` walks ordered strategy classes; `PassthroughStrategy` accepts
  every remaining file. RAG:— (`DocumentDownloader.php`, `DocumentPreparer.php`,
  `PassthroughStrategy.php`).
- The current handlers are Archive (`zip|7z|rar`), GSFX, Spreadsheet (`xls|xlsx`), Word
  (`doc|docx|rtf|odt`), Scan/PDF+images, Image and catch-all passthrough. Their extension lists are
  duplicated and do not enforce a common MIME/signature contract. RAG:— (`config/ai.php`, named
  strategy classes).
- The committed document corpus contains PDF, PNG, XLSX and ZIP-with-XLSX. On 2026-08-12 both `file
  --mime-type` and PHP Fileinfo returned respectively `application/pdf`, `image/png`,
  `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` and `application/zip`.
  The XLSX package contains `[Content_Types].xml` plus `xl/workbook.xml`; the ZIP contains
  `estimates/sample.xlsx`. The GSFX corpus separately contains committed `Data.xml` and
  `Properties.txt` copied from a real GRAND-Smeta container and a deterministic test that packages
  them as ZIP; the generated probe returns `application/zip`. No committed
  DOC/DOCX/RTF/ODT/JPEG/TIFF/BMP/WebP/7z/RAR sample exists, so those rows rely on named primary
  specifications or pinned upstream libmagic rules, never on suffix alone. RAG:—
  (`tests/Fixtures/Documents/`, `tests/Fixtures/gsfx/`, `GsfxStrategyTest.php`; replay and hashes in
  Evidence records).
- Archive processing currently reads from `currentDocumentsDisk()` instead of
  `FileLocationResolver::forDocumentVersion()`, bulk-extracts selected entries, does not reject all
  links/special files/collisions, does not compare declared and actual bytes, has no database
  `(parent_version_id, archive_entry_path)` uniqueness, and dispatches no child prepare job.
  RAG:— (`ArchiveStrategy.php`, `ArchiveExtractor.php`, `PrepareDocumentJob.php`, current schema).

## Frozen admission matrix

Every extension is normalized to lowercase after safe basename parsing. The accepted MIME set is
closed per row: `application/octet-stream`, generic `application/zip` for OOXML/ODT, and any
unlisted `x-*` alias are not accepted unless the row explicitly names them. A valid extension with
a rejected MIME or failed probe yields `format_mismatch`; an extension outside the table yields
`unsupported_format`. A successful handler returns exactly one typed outcome:
`ready_for_screening`, `expanded_container`, or `unprocessable`.

| Family / extensions | Accepted detected MIME aliases | Required content/container probe | Exactly one handler | Success outcome | Exact terminal reasons after extension routing | Evidence |
|:--|:--|:--|:--|:--|:--|:--|
| PDF / `pdf` | `application/pdf` | `%PDF-` header and parser-open succeeds | Scan/PDF handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `password_protected`, `ocr_failed`, `too_large` | E1, E2, C1 |
| Excel binary / `xls` | `application/vnd.ms-excel` | CFB header plus Excel workbook stream identity | Spreadsheet handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E3 |
| Excel OOXML / `xlsx` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` | ZIP/OPC package with `[Content_Types].xml`, `xl/workbook.xml`, and SpreadsheetML main content type | Spreadsheet handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E4, C1 |
| Word binary / `doc` | `application/msword` | CFB header plus WordDocument stream/FIB identity | Word handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E3 |
| Word OOXML / `docx` | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` | ZIP/OPC package with `[Content_Types].xml`, `word/document.xml`, and WordprocessingML main content type | Word handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E4 |
| Rich Text / `rtf` | `application/rtf`, `text/rtf` | ASCII `{\rtf` prolog within the bounded leading probe | Word handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E5 |
| OpenDocument Text / `odt` | `application/vnd.oasis.opendocument.text` | ODF package: first uncompressed `mimetype` entry equals the accepted MIME and manifest/content roots exist | Word handler | `ready_for_screening` | `format_mismatch`, `conversion_failed`, `too_large` | E1, E6 |
| PNG / `png` | `image/png` | PNG signature `89 50 4E 47 0D 0A 1A 0A` and valid IHDR | Image handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large` | E1, E7, C1 |
| JPEG / `jpg`, `jpeg` | `image/jpeg` | JPEG SOI `FF D8 FF` and a valid marker sequence | Image handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large` | E1, E8 |
| TIFF / `tif`, `tiff` | `image/tiff` | `II 2A 00` or `MM 00 2A` and valid first IFD | Image handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large` | E1, E7 |
| BMP / `bmp` | `image/bmp` | `42 4D` (`BM`) plus a supported DIB header and bounded pixel offset | Image handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large` | E1, E7 |
| WebP / `webp` | `image/webp` | RIFF container with `WEBP` form type and valid `VP8 `, `VP8L`, or `VP8X` chunk | Image handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large` | E1, E9 |
| GRAND-Smeta / `gsfx` | `application/zip` | ZIP opens; exact root `Data.xml` exists and parses; `Properties.txt` is optional passport evidence; `JSRF`/`GSRF` are never aliases | GSFX handler | `ready_for_screening` | `format_mismatch`, `unreadable_format`, `too_large`, plus AR1 for attachments | E1, C2 |
| ZIP / `zip` | `application/zip` | ZIP signature plus successful typed metadata listing through ZIP adapter | Archive handler | `expanded_container` | `format_mismatch` or AR1 | E1, E10, C1 |
| 7z / `7z` | `application/x-7z-compressed` | `37 7A BC AF 27 1C` signature plus successful typed `7z -slt` listing | Archive handler | `expanded_container` | `format_mismatch` or AR1 | E10 |
| RAR / `rar` | `application/vnd.rar` | RAR 4 `52 61 72 21 1A 07 00` or RAR 5 `52 61 72 21 1A 07 01 00` plus successful typed adapter listing | Archive handler | `expanded_container` | `format_mismatch` or AR1 | E1, E10, E11 |

`AR1` is the closed archive terminal-reason set:
`password_protected|archive_unsupported|archive_unsafe_path|archive_path_collision|archive_special_entry|archive_depth_exceeded|archive_entry_count_exceeded|archive_entry_size_exceeded|archive_total_size_exceeded|archive_ratio_exceeded|archive_declared_size_mismatch|archive_timeout|archive_resource_limit`.
`zip_bomb` remains a backfill-only legacy value for existing rows and receives no new writes after
P6.3; `provider_limit_size|provider_limit_pages` remain provider-stage values outside prepare.
Infrastructure/tool absence, object-store I/O failure and database failure throw for Horizon retry
instead of being mislabeled as an input-terminal reason.

### Evidence records

- **E1:** canonical MIME aliases checked 2026-08-12 in the current
  [IANA media type registry](https://www.iana.org/assignments/media-types/media-types.xhtml).
- **E2:** PDF media-type/header contract: [RFC 8118](https://www.rfc-editor.org/rfc/rfc8118.html).
- **E3–E5:** detector rules pinned to upstream libmagic commit `711ccc264519cdc5073ccb26651c0a9bafc3b47a`:
  [binary Office](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/ole2compounddocs),
  [OOXML](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/msooxml), and
  [RTF](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/rtf).
- **E6:** [OASIS OpenDocument v1.1 packaging](https://docs.oasis-open.org/office/v1.1/OpenDocument-v1.1.pdf).
- **E7–E8:** libmagic at the same pinned commit:
  [image rules](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/images) and
  [JPEG rules](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/jpeg).
- **E9:** [RFC 9649 WebP container format](https://www.rfc-editor.org/rfc/rfc9649.html).
- **E10–E11:** libmagic [archive rules pinned to the same commit](https://raw.githubusercontent.com/file/file/711ccc264519cdc5073ccb26651c0a9bafc3b47a/magic/Magdir/archive)
  and the [RAR 5 technical note](https://www.rarlab.com/technote.htm).
- **C1:** committed fixture SHA-256 anchors: PDF
  `1289b87c2f966f2e7a366e3298e3a1f9a2bf4cc6b894685b9075747283bb98a9`, PNG
  `a439dd8761d9fd4ff88e82e83200877703594491065880dbd4e59ddf4ce1b204`, XLSX
  `9cf7e61fb21a2de0e2e5a65e76ad4f97edbf5294c0fb869b4b28b92843ae47ce`, and ZIP
  `770349abdfd921a6cc6c63c3d53fcde471161634d53b04e483e33b385899b07c`; PHP Fileinfo and
  container listing replay produced the MIME/entries recorded above.
- **C2:** `GsfxStrategyTest.php::buildGsfxZip()` records that the committed cp1251 fragments are the
  same files as a real GRAND-Smeta container. `Data.xml` SHA-256
  `fdc4e1559b84b0dce51ae7f1f2a9013d17ac35740e03a379973d0bbb71f87de6` and `Properties.txt`
  SHA-256 `74d61b3aa28409cc3331db735fb8b8eb7f094853d07f4d27feef2795496290e2` are anchored in commit
  `e97673a3b175afdb284a4a1d9313d489c4f8b89e`; deterministic ZIP construction replay returned
  `application/zip` and roots `Properties.txt,Data.xml`.

Confidence is high for registered MIME types and primary signatures, medium for pinned libmagic
operational aliases and the committed GSFX corpus contract. Any broader GSFX product shape still
fails closed; this matrix does not claim an issuer specification that the evidence lacks.

## Frozen archive admission and limits

The archive contract stays D31 without a superseding decision:

1. Every listed entry, including directories and metadata later ignored, consumes the count and
   declared-size budgets. Current evidence-backed defaults remain depth `2`, entries `200`, and
   aggregate declared/actual expanded bytes `500 MiB` from `config/ai.php`; P6.3 must expose them
   as typed configuration and Doctor output rather than duplicate literals.
2. A single entry can never exceed the aggregate `500 MiB` cap. Per-entry actual bytes and the
   running aggregate are enforced while streaming; negative/impossible declared sizes and
   declared-versus-actual divergence fail closed. A compression-ratio guard is additional defense,
   not a substitute for actual-byte caps; P6.3 must derive its default from committed valid and
   adversarial fixtures before enabling it, because OWASP prescribes the check but no universal
   safe ratio.
3. A directory entry may carry one terminal `/` marker; strip that marker before segment
   validation, retain the entry type, require at least one non-empty segment, and never materialize
   the directory itself. Reject before materialization: any leading/interior/repeated empty segment,
   absolute/drive/backslash/NUL/dot/dot-dot segment, path outside the fixed root, overlong normalized
   path, duplicate file/directory path or Unicode/case-fold collision, encryption/password,
   symlink/hardlink/device/socket/FIFO/special entry, excessive nesting/count/bytes/ratio, adapter
   timeout, or insufficient bounded temp/destination capacity. Directory records still consume
   count and declared-size budgets.
4. ZIP is read one entry at a time. 7z/RAR adapters must provide the same typed metadata and bounded
   copy contract or fail closed; CLI exit success alone is not admission. No blind `extractTo()` or
   unrestricted CLI output directory is permitted.
5. Parent bytes are resolved only through `FileLocationResolver`; accepted children are written by
   actual SHA-256 to the current documents disk. Object I/O stays outside the short Action
   transaction; DB uniqueness resolves concurrent retries and child `PrepareDocumentJob` dispatch
   occurs after commit. The parent finishes `expanded_container` and never reaches screening.

OWASP requires extension allowlists plus MIME/signature defense in depth and pre-extraction
expanded-size/path controls; it does not prescribe universal numeric archive budgets. Sources:
[File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
[Input Validation Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html),
and [malicious upload testing](https://owasp.org/www-project-web-security-testing-guide/latest/4-Web_Application_Security_Testing/10-Business_Logic_Testing/09-Test_Upload_of_Malicious_Files),
checked 2026-08-12. Confidence: high.

## Decision reconciliation

- D30 is stable: P0.5/D61 confirm the three-signal registry and explicit unsupported disposition;
  no accepted upstream evidence authorizes catch-all passthrough or a new format family.
- D31 is stable: P0.5 DOM-04 and the current code gaps confirm bounded per-entry extraction,
  typed metadata, stable child identity and after-commit dispatch. No upstream evidence contradicts
  its transaction/storage boundary.
- No superseding D is created. The only unresolved operational parameter is a compression-ratio
  default; D31 already assigns new defaults/env/Doctor evidence to implementation, and P6.3 must
  close it with committed corpus evidence before enabling the guard.

## Consumer matrix

| Consumer | Frozen input from P6.1 | Acceptance it owns |
|:--|:--|:--|
| P6.2 | Closed format/MIME/probe/handler/outcome/reason matrix | Typed registry, inspector, result and durable reason; no catch-all |
| P6.3 | Archive rows plus admission/limit contract | Format-neutral typed listing, path/type/resource validation and bounded entry streams |
| P6.4 | D31 storage/identity/transaction boundary | DB-enforced lineage, content addressing, terminal parent and after-commit child jobs |
| P6.5 | Full matrix and archive lifecycle | Real archive-to-child ordinary pipeline plus retry/concurrency/security proof |
| P6.6 | Closed extension set and handler/result keysets | Auto-failing extension contract, architecture guard and synchronized docs |
| P7 | `ready_for_screening|expanded_container|unprocessable` and typed reasons | Semantic document taxonomy stays independent of technical format disposition |
| P10 | Spoof/archive/storage/transaction threats and rejected aliases | Cross-cutting security/data audit cannot weaken D30/D31 |
| P12 | Registry keyset, Doctor-visible tools/limits and terminal reasons | Production rehearsal proves current binaries/config/corpus without narrative waiver |

## Verification status

- RAG: primary IANA/RFC/OASIS/RAR/OWASP sources plus upstream libmagic rules pinned to
  `711ccc264519cdc5073ccb26651c0a9bafc3b47a` checked 2026-08-12; repository corpus and PHP
  Fileinfo/container replay succeeded.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: no external fact required to freeze D30/D31 remains unresolved.
  The numeric compression-ratio default is deliberately not approved by unsupported convention;
  P6.3 owns fixture-backed selection before enabling it.
- Обходки/[POSSIBLE-DEFECT]: the P6.1 bundle omitted exact P0.5/P0.6 product paths; read-only
  journal/Git provenance recovery was used and recorded as a process deviation. No product-code
  workaround was accepted.
