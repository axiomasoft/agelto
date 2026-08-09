# P6 execution design — typed formats and bounded archive expansion

## Sources

- `findings/P6-format-archive-recon-2026-08-09.md` — current code/schema/test map and official
  OWASP/PHP/IANA/Laravel evidence.
- `research/00-user-intent.md` — owner trace for child records, normal dispatch, terminal reasons
  and easy handler extension.
- D5 — document schema/pipeline coupling surface shared by P6–P8/P10–P12.
- P0.5/P0.6 state/products — authoritative future format/archive RAG and frozen contracts.

P6 is authored before P0 execution. P6.1 therefore re-runs the matrix against terminal P0.5/P0.6
and representative fixtures before any schema/application mutation. Contradiction produces a new
superseding decision through plan-design; executor code does not silently reinterpret D30/D31.
RAG:— (repo-grounded: phase order and current P0.5/P0.6 states).

## Alternatives and decisions

### Format routing carrier

- A: preserve ordered strategy `canProcess()` checks plus catch-all passthrough. Lowest diff, but
  duplicated allowlists and unknown/mismatch acceptance remain untestable.
- B: config-only map extension→class. Centralizes keys, but strings/MIME aliases/signature rules and
  handler result stay weakly typed.
- C (D30): backed `DocumentFormat` plus `DocumentFormatRegistry`/inspector. Each format entry pins
  normalized extensions, accepted detected MIME aliases, format-specific content probe and exactly
  one `PreparationStrategy`; a typed result tells the job whether to screen, stop expanded, or reject.

### Machine-readable terminal reason

- A: continue parsing `last_error` prefixes. Backward-compatible, but not castable/indexable and
  every writer can drift.
- B (D30): nullable `unprocessable_reason` column cast to `UnprocessableReason`, backfilled from
  known prefixes; `last_error` remains human diagnostic. One Action/repository write path sets both.

### Archive extraction boundary

- A: sanitize names then bulk `extractTo()`/CLI extract. Existing shape, but declared metadata can
  lie and extraction writes before actual budgets are enforced.
- B (D31): typed `ArchiveEntry` metadata and one-entry-at-a-time bounded reader. ZIP uses read-only
  entry streams; CLI adapters expose bounded streaming/copy behavior. Every entry is type/path-
  validated before bytes, actual bytes/hash are checked while copying, and temp output never becomes
  a source of truth.

### Child identity and dispatch

- A: application-only `<parent id>:<path>` lookup. Works serially but has no database proof for
  concurrent/manual-import children.
- B (D31): persist normalized `archive_entry_path` on child `DocumentVersion` and enforce partial
  unique `(parent_version_id, archive_entry_path)`. A short Action transaction creates/reuses child,
  version and event; `PrepareDocumentJob::dispatch(...)->afterCommit()` is issued once. Parent becomes
  terminal `expanded`, never screening input.

## Frozen format contract

1. Registry allowlist is exactly the owner-required families: PDF; XLS/XLSX; DOC/DOCX/RTF/ODT;
   PNG/JPEG/TIFF/BMP/WebP; GSFX; ZIP/7z/RAR. P6.1 may narrow aliases on evidence but cannot add
   unrelated executable/media formats without a superseding D.
2. Extension is normalized to lowercase after safe filename parsing. Detected MIME comes from bytes;
   persisted source MIME is evidence, not authority. Container formats also run a format-specific
   probe so `PK` alone cannot confuse GSFX/OOXML/plain ZIP.
3. Exactly one registry entry and one handler resolve. Unknown extension → `unsupported_format`;
   known extension with rejected MIME/signature → `format_mismatch`; known handler exhaustion keeps
   the existing precise conversion/OCR/archive reason. Both reason enum and human detail are stored.
4. Handler result is typed: `ready_for_screening`, `expanded_container`, `unprocessable`. Boolean
   `process()` and catch-all `PassthroughStrategy::canProcess=true` are removed.
5. Registry/inspector are read-only Services; terminal outcome and child persistence are Actions;
   repositories perform reads/writes but open no use-case transaction.

## Frozen archive contract

1. Listing returns normalized metadata: original name, normalized relative path, entry type,
   compressed/uncompressed sizes and encryption evidence. All entries, including ignored metadata,
   consume count/size budgets before filtering.
