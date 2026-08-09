# P7 execution design — semantic screening envelope and operator contract

## Sources

- `findings/P7-screening-contract-recon-2026-08-09.md` — current DTO, JSON persistence,
  disposition, DB-axis and Filament map.
- `research/00-user-intent.md` — owner trace for type/cardinality/unknown, useful summary and tags.
- terminal P0.4/P0.5/P0.6 products — future official package/prompt/taxonomy evidence and frozen
  cross-phase contracts.
- terminal P6.6 product — future typed safe document input/handler contract.
- ADR 0004 and archived `document-type-axis.md` — current accepted JSONB and enum/check carriers.

P7 is authored before P0/P6 execution. P7.1 therefore reconciles terminal products and corpus
before changing a taxonomy case, prompt, DB JSON shape or handler. Contradiction gets a new
superseding decision; executor code does not reinterpret D32–D34 silently. RAG:— (repo-grounded:
current phase order, P0/P6 state views and `findings/P7-screening-contract-recon-2026-08-09.md`).

## Alternatives and decisions

### Semantic document type carrier

- A: reuse source `Document.kind`. Rejected: it is raw platform text and loses provenance.
- B: runtime DB dictionary. Flexible, but prompt/schema/check consumers can drift and unknown aliases
  become data-dependent.
- C (D32): backed `AiDocumentType` remains the semantic SSOT; `Unknown` is a real completed verdict,
  while SQL NULL means screening has not produced one. Registry membership decides whether a type
  has extraction, so adding a type does not force an extractor.

### Screening response contract

- A: keep flat nullable `description/tags/ignore_reason`. Lowest diff, but count/decision semantics
  remain implicit and the model appears to own branching.
- B: generic entity/value table for every field. Queryable, but contradicts ADR 0004 and creates a
  lifecycle/projection before a second extractor needs it.
- C (D33): response v2 contains `document_type`, bounded `confidence`, required bounded `summary`,
  typed `estimate_cardinality=none|single|multiple|unknown`, normalized bounded `tags:list<string>`
  and optional non-authoritative `classification_note`. Backend-only disposition is not part of the
  provider schema.

### Persistence and runtime decision

- A: denormalize every field onto `prc_documents`. Easy UI, but duplicates per-run evidence and makes
  rerun/shadow provenance ambiguous.
- B: dedicated screening table. Strong schema, but duplicates the existing one-row-per-step Analysis
  lifecycle and violates the accepted JSONB direction.
- C (D34): keep `Analysis.document_type` as indexed discriminator and migrate `Analysis.result` to a
  versioned nested screening envelope. A short backend mutation path appends typed
  `disposition/reason/extraction_dispatched`; JSONB/partial indexes serve tag/filter reads. Raw old
  flat keys are deterministically backfilled, not dual-written indefinitely.

## Frozen target contract

1. `Document.kind` remains source metadata. `AiDocumentType` remains the only semantic case-set;
   every new case is rejected until DB constraints, prompt schema, fixture/eval/gold consumers,
   Filament labels and architecture tests are updated together.
2. Provider response v2:
   - `document_type: AiDocumentType` — never null; uncertain means `Unknown`;
   - `confidence: float` — 0..1;
   - `summary: string` — non-empty bounded description of successfully read visible content,
     including non-estimate/unknown;
   - `estimate_cardinality: EstimateCardinality` — `none|single|multiple|unknown`;
   - `tags: list<string>` — backend trims, Unicode-normalizes, casefold-deduplicates and applies the
     exact count/length limits frozen by P7.1;
   - `classification_note: ?string` — short model explanation, never a branch command.
3. Structural invariants: `single|multiple` requires `document_type=estimate`; a visible estimate
   keeps `estimate` even when other material surrounds it; `estimate` may carry `unknown` when the
   first-page slice cannot establish count. `none` on `estimate` is invalid. These preserve the
   current prompt rule that a multi-estimate file is one document of type estimate.
