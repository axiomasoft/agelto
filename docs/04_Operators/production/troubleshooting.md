# Production troubleshooting

| Symptom | Check | Resume |
| --- | --- | --- |
| Profile rejected | `production-profile-check.sh check` | Correct the closed profile, then rerun the same check. |
| Deploy stage failed | `first-production-deploy.sh check` | Resume only after the failed stage has a recorded rehearsal result. |
| Backup/restore concern | `ingress-backup-contract.sh` | Use an isolated rehearsal target. |
