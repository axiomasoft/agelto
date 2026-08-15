# P7 execution design — reconciled semantic screening envelope

Execution reconciliation completed 2026-08-12. The normative matrices live in
`findings/P7-screening-contract-recon-2026-08-09.md`; this research records why the selected
contract remains compatible with terminal P0/P6 evidence and how downstream items consume it.
RAG:— (repo-grounded synthesis of terminal products and current corpus).

## Evidence resolved at P7.1

- P0.4 product `04aa63a0` confirms the locked framework/schema surface and introduces no package
  or provider constraint that requires another response carrier.
- P0.5 product `f1cb83dd` confirms that semantic procurement taxonomy is independent of extension,
  MIME/magic and estimate format. It rejects both “every attachment is an estimate” and unproven
  GSFX spelling aliases.
- P0.6 product `52fb69c6` freezes EXT-11–EXT-13: explicit unknown/null, zero/one/multiple estimate
  semantics, required summaries and tag/anchor round-trip.
- P6.6 product `5045cc85`, with D79 terminal recovery, proves the normalized
  `ReadyForScreening` boundary for supported formats. Archive expansion precedes screening, so
  cardinality remains scoped to each child `Document`.
- The current manifest/gold/prompt/tests exercise estimate, technical specification, contract
  draft and hostile unknown boundaries. P0.5 plus the current enum/prompt/test contract preserves
  protocol as a named semantic case; P7.6 owns its representative regression fixture.

The terminal chain contains no evidence-backed contradiction to D32, D33 or D34. They remain
byte-stable and no superseding D is produced.

## Alternatives resolved

### Semantic document type carrier

- Raw `Document.kind` remains source provenance and cannot be the semantic SSOT.
- A runtime dictionary is rejected because DB checks, provider schema, fixtures/eval and UI would
  no longer share a closed value set.
- The backed `AiDocumentType` enum plus `DocumentAnalyzerRegistry` remains selected. `unknown` is a
  completed verdict; SQL NULL is pre-verdict state. Analyzer availability is orthogonal.

### Taxonomy breadth

- Adding `notice`, `attachment`, format names or vendor aliases is rejected: the corpus does not
  establish stable semantic boundaries for them.
- Removing `protocol` is rejected: terminal P0.5 and the current public case/prompt contract name
  procurement protocols independently of estimate formats.
- Multi-label routing is rejected: the accepted rule remains “any visible estimate structure
  makes the document `estimate`”; cardinality captures plurality without changing analyzer routing.

### Response and persistence

- Flat nullable `description/tags/ignore_reason` is rejected because required summary,
  cardinality and backend decision semantics remain implicit.
- A generic entity/value or second screening table is rejected because `Analysis` already owns
  per-run provenance and D34 selects versioned JSONB.
- Provider response v2 contains semantic evidence only. Backend disposition/reason and dispatch
  state remain backend-owned in the stored v2 envelope.

## Why the exact bounds are local contract decisions

The active screening route has a bounded response budget and the committed corpus descriptions
fit comfortably inside a short summary. P7.1 therefore fixes defensive application limits rather
than claiming an external universal optimum: summary 1–1000 code points, up to 12 tags of 1–64
code points with a 512-code-point aggregate, and optional classification note up to 500 code
points. One shared backend normalizer owns NFKC, whitespace, casefold deduplication and rejection
of control/empty values.

These limits are deliberately provider-agnostic, fit the current response budget, are enforceable
by DTO/schema/backend tests, and give persistence/API/Filament one stable tag anchor. Changing them
after P7.1 is a contract change consumed by P7.2–P7.6, not a prompt-only tweak.

## Cardinality design

Cardinality is a property of visible semantic content in one normalized `Document`:

- `single` and `multiple` require `document_type=estimate`;
- `estimate+unknown` is honest when the bounded slice shows an estimate but not its count;
- `estimate+none` is invalid;
- known non-estimate types require `none`;
- `unknown+none` means readable other content with no estimate, while `unknown+unknown` means the
  slice cannot establish either taxonomy or count.

Sheets, pages, archive entries and extracted rows are not cardinality units. P6 child
materialization creates separate documents before this contract applies.

## Backend decision design

The current `0.4`/`0.6` confidence boundaries and ignore-rule precedence are preserved. The
closed result is:

- `ignore/ignore_rule` before model-derived branches;
- `ignore/low_confidence` below the low threshold;
- `review/borderline_confidence` in the inclusive borderline interval after the optional one-shot
  escalation path;
- `ignore/unknown_type` for a high-confidence completed unknown verdict;
- `retain/known_type_no_analyzer` for a known semantic case without extraction;
- `extract/analyzer_available` when the registry owns extraction.

`extraction_dispatched` remains separate. A global extraction stop or pending after-commit dispatch
does not rewrite the semantic `extract/analyzer_available` decision.

## Migration strategy

P7.2 performs one deterministic flat-to-v2 migration:

- `description→summary`, normalized tags/confidence and optional legacy page-count provenance go
  under `screening`;
- `ignore_reason` becomes only `classification_note`, never an authoritative backend reason;
- known `branch_reason`/`needs_review` values map to the closed decision enum;
- unknown enum/range/shape/reason or missing required summary fails the backfill visibly.

No indefinite dual-write and no silent green default are permitted. `Analysis.document_type`
remains the indexed discriminator; JSONB partial indexes support decision/tag filters.

## Sequential item topology

| Item | Consumes | Produces and gates |
|:--|:--|:--|
| P7.1 | terminal P0/P6 + corpus/current consumers | frozen matrices; D32–D34 stable |
| P7.2 | P7.1 | enums, v2 envelope, backfill and indexes |
| P7.3 | P7.2 | DTO/schema/prompt/gold/eval contract and shared normalization |
| P7.4 | P7.3 | idempotent typed disposition and after-commit lifecycle |
| P7.5 | P7.4 | authorized readable operator/search/tag surface |
| P7.6 | P7.5 | representative taxonomy/cardinality/format regression and docs |

P8 consumes raw-label provenance without redefining taxonomy; P9 consumes Russian labels and
operator fields; P10 audits schema/security/race/queue invariants; P12 reproduces the full carrier
on a clean deployment.

## Validation strategy

1. Data shape: clean migration, rollback/reapply, flat-to-v2 backfill, invalid enum/range/shape
   rejection and partial JSON indexes.
2. Provider contract: deterministic schema, required summary, cardinality cross-field rules,
   hostile/oversized tags and shared normalizer.
3. Runtime: ignore rule, low/borderline/escalated confidence, known analyzer/no analyzer, unknown,
   extraction stop, retry/rerun and after-commit dispatch.
4. Operator: policy-preserving summary/tag/cardinality/disposition rendering, exact tag anchors and
   filters with raw JSON audit fallback.
5. End to end: P6 normalized inputs, all semantic cases, single/multiple/unknown estimates,
   non-estimate retention and full no-TIA suite/docs gates.

## Verification status

- Terminal products and committed identities: reconciled.
- Representative corpus and current `AiDocumentType` consumer surface: inventoried.
- Exact taxonomy, cardinality, limits, mapping and disposition matrices: frozen in the finding.
- D32–D34 contradiction: none; no superseding decision required.
- Open owner question: none. Protocol corpus coverage is assigned to P7.6 and does not alter the
  frozen case-set.
