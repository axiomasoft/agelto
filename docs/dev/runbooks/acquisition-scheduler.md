---
audience: dev
type: runbook
status: living
---

# Durable acquisition schedule allocation

`AcquisitionScheduler` accepts a prepared immutable `AcquisitionLaunch`. Preparation must
resolve the registered platform, filter, access policy and listing plan before allocation.
The allocator performs no HTTP request and does not dispatch a queue job.

`prc_acquisition_schedules` has one authority row per `(profile_id, acquisition_kind)`.
It stores cron, timezone, profile version and `next_due_at`. `configure()` preserves the
due instant on identical registration, ignores an older version and rejects conflicting
cron/timezone values for the same version. A newer version replaces the schedule.

`due()` locks that row, rejects stale preparation, creates the Process run, crawl roots,
required links and journal/outbox records, then advances `next_due_at` in the same
transaction. A mandatory write failure rolls back both allocation and due advancement.
Two schedulers contending for the same due row create one required graph.

The scheduled trigger is `(profile_id, acquisition_kind, scheduled UTC instant)`;
different acquisition kinds at the same instant remain independent. Manual allocation
uses a command UUID. Retries must retain their original UUID and immutable launch.
The allocator coalesces missed cron slots; the prepared plan must include the unobserved
source range and coverage debt. Coalescing cron slots alone does not establish source
completeness. Empty plans close discovery explicitly; an unallocated continuation
closes it as incomplete.

The candidate binds allocation to the Process command and minute scheduler. Its targeted
tests do not establish end-to-end cutover or clean installation equivalence. Clean install
and regression checks belong to final acceptance in the isolated rig described in
[process-test-rig.md](process-test-rig.md).
# P2.18 integration boundary

The profile preparer is declared by the existing `PlatformDefinition`. It resolves
reference values before allocation, pins route policy/version and uses the platform
partitioner for bounded initial and recovery plans. Profile edits increment
`profile_version`; allocation rechecks it under a shared profile lock. The
`process:run` command creates a durable intent. The candidate registers the three
acquisition handlers and the minute producer; the atomic cutover is not yet accepted.

Detail publication now upserts `(platform_id, source_document_key)` references,
procurement links and pending download intents in its fenced transaction. Signed
URL rotation changes the versioned access descriptor, preserving identity. Only
complete document sections reconcile missing links; bytes and downstream work are
not deleted. `source_url` remains null for these references so the surviving legacy
downloader cannot select them. Download intents remain
`pending / handler_not_activated` until the document cutover.

Q3 is implemented: ordinary adapter validation binds to WorkAttempt/generation;
separate probe owners cover canaries and standalone diagnostics. Tests cover stale
owners, expiry between the locked read and SQL send authorization, epoch changes,
publication fencing and real PostgreSQL adapter admission concurrency.

Manual commands pin the first resolved default window. Reusing a command UUID with
changed explicit bounds, profile or actor conflicts. Explicit item retry creates a
new intent/work budget and retains the failed work and its three item failures;
required links move only for non-cancelled runs. P3.17 removes the source incident table/readers together with their sidebar resource.
Process incidents and canonical transitions provide the operator view; source circuits and old
acquisition jobs/managers are also absent.

Q4 is implemented. `DiagnosticRunner` selects the profile and pinned route policy;
the registered `DiagnosticCheck` receives an immutable acquisition launch and returns a
`ProbePlan` of one or two typed listing reads. It assesses `DiagnosticInput` without
models, database access or route selection. The plan is bounded separately from the
physical retry budget: every physical authorization is counted from the network ledger.
`AcquisitionDiagnostic` uses a probe owner, retains response/adapter evidence and never
publishes procurement state. A parser exception after HTTP success records a handler
fault and escapes without a blind network retry. Unavailable route/quota produces
`not_enough_data`; it does not diagnose a broken source filter.

The acquisition dependency baseline is empty. The platform architecture test includes
negative fixtures rejecting model/route dependencies in diagnostic adapters. The three
removed legacy adapter paths retain their original hashes in the historical move map
and are explicitly marked removed at P2.18.

The implementation removes legacy acquisition callers and writers, preserves the
deferred incident readers, and records scenario replacements in the D18 transfer
inventory. The coordinator must still run acceptance, the full regression and two
isolated clean installs against the final candidate. Targeted checks alone do not
establish activation readiness or P2.18 acceptance.
