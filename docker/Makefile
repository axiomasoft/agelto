DOCKER_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ROOT_DIR := $(abspath $(DOCKER_DIR)/..)

MODE ?= dev
ENV_FILE ?= .env
COMPOSE_PROJECT_NAME ?= agelto_dev
IMAGE_TAG ?=
COMPOSE_DEV := docker compose --project-directory $(ROOT_DIR) --project-name $(COMPOSE_PROJECT_NAME) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.yml
COMPOSE_PROD := docker compose --project-directory $(ROOT_DIR) --project-name $(COMPOSE_PROJECT_NAME) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.prod.yml
AGENT_ENV := $(if $(CLAUDECODE),-e CLAUDECODE=$(CLAUDECODE)) $(if $(CURSOR_AGENT),-e CURSOR_AGENT=$(CURSOR_AGENT)) $(if $(AI_AGENT),-e AI_AGENT=$(AI_AGENT))

.DEFAULT_GOAL := help
.PHONY: help infra\:preflight secrets\:init dev\:up dev\:down dev\:ps dev\:logs dev\:shell dev\:artisan dev\:composer
.PHONY: agent\:artisan vendor\:sync vendor\:check prod\:config prod\:ps prod\:deploy\:code prod\:deploy\:php-deps prod\:deploy\:frontend prod\:deploy\:runtime prod\:image\:promote prod\:deploy\:image prod\:migrate image\:build

help:
	@echo "Agelto command surface"
	@echo ""
	@echo "Infrastructure:"
	@echo "  make secrets:init                       Create ignored internal Compose secret files"
	@echo "  make infra:preflight MODE=dev ENV_FILE=.env.example  Validate Docker/Compose without mutation"
	@echo ""
	@echo "Development:"
	@echo "  make dev:up | dev:down | dev:ps | dev:logs [SERVICE=app]"
	@echo "  make dev:shell | dev:artisan ARGS=\"about\" | dev:composer ARGS=\"install\""
	@echo "  make vendor:sync | vendor:check             Clone/check the pinned private AzGuard packages"
	@echo ""
	@echo "Agent and production inspection:"
	@echo "  make agent:artisan ARGS=\"test --compact\"  Non-TTY app command"
	@echo "  make prod:config ENV_FILE=.env.production    Render production config only"
	@echo "  make prod:deploy:<class> IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=<project>"
	@echo "    classes: code, php-deps, frontend, runtime; image promotion: prod:image:promote"
	@echo "  make prod:deploy:image IMAGE_TAG=<immutable-tag> COMPOSE_PROJECT_NAME=<project>"
	@echo ""
	@echo "Images:"
	@echo "  make image:build                               Build the current PHP image"
	@echo ""

infra\:preflight:
	bash $(ROOT_DIR)/docker/scripts/preflight.sh --root $(ROOT_DIR) --mode $(MODE) --env-file $(abspath $(ENV_FILE))

secrets\:init:
	bash $(ROOT_DIR)/docker/scripts/secrets-init.sh --root $(ROOT_DIR)

dev\:up:
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

agent\:artisan:
	$(COMPOSE_DEV) exec -T $(AGENT_ENV) app php artisan $(ARGS)

vendor\:sync:
	bash $(ROOT_DIR)/docker/scripts/vendor-sync.sh --composer-check

vendor\:check:
	bash $(ROOT_DIR)/docker/scripts/vendor-sync.sh --check --composer-check

prod\:config:
	$(COMPOSE_PROD) config

prod\:ps:
	$(COMPOSE_PROD) ps

image\:build:
	@test -n "$(IMAGE_TAG)" || (echo "IMAGE_TAG is required" >&2; exit 2)
	docker build --target application -t agelto/app:$(IMAGE_TAG) -f $(ROOT_DIR)/docker/php/Dockerfile $(ROOT_DIR)

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
