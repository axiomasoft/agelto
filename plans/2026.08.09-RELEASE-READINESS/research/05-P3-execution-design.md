# P3 execution design — Livewire/Filament assets and application readiness

## Sources

- `findings/P3-livewire-readiness-recon-2026-08-09.md` — current repo, locked vendor-source and
  official Playwright evidence.
- `findings/bootstrap-recon-2026-08-09.md` — owner-reported 404 and initial bootstrap boundary.
- `research/03-P1-execution-design.md` and `research/04-P2-execution-design.md` — image, frontend,
  vendor, bootstrap and Doctor contracts consumed after their terminal items.
- D9/D17/D21 — clean acceptance, immutable application image and Doctor companion carrier.

P0.4/P0.6, P1.5 and P2.6 are not terminal at authoring time. P3.1 therefore starts with their
fresh states, consumes the frozen contract/findings they name and stops on contradiction. The
repo-derived Nginx explanation is deliberately a ranked hypothesis, not a completed diagnosis.
RAG:— (repo-grounded: state carriers and P3 recon).

## Alternatives and decisions

### Livewire and Filament asset delivery

- A: publish every Livewire asset on every Composer update and treat public copies as the only
  production path. It avoids a PHP hit for the core script, but `post-update-cmd` does not cover
  every clean install, stale public manifests become a second version carrier, and Livewire 4
  component JS/CSS routes still need front-controller reachability.
- B (D22): preserve locked Livewire's dynamic hashed route contract, repair Nginx fallthrough and
  use one explicit Filament asset sync/check/repair surface. Build and deploy call the same carrier;
  an optional published Livewire path is accepted only if P0.4 proves it and D22 is superseded.

### Application health boundary

- A: turn `/up` into a synchronous DB/cache/storage/queue/assets sweep. It is easy to curl, but an
  external dependency failure would convert liveness into restart churn and every probe would run
  costly I/O.
- B (D23): keep `/up` as process/application-boot liveness; expose active checks through one typed
  readiness service reused by `app:readiness` and Doctor. Compose services keep narrow healthchecks;
  production deploy calls the aggregate gate explicitly.

### Browser acceptance carrier

- A: manual Playwright MCP smoke. It reuses an existing launcher but has no deterministic assertion,
  version pin, teardown or CI artifact.
- B: Laravel Dusk adds a second PHP/browser stack but the repo has no Dusk convention.
- C (D24): exact `@playwright/test` 1.62.1 with one Chromium project, setup-project auth and an outer
  isolated clean-install runner. The added Node dev dependency is paid once and P11 can reuse the
  same suite in CI.

### Clean-install Compose isolation

- A: stop the owner's current stack and reuse fixed container/volume names. Simple, but destructive
  to unrelated local state and impossible to run in parallel.
- B (D25): remove fixed `container_name`, require an explicit unique Compose project name and random
  host ports in rehearsal scripts. Existing dev/prod YAML remain the only carriers.

## Item topology

| Item | Input dependency | Product | Downstream gate |
|:--|:--|:--|:--|
| P3.1 | terminal P0.4/P0.6/P1.5/P2.6 | runtime evidence proving/refuting the Nginx-route hypothesis | P3.2 may mutate only after verdict |
| P3.2 | terminal P3.1 | Nginx + asset sync/check/build contract | P3.3 readiness consumes asset check |
| P3.3 | terminal P3.2 | liveness/readiness split + Doctor/service aggregate | P3.4 waits for green app gate |
| P3.4 | terminal P3.3 | isolated candidate-tree clean install + Playwright admin interaction | P3.5 reuses the runner |
| P3.5 | terminal P3.4 | rerun/cache/restart/idempotency and narrow asset repair proof | P12 consumes final P3 evidence |

The five items are a strict evidence→fix→readiness→E2E→recovery chain and share
Make/Docker/composer/docs carriers. They are not independent, so the binary orchestration criterion
does not fire and no workflow script is created. RAG:— (repo-grounded: item dependencies).

