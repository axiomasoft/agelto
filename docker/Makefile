DOCKER_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ROOT_DIR := $(abspath $(DOCKER_DIR)/..)

MODE ?= dev
ENV_FILE ?= .env
COMPOSE_DEV := docker compose --project-directory $(ROOT_DIR) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.yml
COMPOSE_PROD := docker compose --project-directory $(ROOT_DIR) --env-file $(ROOT_DIR)/$(ENV_FILE) -f $(ROOT_DIR)/docker-compose.prod.yml
AGENT_ENV := $(if $(CLAUDECODE),-e CLAUDECODE=$(CLAUDECODE)) $(if $(CURSOR_AGENT),-e CURSOR_AGENT=$(CURSOR_AGENT)) $(if $(AI_AGENT),-e AI_AGENT=$(AI_AGENT))

.DEFAULT_GOAL := help
.PHONY: help infra\:preflight dev\:up dev\:down dev\:ps dev\:logs dev\:shell dev\:artisan dev\:composer
.PHONY: agent\:artisan prod\:config prod\:ps image\:build legacy\:up legacy\:down legacy\:artisan

help:
	@echo "Agelto command surface"
	@echo ""
	@echo "Infrastructure:"
	@echo "  make infra:preflight MODE=dev ENV_FILE=.env.example  Validate Docker/Compose without mutation"
	@echo ""
	@echo "Development:"
	@echo "  make dev:up | dev:down | dev:ps | dev:logs [SERVICE=app]"
	@echo "  make dev:shell | dev:artisan ARGS=\"about\" | dev:composer ARGS=\"install\""
	@echo ""
	@echo "Agent and production inspection:"
	@echo "  make agent:artisan ARGS=\"test --compact\"  Non-TTY app command"
	@echo "  make prod:config ENV_FILE=.env.production    Render production config only"
	@echo ""
	@echo "Images:"
	@echo "  make image:build                               Build the current PHP image"
	@echo ""
	@echo "Legacy aliases (removed after P1): legacy:up, legacy:down, legacy:artisan"

infra\:preflight:
	bash $(ROOT_DIR)/docker/scripts/compose-preflight.sh --mode $(MODE) --env-file $(ROOT_DIR)/$(ENV_FILE)

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

prod\:config:
	$(COMPOSE_PROD) config

prod\:ps:
	$(COMPOSE_PROD) ps

image\:build:
	docker build -t agelto/app:latest -f $(ROOT_DIR)/docker/php/Dockerfile $(ROOT_DIR)

# Compatibility aliases remain only through the P1 migration and are covered by the contract harness.
legacy\:up: dev\:up
legacy\:down: dev\:down
legacy\:artisan: dev\:artisan
