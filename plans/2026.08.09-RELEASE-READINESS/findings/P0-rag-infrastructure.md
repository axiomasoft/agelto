# P0.3 — Infrastructure RAG

## Scope and queries

Проверка повторно выполнена 2026-08-10 для инфраструктурного контура Agelto: Docker Engine
и Docker Compose на одном Ubuntu-сервере, BuildKit/cache, file secrets, production deploy,
rollback/backup, GNU Make и Vite dev/build boundary. Локальные факты взяты только из
digest-qualified inputs P0.3; product code и reference repositories не менялись. Прежний
WebSearch-only материал использован только как provisional seed: каждый INF-verdict ниже
получил новый MCP row и независимую сверку. RAG:✅ 2026-08-10, Context7 + Perplexity MCP.

### MCP preflight evidence

- perplexity-web login: PASS
- perplexity-web cited-search smoke: PASS
- Smoke query: Docker Compose production application-code bind mounts versus rebuilt images,
  current guidance as of 2026; official Docker sources only.
- Smoke sources: https://docs.docker.com/compose/how-tos/production/ ·
  https://docs.docker.com/engine/storage/bind-mounts/

Query log:

1. Perplexity smoke: production application-code bind mounts versus rebuilt images.
2. Perplexity replay: BuildKit cache, reproducible Dockerfile inputs, build secrets and image digests.
3. Perplexity replay: Compose secrets/readiness, destructive volume teardown and recovery evidence.
4. Perplexity replay: Ubuntu installation/firewall, Vite dev/build boundary and GNU Make output safety.
5. Context7 `/docker/docs/__branch__main`: Compose dependencies/secrets, BuildKit and digest semantics.
6. Context7 `/vitejs/vite/v8.0.10`: dev host/allowedHosts/HMR and production build/base semantics.

### MCP replay matrix

| ID | Engine | Query | Checked | Primary sources | Verdict |
|:--|:--|:--|:--|:--|:--|
| INF-01 | perplexity-web | Compose production code bind mount vs rebuilt image | 2026-08-10 | https://docs.docker.com/compose/how-tos/production/ · https://docs.docker.com/engine/storage/bind-mounts/ | REFUTED |
| INF-02 | perplexity-web | BuildKit invalidation, cache mounts and external cache | 2026-08-10 | https://docs.docker.com/build/cache/invalidation/ · https://docs.docker.com/build/cache/optimize/ | CONFIRMED |
| INF-03 | perplexity-web | apt layer safety vs reproducible base/package inputs | 2026-08-10 | https://docs.docker.com/build/building/best-practices/ · https://docs.docker.com/dhi/explore/security-concepts/digests/ | CONFIRMED |
| INF-04 | perplexity-web | Build secrets, SSH mounts and secret cache invalidation | 2026-08-10 | https://docs.docker.com/build/building/secrets/ · https://docs.docker.com/build/cache/invalidation/ | CONFIRMED |
| INF-05 | context7 | Compose external/file/environment secret semantics | 2026-08-10 | https://github.com/docker/docs/blob/main/content/reference/compose-file/services.md · https://docs.docker.com/reference/compose-file/secrets/ | REFUTED |
| INF-06 | context7 | depends_on service_healthy readiness boundary | 2026-08-10 | https://github.com/docker/docs/blob/main/content/reference/compose-file/services.md · https://docs.docker.com/compose/how-tos/startup-order/ | CONFIRMED |
| INF-07 | perplexity-web | Ubuntu 24.04 Docker install and published-port firewall path | 2026-08-10 | https://docs.docker.com/engine/install/ubuntu/ · https://docs.docker.com/engine/network/packet-filtering-firewalls/ | CONFIRMED |
| INF-08 | perplexity-web | docker compose down --volumes persistence effect | 2026-08-10 | https://docs.docker.com/reference/cli/docker/compose/down/ · https://docs.docker.com/engine/storage/volumes/ | REFUTED |
| INF-09 | perplexity-web | backup/restore mechanics vs off-host retention policy | 2026-08-10 | https://docs.docker.com/engine/storage/volumes/ | UNVERIFIED |
| INF-10 | perplexity-web | mutable tags and manifest/platform digest identity | 2026-08-10 | https://docs.docker.com/dhi/explore/security-concepts/digests/ | CONFIRMED |
| INF-11 | context7 | Vite v8 dev host/allowedHosts/HMR vs build/base | 2026-08-10 | https://github.com/vitejs/vite/blob/v8.0.10/docs/guide/cli.md · https://vite.dev/config/server-options · https://vite.dev/config/shared-options | CONFIRMED |
| INF-12 | perplexity-web | GNU Make 4.4.1 output sync and flag propagation | 2026-08-10 | https://www.gnu.org/software/make/manual/html_node/Parallel-Output.html · https://www.gnu.org/software/make/manual/html_node/Options_002fRecursion.html | CONFIRMED |

