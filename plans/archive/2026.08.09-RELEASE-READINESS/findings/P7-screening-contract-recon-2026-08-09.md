# P7 recon — frozen screening taxonomy, envelope and disposition contract

Дата исполнения: 2026-08-12. Repo anchor фактов: `12517487`. Terminal product anchors:
P0.4 `04aa63a0`, P0.5 `f1cb83dd`, P0.6 `52fb69c6` + bookkeeping `bf234d23`,
P6.6 `5045cc85` с truthful recovery D79 в terminal state. RAG:— (repo-grounded:
item state views, committed products и current corpus).

## Reconciled evidence chain

| Evidence | Terminal fact | P7 consequence |
|:--|:--|:--|
| P0.4 | Locked Laravel/Filament/Livewire/Pest ecosystem verified; no provider constraint changes the current structured-response carrier | P7 uses the locked `spatie/laravel-data`/schema adapters and introduces no dependency |
| P0.5 DOM-03/DOM-05/DOM-06 | Extension, MIME/magic and semantic procurement taxonomy are separate; procurement attachments are not failed estimates; `.gsfx` is literal vendor evidence | `AiDocumentType` stays independent of `DocumentFormat`; `JSRF`/`GSRF` are not aliases or taxonomy cases |
| P0.6 EXT-11–EXT-13 | Taxonomy is independent of estimate format; unknown/null and zero/one/multiple estimates are explicit; every result has summary and round-trippable tags/anchors | D32–D34 remain the governing carrier; P7 freezes exact semantics below |
| P6.6 | Normalized PDF/text/spreadsheet/image/GSFX representations reach `PreparationResult::ReadyForScreening`; archives produce child documents before screening | Cardinality is per screened `Document`, not per archive; format/handler reasons never become semantic document types |
| Current corpus | `estimates/manifest.json` contains 25 representatives: 12 estimate, 1 tech_spec, 1 contract_draft and 11 unknown; committed screening gold covers the estimate boundary | Existing five-case enum is sufficient; protocol remains evidence-backed by P0.5/current contract but needs a representative regression fixture in P7.6 |

Corpus counts reproduce on `12517487` with:

```bash
jq '.fixtures | group_by(.document_type) | map({document_type: .[0].document_type, count: length})' estimates/manifest.json
rg --files estimates/gold -g '*.screening.json'
```

The current corpus includes multi-estimate workbook, KС-2/KС-3/LSR/VOR boundaries, scan PDF,
GSFX, contract draft, technical specification and hostile non-estimate tables/drawings/acts/NMCK.
It supplies no evidence for a new semantic case and no evidence for multi-label routing.

## Frozen semantic taxonomy

`Document.kind` remains the raw platform label. `AiDocumentType` remains the only semantic
case-set. SQL NULL means “successful screening has not produced a verdict”; `unknown` means a
completed verdict. Case labels and aliases below are prompt/UI vocabulary, not additional stored
values.

| Stored case | Russian label | Accepted content/aliases | Provenance and corpus boundary | Analyzer rule |
|:--|:--|:--|:--|:--|
| `estimate` | Смета | ЛСР, локальная смета, КС-2, КС-3, ведомость ресурсов/объёмов на сметном бланке, дефектная ведомость with estimate structure | P0.5 DOM-05, current prompt, estimate corpus across xls/xlsx/pdf/gsfx | Registry may provide extraction; current registry does |
| `tech_spec` | Техническое задание | техническое задание, техническая спецификация, описание требований/объекта закупки without estimate structure | current enum/prompt and `NEG-DOCX-SPEC-001` | Valid known type even without analyzer |
| `contract_draft` | Проект контракта | проект контракта/договора, draft agreement with legal structure | P0.5 DOM-05, current enum/prompt and `NEG-DOCX-CONTRACT-001` | Valid known type even without analyzer |
| `protocol` | Протокол | протокол рассмотрения заявок, подведения итогов, разногласий and equivalent procurement protocol | P0.5 DOM-05, current enum/prompt/tests | Valid known type even without analyzer; P7.6 adds a representative fixture |
| `unknown` | Тип не определён | readable content outside the four named cases, unreadable/insufficient slice, notice or generic attachment whose semantics are not proven by the visible content | D32, hostile negative corpus and honest fallback rule | Never has an analyzer; completed non-null verdict |

Rules:

- any visible estimate structure makes the semantic type `estimate`; surrounding contract,
  protocol or technical text does not create a multi-label result;
