---
audience: operator
type: runbook
status: living
---

# Production secret rotation preparation

The credential enum is closed: `APP`, `DB`, `REDIS`, `MINIO`. Each rotation receives current/next
values through owner-only files, records only their SHA-256 fingerprints, stages the next value,
checks every declared consumer, and retires the old value only after all consumers are green.

Consumer sets are executable in `rotate-production-secrets.sh check`. A partial failure keeps the old
credential and rolls already switched consumers back in reverse consumer order. Secret bytes never
enter argv, environment, logs, state or evidence. The local carrier uses fake consumers; the
follow-up operator rehearsal owns real service/provider cutover and final retirement approval.