4. Stored result v2 contains `schema_version`, nested `screening` and backend `decision` objects.
   `decision.disposition` is `extract|retain|review|ignore`; reason is a backed enum such as
   `analyzer_available`, `known_type_no_analyzer`, `unknown_type`, `low_confidence`,
   `borderline_confidence`, `ignore_rule`. `extraction_dispatched` is a separate boolean, so a global
   extraction stop does not rewrite the semantic decision.
5. `ScreeningProcessor` owns provider call/validation/usage and produces typed screening evidence.
   `ScreeningOutcomeHandler` computes disposition. A short Action/repository mutation persists the
   decision, Document status/event/run termination and after-commit extraction dispatch; no DB
   transaction spans upload or LLM work.
6. Existing flat rows and committed gold/test payloads are migrated once: `description→summary`,
   flat tags/confidence/page count/ignore note into `screening`, current branch keys into `decision`
   where deterministically mappable. Unmappable historical values remain named and fail the migration
   or explicit backfill test; no silent default to a green disposition.
7. Analysis Filament index/view renders the envelope as fields, uses enum labels/badges, exact tag
   anchors and filters for type/cardinality/disposition/reason. Raw JSON remains audit evidence, not
   the only operator interface. No edit surface or authorization broadening is introduced.

RAG:— (repo-grounded synthesis: D32–D34, current screening prompt/handler and owner brief).

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P7.1 | terminal P0.4/P0.5/P0.6 + P6.6 + current corpus | reconciled taxonomy/tag/response matrix and stable D32–D34 or superseding chain | P7.2 may mutate JSON/schema only after freeze |
| P7.2 | terminal P7.1 | enums, versioned stored envelope, backfill and indexes | P7.3 emits only a representable provider payload |
| P7.3 | terminal P7.2 | DTO/schema/prompt/gold/eval contract with structural validation | P7.4 persists and branches only validated values |
| P7.4 | terminal P7.3 | idempotent backend disposition and useful non-estimate persistence | P7.5 reads a stable operator contract |
| P7.5 | terminal P7.4 | authorized Filament fields, tag anchors, filters/search | P7.6 proves UI and data contract together |
| P7.6 | terminal P7.5 | format/cardinality/unknown/non-estimate/retry matrix plus docs | P8/P9/P10/P12 consume stable P7 contract |

All items are sequential and share enum/schema/result/handler/test/doc hotspots. The N≥3 independent
criterion is false; there is no long autonomous external-state chain. Orchestration is not declared.
RAG:— (repo-grounded: dependency matrix above).

## Validation strategy

1. Reconcile gate: terminal P0.4/P0.5/P0.6/P6.6, exact taxonomy/source/tag limits and explicit
   contradictions/superseding chain.
2. Data shape: clean migration, rollback/reapply, flat→v2 backfill, invalid enum/range/shape failures,
   partial JSON indexes and no second semantic type carrier.
3. Provider contract: deterministic generated schema/snapshot, prompt version bump, required summary,
   honest unknown/cardinality and hostile/oversized tags rejected by common validator.
4. Runtime: known analyzer/no analyzer, unknown, ignore rule, low/borderline/escalated confidence,
   extraction disabled, rerun/shadow and duplicate job handling; no transaction across LLM/storage.
5. Operator: policy-preserving Analysis table/infolist, summary/tag/cardinality/disposition rendering,
   exact filters/anchors and raw JSON audit fallback.
6. End-to-end: P6 normalized representations, non-estimate summary retention, single/multiple/unknown
   estimate count, active-analysis replacement and full no-TIA suite/docs gates.

## Verification status

- Repo contract and locked package map: complete for design.
- External taxonomy/tag vocabulary and provider/framework constraints: terminal P0.4/P0.5 are the
  required source; P7.1 owns the re-pass before mutation.
- Safe input representation contract: terminal P6.6 required; no behavior is assumed from skeleton P6.
- Open owner question: none. Mixed visible content preserves current repo rule «any visible estimate
  ⇒ type estimate»; changing that to multi-label routing requires a superseding D, not executor choice.
