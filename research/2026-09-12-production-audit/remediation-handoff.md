# Production audit remediation handoff

- Date: 2026-09-12
- Audit baseline: `941ade6d743628acca72564cd31afabd1bb9bd26`
- Scope: F01–F10, including F06a/F06b/F06c
- Result: implementation and local verification complete
- Route: direct `task:task-dev`

## Route decision

The audit’s historical “Передача Sol” text asked for a native Task successor plan. During execution the owner explicitly corrected that route: Task/Design is not a mandatory hub, and this remediation must continue directly without plan ceremony. Therefore no v2 successor plan, phase carrier, audit phase, or plan close was created. This handoff is the requested durable result carrier; the historical audit and characterization probe remain unchanged.

The routing problem itself is recorded separately in [the Swissknifeman roadmap relay](../2026-09-12-swissknifeman-task-opt-in-routing-field-incident.md).

## Completed findings

| ID | Result | Durable behavior and regression evidence |
|:--|:--|:--|
| F01 | Fixed | `Prepared` and the initial `AnalysisRun` are committed atomically; dispatch happens after commit. Recovery creates exactly one missing initial run for historical orphans, preserves screening-off behavior, and excludes terminal history. FK fault injection proves rollback; two recovery ticks prove one run/one continuation. |
| F02 | Fixed | Direct detail completion and the scheduled backlog share one bounded `Discovered → DownloadDocumentJob` claim. It includes `attempts=0`, honors backoff, permanent failure and active lease, and no longer downloads inline in the detail processor. |
| F03 | Fixed | Required incomplete/failed common or document snapshots persist their parse outcome, leave `detail_fetched_at` unset, enter the error block and become due after backoff. A legitimate empty but complete document snapshot still succeeds. |
| F04 | Fixed | Parser and downloader share an HTTPS exact-host policy; userinfo, non-default ports, IP literals, loopback/private/link-local/IPv6/lookalikes are rejected. Automatic redirects are disabled and every `Location` is revalidated before another request. The allowlist is an owner-controlled trust boundary, so arbitrary DNS names never reach resolution. |
| F05 | Fixed | Downloading uses the PSR stream and a bounded spill-to-disk buffer, counts actual bytes independently of `Content-Length`, hashes incrementally, writes via `writeStream`, verifies the stored stream hash, removes failed partial objects, and creates no version before success. Format inspection reads only a bounded signature after a size preflight. |
| F06a | Fixed | Detail processing persists discovered attachments and dispatches bounded download jobs instead of hiding downloads inside the detail worker budget. |
| F06b | Fixed | Borderline screening persists route progress and dispatches a separate continuation job. The full budget is 60 s PDF slice + 150 s upload/poll + 90 s generation + 30 s setup + 30 s reserve = 360 s job; supervisor 390 s; Redis retry_after and lease 420 s. A common deadline is checked before each remaining operation. |
| F06c | Fixed | Prepare passes one deadline through archive list/extract work; every external process timeout is clamped to remaining time and ZIP metadata/copy loops check the same deadline. Durable child records make archive expansion retry-resumable. |
| F07 | Fixed | Expired leases are counted once as a failed domain execution, receive bounded backoff/permanent-failure accounting, and must be observed by a later durable retry before execution resumes. Real SIGTERM/SIGKILL worker recovery was exercised. |
| F08 | Fixed | Recovery eligibility is pushed into relational SQL over latest version/run/analyses, filters terminal, delayed and disabled feature branches before `LIMIT`, and no longer scans full history in PHP. Regressions cover 20 irrelevant rows with `limit=1`, terminal/delayed starvation, and screening-disabled extraction. |
| F09 | Fixed | Every lease acquisition gets a unique execution token and uses database time. Release and repository writes are conditional on that token; an old worker cannot clear or mutate state owned by a newer worker. |
| F10 | Fixed | Pipeline temporary files are atomically created once with the final extension under a private directory. All conversion/screening/extraction paths use the helper, and an hourly age-safe sweeper removes stale files only after a retention window longer than the maximum lease. |

## Verification

Every database test run was preceded by `make test-preflight`; it exited 0 and confirmed the isolated `agelto_test` PostgreSQL database.

Extended audit regression profile:

```bash
docker compose -p agelto_dev exec -T -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app php artisan test --compact --no-tia tests/Feature/Document tests/Feature/Procurement/Pipeline tests/Feature/Procurement/Prepare tests/Feature/Procurement/Screening tests/Feature/Procurement/Analyzers/Estimate tests/Feature/Crawler/DetailAnalysisProcessorTest.php tests/Unit/Crawler/DocumentsParserTest.php tests/Unit/Procurement/Prepare/ArchiveEntryValidatorTest.php tests/Unit/Procurement/SourceTargetValidatorTest.php tests/Unit/Support/PipelineTemporaryFileTest.php tests/Unit/Config/QueueTimeoutInvariantTest.php
```

Exit 0: **323 passed / 1432 assertions**, 22.33 s.

Real worker lifecycle:

```bash
bash docker/tests/pipeline-worker-recovery-contract.sh
```

Exit 0: 6 passed / 60 assertions for the slow-worker contract, then 4 passed / 14 assertions for durable recovery. The harness observed a successful SIGTERM path, interrupted the second worker with SIGKILL, verified recovery and no overlap, and restored Horizon.

Quality and security gates:

- `vendor/bin/phpstan analyse --no-progress --error-format=table --memory-limit=1G` — exit 0, `[OK] No errors`.
- `composer audit --locked --no-interaction` — exit 0, no security vulnerability advisories.
- targeted `vendor/bin/pint --test` over all 49 changed/new product PHP files — exit 0.
- `git diff --check` — exit 0.
- `php -l research/2026-09-12-production-audit/ProductionAuditProbeTest.php` — no syntax errors.

F08 was also profiled once with `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` against the isolated PostgreSQL fixture. The root was `Limit` (planned/actual rows 1) over the ordered `prc_documents_pkey` index scan; active analyses used `prc_ai_analyses_active_version_step_unique`. Planning was 2.596 ms, execution 0.698 ms, with 0 shared reads and 0 temp blocks. This is evidence for the 20-row regression shape, not a production-volume benchmark. The diagnostic test insertion was restored and is absent from the diff.

The historical characterization probe intentionally expects the old defects and was not converted into an acceptance test. Its corrected contracts now live in the named production suites above; no temporary copy was left in `tests/Feature`.

## Changed architecture surfaces

- Lifecycle/recovery: prepare job, pipeline dispatcher/reconciler, run/analysis/document repositories, lease/error concerns.
- Untrusted download boundary: EIS document parser, target validator, streaming downloader, format inspector and crawler config.
- Operation budgets: screening job/processor/page slicer/outcome continuation, archive/preparation deadlines, Horizon and AI config.
- Temporary files: shared allocator, cleanup command/schedule, office/spreadsheet/screening/extraction users.
- Living workflow documentation: `docs/02_Workflow/document-and-estimate-pipeline.md`.

## Production boundary

No deploy, live EIS request, paid LLM call, production database access, or 72-hour soak was performed. Before production, validate the configured legitimate document-host allowlist against actual EIS/file-CDN traffic and repeat the controlled long-run/throughput/RSS/inode observation in the target environment. Those are deployment gates, not missing local F01–F10 implementation.