- filename, source `Document.kind`, extension, MIME and archive/container format never select a
  semantic case by themselves;
- a notice/generic attachment maps by visible content to a named case or to `unknown`; adding
  `notice`, `attachment` or another case requires corpus, DB/prompt/eval/UI updates and a new
  superseding decision;
- `DocumentAnalyzerRegistry` alone decides whether a known semantic type has extraction.

## Frozen response and limits

Provider response v2 contains exactly these semantic fields:

| Field | Contract |
|:--|:--|
| `document_type` | required `AiDocumentType`; never null |
| `confidence` | required finite number in `[0, 1]` |
| `summary` | required string; NFKC, trim, non-empty, 1–1000 Unicode code points |
| `estimate_cardinality` | required enum `none|single|multiple|unknown` |
| `tags` | required list; 0–12 values, each NFKC+trim+collapsed whitespace, 1–64 Unicode code points, total normalized payload ≤512 code points |
| `classification_note` | optional/null; when present NFKC+trim, 1–500 Unicode code points; explanatory only |

Tag normalization is backend-owned: reject control characters, normalize NFKC, trim and collapse
whitespace, deduplicate by Unicode casefold while preserving first-seen spelling and order. Empty
values after normalization are rejected. Persistence, API and Filament use the same normalized
value as the exact filter/anchor key; display labels do not become a second value carrier.

## Estimate cardinality invariants

| Semantic situation in normalized visible input | `document_type` | Cardinality | Validity |
|:--|:--|:--|:--|
| exactly one logical estimate | `estimate` | `single` | valid |
| two or more logical estimates, including multiple sheets/sections in one file | `estimate` | `multiple` | valid |
| estimate is visible but the bounded slice cannot establish count | `estimate` | `unknown` | valid |
| known non-estimate type | `tech_spec|contract_draft|protocol` | `none` | valid |
| readable other content conclusively contains no estimate | `unknown` | `none` | valid |
| unreadable/insufficient content cannot establish type or count | `unknown` | `unknown` | valid |
| `single|multiple` with non-estimate type | any non-`estimate` | — | invalid |
| `none` with `estimate` | `estimate` | — | invalid |

Cardinality counts logical estimates inside one screened `Document`. Archive children are separate
documents after P6 admission; pages, worksheets, attachments and extracted rows are not counts.

## Stored envelope and flat-to-v2 mapping

Stored `Analysis.result` v2 is:

```json
{
  "schema_version": 2,
  "screening": {
    "confidence": 0.0,
    "summary": "…",
    "estimate_cardinality": "unknown",
    "tags": [],
    "classification_note": null,
    "page_count_estimate": null
  },
  "decision": {
    "disposition": "review",
    "reason": "borderline_confidence",
    "extraction_dispatched": false
  }
}
```

`Analysis.document_type` remains the indexed semantic discriminator and is not duplicated inside
the envelope. `screening.page_count_estimate` is optional storage-only legacy/input provenance;
provider response v2 no longer emits it.

| Flat v1 carrier | V2 carrier | Mapping rule |
|:--|:--|:--|
| `document_type` column | unchanged column | valid enum value only; null remains pre-screening only |
| `confidence` | `screening.confidence` | preserve finite 0..1 value; invalid value fails backfill |
| `description` | `screening.summary` | trim/normalize; null/empty fails backfill for succeeded screening |
| `tags` | `screening.tags` | run the common normalization/limits; invalid/oversized value fails backfill |
| `page_count_estimate` | `screening.page_count_estimate` | preserve nullable non-negative integer as storage-only provenance |
| `ignore_reason` | `screening.classification_note` | preserve as non-authoritative note; never infer backend reason from prose |
| `needs_review=true` | `decision=review/borderline_confidence` | deterministic only when current confidence is in the borderline interval |
| `branch_reason=ignore_rule|low_confidence|unknown` | corresponding typed decision reason | `unknown` maps to `unknown_type`; unknown strings fail backfill |
| `extraction_dispatched` | `decision.extraction_dispatched` | preserve boolean only after decision mapping succeeds |

No indefinite dual-write is permitted. A succeeded historical row that cannot satisfy the required
summary, enum, range or deterministic decision mapping is reported and stops migration/backfill.

## Backend disposition and reason matrix

Current thresholds are frozen at low `0.4` and high `0.6`; boundaries match the current handler.
Ignore rule has first precedence. Borderline escalation may make one provider retry; the final
validated result is then evaluated by the same matrix.

