---
item: P8
date: 2026-08-13
file: phases/P8/P8.md
rule_or_contract_locus: Audit P8 round 1 return to bounded P8.6 remediation
defect_class: rule_conflict
gate: return-loop-v1
qualification: new
fingerprint: 1e3d5db4bcf58f1ed7efe6ea027dc6efbb93efc1d676c149b14eb4e7540cbba9
fingerprint_digest: 1e3d5db4bcf58f1ed7efe6ea027dc6efbb93efc1d676c149b14eb4e7540cbba9
occurrences: 1
---

# P8.6 remediation evidence

Candidate product commit: `8caf8acc4990da82357f5ae061726e36176d77e6`.

## Repair

`ProcurementFactory` now resolves the canonical `(eis, fz44)` identity with
`firstOrCreate`, so multiple default procurements no longer attempt duplicate registry inserts.
The colliding-registry regression is covered by `ProcurementFactoryTest`. Crawler-path fixtures
that exercise EIS-specific adapters now request the explicit `RegistryFactory::eis()` state;
generic fixture defaults remain generic. The P8.5 carrier, architecture guard, guide and root
allowlist repair are included in the same candidate.

## Validation record

| Command | Exit | Result |
|:--|:--:|:--|
| `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test app php artisan migrate:fresh --force` | 0 | Fresh guarded test schema. |
| `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test app php artisan test --no-tia tests/Feature/Crawler/CsvBlockIngestorTest.php tests/Feature/Crawler/DetailAnalysisProcessorTest.php tests/Feature/Crawler/PlatformRequestBudgetTest.php tests/Feature/Crawler/ProxyBindingResolverTest.php tests/Feature/Procurement/RevisionTest.php` | 0 | 24 passed / 110 assertions. |
| `docker compose exec -T app vendor/bin/pint --test` on the repaired P8 factory and test paths | 0 | 9 files clean. |
| `bash docker/tests/platform-extension-contract.sh` (candidate product tree) | 1 | All code gates passed, including full no-TIA: 1547 passed / 6775 assertions; it correctly stopped at pre-final stale plan views and historical P8 bookkeeping. |
| `bash docker/tests/platform-extension-contract.sh` (normalized plan tree) | 0 | Full carrier green: 1547 passed / 6775 assertions in 85.13s; Task views, strict citations and `git diff --check` passed. |

P7.6 baseline was `1526 passed / 6679 assertions`. P8.2 introduced the affected adapter/factory
surface in `b27a5c09`. P8.5's prior `frontier/medium` route and its claim that the failures were
pre-existing were incorrect: the failure was introduced by the P8 factory interaction. P8.6 repairs
that interaction and records the correction through the canonical P8.2/P8.4/P8.5 journal events.

This is a qualified new P8 return, not a repeat: Audit P8 round 1 identified the bounded factory
collision and incomplete acceptance carrier, and this candidate reproduces the full-suite baseline
again. RAG:— (repo-grounded: Audit P8 round 1, P7.6/P8.2/P8.5 states and the commands above).
