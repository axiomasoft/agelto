DOCKER_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ROOT_DIR := $(abspath $(DOCKER_DIR)/..)

MODE ?= dev
ENV_FILE ?= .env
COMPOSE_PROJECT_NAME ?= agelto_dev
IMAGE_TAG ?=
COMPOSE_DEV := docker compose --project-directory $(ROOT_DIR) --project-name $(COMPOSE_PROJECT_NAME) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.yml
COMPOSE_PROD := docker compose --project-directory $(ROOT_DIR) --project-name $(COMPOSE_PROJECT_NAME) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.prod.yml
AGENT_ENV := $(if $(CLAUDECODE),-e CLAUDECODE=$(CLAUDECODE)) $(if $(CLAUDE_CODE),-e CLAUDE_CODE=$(CLAUDE_CODE)) $(if $(CURSOR_AGENT),-e CURSOR_AGENT=$(CURSOR_AGENT)) $(if $(CODEX_THREAD_ID),-e CODEX_THREAD_ID=$(CODEX_THREAD_ID)) $(if $(AI_AGENT),-e AI_AGENT=$(AI_AGENT))

# The dev app container pointed at the isolated test database; every test target runs through it.
DEV_TEST_APP = $(COMPOSE_DEV) exec -T $(AGENT_ENV) -e APP_ENV=testing -e DB_DATABASE=agelto_test -e DB_HOST=postgres app

# Object-store bootstrap (dev:storage-init, prod:storage:init): the script and its policy
# template are mounted read-only into a one-off app container next to the root secret.
STORAGE_BOOTSTRAP := /run/agelto/storage/bootstrap.sh
STORAGE_BOOTSTRAP_MOUNTS := -v "$(ROOT_DIR)/local/secrets/minio_root_password:/run/secrets/minio_root_password:ro" \
	-v "$(ROOT_DIR)/docker/scripts/storage-bootstrap.sh:$(STORAGE_BOOTSTRAP):ro" \
	-v "$(ROOT_DIR)/docker/storage/app-policy.json:/run/agelto/storage/app-policy.json:ro"

.DEFAULT_GOAL := help
.PHONY: help infra\:preflight secrets\:init bootstrap host\:check host\:apply host\:preflight dev\:vpn-bypass dev\:up dev\:down dev\:ps dev\:logs dev\:shell dev\:artisan dev\:composer dev\:storage-init dev\:health
.PHONY: agent\:artisan vendor\:sync vendor\:check repositories\:prepare repositories\:sync doctor\:local readiness\:check assets\:check assets\:sync assets\:repair documentation\:check documentation\:acceptance quality prod\:doctor prod\:infra\:up prod\:app\:up prod\:storage\:init prod\:workers\:up prod\:admin\:create prod\:readiness dev\:migrate dev\:seed-base dev\:seed-demo dev\:admin-create dev\:admin-reset dev\:composer-bootstrap prod\:config prod\:ps prod\:deploy\:code prod\:deploy\:php-deps prod\:deploy\:frontend prod\:deploy\:runtime prod\:image\:promote prod\:deploy\:image prod\:migrate prod\:seed-base image\:build test-preflight test-php test\:edit test\:full test\:junit test\:profile test\:coverage test\:release test\:tia-reset test\:tia-refresh test\:tia-baseline-export test\:shard test\:parallel db\:create-test preparation\:check
.PHONY: profile\:check backup\:check first-deploy\:check release\:check rollback\:check restore\:check rotation\:check commands\:check
.PHONY: core-manifest-sync

