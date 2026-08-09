# P10 code adversarial audit — 2026-08-09

## Verdict and boundary

**GREEN for P10 product correctness; historical delivery/projection verdict remains RED.** P10.7
replayed all eight adversarial scenarios against the integrated worktree after `migrate:fresh --seed`:
the targeted suite passes 54 tests / 187 assertions. The full root-workaround suite has 12 known
non-P10 outcomes, so it is recorded as a plan deviation rather than used to waive a P10 regression.
The separate dirty-worktree attribution and generated-projection debt in
`findings/final-manual-audit-2026-08-09.md` is not repaired or relabelled by this verdict.

This report is the durable input for P10. It does not reopen or rewrite closed P1–P9 item
specifications. Swissknifeman Layout-v2 rollout, read-admission migration and generated projection
defects are explicitly outside P10: the owner stated that Swissknifeman is still being completed
and will perform its own migrations. No application code was changed by this audit/design pass.

RAG:— (repo-grounded: all findings were derived from the named repository files, PostgreSQL
transaction semantics already documented in `docs/03_Dev/crawling-pipeline.md`, and local rollback
probes; no external fact is used).

## Findings

### F1 — Blocker: background scans can capture and complete another run

**Anchors:** `app/Services/Procurement/CrawlerRunManager.php:25-31`,
`app/Services/Procurement/Modes/DeltaScanProcessor.php:54`,
`app/Services/Procurement/Modes/BacklogRescanProcessor.php:37-41`,
`app/Services/Procurement/Modes/BacklogRescanProcessor.php:91-118`, `routes/console.php:31-34`.

`startOrResume()` identifies ownership only by `crawler_id` and returns any running run. Both
delta and backlog reuse the active archive profile. A scheduled delta/backlog invocation can
therefore continue, mutate the cursor of, or complete a normal operator-started archive run.
Backlog has a second collision: after the first candidate checkpoints, the loop proceeds to the
next flagged fresh run and reuses the same archive run/cursor, although the requested windows and
the fresh-run acknowledgement are different subjects.

**Failure scenario:** operator archive run A is running; the 15-minute sweep starts for fresh run
B, receives A from `startOrResume()`, scans B's window into A, calls `complete(A)`, and marks B
rescanned. Alternatively backlog B checkpoints and backlog C resumes B's cursor, so neither
subject has an honest lifecycle.

### F2 — Blocker: PostgreSQL unique-race recovery queries an aborted transaction

**Anchors:** `app/Services/Procurement/CrawlerRunManager.php:24-60`,
`docs/03_Dev/crawling-pipeline.md:332-341`.

The manager opens an outer transaction, catches `UniqueConstraintViolationException` from
`create()`, then runs `SELECT` in the same PostgreSQL transaction. PostgreSQL has already marked
that transaction aborted. The repository's own canon explicitly requires a nested transaction /
SAVEPOINT around a create-then-recover pattern.

**Failure scenario:** two workers see no running row; one insert wins, the other receives the
unique violation and its recovery `firstOrFail()` fails with `current transaction is aborted`
instead of returning the winning run.

### F3 — Blocker: the real EIS fresh cap is shadowed by the generic no-change stop

**Anchors:** `app/Services/Procurement/Modes/FreshRunProcessor.php:101-132`,
`tests/Feature/Crawler/FreshRunProcessorTest.php:136-153`,
`docs/03_Dev/crawling-pipeline.md:207-210`.

EIS repeats the exact last 500-row block beyond its depth cap. After the first occurrence has
already been ingested, the repeated occurrence naturally produces `rowsNew=0` and
`rowsUpdated=0`. The processor completes at the generic no-change branch before comparing the
dedup signature. The current cap test uses different registry numbers for its two blocks, so it
does not reproduce the real repeated-block payload.

**Rollback probe:** an exact repeated block completed with
`stop_reason=no_new_or_updated_rows`, `cap_hit=null`, `cursor.blockFrom=501`,
`cursor.reachedKnown=true`. The required result is `cap_hit=true` and a durable possible-skip
window.

### F4 — Major: cap-detector state is lost at archive/fresh checkpoints

**Anchors:** `app/Services/Procurement/ArchiveWindowScanner.php:47-58`,
`app/Services/Procurement/ArchiveWindowScanner.php:75-117`,
`app/Dto/Procurement/Crawler/ListingCursor.php:21-54`,
`app/Services/Procurement/Modes/FreshRunProcessor.php:71`,
`app/Services/Procurement/Modes/FreshRunProcessor.php:116-144`.

`previousSignature` and `blocksInInterval` are local variables. `ListingCursor` persists neither.
Every continuation therefore forgets the previous block and resets the per-interval safety cap.
With a platform budget that permits one request per job continuation, repeated-block detection
and `max_blocks_per_window` can be postponed indefinitely.

**Failure scenario:** each continuation reads one full repeated block, checkpoints, resumes with
`previousSignature=null` and `blocksInInterval=0`, advances the cursor and never splits the
interval even though the logical scan exceeded its cap.

### F5 — Blocker: procurement revisions are not serialized per procurement identity

