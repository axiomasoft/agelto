# P7 recon — screening contract, persistence and operator surface

Дата проверки: 2026-08-09. Источник — текущее repo tree и locked Composer metadata; внешних
RAG-вызовов в этом ходе не было. External taxonomy/provider/framework verdict остаётся продуктом
terminal P0.4/P0.5 и hard-gate P7.1.

## Current carriers

- `AiDocumentType` уже является единственным code carrier пяти значений: `estimate`, `tech_spec`,
  `contract_draft`, `protocol`, `unknown`; `DocumentTypeAxisTest` проверяет DB constraints восьми
  таблиц, а `DocumentAnalyzerRegistry` отделяет классификацию от наличия extraction analyzer.
- `Document.kind` — nullable raw label источника, а не semantic verdict; слияние этих осей потеряет
  provenance и сломает уже запечатанный document-type contract.
- `ScreeningResult` уже несёт `document_type`, `confidence`, `description`, `tags`,
  `page_count_estimate`, `ignore_reason`. `ScreeningProcessor` сохраняет тип в отдельную колонку
  `prc_ai_analyses.document_type`, остальные поля — flat JSON в `prc_ai_analyses.result`.
- `ScreeningOutcomeHandler` отдельно вычисляет ветвление по ignore rule, confidence и наличию
  analyzer, затем дописывает string keys `branch_reason`, `needs_review` и
  `extraction_dispatched`. То есть модель уже не является окончательным владельцем runtime-решения,
  но typed disposition/reason carrier отсутствует.
- `AnalysisResource` показывает `document_type` и raw result JSON. Summary/tags/cardinality/reason
  не имеют отдельного readable UI, фильтров или tag anchors; текущая table search ищет только title
  документа.
- ADR 0004 уже отверг generic normalized result table: non-estimate output остаётся в
  `prc_ai_analyses.result`, пока реальный второй extractor не докажет необходимость своей витрины.

RAG:— (repo-grounded: `app/Enums/Procurement/Ai/AiDocumentType.php:17`,
`app/Dto/Procurement/Ai/Screening/ScreeningResult.php:20`,
`app/Services/Procurement/Screening/ScreeningProcessor.php:219`,
`app/Services/Procurement/Screening/ScreeningOutcomeHandler.php:62`,
`docs/03_Dev/adr/0004-jsonb-for-non-estimate-results.md:17`).

## Gaps against the owner brief

| Requirement | Current fact | Gap owned by P7 |
|:--|:--|:--|
| Extensible semantic document taxonomy | enum + DB checks + analyzer registry exist | exact initial cases must be reconciled with terminal P0.5/corpus; new-case gate must cover every DB/UI/prompt consumer |
| One / multiple / none / unknown estimates | prompt says multi-estimate is still `estimate` | no typed cardinality field or structural consistency test |
| Useful summary for non-estimate documents | `description` is nullable flat JSON | no required bounded summary contract, versioning/backfill or first-class UI |
| Tags/anchors | `list<string>` flat JSON | no normalization/dedup/limits, indexed contains query or clickable filter carrier |
| Honest decision reason | model `ignore_reason` + backend ad-hoc strings | model note and backend disposition are conflated; no closed enum or readable outcome |
| End-to-end schema/data/job/action/model/DB | DTO→processor→JSON exists | storage envelope is unversioned and mutation/idempotency remains spread across processor/handler/repositories |

## Coupling surface

1. Taxonomy case-set: enum, eight DB constraints/migrations, factories, routes/prompts, fixtures,
   eval/gold builders, Filament filters and architecture tests.
2. Structured response: `ScreeningResult`, response-schema snapshots, prompt files, fake provider
   payloads, screening eval/gold import/export and persisted JSON.
3. Outcome lifecycle: `ScreeningProcessor`, `ScreeningOutcomeHandler`, `AnalysisStoreRepository`,
   `DocumentStoreRepository`, analysis-run termination, procurement events and extraction dispatch.
4. Operator/search surface: `AnalysisResource`, table/infolist, JSON indexes and Filament tests.
5. Docs: feature card, document pipeline, database map, prompt/training loop and operator statuses.

## Locked package evidence

`composer show --locked` on the working tree reports Laravel `13.24.0`, Filament `5.7.6` and
spatie/laravel-data `4.23.0`. P7 must use the locked local APIs and their existing project
adapters (`JsonSchemaGenerator`, `StructuredResponseValidator`, Filament Schema API); version/API
claims beyond this repo snapshot require terminal P0.4 or official-source revalidation in P7.1.

RAG:— (repo-grounded: `composer.lock`, `app/Services/Llm/Schema/JsonSchemaGenerator.php:24`,
`app/Services/Llm/Validation/StructuredResponseValidator.php:21`,
`app/Filament/Resources/Procurement/Ai/Analyses/Schemas/AnalysisInfolist.php:17`).

## Verification status

- Repo code/schema/test/UI map: complete for the P7 coupling surface above.
- External procurement taxonomy/corpus: not yet produced; terminal P0.5 is mandatory before P7.1
  can freeze cases.
- Framework/provider structured-output limits: terminal P0.4 plus locked vendor source are the
  execution inputs; this authoring pass makes no external compatibility claim.
- Real-format behavior: terminal P6.6 is mandatory before P7.1; P7 does not bypass format safety.