help:
	@echo "Agelto command surface"
	@echo ""
	@echo "Infrastructure:"
	@echo "  make secrets:init                       Create ignored internal Compose secret files"
	@echo "  make infra:preflight MODE=dev ENV_FILE=.env.example  Validate Docker/Compose without mutation"
	@echo "  make bootstrap MODE=dev|prod-build ENV_FILE=.env.example  Run resumable local bootstrap"
	@echo "  make host:check PROFILE=docker/host/production-profile     Read-only Ubuntu host checks"
	@echo "  make host:apply PROFILE=... CONFIRM=apply                  Provision a fresh Ubuntu 24.04 host"
	@echo "  make host:preflight PROFILE=...                            Read-only host runtime proof"
	@echo ""
	@echo "Development:"
	@echo "  make dev:up | dev:down | dev:ps | dev:logs [SERVICE=app]"
	@echo "  make dev:shell | dev:artisan ARGS=\"about\" | dev:composer ARGS=\"install\""
	@echo "  make vendor:sync | vendor:check             Clone/check the pinned private AzGuard packages"
	@echo "  sudo make repositories:prepare PROFILE=...   Create separate GitHub deploy-key material"
	@echo "  sudo make repositories:sync PROFILE=...     Verify enrollment and sync pinned source"
	@echo "  make profile:check PROFILE=...               Validate the closed production profile"
	@echo "  make backup:check | first-deploy:check       Inspect local preparation stage contracts"
	@echo "  make release:check | rollback:check          Inspect release/recovery stage contracts"
	@echo "  make restore:check TARGET=agelto_rehearsal   Validate an isolated restore target"
	@echo "  make rotation:check | commands:check         Verify credential and command matrices"
	@echo "  make preparation:check                       Run the aggregate local preparation gate"
	@echo "  make db:create-test | test-preflight        Create/check the isolated PostgreSQL test database"
	@echo "  make test:edit | test:full | test:junit | test:profile   Local TIA replay or full no-TIA PHP suite"
	@echo "  make test:coverage | test:tia-refresh       Full no-TIA coverage or explicit local TIA refresh"
	@echo "  make test:release                            Collect and validate no-TIA release-quality evidence"
	@echo "  make test:shard SHARD=1/2                   One full no-TIA time-balanced shard"
	@echo "  make test:parallel PROCESSES=2|4            Guarded isolated parallel Pest proof"
	@echo "  make test:tia-reset                         Remove only the project TIA graph"
	@echo "  make quality                                Run the deterministic local release-quality gate"
	@echo "  make core-manifest-sync                     Refresh immutable contract and gold fixture hashes"
	@echo ""
	@echo "Agent and production inspection:"
	@echo "  make agent:artisan ARGS=\"test --compact\"  Non-TTY app command"
	@echo "  make prod:config ENV_FILE=.env.production    Render production config only"
	@echo "  make prod:infra:up ...                       Build PostgreSQL once; start and wait for data services"
	@echo "  make prod:migrate OWNER_GATE=approved ...    Apply schema after an explicit migration decision"
	@echo "  make prod:app:up | prod:storage:init | prod:workers:up | prod:readiness"
	@echo "  make prod:seed-base ... | prod:admin:create PASSWORD_FILE=..."
	@echo "  make prod:deploy:<class> IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=<project>"
	@echo "    classes: code, php-deps, frontend, runtime; image promotion: prod:image:promote"
	@echo "  make prod:deploy:image IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=<project>"
	@echo ""
	@echo "Images:"
	@echo "  make image:build                               Build the current PHP image"
	@echo "  make documentation:check                       Verify VitePress output and its immutable image boundary"
	@echo ""

infra\:preflight:
	bash $(ROOT_DIR)/docker/scripts/preflight.sh --root $(ROOT_DIR) --mode $(MODE) --env-file $(abspath $(ENV_FILE))

core-manifest-sync:
	python3 $(ROOT_DIR)/docker/tests/process-core/fixture_manifest.py $(ROOT_DIR) --sync

secrets\:init:
	bash $(ROOT_DIR)/docker/scripts/secrets-init.sh --root $(ROOT_DIR)

bootstrap:
	bash $(ROOT_DIR)/docker/scripts/bootstrap.sh --root $(ROOT_DIR) --mode $(MODE) --env-file $(ENV_FILE) --image-tag "$(IMAGE_TAG)" --project-name "$(COMPOSE_PROJECT_NAME)" $(if $(ADMIN_PASSWORD_FILE),--admin-password-file "$(ADMIN_PASSWORD_FILE)")