**Anchors:** `app/Services/Procurement/ProcurementIngestProcessor.php:38-60`,
`app/Services/Procurement/ProcurementIngestProcessor.php:130-155`,
`app/Repositories/Procurement/ProcurementStoreRepository.php:17-44`,
`app/Repositories/Procurement/ProcurementStoreRepository.php:141-158`,
`database/migrations/2026_07_04_100012_create_prc_revisions_table.php:38-40`,
`app/Jobs/Procurement/CrawlRunJob.php:53-55`,
`app/Services/Procurement/CsvBlockIngestor.php:88-95`.

The ingest transaction does an unlocked find, reads the latest revision without a row/advisory
lock, and derives `revision_number` from an unlocked `revision_count`. Different crawler jobs may
legally overlap the same registry procurement because job uniqueness is per crawler. The
partitioned revision unique index includes `detected_at`, so it does not reject equal revision
numbers written at different timestamps. Row-level ingest exceptions are logged and swallowed.

**Failure scenario:** two workers read `revision_count=3`; both create revision 4, then both write
the procurement head. The last writer wins, history has duplicate logical sequence numbers, and
one create/update can be reported only as a swallowed row error.

**D138 follow-up Blocker:** advisory serialization fixed duplicate numbering, but
`findLatestRevision()` still ordered by `detected_at DESC` before `revision_number`. Because each
worker captured `Carbon::now()` before waiting for the lock, revision 2 could commit with an earlier
`detected_at` than revision 1. The next ingest selected revision 1 against `revision_count=2` and
threw `Procurement <id> has an inconsistent revision head`. D138 defines the current logical head
by `revision_number`; `findRevisionAsOf()` deliberately retains temporal ordering.

### F6 — Major: a terminal procurement discovered in that status is displayed as ongoing

**Anchors:** `app/Services/Procurement/ProcurementIngestProcessor.php:103-111`,
`app/Services/Procurement/History/ProcurementTimelineBuilder.php:41-79`,
`app/Filament/Resources/Procurement/Procurements/Schemas/ProcurementInfolist.php:34-40`,
`app/Filament/Resources/Procurement/Procurements/Schemas/ProcurementInfolist.php:164-167`.

Initial ingest stores `Discovered`; it stores `StatusChanged` only for an unknown source status.
The timeline builder derives all durations and terminal state exclusively from `StatusChanged`.
Thus a procurement first observed as `completed` can have a terminal model status and a succeeded
event while the UI prints `идёт` and has no initial-status duration.

**Rollback probe:** `procurement_status=completed`,
`event_types=["discovered","succeeded"]`, `terminal_status=null`, `total_duration=null`,
`by_status=[]`.

### F7 — Major: ListingShapeCheck treats valid unchanged data as broken parsing

**Anchors:** `app/Services/Procurement/Health/Checks/ListingShapeCheck.php:32-52`,
`tests/Feature/Health/ListingShapeCheckTest.php:38-44`,
`database/migrations/2026_07_04_100041_create_prc_fetches_table.php:31-32`,
`resources/crawler/health/eis-baseline.json:61-69`.

The fetch carrier records new/updated business rows but not parsed/error counts. The check labels
every non-empty response with zero new and zero updated rows as bad. That is the normal terminal
condition of a fresh scan against already known unchanged rows, so the check cannot distinguish
healthy unchanged input from mapping failure. Its test currently enshrines the false positive.

**Failure scenario:** a healthy repeated observation of known rows increases the broken share and
can trigger a platform-health alert even though parsing succeeded for every source row.

### F8 — Major: concurrent proxy outcomes lose failures and skew EWMA

**Anchors:** `app/Services/Procurement/Proxy/ProxyResolver.php:97-151`,
`app/Services/Procurement/Proxy/ProxyResolver.php:297-311`,
`app/Repositories/Procurement/Proxy/ProxyHealthStoreRepository.php:35-57`,
`app/Services/Procurement/Eis/CsvSourceMethod.php:378`,
`app/Services/Procurement/DocumentDownloader.php:193`.

`reportOutcome()` computes breaker counters and EWMA from detached proxy/target models and persists
ordinary updates without locking or atomic increments. Concurrent channels can both read zero
failures and write one, or compute the same EWMA from the same previous sample.

**Failure scenario:** two simultaneous failures yield `consecutive_failures=1` instead of 2,
delaying circuit opening; two latency samples collapse into one logical sample. The network call is
already complete, so serialization can be kept to a short persistence-only transaction.

## Existing checks and coverage gaps

The following checks were reproduced on this worktree before P10 design:

- nine targeted crawler/history/health/proxy test files: **65 passed / 184 assertions**;
- full suite with the documented root workaround: **1396 passed / 1 skipped / 5872 assertions**;
- Pint: **1040 files**, exit 0; PHPStan: **0 errors**; `git diff --check`: exit 0;
- ordinary app-user full suite: **284 failures**, sampled to pre-existing root-owned
  `storage/framework/testing/disks/**`; this is inherited environment state, not a P10 product
  defect.

These green results coexist with F1–F8 because no current test exercises exact repeated EIS rows
through the real branch order, multi-continuation detector state, a PostgreSQL unique recovery
inside the manager transaction, or two real concurrent DB connections for ingest/proxy updates.

