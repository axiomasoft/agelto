# P12 operator rehearsal brief

This is input for a later `task:plan-design new ...`; it creates no plan, performs no mutation and
grants no authority. The owner must provide a disposable Ubuntu 24.04 host plus explicit authority
for DNS, GitHub deploy-key enrollment, registry and CI access, TLS, deployment and recovery actions.

## Required sequence and evidence

| Gate | Frozen source | Required authority/input | Runtime acceptance |
|:--|:--|:--|:--|
| host baseline | D43, D89 | disposable Ubuntu host; second SSH session | apply/reboot/rerun, key-only SSH, effective ports/UFW/DOCKER-USER, logs/time/updates |
| repository identity | D19, D89 | GitHub deploy-key owner | separate read-only aliases, official host fingerprint, exact clean detached Agelto/AzGuard SHAs |
| registry and CI | D19, D89 | registry/CI credentials | immutable app/Doctor pair exists and selected CI is green |
| DNS and TLS | D43, D89 | DNS/certificate owner | A/AAAA, NGINX syntax/reload, HTTPS/WebSocket, renewal dry-run |
| first deployment | D45, D89 | deployment owner and file secrets | every staged receipt, failure/resume, second-run idempotency, browser and Doctor |
| scheduled backup | D44, D50 | off-host target and retention policy | timer after reboot, custom dump/isolated restore, verified copy before prune |
| alert delivery | D50 | endpoint secret-file owner | stale/service failure produces a redacted correlation receipt |
| secret rotation | D49 | APP/DB/REDIS/MINIO owners | every consumer green before retirement; injected failure reverses in order |
| rollback | D45, D53 | previous digest and schema verdict | bounded drain, compatible promotion, readiness/canary and measured duration |
| isolated restore | D44, D53 | allowlisted DB/object target | PostgreSQL/object/secret/config restore, integrity and measured RPO/RTO |
| final release verdict | D45, D90 | repository owner | binary D45 decision from all preceding runtime receipts |

Use only commands generated in `P12-command-matrix.md`; pin the resulting plan to the applicable
implementation/high execution routes and full reviews. Blank fields in P12 templates become runtime
evidence only when the external command actually succeeds. Fixture output is never accepted.

The follow-up must retain the current D89 boundary: failure of a real gate is repaired or reported in
that rehearsal plan and must not be retroactively rewritten into this local preparation report.
