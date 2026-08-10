# P0.1 — Agelto inventory

## Method and evidence boundary

This is a read-only baseline of the supplied P0.1 inputs. `available` means a carrier and a
directly observed command/configuration exist; it does not assert that a production deployment
has succeeded. `behind-gate` means the carrier is present but needs configuration or a runtime
proof. `disabled` means the supplied evidence deliberately shows only a placeholder. No external
best-practice claim or target architecture is made here. RAG:— (repo-grounded:
`phases/P0/P0.1.md:3-12`, `findings/bootstrap-recon-2026-08-09.md:1-45`).

## Docker and Make

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| Root Makefile delegates every target to the Docker Makefile. | One entrypoint exists for the listed Docker targets. | available | The target list is a command catalogue, not a proof that every target works on a clean host. | P1, P11, P12 |
| Docker Makefile selects dev or prod Compose and checks the corresponding env file before `up`. | Dev uses `.env` plus optional `.env.docker`; prod additionally requires `.env.docker.production`. | behind-gate | The base `.env` is always required; a verified fresh-host/bootstrap path is not supplied. | P1.1, P2.3, P12 |
| Dev Compose builds `app`, `horizon`, and `scheduler` from the PHP Dockerfile and bind-mounts the project. | Source edits are visible in the containers; build/rebuild remains an explicit target. | available | The browser service is an intentional empty Node container, not an enabled rendering capability. | P1.2, P3, P8 |
| Production Compose names an app image and uses the shared `vendor_data` volume. | Runtime app services consume the same image and vendor volume. | behind-gate | Image provenance and promotion are not evidenced by this inventory. | P1.3, P12 |

Evidence: `Makefile:1-8`, `docker/Makefile:12-31`, `docker/Makefile:104-139`,
`docker-compose.yml:4-27`, `docker-compose.yml:227-236`, `docker-compose.prod.yml:4-16`.

## Deploy and bootstrap

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| Production commands cover image build, Composer install, config cache, restart, and several deploy variants. | `prod-deploy` pulls services, optionally pauses Horizon, migrates, terminates workers, starts services, and checks Horizon. | available | It is not a recorded first deployment from an empty Ubuntu host. | P1, P2.4, P12 |
| The PHP image provides the system tools and PHP extensions needed by the application pipeline. | Image build installs document/OCR utilities and database/Redis/GD/PCOV extensions. | available | The image is mutable at package-install time; reproducible image/digest policy remains for later phases. | P1.3, P6, P12 |
| Composer has path repositories for three private AzGuard packages. | Dependency resolution relies on paths outside this repository and does not symlink them. | behind-gate | Clone/layout and provenance requirements for those packages are not part of the current bootstrap proof. | P2.1, P2.4, P12.2 |

Evidence: `docker/Makefile:210-230`, `docker/Makefile:246-296`,
`docker/php/Dockerfile:1-47`, `composer.json#repositories`.

## Secrets and preflight

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| Production Compose declares four external Swarm secrets and supplies `*_FILE` paths to app services. | The app, Horizon, scheduler, and MinIO have a file-secret carrier in the prod topology. | behind-gate | The Compose comments state ordinary Compose without Swarm falls back to `.env`; there is no generated-secret/preflight or leakage-test proof yet. | P2.2, P2.3, P12.4 |
| Entrypoint script reads only `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, and `MINIO_ROOT_PASSWORD` from readable files. | It exports the value and unsets the `*_FILE` variable. | available | The prod Compose uses `AWS_SECRET_ACCESS_KEY_FILE`, which is outside this script’s four-name loop; this inventory records a carrier mismatch rather than assuming a working mapping. | P2.2, P2.3 |
| Test preflight checks Compose status and a PostgreSQL PDO connection. | `test-php` also creates the test database and sets its database name for the Laravel test command. | behind-gate | It is a runtime command and was not run in this read-only baseline. | P11, P12 |

Evidence: `docker-compose.prod.yml:17-64`, `docker-compose.prod.yml:241-250`,
`docker/php/entrypoint.d/10-secrets.sh:1-15`, `docker/Makefile:184-191`,
`.env.docker.production.example:1-17`.

## Assets and readiness

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| App, Horizon, and scheduler expose healthchecks; app calls `/up`. | Service dependency conditions wait for the app and backing services to be healthy. | behind-gate | Configuration is present, but no clean runtime evidence proves the readiness path. | P3, P11, P12 |
| Composer publishes Laravel assets after `composer update`; the prod dependency command explicitly suppresses scripts then runs package discovery. | There is an asset-publication hook and a separate production dependency path. | behind-gate | The supplied carrier does not prove a Livewire/Filament asset request or establish the cause of any 404. | P3.1, P9 |
| Development documentation assigns Vite HMR to host-side Yarn. | `composer dev` also names a Yarn Vite process. | available | No Docker Make target makes Vite a first-class container workflow. | P1.5, P3.1 |

Evidence: `docker-compose.yml:57-66`, `docker-compose.prod.yml:55-64`,
`composer.json#scripts.post-update-cmd`, `composer.json#scripts.dev`,
`docs/03_Dev/docker/development.md:21-27`, `docs/03_Dev/docker/production.md:9-16`.