## Frozen implementation contract

### Assets

- P3.1 records the admin HTML script URL, HTTP status/content type, Laravel route inventory, active
  Nginx config and relevant container logs before any fix. A missing route, stale package manifest
  or different proxy layer changes the diagnosis and stops P3.2 for plan-design.
- Static files are served directly with cache headers. Missing `.js/.css` may reach Laravel, but a
  nonexistent path must remain 404. Livewire core/update/module URLs and Filament public artifacts
  are checked separately; a green core script alone is insufficient.
- A single Make/script carrier owns `assets:sync`, `assets:check` and narrow repair. It never runs
  `composer update`, rotates secrets, deletes volumes or rebuilds unrelated layers.

### Readiness

- `/up` proves liveness/application boot only. It does not touch DB/cache/storage/Horizon and remains
  the app container healthcheck, preventing a dependency outage from restart-looping a live process.
- `app:readiness --format=agent|json` actively checks DB query, cache round-trip with cleanup, configured
  storage round-trip with cleanup, migrations/base/admin state, Livewire/Filament asset contract,
  Horizon status and scheduler heartbeat. Checks have stable codes, bounded timeouts and redacted output.
- P3 Doctor diagnostics delegate to the same service. No duplicate logic, HTTP Doctor route, secret
  content, `--fix` in production or score threshold may replace fail/warn exit semantics.

### Browser and clean install

- Pin `@playwright/test` exactly to 1.62.1 under the pnpm lock from P1.4; install Chromium through the
  pinned CLI. Config uses `E2E_BASE_URL`, one Chromium project, `forbidOnly`, CI-only retry, failure
  screenshot/trace and one worker for this stateful smoke. RAG:✅ 2026-08-09 official Playwright
  configuration and npm registry.
- Auth setup performs the Filament login interaction once, waits for final `/admin`, stores state only
  under ignored output, and never prints credentials. The smoke asserts admin render, successful
  Livewire update request, no failed Livewire/Filament asset response and no page/console error.
- The outer runner materializes only the candidate tracked tree into a temporary directory, uses
  unique `COMPOSE_PROJECT_NAME`, ports, DB/media/volumes and P2 bootstrap/admin carriers, and tears down
  only its own project. It refuses fixed `container_name`, a non-test DB or missing P2/P3 gates.

### Recovery

- Repeating bootstrap, asset sync/check, optimize/cache and service restart must preserve data,
  secrets and generated asset hashes where inputs did not change. Second-run changes are a failure.
- Narrow recovery deletes one disposable generated Filament asset only inside the isolated project,
  proves `assets:check` red, restores it through the canonical repair target and returns all readiness
  and browser gates to green. Source/vendor, DB and volumes are not recreated.

## Validation strategy

1. Evidence: expected-broken repro proves route-vs-Nginx chain without changing production carriers.
2. Contract: shell tests inspect effective Nginx/Compose/image and perform HTTP core/module/missing-file
   checks with status, MIME and cache assertions.
3. PHP: focused Pest tests cover readiness pass/fail/timeout/cleanup/redaction and Doctor delegation.
4. Browser: pinned Chromium login/admin/Livewire smoke with isolated DB/media/auth and failure artifacts.
5. Recovery: two-run hashes/state plus deliberately removed generated asset and narrow restoration.
6. Final P3: P0/P1/P2 gates, Task checks and citation verification remain green; P12 retains the only
   full Ubuntu/production-host rehearsal.

## Verification status

- RAG: official Playwright docs ×3 + npm registry ×1, OK; locked Livewire/Filament source and repo
  carriers inspected, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: P0.4 ecosystem verdict and P3.1 runtime root-cause evidence are
  explicit terminal gates; executor must not infer them from this design.
- Обходки/[POSSIBLE-DEFECT]: none.
