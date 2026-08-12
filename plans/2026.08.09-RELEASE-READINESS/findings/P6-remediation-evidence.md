# P6.7 remediation evidence — 2026-08-12

## Candidate

- Product anchor: `84d033fc124dcfc8ba365ba3a67309118ba5b472`
  (`test(prepare): type contour archive result (P6.7)`), on top of
  `b86d47c6fc55e95317bdcdf29a8f2c77b632c861`
  (`fix(prepare): unify GSFX attachment lifecycle (P6.7)`).
- D78 authorizes only `tests/Feature/Procurement/ContourTest.php`: its archive-child
  assertion now checks `PreparationResult::ExpandedContainer`; no D30/D31 semantic change.

## Executed evidence

| Command | Exit | Result |
|:--|:--:|:--|
| `docker compose exec -T app php artisan test --no-tia tests/Feature/Procurement/ContourTest.php tests/Feature/Procurement/Prepare/GsfxStrategyTest.php tests/Feature/Procurement/Prepare/ScanStrategyTest.php tests/Feature/Procurement/Prepare/SpreadsheetStrategyTest.php tests/Feature/Procurement/Prepare/ArchivePipelineTest.php tests/Feature/Procurement/Prepare/DocumentFormatContractTest.php tests/Unit/Procurement/Prepare/ArchiveEntryValidatorTest.php` | 0 | 75 passed, 361 assertions |
| `docker compose exec -T app php artisan test --no-tia --log-junit /tmp/p6.7-full.xml` | 0 | 1491 passed, 6398 assertions |
| `docker compose exec -T app vendor/bin/pint --test tests/Feature/Procurement/ContourTest.php docker/tests/p6.7-release-remediation.sh` | 0 | 2 files pass |
| `php -l tests/Feature/Procurement/ContourTest.php` | 0 | no syntax errors |
| `bash -n docker/tests/p6.7-release-remediation.sh` | 0 | shell syntax valid |
| `rg -n 'ZipArchive::extractTo\\(' app` | 1 | zero production matches |
| `rg -n 'indexChild\\(|storeRepository|procurementStore' app/Services/Procurement/Prepare/GsfxStrategy.php` | 1 | zero GSFX private child-persistence matches |

The focused set includes the full-list-before-filter, hostile-entry, retry and typed registry/
inspector coverage from `GsfxStrategyTest`, plus the owner-authorized Contour consumer assertion.
No test was skipped or guard weakened. The suite uses `agelto_test`; migrations 100089 and 100090
were already reported `Ran` by the item-owned carrier preflight. RAG:— (repo-grounded: JUnit
`/tmp/p6.7-full.xml`, Docker test output, P6.7 Validation).

## Append-only reconciliation

Current downstream records were materialized for P6.3, P6.5 and P6.6 against the product anchor.
They preserve `unknown` only for future consumers whose own item-owned carrier does not yet exist;
none is a P6.7 acceptance failure. Journal reconciliation records retain the historical
`block:ROUTE_UNDERSHOOT / n/a` facts for P6.3/P6.5, records P6.4's
`tests/Feature/Procurement/Pipeline/StuckCheckTest.php` Files drift at `89b4e793`, and replaces
P6.6's obsolete full-suite claim with the P6.7 carrier above. RAG:— (repo-grounded:
`journal.jsonl`, generated downstream findings, D77).
