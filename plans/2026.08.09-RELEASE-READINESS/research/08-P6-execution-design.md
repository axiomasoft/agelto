# P6 execution design — typed formats and bounded archive expansion

## Sources and terminal reconcile

- `findings/P6-format-archive-recon-2026-08-09.md` — current code/schema/test map and official
  OWASP/PHP/IANA/Laravel evidence.
- `research/00-user-intent.md` — owner trace for child records, normal dispatch, terminal reasons
  and easy handler extension.
- D5 — document schema/pipeline coupling surface shared by P6–P8/P10–P12.
- Terminal P0.5 product `findings/P0-rag-domain-security.md` (`f1cb83dd`, closure anchor
  `4c8ec806`) and terminal P0.6 product set `research/02-P0-target-contracts.md`,
  `root/contracts/release-readiness-v1.md`, D57–D61 and `findings/P0-contract-self-audit.md`
  (`52fb69c6`, bookkeeping anchor `bf234d23`).

P6.1 re-ran the matrix on 2026-08-12 against those terminal products, the current prepare contour,
the committed PDF/PNG/XLSX/ZIP and GSFX corpus, current primary format registries/specifications and
libmagic rules pinned to upstream commit `711ccc264519cdc5073ccb26651c0a9bafc3b47a`. No
evidence-backed contradiction was found: D30/D31 stay byte-stable,
and no superseding decision is required. The generated upstream states and P6.1 bundle did not name
the exact products; append-only journal/Git provenance recovered them read-only. This is a process
deviation, not a frozen-contract reinterpretation. RAG:— (repo-grounded provenance and
`findings/P6-format-archive-recon-2026-08-09.md`).

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
   known extension with rejected MIME/signature → `format_mismatch`. The finding freezes the full
   handler/archive keyset, including every existing value and the precise `AR1` archive subset;
   no prose placeholder may become an enum value. Both reason enum and human detail are stored.
4. Handler result is typed: `ready_for_screening`, `expanded_container`, `unprocessable`. Boolean
   `process()` and catch-all `PassthroughStrategy::canProcess=true` are removed.
5. Registry/inspector are read-only Services; terminal outcome and child persistence are Actions;
   repositories perform reads/writes but open no use-case transaction.

The complete extension→accepted MIME aliases→probe→handler→typed outcome/reason table is frozen in
`findings/P6-format-archive-recon-2026-08-09.md`. It covers every owner-required extension. Generic
`application/octet-stream`, container-only `application/zip` for OOXML/ODT, unlisted `x-*` aliases,
and `JSRF`/`GSRF` are rejected rather than treated as compatibility. Exact IANA/RFC/OASIS/RAR/OWASP
sources and libmagic commit `711ccc264519cdc5073ccb26651c0a9bafc3b47a` were checked
2026-08-12; committed Fileinfo/container replay covers PDF/PNG/XLSX/ZIP and GSFX. RAG:✅
2026-08-12, primary sources, pinned detector rules and corpus hashes named in the finding.

## Frozen archive contract

1. Listing returns normalized metadata: original name, normalized relative path, entry type,
   compressed/uncompressed sizes and encryption evidence. All entries, including ignored metadata,
   consume count/size budgets before filtering.
2. A directory may have one terminal `/`; strip it before segment validation, retain directory type,
   require a non-empty relative path and ignore materialization only after it consumed budgets.
   Reject archive before extraction on leading/interior/repeated empty segments,
   absolute/drive/backslash/NUL/dot/dot-dot segments, overlong paths, duplicate file/directory paths,
   case-fold collisions, symlink/hardlink/device/special entries, password/encryption,
   negative/impossible sizes, excessive depth/count/per-entry/total expanded bytes or compression
   ratio. Unsafe input gets one exact `AR1` reason; no partial child set.
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

Evidence-backed defaults remain depth `2`, entries `200`, aggregate declared/actual expansion
`500 MiB`, with per-entry bytes never exceeding the aggregate. Actual-byte streaming caps remain
authoritative. OWASP requires ratio defense but publishes no universal safe ratio; therefore P6.3
must select and Doctor-report that default from committed valid/adversarial fixtures before enabling
it. No unsupported conventional ratio is frozen by P6.1.

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

## Reconciled consumer contract

| Consumer | P6.1 product it consumes | Non-negotiable gate |
|:--|:--|:--|
| P6.2 | Closed extension/MIME/probe/handler/outcome/reason matrix | One typed registry/inspector/result and durable reason; no catch-all |
| P6.3 | Archive rows and frozen admission/limit rules | Typed metadata plus path/type/resource checks and bounded entry streams |
| P6.4 | D31 identity/storage/transaction boundary | Database uniqueness, content-addressed child write and after-commit dispatch |
| P6.5 | Whole matrix and lifecycle | Real archive→children→ordinary pipeline with retry/concurrency/security proof |
| P6.6 | Closed extension and handler/result keysets | New-handler contract fails automatically and docs match code |
| P7 | Typed technical outcome/reasons | Semantic taxonomy stays independent of format disposition |
| P10 | Spoof/archive/storage/transaction threat map | Cross-cutting audit cannot weaken D30/D31 |
| P12 | Registry keyset, tool/limit Doctor surface and terminal reasons | Production rehearsal proves actual tools/config/corpus |

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

- RAG: terminal P0.5/P0.6 products reconciled; primary IANA/RFC/OASIS/RAR/OWASP sources and pinned
  upstream libmagic rules checked 2026-08-12; PDF/PNG/XLSX/ZIP/GSFX corpus probe OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: no fact required to keep D30/D31 stable. P6.3 must still produce
  the fixture-backed compression-ratio default and real adapter evidence assigned to it.
- Обходки/[POSSIBLE-DEFECT]: v2 bundle product-path omission was repaired read-only through exact
  journal/Git provenance and remains a process deviation. No host RAR happy-path claim or fake
  encoder was introduced.
