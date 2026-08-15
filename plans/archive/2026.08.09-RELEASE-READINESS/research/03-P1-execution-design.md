# P1 execution design — Makefile and Docker dev/prod with Vite-ready topology

## Sources

- `findings/P1-docker-make-vite-recon-2026-08-09.md` — current repo and official-source verdicts.
- `research/00-user-intent.md` — owner requirement trace.
- `research/01-P0-execution-design.md` — P0 evidence/freeze protocol.
- D5 — cross-phase coupling surface; D10 — Make compatibility is not frozen.
- `docs/03_Dev/docker/` and `docs/03_Dev/process/deploy.md` — current living behavior contract.

P0.2/P0.3/P0.6 are not terminal at authoring time. P1 is therefore detailed against current
repo evidence and official sources, but every execution item first gates on terminal P0.6 and
reconciles `root/contracts/release-readiness-v1.md`. A contradiction is a `plan-design P1`
escalation, never an executor-side choice. RAG:— (repo-grounded: P0.6 state and D4).

## Alternatives and decisions

### Compose carrier topology

- A: common base plus environment override files. Less duplicated YAML, but the effective prod
  contract depends on merge order and can accidentally inherit a dev bind mount.
- B (D16): two explicit standalone carriers, each independently valid; executable parity tests own
  the intentionally shared invariants. More duplicated YAML, but the production absence of host
  code mounts is directly inspectable.

### Production artifact boundary

- A: keep host checkout/vendor volumes and restart containers. Fast, but the image is not the code
  being served and rollback provenance is weak.
- B (D17): immutable tagged image, no application-code bind mount, common image across PHP services;
  BuildKit layers isolate runtime, Composer, frontend and source invalidation. P2 fills the private
  Composer closure and P3 proves framework assets before the image is called release-ready.

### Frontend package manager

- A: preserve yarn from stale docs without a lockfile/tool pin.
- B (D18): pin existing pnpm 9.15.0 in `package.json`, commit `pnpm-lock.yaml`, use frozen installs in
  Docker/validation and keep Node outside the PHP runtime stage.

## Item topology

| Item | Input dependency | Main product | Downstream gate |
|:--|:--|:--|:--|
| P1.1 | D10/D16 + current Make carriers | target taxonomy, help/preflight and fake-Docker contract harness | P1.2–P1.5 reuse the harness |
| P1.2 | P1.1 terminal | dev Compose/code-sync/non-TTY agent surface | P1.3 reads the stabilized dev/runtime target |
| P1.3 | P1.2 terminal | non-root runtime-base + immutable prod Compose/image contract | P2 supplies private Composer closure |
| P1.4 | P1.3 terminal | pnpm lock, Vite service/HMR and frontend build stage | P3 proves Livewire/Filament assets |
| P1.5 | P1.4 terminal | build/deploy classification, contract tests and synchronized docs | P11 consumes commands; P12 runs clean rehearsal |

The sequence is intentionally linear: all five items touch Make/Compose/Dockerfile/docs and each
uses the preceding state. The binary orchestration criterion does not fire; no workflow script is
created. RAG:— (repo-grounded: item Files and Inputs below).

## Frozen behavior contract for implementation

### Dev

- Source code is a bind mount and a sentinel written on the host is visible inside `app` without
  changing the image ID. `vendor`, bootstrap/cache, framework runtime and node_modules have named,
  explicit ownership boundaries. RAG:— (repo-grounded: D16 and current dev Compose).
- Every agent-facing command has a non-interactive `-T` path and preserves PAO agent markers; help
  and preflight do not start or mutate the stack. RAG:— (repo-grounded: current `AGENT_ENV`).
- Services depend on health/completion, not creation order. RAG:✅ 2026-08-09 Docker Compose startup-order.

### Production

- `app`, `horizon`, `scheduler` use the same required immutable `IMAGE_TAG`; there is no source or
  `vendor` host bind mount. Persistent state is limited to named data volumes and explicitly approved
  backup/runtime mounts. RAG:✅ 2026-08-09 Docker production Compose guidance.
- Runtime image ends as `www-data`, contains no `.env`, auth token, plan/evidence tree or build-only
  Node/Composer cache. Secrets remain P2 runtime input. RAG:✅ 2026-08-09 Docker/serversideup packaging.
- P1 may prove `runtime-base` and frontend/application topology, but must not claim the final app image
  runnable until P2 private packages/vendor and P3 framework assets are terminal. RAG:— (repo-grounded: D6, P2/P3 skeleton contracts).

### Frontend

- `pnpm-lock.yaml` and `packageManager` pin the dependency graph/tool; dev and build use
  `--frozen-lockfile`. Vite listens on `0.0.0.0` inside Docker, exposes a fixed port, restricts hosts and
  origins, and writes/consumes the Laravel hot-file/manifest contract. RAG:✅ 2026-08-09 Vite 7, Laravel 13 and pnpm official docs.

### Deployment taxonomy

- Classification is `code | php-deps | frontend | runtime | image-promotion`. It controls which
  build layers/preflights must invalidate; production deploy itself consumes an already built immutable
  tag and never silently builds on the host. RAG:— (repo-grounded: owner brief and D17).
- No production target runs `down --volumes`, `migrate:fresh`, destructive migration or unscoped cleanup.
  Those need a separate owner gate and backup/restore evidence. RAG:— (repo-grounded: plan Execution Rules).

## Validation strategy

1. Static: `make help`, preflight, `make -n`, shell harness with a fake `docker` binary, and grep checks
   for forbidden target invocations.
2. Config: both Compose carriers render independently with validation-only environment values; parity
   script checks service/image/health/volume invariants.
3. Dev dynamic: start the minimum service set, prove health and host→container code sentinel visibility
   without image-ID change; prove non-TTY agent invocation.
4. Image: build named runtime/frontend targets, inspect final user/files, scan build history/context for
   secret carriers, and prove prod config has no application-code bind mount.
5. Frontend: frozen install, `pnpm build`, manifest existence, Vite health/HMR smoke, no `allowedHosts: true`.
6. Final P1: run every P1 contract script plus Task checks and citation gate; P12 retains the only full
   clean-clone production rehearsal.

## Verification status

- RAG: official Docker, serversideup/php, Vite 7, Laravel 13 and pnpm docs — OK; details in findings.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0 reference comparison/frozen cross-phase contract; mandatory
  P0.6 terminal reconcile is embedded in every item.
- Обходки/[POSSIBLE-DEFECT]: none. P2/P3 gates are explicit scope boundaries, not readiness claims.