| Condition after successful provider validation | Disposition | Reason | `extraction_dispatched` |
|:--|:--|:--|:--|
| procurement title matches configured ignore rule | `ignore` | `ignore_rule` | false |
| confidence `< 0.4` | `ignore` | `low_confidence` | false |
| confidence `0.4..0.6` inclusive after optional one-shot escalation | `review` | `borderline_confidence` | false |
| confidence `> 0.6`, type `unknown` | `ignore` | `unknown_type` | false |
| confidence `> 0.6`, known type without extraction analyzer | `retain` | `known_type_no_analyzer` | false |
| confidence `> 0.6`, known type with extraction analyzer | `extract` | `analyzer_available` | true only after after-commit dispatch; false while global extraction is disabled or dispatch is pending |

The global extraction stop changes only `extraction_dispatched`; it does not rewrite semantic
disposition/reason. Provider/shape failure belongs to Analysis failure lifecycle and is not
invented as a successful screening decision.

## Current consumer surface

| Surface | Current carriers that consume the case-set | P7 obligation |
|:--|:--|:--|
| Semantic/DB | `AiDocumentType`; checks on analyses, corrections, dataset examples, eval runs, fixtures, prompt suggestions, routes and prompts | all values change atomically with migrations/check tests |
| Provider/prompt | `ScreeningResult`, JSON schema generator snapshots, `screening:classify` prompt | v2 fields, limits and structural validation share one contract |
| Registry/routing | `DocumentAnalyzerRegistry`, `config/ai.php`, prompt/purpose registries, route/prompt readers and seeders | case existence never implies analyzer existence |
| Runtime | `ScreeningProcessor`, `ScreeningOutcomeHandler`, rerun actions, shadow dispatch and usage lifecycle | persist typed evidence/decision atomically; after-commit extraction only |
| Corpus/eval | fixture manifest/upload/verify, gold builder/importer, screening eval/metrics, dataset/eval factories and tests | flat fixtures migrate once; protocol representative added in P7.6 |
| Operator UI | Analysis, fixture, dataset, prompt, route and suggestion Filament surfaces | labels, filters and tags read the same enum/normalized values |
| Architecture/tests | DB-axis, second-type, registry, branching, schema, gold, route and Filament tests | prevent string/switch/type-axis drift |

## Downstream consumer matrix

| Consumer | Exact dependency on this freeze | Acceptance carrier |
|:--|:--|:--|
| P7.2 | enums, stored v2 envelope, deterministic backfill, JSON/partial indexes | migration/rollback/reapply and invalid-history tests |
| P7.3 | provider DTO/schema/prompt v2, exact limits, normalization and cardinality invariants | schema snapshot, prompt/gold/eval validation |
| P7.4 | typed disposition/reason, idempotent mutation and after-commit dispatch | branch/retry/concurrency tests |
| P7.5 | readable summary/cardinality/disposition/reason, exact tag anchors and filters | policy-preserving Filament tests |
| P7.6 | representative case/cardinality/format regression and docs | protocol fixture plus full P6→P7 matrix |
| P8 | platform raw labels/status mappings remain upstream data, not taxonomy aliases | typed platform mapping tests preserve `Document.kind` provenance |
| P9 | Russian labels/search/filters consume P7 values without redefining them | global Filament IA/browser acceptance |
| P10 | schema, authz, migration, race/queue and no-switch invariants | cross-cutting audit and full suite |
| P12 | clean deployment must reproduce migrations, prompts, corpus and operator surface | release rehearsal and READY/NOT READY evidence |

## Decision verdict

D32, D33 and D34 remain byte-stable. Terminal evidence strengthens their separation and supplies
exact limits/matrices but does not contradict their alternatives or consequences. No superseding
decision is created.

## Verification status

- Terminal state gate: P0.4/P0.5/P0.6/P6.6 are terminal and their committed product identities
  were recovered from journal/git provenance.
- Corpus gate: manifest, committed screening gold, enum, DTO, prompt, DB-axis and current
  consumer surface were reconciled on `12517487`.
- Contract gate: every accepted case has provenance; the missing real protocol representative is
  an explicit P7.6 regression obligation, not authority to add/remove a case.
- Contradictions: none; D32–D34 remain current and no product/schema/prompt mutation occurred in P7.1.
- External claims are consumed through terminal P0 findings; this file adds only repo-grounded
  synthesis and therefore uses RAG:—.
