# P12 execution design — Ubuntu runbook, recovery и clean-room production proof

## Sources and inherited contracts

- `findings/P12-ubuntu-deployment-rag-2026-08-09.md` — current official Ubuntu/Docker/GitHub/
  Nginx/Certbot/PostgreSQL/MinIO facts and repo boundary.
- D9/D17/D19–D27/D39/D42 plus additive D49/D50 and ordering correction D53 — clean-room,
  immutable image, private package, secrets/rotation, Doctor, assets/readiness/private docs,
  post-deploy recurring backup/alert, quality and CI contracts.
- Terminal P1.5/P2.6/P3.5/P4.5/P10.6/P11.6 specifications and state carriers — the commands P12
  documents and then executes literally.
- Owner brief sections 2–5 and 14 plus `research/00-user-intent.md` — complete Ubuntu-to-ready
  path, no hidden private dependency/bootstrap step, and final production-like evidence.

## Alternatives and decisions

### Host baseline and ingress

- Ubuntu 26.04 LTS is officially supported by Docker but is rejected as the first-deploy baseline:
  P12 uses mature Ubuntu 24.04 LTS and treats a 26.04 move as a separate full rehearsal. D43 records
  this internal release choice; it does not claim 26.04 unsupported.
- Publishing the app container directly on all interfaces is rejected because Docker-published
  ports can bypass a prose-only UFW policy. D43 selects host Nginx/Certbot with app bound to
  loopback and executable `sshd`/firewall/socket/renewal checks.
- Root login and a broad admin account are rejected. A dedicated key-only `deploy` identity owns
  checkout/runtime state; provisioning elevation is explicit. Membership in `docker` is documented
  as root-equivalent, Docker TCP API stays closed, and every credential has a rotation/revocation
  step.

### Source, artifacts and registry

- A single deploy key reused for Agelto and AzGuard is rejected: GitHub keys are per-repository.
  P12.2 uses two read-only keys, two SSH aliases, verified GitHub host fingerprint and D19 exact
  origin/SHA checks.
- Build-on-host is rejected by D17. The production profile supplies registry/repository/digest and
  read-only pull credentials; a trusted release carrier publishes app+Doctor artifacts with the same
  candidate SHA. The registry vendor is intentionally not frozen by P12.
- The AzGuard checkout remains present because the owner requires an auditable nested private source
  and D19 preflight consumes it, but production PHP services still run `vendor` from immutable image.

### Data protection and rollback

- Local-only and DB-only backups are rejected. D44 requires PostgreSQL custom dump+restore, object/
  media copy with version semantics where configured, encrypted secret recovery copy and the exact
  config/lock manifests needed to recreate the deployment.
- P12 does not invent business RPO/RTO/schedule/retention. P12.3 creates a machine-checked deployment
  profile and cannot close until the owner fills/approves these values, an off-host destination and
  root-only alert endpoint. Under D53 it proves templates and deterministic fixtures only: no live app,
  scheduled production backup or application restore exists yet. After P12.4 first deploy, P12.5 enables
  the D50 persistent backup/watchdog timers and proves verified-copy-before-prune retention, real
  correlation-ID delivery, fail-closed overdue/disk checks and application-checkable isolated restore.
  Missing policy, delivery or post-deploy proof is a go-live blocker, not a default.
- Image rollback without a restore decision is allowed only across additive/backward-compatible
  migrations. P12.5 rehearses previous-digest rollback and a separate isolated restore; any
  destructive migration needs a new owner decision and maintenance plan. D49 assigns the D20
  `.next` apply path only to P12.5: locked backup-first APP/DB/Redis/MinIO cutover, full service
  verification, old active-auth retirement and reverse-order rollback are one evidence contract.

### Readiness verdict

- D45 defines one `READY|NOT_READY` report schema. A red/missing mandatory check, unpublished exact
  artifact, unverified restore, absent owner production profile or residual secret in evidence yields
  `NOT_READY`; P12.7 remains blocked with exact remediation/resume rather than closing by prose.
- Clean-room means a fresh Ubuntu 24.04 VM, clean clones, empty project-scoped volumes and no use of
  host PHP/Composer/Node fallback. Evidence binds Git SHA, app/Doctor digests, private package SHA,
  Compose project, commands, timestamps and redacted artifact hashes.