host\:check:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/host-provision.sh check --profile "$(abspath $(PROFILE))"

host\:apply:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	@test "$(CONFIRM)" = apply || (echo "CONFIRM=apply is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/host-provision.sh apply --profile "$(abspath $(PROFILE))" --confirm

host\:preflight:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/host-preflight.sh --profile "$(abspath $(PROFILE))"

dev\:vpn-bypass:
	bash $(ROOT_DIR)/docker/scripts/dev-vpn-bypass.sh $(ROOT_DIR)/$(ENV_FILE)

dev\:up: dev\:vpn-bypass
	$(COMPOSE_DEV) up -d

dev\:down:
	$(COMPOSE_DEV) down

dev\:ps:
	$(COMPOSE_DEV) ps

dev\:logs:
	$(COMPOSE_DEV) logs -f $(or $(SERVICE),app)

dev\:shell:
	$(COMPOSE_DEV) exec app bash

dev\:artisan:
	$(COMPOSE_DEV) exec app php artisan $(ARGS)

dev\:composer:
	$(COMPOSE_DEV) exec app composer $(ARGS)

dev\:composer-bootstrap:
	$(COMPOSE_DEV) run --rm --no-deps app composer $(ARGS)

dev\:storage-init:
	$(COMPOSE_DEV) run --rm --no-deps --entrypoint sh -e MINIO_ROOT_USER $(STORAGE_BOOTSTRAP_MOUNTS) app $(STORAGE_BOOTSTRAP) agelto-estimates

dev\:migrate:
	$(COMPOSE_DEV) exec -T app php artisan migrate --force

dev\:seed-base:
	$(COMPOSE_DEV) exec -T app php artisan db:seed --class=Database\\Seeders\\BaseSeeder --force

dev\:seed-demo:
	$(COMPOSE_DEV) exec -T app php artisan db:seed --class=Database\\Seeders\\DemoSeeder

dev\:admin-create:
	@test -n "$(PASSWORD_FILE)" || (echo "PASSWORD_FILE is required" >&2; exit 2)
	$(COMPOSE_DEV) run --rm -v "$(abspath $(PASSWORD_FILE)):/run/secrets/initial_admin_password:ro" app php artisan admin:create-initial --password-file=/run/secrets/initial_admin_password

dev\:admin-reset:
	@test -n "$(PASSWORD_FILE)" || (echo "PASSWORD_FILE is required" >&2; exit 2)
	$(COMPOSE_DEV) run --rm -v "$(abspath $(PASSWORD_FILE)):/run/secrets/initial_admin_password:ro" app php artisan admin:reset-password --force --password-file=/run/secrets/initial_admin_password

dev\:health:
	$(COMPOSE_DEV) exec -T app curl -fsS http://127.0.0.1:8080/up >/dev/null

agent\:artisan:
	$(COMPOSE_DEV) exec -T $(AGENT_ENV) app php artisan $(ARGS)

db\:create-test:
	bash $(ROOT_DIR)/docker/scripts/test-database.sh --root $(ROOT_DIR) --env-file $(ENV_FILE) --project-name $(COMPOSE_PROJECT_NAME) --create --phase identity

test-preflight:
	bash $(ROOT_DIR)/docker/scripts/test-database.sh --root $(ROOT_DIR) --env-file $(ENV_FILE) --project-name $(COMPOSE_PROJECT_NAME)

.PHONY: test-identity
test-identity:
	bash $(ROOT_DIR)/docker/scripts/test-database.sh --root $(ROOT_DIR) --env-file $(ENV_FILE) --project-name $(COMPOSE_PROJECT_NAME) --phase identity

test-php: test-identity
	$(DEV_TEST_APP) php artisan migrate:fresh --force --no-interaction
	$(DEV_TEST_APP) php artisan test --compact --no-tia

test\:edit: test-preflight
	$(DEV_TEST_APP) php artisan test --compact

test\:full: test-preflight
	$(DEV_TEST_APP) php artisan test --ci --no-tia --compact

test\:junit: test-preflight
	$(DEV_TEST_APP) sh -ec 'mkdir -p storage/app/ci && php artisan test --ci --no-tia --compact --log-junit=storage/app/ci/junit.xml'

test\:profile: test-preflight
	$(DEV_TEST_APP) php artisan test --ci --no-tia --compact --profile

test\:coverage: test-preflight
	$(DEV_TEST_APP) sh -ec 'mkdir -p storage/app/coverage && php artisan test --ci --no-tia --compact --coverage-clover=storage/app/coverage/clover.xml --log-junit=storage/app/coverage/junit.xml'

test\:release:
	RELEASE_QUALITY_EXECUTE=1 COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME) bash $(ROOT_DIR)/docker/tests/release-quality-contract.sh