## Remediation map

| Finding | P10 item | Required proof |
|:--|:--|:--|
| F1, F2 | P10.1 | typed run ownership; mismatched owner never reused; PostgreSQL race recovery succeeds |
| F3, F4 | P10.2 | exact-repeat cap branch wins; detector survives one-request continuations |
| F5 | P10.3 + P10.7/D138 | two-connection ingest preserves one logical sequence; reversed detected times still select revision-number head and permit revision 3 |
| F6 | P10.4 | completed-at-discovery is terminal with zero observed duration, not ongoing |
| F7 | P10.5 | parsed/error counters distinguish unchanged success from mapping failure |
| F8 | P10.6 | two concurrent outcomes count twice and open once at threshold |
| F1–F8 | P10.7 | integrated regression, full validation, updated docs and final verdict |

## Inherited failures versus plan defects

- The storage ownership failure is inherited environment state and must remain separately named;
  it must not be used to waive any P10 regression.
- The complete-plan lint's current 27 fail-closed read-admission/parser diagnostics predate P10.
  They belong to staged legacy/future carriers and the Swissknifeman rollout, not to the product
  defects above. P10 must introduce **zero additional** lint diagnostics.
- Missing historical delivery commits and generated state contradictions from
  `findings/final-manual-audit-2026-08-09.md` remain separate delivery/projection debt. P10 neither
  erases nor silently absorbs them.

## P10 incorporation evidence

The owner-directed plan-design pass changed only v2 plan carriers/bookkeeping. No application
code, closed P1–P9 item specification or v1 carrier was edited.

- Marketplace root resolved to `/home/vostrikov/projects/packages/swissknifeman`.
- Before the design change, `plan-lint` failed closed on exactly **27 errors / 0 warnings**;
  `state --all --check` checked 59 items, `decisions --check` checked 4 generated views and
  `status --check` checked 1 view.
- P10 adds 7 item specs/states, D131–D137, this durable audit and a manual P10.1 handoff. Master
  inventory is now **10 phases / 66 items**.
- Post-design `plan-lint` again fails closed on exactly **27 errors / 0 warnings**. Every diagnostic
  is the inherited P5.3/P6.5/P7–P9 inventory classified above; **zero diagnostics point to P10**.
- Dependency-ordered regeneration produced state **66**, decisions **4 generated views from 137
  source decisions**, and status **1**. All seven P10 item bundles resolve without
  `unresolvable_entry`.

The retained lint exit 1 is evidence, not a waiver. A P10 session must compare its postflight to
this inventory and stop if it adds any diagnostic.

## P10.7 closure evidence

- `migrate:fresh --seed --no-interaction` completed on `agelto_test` (63 migrations).
- The integrated F1–F8 carrier suite passed **54 tests / 187 assertions**. F1/F2 use
  `CrawlerRunManagerTest.php` (the P10.7 inventory's old
  `CrawlerRunOwnershipConcurrencyTest.php` name is absent); F3/F4 use fresh/archive processor
  tests; F5, F6, F7 and F8 use the named ingest, timeline, health and proxy PostgreSQL probes.
- P10.7 corrected the F5 carrier's expected event stream: P10.4 deliberately makes the first
  observed status a `StatusChanged`, so the concurrency probe now expects that canonical event.
- D138 adds a deterministic F5 rollback/fix proof. Worker 1 holds the procurement advisory lock;
  PostgreSQL `pg_locks` proves worker 2 is waiting with test time `10:00:00`; worker 1 commits
  revision 1 at `10:00:01`, worker 2 commits revision 2 with the earlier timestamp, and a third
  different ingest commits revision 3. The old `detected_at`-first query fails at that third ingest
  with the named inconsistent-head `LogicException`; the revision-number-only query passes **1 / 8**.
  `findRevisionAsOf()` and `ProcurementIngestProcessor` were not changed. Product commit:
  `97b2f84a20e2fb7b98d42e03cd347be5ca1af884`.
- PHPStan (643 files), the scoped Pint check, `git diff --check` and the application TLS guard
  (`verify => false`) pass. Repository-wide Pint remains red only for unrelated untracked
  `app/Console/Commands/ResetAdminPassword.php`.
- Native Pest JUnit capture under the documented root workaround records **1,418 tests, 5,943
  assertions, 1,405 passed, 1 skipped, 3 failures and 9 errors**. Against D112's named
  18-failure baseline that is **Δ −6 non-passing tests**; all 12 remaining outcomes are outside
  P10's owned carriers: EstimateResource authorization, the unrelated untracked
  ProcurementHistory test, StatusMappingSeeder state, PlatformForm, and eight EisSeeders
  duplicate-record errors. The ordinary `www-data` storage-ownership deviation remains inherited;
  the root workaround does not waive it.
- Layout-v2 postflight retains exactly 27 inherited complete-plan lint diagnostics and no P10
  diagnostic; state 66, decisions 4 and status 1 views are regenerated and checked separately.
  D138 is accepted, P10.7/P10 are terminal again, and the historical delivery/projection RED
  remains separate.
