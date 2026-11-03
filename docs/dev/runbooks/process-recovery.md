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
