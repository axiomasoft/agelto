# P1 Docker / Make / Vite recon — 2026-08-09

## Scope

Проверены текущие Make/Compose/Docker/Vite carriers Agelto и официальные contracts,
необходимые для детализации P1. Product files не изменялись. Внешняя проверка ограничена
первичными официальными источниками; reference repositories P0.2 здесь не читались.

## Repo-grounded baseline

1. Root `Makefile` уже делегирует в `docker/Makefile`, однако канон целей смешивает lifecycle,
   image build, runtime deploy и destructive `down --volumes`; `prod-pre-clean` способен удалить
   named volumes. RAG:— (repo-grounded: `docker/Makefile::prod-pre-clean`).
2. Dev Compose bind-mount'ит весь repository root, поэтому code-only правка видна без rebuild;
   `app`, `horizon` и `scheduler` используют один Dockerfile и health dependencies.
   RAG:— (repo-grounded: `./docker-compose.yml:3`).
3. Prod Compose тоже bind-mount'ит repository root и кладёт `vendor` в named volume. Это
   противоречит заявленному immutable/prebuilt image contract: running code остаётся host-owned.
   RAG:— (repo-grounded: `./docker-compose.prod.yml:3`).
4. `docker/php/Dockerfile` устанавливает system/PHP dependencies, но не копирует application
   source, Composer dependencies или frontend artifacts; `.dockerignore` отсутствует.
   RAG:— (repo-grounded: `docker/php/Dockerfile:1`).
5. `composer.json` использует три sibling path repositories вне Docker build context, поэтому
   self-contained Composer production stage нельзя честно закрыть до P2 private-package contract.
   RAG:— (repo-grounded: `./composer.json:8`).
6. `package.json` содержит Vite 7 scripts, но package-manager field и lockfile отсутствуют;
   living docs требуют yarn, тогда как host preflight даёт Node 24.12.0 и pnpm 9.15.0.
   RAG:— (repo-grounded: `./package.json:1`, `docs/03_Dev/getting-started.md:9`).
7. `vite.config.js` не задаёт container listen address, fixed port, host allowlist или
   Docker HMR/WebSocket endpoint; Compose service `vite` отсутствует.
   RAG:— (repo-grounded: `./vite.config.js:1`, `./docker-compose.yml:3`).

## Official-source verification

### Docker build and production Compose

- Source: https://docs.docker.com/compose/how-tos/production/ — checked 2026-08-09.
  Verdict: official production guidance names removal of application-code volume bindings and
  use of production-specific Compose configuration. Confidence: high.
- Source: https://docs.docker.com/build/building/multi-stage/ — checked 2026-08-09.
  Verdict: named stages allow build-only tools/artifacts to be left outside the final image and
  support explicit development/runtime targets. Confidence: high.
- Source: https://docs.docker.com/build/cache/optimize/ — checked 2026-08-09.
  Verdict: dependency manifests must precede frequently changing source COPY steps; `.dockerignore`
  and cache mounts reduce context/invalidation. Confidence: high.
- Source: https://docs.docker.com/compose/how-tos/startup-order/ — checked 2026-08-09.
  Verdict: container start is not readiness; `service_healthy` / `service_completed_successfully`
  are the explicit readiness contracts. Confidence: high.

### Runtime image

- Source: https://serversideup.net/open-source/docker-php/docs/deployment-and-production/packaging-your-app-for-deployment
  — checked 2026-08-09. Verdict: package application code in a versioned image, run as non-root,
  and inject secrets only at runtime. Confidence: medium (one official vendor source).
- Source: https://serversideup.net/open-source/docker-php/docs/image-variations/fpm-nginx
  — checked 2026-08-09. Verdict: `serversideup/php:8.5-fpm-nginx` exposes 8080, defaults to an
  unprivileged runtime and supports a Laravel `/up` health endpoint. Confidence: medium.

### Vite / Laravel / pnpm

- Source: https://vite.dev/config/server-options — checked 2026-08-09.
  Verdict: container access needs explicit `server.host`; `strictPort` prevents silent port drift;
  `allowedHosts: true` is unsafe; Vite 7 moves WebSocket details toward `server.ws` while keeping
  deprecated HMR fields synchronized. Confidence: high.
- Source: https://laravel.com/docs/13.x/vite — checked 2026-08-09.
  Verdict: Laravel 13 consumes the Vite hot-file in development and manifest/build directory in
  production; `APP_URL` is an allowed dev origin and finer CORS control is explicit. Confidence: high.
- Source: https://pnpm.io/docker and https://pnpm.io/cli/install — checked 2026-08-09.
  Verdict: a committed lockfile plus `--frozen-lockfile`, separate dependency/source layers and a
  multi-stage frontend build form the reproducible Docker contract. Confidence: high.

## Design consequences for P1

1. Keep explicit standalone dev/prod Compose carriers; prevent drift with executable parity tests
   instead of implicit multi-file merge semantics.
2. Dev keeps source bind mounts; prod removes all application-code bind mounts and references an
   immutable `IMAGE_TAG` shared by app/horizon/scheduler.
3. P1 builds runtime/frontend topology and cache boundaries. P2 owns private Composer package
   availability/vendor closure; P3 owns final Laravel/Filament asset readiness.
4. Standardize frontend commands on pinned pnpm + committed lockfile; remove yarn prose/scripts.
5. Separate build classification from deploy execution. No target may use `down --volumes` or build
   on the production host as an implicit side effect.

## Verification status

- RAG: official Web search/open, 9 primary documentation pages, OK.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact P0.2 reference comparison and P0.3 frozen verdicts are not
  available until those items execute; P1 carries an explicit terminal-state/reconcile gate.
- Обходки/[POSSIBLE-DEFECT]: none; the P2/P3 boundaries are modeled as named downstream gates.