Output contract: claim → applicable scope/version → primary URL → failure scenario →
consumer item; unsupported version-specific assertions remain `[UNVERIFIED]`.

## Sources

| ID | Primary source | Checked | Applicable scope | Confidence |
|:--|:--|:--|:--|:--|
| S1 | Docker, [Use Compose in production](https://docs.docker.com/compose/how-tos/production/) | 2026-08-10 | Current Docker Compose, single-server production and overrides | medium — one primary source |
| S2 | Docker, [Compose services: `depends_on` and `secrets`](https://docs.docker.com/reference/compose-file/services/) | 2026-08-10 | Current Compose Specification; `service_healthy`, secret mounts | high — cross-checked with S3/S4 |
| S3 | Docker, [Compose top-level secrets](https://docs.docker.com/reference/compose-file/secrets/) | 2026-08-10 | Current Compose; `file`, `environment`, `external` source boundary | high — cross-checked with S2 |
| S4 | Docker, [Manage sensitive data with Docker secrets](https://docs.docker.com/engine/swarm/secrets/) | 2026-08-10 | Docker Swarm secrets and `/run/secrets` | medium — one primary source for Swarm semantics |
| S5 | Docker, [Build cache invalidation](https://docs.docker.com/build/cache/invalidation/) | 2026-08-10 | Current BuildKit cache and build-secret invalidation | high — cross-checked with S6/S7 |
| S6 | Docker, [Optimize cache usage](https://docs.docker.com/build/cache/optimize/) | 2026-08-10 | BuildKit layer order, cache mounts, external cache | high — cross-checked with S5/S7 |
| S7 | Docker, [Cache storage backends](https://docs.docker.com/build/cache/backends/) | 2026-08-10 | Buildx cache exporters/importers and secret-leak boundary | high — cross-checked with S5/S6 |
| S8 | Docker, [Building best practices](https://docs.docker.com/build/building/best-practices/) | 2026-08-10 | Current Dockerfile guidance: trusted bases, rebuilds, apt, pinning | high — cross-checked with S5/S11 |
| S9 | Docker, [Install Docker Engine on Ubuntu](https://docs.docker.com/engine/install/ubuntu/) | 2026-08-10 | Ubuntu 24.04 LTS and current stable apt repository | high — cross-checked with S10 |
| S10 | Docker, [Packet filtering and firewalls](https://docs.docker.com/engine/network/packet-filtering-firewalls/) | 2026-08-10 | Docker Engine port publishing on Ubuntu/ufw | high — cross-checked with S9 |
| S11 | Docker, [Image digests](https://docs.docker.com/dhi/explore/security-concepts/digests/) | 2026-08-10 | Immutable digest semantics, including multi-platform manifests | medium — one primary source |
| S12 | Docker, [`docker compose down`](https://docs.docker.com/reference/cli/docker/compose/down/) | 2026-08-10 | Current Compose CLI `--volumes` behavior | high — cross-checked with S13 |
| S13 | Docker, [Volumes: backup and restore](https://docs.docker.com/engine/storage/volumes/) | 2026-08-10 | Docker-managed volume lifecycle and restore mechanics | high — cross-checked with S12 |
| S14 | Vite, [Server options](https://vite.dev/config/server-options) | 2026-08-10 | Current unversioned Vite docs; dev server, HMR, host security | medium — project Vite version was not an input |
| S15 | Vite, [Building for production](https://vite.dev/guide/build.html) | 2026-08-10 | Current unversioned Vite docs; production bundle and base path | medium — project Vite version was not an input |
| S16 | GNU, [GNU Make 4.4.1 manual](https://www.gnu.org/software/make/manual/make.html) | 2026-08-10 | GNU Make 4.4.1; recursive flags and synchronized output | medium — one primary source |
| S17 | Docker, [Build secrets](https://docs.docker.com/build/building/secrets/) | 2026-08-10 | Current BuildKit secret and SSH mounts for private build inputs | high — cross-checked with S5/S7 |

## Verified findings

### INF-01 — production source boundary

`docker-compose.prod.yml` bind-mounts `./:/var/www/html`, while the PHP Dockerfile does not
copy application source into the image. Docker's production guidance says production
configuration commonly removes application-code bind mounts, and deploying changed code in
an image requires rebuild plus container recreation. The two target properties “immutable
production image” and “production code-only update without image rebuild” therefore cannot be
claimed simultaneously with the current topology. **Verdict: REFUTED** for the current tree as
an immutable release topology. RAG:✅ 2026-08-10, S1/S8; local evidence:
`docker-compose.prod.yml`, `docker/php/Dockerfile`, `docker/Makefile`. Confidence: high.

Failure scenario: the bind-mounted host tree changes independently of the advertised
`agelto/app:latest`, so rollback by image tag does not restore the executed code. Consumers:
P1.3, P1.4, P12.2, P12.4, P12.5.

### INF-02 — rebuild-cause taxonomy

BuildKit invalidates `COPY`/`ADD` layers from input metadata and all following layers; ordinary
`RUN` cache is keyed by the instruction, not by freshness of installed packages. Cache mounts
and external `cache-to/cache-from` stores reduce rebuild cost but do not change correctness.
Application source, dependency manifests/lockfiles, frontend manifests/lockfiles, Dockerfile or
build-context configuration, base-image refresh, and explicit clean/security rebuilds must be
distinct trigger classes. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10, S5/S6/S7/S8.
Confidence: high.

Failure scenario: treating every Git change as an image trigger destroys cache efficiency;
never refreshing base/package layers leaves a green build on stale vulnerable bytes. Consumers:
P1.2–P1.4, P11.5, P12.4.

### INF-03 — current Dockerfile cache posture

The PHP Dockerfile correctly combines `apt-get update` and `apt-get install` in one layer and
removes apt lists, matching official cache guidance. It does not pin Debian package versions,
and its floating `FROM` references do not produce a byte-reproducible rebuild. **Verdict:
CONFIRMED** for cache-safe apt syntax; **REFUTED** for byte reproducibility. RAG:✅ 2026-08-10,
S5/S8/S11; local evidence: `docker/php/Dockerfile`. Confidence: high.

Failure scenario: a later rebuild under the same source commit resolves different base or apt
packages, so the rebuilt artifact cannot serve as an exact rollback target. Consumers: P1.3,
P11.5, P12.4.

### INF-04 — build secrets and private dependencies

Docker requires build credentials to use dedicated BuildKit secret/SSH mounts; copying them or
passing them as ordinary build arguments can leak credentials. Secret values themselves do not
invalidate the build cache, so a deliberate non-secret cache-bust input is required when a
rotated secret must force re-execution. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10, S5/S7/S17.
Confidence: high.

Failure scenario: a private Composer token enters image history/cache metadata, or a rotated
credential leaves an old authenticated layer silently reused. Consumers: P1.3, P2.1–P2.4,
P11.5, P12.2.

### INF-05 — Compose secrets do not silently fall back

Compose grants each secret explicitly; top-level `file` and `environment` create Compose-managed
sources, whereas `external` delegates lookup to the platform. The current production file declares
all four secrets as `external: true` and mounts them unconditionally, so its comment that ordinary
`docker compose up` falls back to `.env` is not an executable fallback contract. **Verdict:
REFUTED.** RAG:✅ 2026-08-10, S2/S3/S4; local evidence: `docker-compose.prod.yml`.
Confidence: high.

Additionally, Compose documents that file-backed secrets use bind mounts and cannot honor `uid`,
`gid` or `mode` remapping; the default secret mode is `0444`. A single-server design must test
actual readability by the configured container user and must not infer Swarm tmpfs properties.

Failure scenario: fresh-host `docker compose up` fails before app start because external secrets
do not exist, or a file-secret is broader-readable than the plan assumes. Consumers: P2.2–P2.4,
P12.2, P12.4.

### INF-06 — health ordering is necessary but not readiness proof

Compose long-form `depends_on.condition: service_healthy` waits for the dependency healthcheck;
short syntax only guarantees startup order. The current tree uses the long form for core services,
which is a valid readiness carrier. It does not prove migrations, assets, queue behavior or
business readiness. **Verdict: CONFIRMED** for startup gating; **REFUTED** as a complete Doctor.
RAG:✅ 2026-08-10, S2; local evidence: both Compose files. Confidence: medium.

Failure scenario: `/up` becomes green before schema/assets/admin flows are usable, and deployment
reports success on a shallow container-health signal. Consumers: P1.4, P2.6, P3.3, P12.4.

### INF-07 — Ubuntu installation and firewall gate

Docker's official Ubuntu path uses Docker's signed apt repository and installs Engine, CLI,
containerd, Buildx and the Compose plugin; the convenience script is recommended only for test/dev.
Docker also warns that published ports bypass normal ufw processing and require an explicit
Docker firewall policy such as rules in the Docker-managed path. **Verdict: CONFIRMED.**
RAG:✅ 2026-08-10, S9/S10. Confidence: high.

Failure scenario: a host passes an ufw-only checklist while PostgreSQL/Redis/MinIO or the app port
is reachable beyond the intended ingress boundary. Consumers: P12.1, P12.3, P12.7.

### INF-08 — destructive production image rebuild target

`prod-pre-clean` executes `docker compose ... down --volumes`; Docker defines `--volumes` as
removing named volumes declared by the Compose file. In this tree those volumes include production
PostgreSQL, Redis, MinIO and vendor data. **Verdict: REFUTED** as a safe production rebuild
precondition. RAG:✅ 2026-08-10, S12/S13; local evidence: `docker/Makefile`,
`docker-compose.prod.yml`. Confidence: high.

Failure scenario: `make prod:deploy:image` destroys production data before the replacement image
is proven healthy; the same command path has no owner gate or restore receipt. Consumers: P1.1,
P1.4, P2.3, P12.4, P12.5.

### INF-09 — backup evidence requires restore evidence

Docker volumes persist independently of a container, and official procedures explicitly pair
backup with restore into another volume. Это подтверждает isolated restore как проверяемый
recovery carrier, но Docker documentation не задаёт универсальные off-host, retention, cadence,
RPO/RTO или audit-evidence требования. Поэтому полный claim «локальный archive не доказывает
production recoverability/retention/off-host durability» остаётся **Verdict: UNVERIFIED** до
owner/provider policy; mechanics backup→restore подтверждены. RAG:✅ 2026-08-10, S13.
Confidence: medium для mechanics, insufficient для operational policy.

Failure scenario: the only recovery set is corrupt or removed with the host/volume, but release
readiness reports “backup configured.” Consumers: P12.3, P12.5, P12.7.

### INF-10 — image identity and rollback

Docker image tags are mutable; a digest identifies immutable content. A production promotion and
rollback receipt must therefore name the resolved manifest/platform digest, not only
`agelto/app:latest`. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10, S11. Confidence: medium.

Failure scenario: the `latest` tag advances between deploy and rollback, so “rollback” pulls the
same bad or an unrelated image. Consumers: P1.3, P12.2, P12.4, P12.5.

### INF-11 — Vite dev and production are different carriers

Current Vite docs define `server.host`/HMR options for development only and warn against unrestricted
`allowedHosts`; reverse proxies must carry WebSocket traffic for HMR. Production uses `vite build`,
with `base` controlling deployed asset URLs. A first-class dev service needs a fixed port,
`0.0.0.0` container binding, explicit allowed hosts and WS/HMR wiring; production must consume a
built asset artifact rather than run the dev server. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10,
S14/S15. Confidence: medium because the project Vite version was outside declared inputs.

Failure scenario: HMR works only on the host, or an unrestricted dev server exposes source through
DNS rebinding; production assets are built for the wrong base path and return 404. Consumers: P1.5,
P3.1, P9.6, P12.4.

### INF-12 — agent-safe Make output

GNU Make 4.4.1 provides `--output-sync` to keep parallel recipe output contiguous and recommends
`GNUMAKEFLAGS` for GNU-specific non-semantic flags such as `--output-sync` and
`--no-print-directory`. It warns that behavior-changing flags such as dry-run/question/touch in
global `MAKEFLAGS` can undermine recipes. **Verdict: CONFIRMED.** RAG:✅ 2026-08-10, S16.
Confidence: medium.

Failure scenario: parallel logs interleave so an agent attributes a failure to the wrong target,
or inherited global flags make a validation target report without executing. Consumers: P1.1,
P11.2, P11.3, P12.6.

## Contradictions and rejected claims

| ID | Local/provisional claim | Verdict | Evidence-backed consequence |
|:--|:--|:--|:--|
| C1 | Production code-only deploy can coexist with immutable image rollback while source is bind-mounted. | REFUTED | P0.6/P1 must choose image-contained production code; code-only no-rebuild remains a dev property or becomes an image promotion, not host mutation. |
| C2 | `external: true` Compose secrets fall back to `.env` outside Swarm. | REFUTED | P2 must select one explicit single-server secret source and test permissions/leakage; no silent dual semantics. |
| C3 | `prod-pre-clean down --volumes` is harmless cleanup before image rebuild. | REFUTED | Remove it from the production rebuild path or put a destructive owner gate plus verified restore in front of it; current default is release-blocking. |
| C4 | `latest` tags are adequate deployment and rollback identity. | REFUTED | Promotion, deploy receipt and rollback must pin/record immutable digest and platform. |
| C5 | A green Compose health graph proves application readiness. | REFUTED | Doctor must additionally test schema, assets, workers and named application paths. |
| C6 | A backup artifact alone proves recoverability. | REFUTED | Acceptance requires an isolated restore check and separate retention/off-host policy. |
| C7 | Vite HMR and production asset publication are one lifecycle. | REFUTED | Treat dev server/HMR and immutable production build/publication as separate carriers and acceptance checks. |
| C8 | Current Dockerfile is byte-reproducible because apt update/install share a layer. | REFUTED | Syntax is cache-safe, but base/package inputs float; rebuild receipts must capture digest/package lock policy. |

No conflict was found between official Compose `service_healthy` semantics and the long-form
dependencies already present in Agelto. No official source supports weakening the project's
no-secret-leak, no-destructive-volume or restore-proof gates.

## Recommendations

These are evidence inputs for P0.6, not accepted D-records.

1. **P1.1/P1.4 — fail closed on destructive commands.** Make the production target taxonomy
   mechanically distinguish safe lifecycle operations from data-destructive operations; remove
   `down --volumes` from ordinary build/deploy paths. A destructive path needs an owner gate,
   named backup receipt and successful isolated restore.
2. **P1.2/P1.3 — split build triggers.** Keep dev source edits bind-mounted and rebuild-free.
   Production code/dependency/frontend/runtime changes produce a promoted immutable image; image
   and package/base refresh triggers remain explicit. Use cache mounts locally and a registry
   cache in CI without putting credentials into cache layers.
3. **P1.3/P12 — make digest the release identity.** Record source commit, image manifest digest,
   platform digest and configuration revision in deploy/rollback receipts; never use `latest` as
   the only rollback key.
4. **P2.1/P2.2 — freeze one secret contract per deployment mode.** For single-server Compose,
   use declared file/environment-backed sources with tested ownership/readability; for Swarm,
   use external Swarm secrets. Build-time private credentials use BuildKit secret/SSH mounts.
5. **P2.3/P12.1 — host preflight includes firewall reality.** Verify official Docker packages,
   Engine/Buildx/Compose versions, daemon health, architecture, published-port exposure and
   effective Docker firewall rules—not only ufw status.
6. **P1.5/P3.1 — split Vite carriers.** Dev: fixed port, explicit host/allowed-hosts and WS/HMR.
   Production: deterministic `vite build`, correct base path and HTTP checks for emitted assets;
   Filament/Livewire publication remains a separate acceptance surface.
7. **P11.2/P11.3 — make logs deterministic for agents.** Use GNU Make synchronized target output
   for parallel recipes, suppress recursive-directory noise where safe, and never inherit global
   dry-run/question/touch flags into validation.
8. **P12.3/P12.5 — prove recovery, not artifact existence.** Pair database/object/volume backup
   with isolated restore, retention/off-host receipt, alarm delivery and rollback rehearsal.

## Open questions

- **Decision pending — P0.6:** exact production code carrier: image-contained source is the
  evidence-backed recommendation; keeping a host source bind mount must be an explicit deviation
  with a weaker rollback/security contract.
- **Decision pending — P0.6/P2:** single-server secret source versus Swarm. The current hybrid
  comments/configuration are contradictory and cannot be frozen as-is.
- **Decision pending — owner/P12:** registry, signing/attestation and immutable digest retention
  policy for the first deployment.
- **Decision pending — owner/P12:** RPO, retention, off-host destination and restore cadence.
- `[UNVERIFIED]` Exact project Vite major compatibility: `package.json`/lockfile were not declared
  P0.3 inputs, so only current official unversioned Vite semantics were used. P1.5 must pin the
  installed major before implementation.
- `[UNVERIFIED]` Runtime behavior of the current external-secret topology was not executed: this
  item is read-only research. P2.3 must prove the selected mode with `docker compose config` and a
  clean-host preflight.

### Verification status

- RAG: `perplexity-web` login PASS + cited-search smoke PASS + four cited searches OK;
  Context7 resolve ×2 and docs query ×2 OK; official Docker/Vite/GNU primary sources only,
  checked 2026-08-10.
- Непроверено → ПОВТОРНЫЙ ПРОХОД: exact installed Vite major, real external-secret startup
  behavior and owner/provider off-host/retention/RPO policy; все три адресованы declared consumers.
- Обходки/[POSSIBLE-DEFECT]: no workaround accepted; daemon was started through its shipped
  `dist/daemon/main.js --pool=default` launcher with the session's current Xauthority, without
  config/profile edits, after the inherited MCP client exposed a stale Xauthority.