## Seven-item execution chain

1. P12.1 creates the idempotent/checkable Ubuntu host and production-profile carrier.
2. P12.2 installs per-repo Git identity and proves two clean pinned clones.
3. P12.3 configures loopback ingress/TLS/logging and closes owner-approved recurring backup,
   retention, watchdog and alert carrier on deterministic config/fixture gates only.
4. P12.4 executes the first deploy from immutable artifacts through seeds/admin/assets/docs/Doctor
   and supplies the first live HTTPS/application proof.
5. P12.5 enables and proves the real scheduled off-host backup/retention/watchdog/alert/restore
   path, then rehearses normal release, queue drain, D20 secret rotation apply/verify/rollback and
   previous-digest rollback.
6. P12.6 reconciles every dev/prod/agent/Vite/backup/rotation command and failure remediation with live carriers.
7. P12.7 repeats the literal guide on a fresh VM, runs terminal release gates and emits the verdict.

Every item is sequentially coupled to the previous state/deliverable, so the phase does not meet the
plan-protocol orchestration criterion despite having seven items.

## Pre-mortem (6–12 months)

| Failure cluster | Early signal | Mitigation / owner |
|:--|:--|:--|
| Public Docker port bypasses host firewall | service reachable on container port outside 80/443 | loopback bind + effective DOCKER-USER/socket test in P12.1/P12.3; owner: infrastructure operator |
| Deploy key or registry credential leaks | credential appears in argv/log/config/artifact | per-repo read-only keys, file/stdin auth, sentinel scans and revocation drill in P12.2/P12.7; owner: repository owner |
| Backup exists but restore is impossible | no recorded scratch restore/hash/version | P12.3 config/fixture gate plus P12.5 real scheduled set and isolated application-checkable DB/object/secret restore; owner: release operator |
| Backup silently stops or fills disk | stale last-success, disabled timer, threshold breach without delivered alarm | persistent backup timer + independent watchdog + correlation-ID delivery in P12.3/D50; owner: infrastructure operator |
| Secret rotation leaves mixed credentials | one service switches while consumers still use old files | locked backup-first matrix, per-service checks and reverse rollback in P12.5/D49; owner: release owner |
| Rollback image cannot read migrated schema | migration not additive/backward-compatible | pre-deploy compatibility classification, previous digest smoke and restore gate; owner: release owner |
| Guide drifts from Make/Compose commands | copy-pasted raw command differs from help/agent JSON | P12.6 generated command matrix + literal execution; owner: code owner |
| Warm state hides missing bootstrap/assets/docs | existing volumes or browser auth required | unique Compose project, empty volumes, fresh admin and browser smoke in P12.7; owner: release owner |
| Disk fills from Docker logs/backups | unbounded json-file/backups on host | log rotation, capacity thresholds, retention enforcement and disk alert check; owner: infrastructure operator |

## Requirement trace

| Owner requirement | P12 carrier |
|:--|:--|
| Empty Ubuntu, deploy user, SSH, Docker/Compose, firewall | P12.1 + D43 |
| Two private clones and exact AzGuard path/pin | P12.2 + D19 |
| DNS, reverse proxy, TLS, persistent data, logs and recurring backups/alerts | P12.3 config/fixture carrier → P12.4 live app → P12.5 real proof + D43/D44/D50/D53 |
| First deploy, secrets, migrations, seeds, admin, assets, docs, Doctor | P12.4 + D9/D20–D27 |
| Subsequent release, Horizon drain, D20 secret rotation, rollback and restore | P12.5 + D17/D20/D23/D44/D49 |
| Human/agent/dev/prod/Vite command reference and troubleshooting | P12.6 + P1/P10/P11 contracts |
| Production-like clean rehearsal and full report | P12.7 + D9/D39/D42/D45 |

## Проверка (verification status)

- RAG: official primary sources captured in `findings/P12-ubuntu-deployment-rag-2026-08-09.md`;
  repo/plan contracts inspected.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact terminal commands/versions/digests and actual host/DNS/
  registry/backup values are execution outputs; every relevant item fails closed until present.
- Обходки/[POSSIBLE-DEFECT]: нет.
