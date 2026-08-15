---
audience: operator
type: runbook
status: living
---

# Backup and recovery preparation

The tracked backup package separates local contract proof from runtime evidence. Run the hermetic
carrier locally:

```bash
bash docker/tests/ingress-backup-contract.sh
```

The production sequence is fixed: take the exclusive release/backup lock, validate the closed
profile and disk floor, create a PostgreSQL custom-format dump, restore it into an isolated scratch
database, export object current-state plus configuration/secret-file metadata, hash and encrypt the
set, copy it off-host, verify the copied bytes, and only then apply retention. A local or remote copy
without a successful restore/checksum is not a verified backup.

The systemd service/timer/watchdog/alert files under `docker/host/systemd/` are templates. Their
backup service is guarded by `/etc/agelto/operator-rehearsal-authority`; do not install or enable any
unit in the local preparation phase. The rehearsal must validate them with `systemd-analyze verify`,
enable the timer, observe a scheduled run after reboot, prove a stale/failure alert without putting
the endpoint secret in argv/logs, and record the resulting state in the evidence template.

Community MinIO `mc mirror` represents current-state copying. It must not be described as preserving
version history. A version-preserving design remains conditional on the pinned MinIO/`mc` capability
matrix and a destructive create/update/delete/restore fixture in the operator rehearsal.

Restore always targets a separately allowlisted `*_rehearsal` database/bucket. Production identity,
`pg_restore --clean`, volume deletion, prune-before-copy and implicit destructive flags are forbidden.
See `plans/2026.08.09-RELEASE-READINESS/artifacts/P12-command-matrix.md` for canonical commands.