2. Reject archive before extraction on absolute/drive/backslash/NUL/empty/dot/dot-dot path segments,
   overlong paths, duplicate normalized paths, case-fold collisions, symlink/hardlink/device/special
   entries, password/encryption, negative/impossible sizes, excessive depth/count/per-entry/total
   expanded bytes or compression ratio. Unsafe input gets a typed reason; no partial child set.
3. Extract/copy one entry through a byte-counting stream with per-entry and aggregate actual caps;
   declared and actual size must agree. Child object path is content-addressed from actual SHA-256 and
   written to `currentDocumentsDisk`; the parent is read through `FileLocationResolver` recorded/fallback
   location. Caller-supplied path is never a storage path.
4. No database transaction spans archive reading or object-store I/O. The Action transaction owns
   child Document, child DocumentVersion, procurement event and after-commit job registration.
   Database uniqueness, not a preflight query alone, resolves concurrent retries.
5. Successful expansion marks parent `DocumentStatus::Expanded`; it creates no representation and
   never dispatches ScreeningJob for the archive. Every new child starts `Downloaded` and receives
   ordinary `PrepareDocumentJob`; nested archives therefore re-enter the same registry/depth guard.
6. ZIP has a real adversarial fixture path; 7z has real CLI coverage where the image exposes the tool;
   RAR adapter uses deterministic captured listing/error contracts plus runtime Doctor unless a legal
   committed RAR fixture exists. No non-free encoder dependency is introduced for tests.

RAG:— (repo-grounded synthesis: D30/D31 and P6 findings).

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P6.1 | terminal P0.5/P0.6 + current corpus | reconciled extension/MIME/signature/handler matrix and stable D30/D31 or superseding chain | P6.2 may mutate schema/registry only after freeze |
| P6.2 | terminal P6.1 | typed registry/inspector/result and machine-readable terminal reason | P6.3 consumes one classified archive format/outcome path |
| P6.3 | terminal P6.2 | bounded format-neutral archive listing/extraction and adversarial unit contract | P6.4 persists only validated entry streams |
| P6.4 | terminal P6.3 | unique child lineage, short Action transaction, parent expanded outcome and after-commit dispatch | P6.5 exercises full lifecycle |
| P6.5 | terminal P6.4 | real archive→children→ordinary processing plus security/retry/concurrency proof | P6.6 freezes extension gate |
| P6.6 | terminal P6.5 | auto-failing handler contract test, developer matrix/docs and architecture guard | P7/P10/P12 consume stable contract |

All six items are sequential: every item's Inputs name the previous state/product, and P6.2–P6.6
share format/schema/archive/job/test/doc hotspots. The N≥3 independent criterion is false and there
is no autonomous external-state chain; orchestration is not declared. RAG:— (repo-grounded:
dependency matrix above).

## Validation strategy

1. Reconcile gate: terminal P0.5/P0.6, explicit matrix for every owner-required family, no unresolved
   alias and no hidden expansion of supported formats.
2. Schema/runtime: clean migration + rollback/reapply; reason backfill; registry uniqueness; unknown,
   spoofed and valid representative files; no generic passthrough.
3. Archive security: shared metadata/path/budget datasets; ZIP symlink/traversal/duplicate/bomb cases;
   actual-byte overshoot; password and corrupt adapter errors; no child on preflight failure.
4. Persistence: parent recorded-disk read, current-disk child write, `(parent,path)` concurrency,
   orphan/retry recovery, one event and one after-commit child dispatch.
5. Lifecycle: a committed ZIP containing XLSX and nested supported/unsupported entries produces child
   records, runs ordinary PrepareDocumentJob and reaches deterministic statuses/reasons; parent is
   expanded and never screened.
6. Extension gate/docs: enum/registry/fixture keysets equal; every handler has valid/mismatch/unsupported
   contract cases; project document-pipeline docs and operator status reference match code.

## Verification status

- RAG: official OWASP/PHP/IANA/Laravel primary sources OK; repo inspection and fixture probe OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: terminal P0.5/P0.6 products and full MIME alias corpus; P6.1 owns
  the re-pass before mutation.
- Обходки/[POSSIBLE-DEFECT]: no host RAR happy-path claim; the plan requires shared-validator and
  adapter/runtime evidence instead of a fake encoder.