## Protected docs

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| The supplied baseline contains Docker documentation only. | Development and production Docker pages link to general project material but define no protected-document route or access policy. | disabled | A private-document serving and authorization boundary is not evidenced; P4 must establish it without treating the absence as a product decision. | P4, P12 |
| Bootstrap recon describes Vintera’s handbook route as a reference and rejects its non-HTML asset authorization boundary for Agelto. | This is reference evidence, not an Agelto implementation. | disabled | Do not reuse that pattern as proof that private document assets are protected. | P4 |

Evidence: `docs/03_Dev/docker/development.md:29-31`, `docs/03_Dev/docker/production.md:27-30`,
`findings/bootstrap-recon-2026-08-09.md:25-37`.

## Proxy

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| No proxy configuration, resolver invocation, or health carrier is present in the supplied P0.1 baseline inputs. | No runtime-proxy capability is inferred from the browser placeholder or Docker networking. | disabled | Proxy precedence, secret treatment, routing semantics, and health separation require dedicated evidence in P5. | P5, P12 |

Evidence boundary: `docker-compose.yml:227-236`, `findings/bootstrap-recon-2026-08-09.md:8-16`,
`phases/P0/P0.1.md:10-12`.

## Documents and extension seams

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| The PHP image includes QPDF, LibreOffice, archive utilities, Poppler, Tesseract, OCRmyPDF, and Ghostscript. | Runtime image has tool carriers for document conversion, archive handling, and OCR. | available | Installed utilities do not prove a safe archive/document policy, type registry, or successful processing route. | P6, P7, P10 |
| Composer requires mPDF, PhpSpreadsheet, Symfony DOM/CSS components, and S3 Flysystem support. | Application dependencies contain document and storage carriers. | available | Dependency presence does not establish extension taxonomy, archive limits, or platform mapping. | P6, P8, P10 |
| Prod configuration selects S3/MinIO storage and separate estimates bucket settings. | Object-storage carrier is available in the Compose topology. | behind-gate | Bucket provisioning and end-to-end document transfer have no runtime proof in this inventory. | P6, P12 |

Evidence: `docker/php/Dockerfile:7-30`, `composer.json#require`,
`docker-compose.prod.yml:42-54`, `docker-compose.prod.yml:208-229`.

## Tests and CI

| Current carrier | Observed behaviour | Maturity | Gap / risk | Consumers |
|:--|:--|:--|:--|:--|
| Composer defines a Laravel test script; the Docker Makefile supplies preflight and an isolated test-database command. | Tests can be invoked through Composer or Docker; the Docker path sets `DB_DATABASE=agelto_test`. | behind-gate | Neither command was executed by this read-only inventory, so suite health is unproven. | P11, P12 |
| Composer development dependencies include Pest 5 and the Laravel integration. | Test-framework dependencies are declared. | behind-gate | No CI workflow was included among P0.1 inputs, so CI gates and execution topology are not evidenced. | P11 |
| The dev compose has a named Pest TIA volume. | There is a test-performance carrier in development. | behind-gate | Its correctness and reproducibility are not demonstrated by a test run. | P11 |

Evidence: `composer.json#scripts.test`, `composer.json#require-dev`,
`docker/Makefile:184-199`, `docker-compose.yml:20-27`.

## Command inventory and consumer map

| Command | Purpose | Evidence level | Downstream use |
|:--|:--|:--|:--|
| `make up` / `make up ENV=prod` | Start the selected Compose topology after its env-file check. | carrier only | P1, P12 |
| `make prod:deploy`, `make prod:deploy:code`, `make prod:deploy:runtime`, `make prod:deploy:image` | Existing deployment variants. | carrier only | P1, P12 |
| `make prod:doctor` | Report Compose state, `/up`, vendor presence, and service states. | carrier only | P11, P12 |
| `make test-preflight` / `make test-php` | Database connectivity and Laravel test execution. | carrier only | P11 |
| `python3 plans/verify-citations.py plans/2026.08.09-RELEASE-READINESS --strict` | Validate repository citations in P0 findings. | executed green in this item | P0.2–P0.6 |

The matrix deliberately distinguishes executable carriers from demonstrated capabilities. The next
baseline consumer is P0.2; target decisions and external verification remain with P0.3–P0.6.
RAG:— (repo-grounded: `phases/P0/P0.md:27-37`, `phases/P0/P0.1.md:3-12`).
