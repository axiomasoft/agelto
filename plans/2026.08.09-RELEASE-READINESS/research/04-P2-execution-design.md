# P2 execution design — private vendor, secrets, bootstrap and Doctor

## Sources

- `findings/P2-vendor-secrets-doctor-recon-2026-08-09.md` — repo and official-source evidence.
- `research/00-user-intent.md` — owner trace for vendor, secrets, bootstrap, admin and Doctor.
- `research/03-P1-execution-design.md` — Make/Compose/image contracts consumed after P1.5.
- D6/D9/D17–D21 — nested vendor, clean acceptance, immutable image, vendor lock, secrets and Doctor.

P0.4/P0.6 and P1.5 are not terminal at authoring time. Every P2 item therefore gates on their
fresh state and reconciles any named frozen output before writing. Contradiction means `plan-design
P2`, never executor-side reinterpretation. RAG:— (repo-grounded: phase order and state carriers).

## Alternatives and decisions

### Private package reproducibility

- A: clone `main` into `local/vendors/azguard` and let Composer record whatever HEAD is present.
  Simple, but two builds from the same Agelto commit can resolve different private source.
- B (D19): tracked manifest pins normalized origin, full commit SHA and allowed package paths;
  clone/sync is separate and the build refuses wrong origin, dirty package source or SHA mismatch.

### Production secret backend

- A: keep external Swarm secrets plus environment fallback. This leaves ordinary Compose unusable
  or silently falls back to secret values in env/config.
- B (D20): ignored host files under `local/secrets/` become the source for Compose file secrets;
  service grants are explicit, env files hold only nonsecret config, generator is create-only.

### Doctor placement

- A: move `laravel/doctor` into production Composer requirements. Operationally simple, but ships
  dev diagnostic/fix code in every runtime service contrary to official install guidance.
- B (D21): keep it require-dev and build a same-source one-off Doctor image/profile. Production
  runtime stays lean; the companion artifact costs one extra pinned image and deploy pull.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P2.1 | terminal P0.6/P1.5 | local vendor lock/sync/check + Composer/image closure | P2.2–P2.4 reuse paths and build boundary |
| P2.2 | terminal P2.1 | create-only env/file-secret contract + leakage tests | P2.3 validates, P2.4 consumes |
| P2.3 | terminal P2.2 | non-mutating host/repo/env/Compose preflight | P2.4 is forbidden to start without green preflight |
| P2.4 | terminal P2.3 | resumable clone-to-running dev and prod-build bootstrap | P2.5 adds base/admin stages |
| P2.5 | terminal P2.4 | base/demo split + secure admin create/reset | P2.6 and P12 inspect readiness |
| P2.6 | terminal P2.5 | first-party Doctor profiles/custom diagnostics + one-off image | P3 extends app/assets; P12 runs production gate |

The sequence is deliberately linear: every item edits Make/scripts/docs and consumes the previous
state. Six items are not independent, so the binary orchestration criterion does not fire and no
workflow script is created. RAG:— (repo-grounded: item Inputs/Files).

## Frozen behavior contract for implementation

### Vendor

- `local/vendors/.gitkeep` is tracked; `local/vendors/azguard/**` is ignored by Agelto Git, while
  package sources remain available to Docker build and nested `.git` metadata is excluded.
- The lock manifest stores no credentials. It pins one normalized GitHub repository identity, a
  full commit SHA and only required Composer package subpaths. SSH/HTTPS forms may normalize to the
  same host/path; credential-bearing URLs are rejected.
- Default sync clones/fetches missing data but never resets, cleans or overwrites a dirty checkout.
  Updating the pin is an explicit reviewable manifest/lock change.

### Secrets

- Generated internal secrets: APP key, PostgreSQL password, Redis password/ACL, MinIO root password
  and initial-admin password. External API/bot/mail/Sentry credentials are declared but never forged.
- Create-only is the default: rerun preserves values. Rotation writes a staged next value and never
  silently applies it to existing DB/storage. `umask 077`, atomic writes and 0600 host files are gates.
- Production `.env.production`/`.env.docker.production` carry nonsecret config only. Compose mounts
  required secret files per service; no secret enters Git, build context/history, argv or diagnostic output.

### Preflight and bootstrap

- Preflight is read-only: executable/version checks, vendor origin/SHA/dirty state, file presence and
  permissions, placeholder detection, port availability, `docker compose config --quiet` and image
  contract checks. It cannot build, pull, create containers, migrate, seed or print config/secrets.
- Bootstrap is an ordered state machine with named stages and a durable nonsecret progress marker.
  Rerun resumes safely, never rotates secrets or deletes volumes, and reports the exact failed stage.
- `MODE=dev` may reach a healthy local stack. `MODE=prod-build` stops at pinned app/Doctor image and
  contract validation; server provisioning, deploy, rollback and clean-room acceptance remain P12.

### Data and Doctor

- Production base seeders are explicit and idempotent; demo data is local/testing-only and refuses
  production. Admin creation/reset is a short Action-backed mutation, with hidden prompt or file input;
  no `--password=` and no plaintext output/logging.
- Doctor v0.1 stays require-dev. Dev and one-off production profiles use deterministic agent/JSON
  output; production runs `--fail-on=warn` without `--fix`. App diagnostics report only named readiness
  facts and remediation, never secret values.

## Validation strategy

1. Static shell checks and fake-tool harnesses prove exact argv, fail-closed behavior and non-mutation.
2. Temp-repository tests cover vendor origin/ref/dirty/missing cases and generator create-only/permissions.
3. Compose config tests prove per-service secret grants and absence of secret values/environment fallbacks.
4. Fresh-volume integration covers vendor install, bootstrap rerun, base-seed idempotency and admin flows.
5. Doctor tests cover local/production modes, custom diagnostic codes, nonzero warn/fail and redaction.
6. Final P2 runs package quality commands, Task checks and citation gate; P12 retains clean Ubuntu/prod rehearsal.

## Verification status

- RAG: official Composer, Docker, Redis, PostgreSQL, MinIO and Laravel Doctor sources — OK; registry in findings.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0/P1 terminal outputs and intended AzGuard SHA; hard reconcile gates embedded.
- Обходки/[POSSIBLE-DEFECT]: none.