test\:tia-reset:
	$(COMPOSE_DEV) exec -T app sh -ec 'find /var/www/.pest -mindepth 1 -maxdepth 1 -type d -name tia -exec rm -rf {} +'

test\:tia-refresh: test-preflight
	$(DEV_TEST_APP) php artisan test --tia --fresh --ci --compact

test\:tia-baseline-export:
	rm -rf $(ROOT_DIR)/.ci-artifacts/tia-baseline
	$(COMPOSE_DEV) cp app:/var/www/.pest/tia $(ROOT_DIR)/.ci-artifacts/tia-baseline

test\:shard: test-preflight
	@test -n "$(SHARD)" || (echo 'SHARD=1/2 is required' >&2; exit 2)
	$(DEV_TEST_APP) php artisan test --ci --no-tia --compact --shard=$(SHARD)

test\:parallel: test-preflight
	@test "$(PROCESSES)" = 2 || test "$(PROCESSES)" = 4 || (echo 'PROCESSES must be 2 or 4' >&2; exit 2)
	$(DEV_TEST_APP) ./vendor/bin/pest --parallel --processes=$(PROCESSES) --no-tia --compact

quality:
	python3 $(ROOT_DIR)/docker/tests/process-core/rig.py core-quality

profile\:check:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/production-profile-check.sh check --profile "$(abspath $(PROFILE))"

backup\:check:
	bash $(ROOT_DIR)/docker/scripts/backup-production.sh check

first-deploy\:check:
	bash $(ROOT_DIR)/docker/scripts/first-production-deploy.sh check

release\:check:
	bash $(ROOT_DIR)/docker/scripts/release-production.sh check

rollback\:check:
	bash $(ROOT_DIR)/docker/scripts/rollback-production.sh check

restore\:check:
	@test -n "$(TARGET)" || (echo "TARGET=agelto_rehearsal is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/restore-production.sh check --target "$(TARGET)"

rotation\:check:
	bash $(ROOT_DIR)/docker/scripts/rotate-production-secrets.sh check

commands\:check:
	@command_matrix_path=$$(mktemp /tmp/agelto-command-matrix.XXXXXX.md); \
	trap 'unlink "$$command_matrix_path"' EXIT; \
	python3 $(ROOT_DIR)/docker/scripts/check-command-reference.py render --output "$$command_matrix_path" && \
	python3 $(ROOT_DIR)/docker/scripts/check-command-reference.py check --output "$$command_matrix_path"

preparation\:check:
	bash $(ROOT_DIR)/docker/tests/production-preparation-contract.sh

doctor\:local:
	$(COMPOSE_DEV) exec -T app php artisan doctor --format=agent --no-interaction

readiness\:check:
	$(COMPOSE_DEV) exec -T app php artisan app:readiness --format=agent

assets\:check:
	COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME) ENV_FILE=$(ENV_FILE) bash $(ROOT_DIR)/docker/tests/assets-contract.sh check

