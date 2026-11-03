---
audience: dev
type: howto
status: living
---

# Recovery after the final legacy cutover

PostgreSQL Process work, attempts, transitions and outbox are authoritative. Redis messages,
Horizon process counts and technical logs are delivery or diagnostic evidence. Read
[Process controls](process-controls.md) for versioned operator actions and
[Process core](../process-core.md) for ownership and recovery bounds.

The isolated fault corpus records a named boundary, the exact worker PID and the same persisted
work ID through recovery. A kill command alone is not recovery proof. Inspect the rig JUnit and
candidate hashes; use separate e2e/fault/W10/W20/regression/quality results. The test runtime uses
short, ordered timeouts and a real DB-clock expiry; these are not production recovery SLOs.
See [acceptance evidence](../process-core-acceptance.md) for the corpus and claim boundaries.

P3.17 removes source incident navigation, readers, factory and baseline table together.
Circuits were removed earlier. The existing journal URL reads `prc_process_transitions`;
Process incidents provide the open badge, filter and audited acknowledge action. Recreating
legacy tables, clearing old counters or importing old environment switches is not recovery.

1. Inspect `process:status` and the work drilldown: state, reason, absolute retry,
   generation, effective controls and provider recovery directive.
2. Confirm relay/reconcile/feed/project daemon readiness. A fresh heartbeat alone does not
   establish dependency or projection readiness. Reconcile owns interrupted work;
   delivery retries do not replace it.
3. Use versioned pause/resume/cancel/retry/acknowledge actions. Resume clears only the
   chosen manual pause. It does not clear handler holds, item exhaustion or unknown spend.
4. A claimed provider operation without a verified outcome remains explicitly unknown.
   Periodic blocked-provider reconciliation may restore a pinned receipt without another
   send. Operator replay requires a separate audited intent and financial reservation.

For Redis loss, restore the isolated/runtime Redis service and observe admitted delivery
regeneration through relay and reconciliation. Keep the same PostgreSQL database and work IDs;
do not allocate replacement intents to hide a missing delivery. A live running lease is not
republished. An expired lease becomes recoverable; the fenced takeover determines ownership.
Compare the old/new generation, attempts, transitions and accepted publication count.

For PostgreSQL loss, restore connectivity before new effects. Inspect daemon readiness and
durable state after reconnect; a technical timeout cannot establish whether a transaction
committed. Identify the existing work/event/request IDs before retrying. For object-store or
converter interruption, preserve staging/storage reservations until verified bytes or verified
process-stop/cleanup proof exists. Never free capacity solely because a work lease expired.

For `provider_result_unknown`, inspect the exact logical request, physical call, purpose,
request epoch, original budget period and pinned recovery envelope. Do not infer zero cost from
elapsed time, a daily billing aggregate or missing logs. Reconciliation runs a separate periodic
blocked-provider scan even with no Redis event. A successful generation receipt resumes parsing
of that immutable receipt with zero new sends/reservations; asset/error receipts follow their
own typed branch. Cancellation permits financial history and settlement, with zero business
publication. No verified receipt means retained reserve and an explicit operator decision.

For projector lag, read authoritative work/transition state and pending consumer receipts.
The bounded `process:metrics-rebuild <projector>` command changes only projection generations.
It refuses incomplete retained history. A stuck maintenance token requires the versioned
`process:metrics-rebuild-resume <projector> --expected-token=...` recovery action; never clear
someone else's token or rerun paid business work to repair a dashboard.

The schedule contains only daily `partitions:maintain`, the minute readiness heartbeat
callback, minute `process:schedule-acquisition`, hourly `process:retention` and five-minute
`process:health-report`. The health report reads Process authority and sends no external
notification. `process:diagnostics` retains explicit operator diagnostics; it is not scheduled.

Retention uses the existing projection receipt/pin policy, cleans stale temporary workspaces,
and deletes a bounded batch of retired raw objects. Raw publication and deletion share a
PostgreSQL session address gate; object I/O runs outside a database transaction. Canonical
`raw/acquisition/` evidence is conservatively retained. This slice does not establish automatic
reclamation of canonical raw evidence.

Horizon consumes only `supervisor-1` for the default queue and the six Process supervisors.
The obsolete prepare/crawl connections are absent. Both application Compose definitions use
370 seconds as the default Horizon shutdown grace; deployment overrides must preserve the
maximum active-job plus cleanup bound.

For a local code/schema rollback, use a separate branch at the last verified package commit
and a clean isolated install. Roll back consumers, schema and configuration together. The
coordinator runs `make core-test-install` to prove two identical full resets, including
pg_partman, and runs full acceptance. Never reset a dev/shared database. Targeted test files
do not prove the later fault/load package or authorize deployment.

Preserve the report directory, inputs/JUnit, named boundary, work/request IDs and immutable
artifact hashes before a rollback. Compare the final candidate manifest after any fix. Changes
to runtime, schema, fixtures or tests invalidate affected evidence; documentation/report-only
reuse must satisfy the explicit collector checks. See [quality limits](process-quality-limits.md).
