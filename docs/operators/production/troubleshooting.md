---
audience: operator
type: reference
status: living
---

# Production troubleshooting

| Symptom | Check | Resume |
|:--|:--|:--|
| Profile rejected | `production-profile-check.sh check` | Correct the closed profile, then rerun the same check. |
| Deploy stage failed | `first-production-deploy.sh check` | Preserve identity/state; repair and resume the failed stage. |
| Backup stale or failed | `backup-watchdog.sh check` | Verify copy/manifest before any retention action. |
| Release/rotation failed | `release-recovery-contract.sh` | Keep old digest/credential active and reverse completed mutations. |
| Restore concern | `restore-production.sh check` | Use only an isolated `*_rehearsal` target. |

For a first-deploy failure, preserve the candidate/profile hashes and state file, identify the last
completed stage, repair the canonical prerequisite, and rerun the same command. The executable
contract proves failure state and resume for every stage. Never edit `completed=` by hand, change the
candidate under an existing state, recreate secrets/admin, retry an unsafe migration implicitly or
mark a fixture result as runtime evidence.

For backup/release/rotation/restore failures, keep the shared lock and evidence directory intact.
Copy/checksum failure forbids pruning; unknown schema compatibility forbids rollback; partial
credential cutover keeps the old credential active and triggers reverse-order rollback. The
follow-up operator rehearsal owns all live commands and approvals.

The exact check and resume entrypoint for every symptom is the generated
[текущий inventory команд](commands.md#current-command-inventory);
do not substitute a copied prose command.