assets\:sync:
	COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME) ENV_FILE=$(ENV_FILE) bash $(ROOT_DIR)/docker/tests/assets-contract.sh sync

assets\:repair:
	COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME) ENV_FILE=$(ENV_FILE) bash $(ROOT_DIR)/docker/tests/assets-contract.sh repair

documentation\:check:
	bash $(ROOT_DIR)/docker/tests/documentation-build-contract.sh

documentation\:acceptance:
	bash $(ROOT_DIR)/docker/tests/private-documentation-acceptance-contract.sh

vendor\:sync:
	bash $(ROOT_DIR)/docker/scripts/vendor-sync.sh --composer-check

vendor\:check:
	bash $(ROOT_DIR)/docker/scripts/vendor-sync.sh --check --composer-check

repositories\:prepare:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/deploy-repositories.sh prepare --profile "$(abspath $(PROFILE))"

repositories\:sync:
	@test -n "$(PROFILE)" || (echo "PROFILE is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/deploy-repositories.sh sync --profile "$(abspath $(PROFILE))" --enrollment-confirmed

prod\:config:
	$(COMPOSE_PROD) config

prod\:ps:
	$(COMPOSE_PROD) ps

prod\:infra\:up:
	$(COMPOSE_PROD) up -d --no-build --wait postgres redis redis-cache redis-session minio

prod\:app\:up:
	$(COMPOSE_PROD) up -d --no-build --wait app

prod\:storage\:init:
	$(COMPOSE_PROD) run --rm --no-deps --entrypoint sh -e MINIO_ROOT_USER $(STORAGE_BOOTSTRAP_MOUNTS) app $(STORAGE_BOOTSTRAP)

prod\:workers\:up:
	$(COMPOSE_PROD) up -d --no-build --wait horizon scheduler process-relay process-reconciler process-feeder process-projector

image\:build:
	@test -n "$(IMAGE_TAG)" || (echo "IMAGE_TAG is required" >&2; exit 2)
	bash $(ROOT_DIR)/docker/scripts/image-pair.sh --root $(ROOT_DIR) --tag "$(IMAGE_TAG)"

prod\:deploy\:code:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh code $(ROOT_DIR) $(ENV_FILE)

prod\:deploy\:php-deps:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh php-deps $(ROOT_DIR) $(ENV_FILE)

prod\:deploy\:frontend:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh frontend $(ROOT_DIR) $(ENV_FILE)

prod\:deploy\:runtime:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh runtime $(ROOT_DIR) $(ENV_FILE)

prod\:image\:promote:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh image-promotion $(ROOT_DIR) $(ENV_FILE)

prod\:deploy\:image:
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh image-promotion $(ROOT_DIR) $(ENV_FILE)
	bash $(ROOT_DIR)/docker/scripts/release-deploy.sh runtime $(ROOT_DIR) $(ENV_FILE)

prod\:migrate:
	@test "$(OWNER_GATE)" = approved || (echo "OWNER_GATE=approved is required for migrations" >&2; exit 2)
	$(COMPOSE_PROD) run --rm --no-deps app php artisan migrate --force

prod\:seed-base:
	$(COMPOSE_PROD) run --rm --no-deps app php artisan db:seed --class=Database\\Seeders\\BaseSeeder --force

prod\:admin\:create:
	@test -n "$(PASSWORD_FILE)" || (echo "PASSWORD_FILE is required" >&2; exit 2)
	$(COMPOSE_PROD) run --rm --no-deps -v "$(abspath $(PASSWORD_FILE)):/run/secrets/initial_admin_password:ro" app sh -ec 'php artisan admin:create-initial --email="$$ADMIN_EMAIL" --password-file=/run/secrets/initial_admin_password'

prod\:readiness:
	$(COMPOSE_PROD) exec -T app php artisan app:readiness --format=agent

prod\:doctor:
	$(COMPOSE_PROD) run --rm --no-deps doctor php artisan doctor --format=agent --fail-on=warn --no-interaction
