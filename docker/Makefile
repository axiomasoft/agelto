DOCKER_DIR := $(dir $(abspath $(lastword $(MAKEFILE_LIST))))
ROOT_DIR   := $(abspath $(DOCKER_DIR)/..)
MAKEFILE_PATH := $(abspath $(lastword $(MAKEFILE_LIST)))

# ENV=dev (по умолчанию) — docker-compose.yml
# ENV=prod — docker-compose.prod.yml
#
# Примеры:
#   make up | make up ENV=prod
#   make prod:deploy:code

ENV ?= dev

COMPOSE_ENV_BASE := --env-file $(ROOT_DIR)/.env

# Dev: читаем .env.docker если существует (необязателен для dev)
DEV_ENV_FILES := $(COMPOSE_ENV_BASE)
ifeq ($(wildcard $(ROOT_DIR)/.env.docker),$(ROOT_DIR)/.env.docker)
DEV_ENV_FILES += --env-file $(ROOT_DIR)/.env.docker
endif

# Prod: читаем .env.docker.production — обязателен, без него prod-цели не запустятся
PROD_ENV_FILES := $(COMPOSE_ENV_BASE)
ifeq ($(wildcard $(ROOT_DIR)/.env.docker.production),$(ROOT_DIR)/.env.docker.production)
PROD_ENV_FILES += --env-file $(ROOT_DIR)/.env.docker.production
endif

COMPOSE_DEV  = docker compose --project-directory $(ROOT_DIR) -f $(ROOT_DIR)/docker-compose.yml $(DEV_ENV_FILES)
COMPOSE_PROD = docker compose --project-directory $(ROOT_DIR) -f $(ROOT_DIR)/docker-compose.prod.yml $(PROD_ENV_FILES)
COMPOSE      = $(if $(filter prod,$(ENV)),$(COMPOSE_PROD),$(COMPOSE_DEV))
MIGRATE_FORCE = $(if $(filter prod,$(ENV)),--force,)

PROD_EXEC = $(COMPOSE_PROD) exec -T app
MK_SELF   := $(MAKE) -f $(MAKEFILE_PATH)
MK_PROD   := $(MAKE) -f $(MAKEFILE_PATH) ENV=prod

# Проверка наличия обязательного конфига
define require-dev-config
	@test -f $(ROOT_DIR)/.env.docker || \
		(echo "Ошибка: .env.docker не найден." && \
		 echo "Скопируйте: cp .env.docker.example .env.docker" && exit 1)
endef

define require-prod-config
	@test -f $(ROOT_DIR)/.env.docker.production || \
		(echo "Ошибка: .env.docker.production не найден." && \
		 echo "Скопируйте: cp .env.docker.production.example .env.docker.production" && exit 1)
endef

# --- Алиасы prod:* ---

prod\:up: ENV=prod
prod\:up: up
prod\:down: ENV=prod
prod\:down: down
prod\:ps: ENV=prod
prod\:ps: ps
prod\:logs: ENV=prod
prod\:logs: logs
prod\:shell: ENV=prod
prod\:shell: shell
prod\:artisan: ENV=prod
prod\:artisan: artisan
prod\:composer: ENV=prod
prod\:composer: composer
prod\:db-migrate: ENV=prod
prod\:db-migrate: db-migrate
prod\:db-seed: ENV=prod
prod\:db-seed: db-seed
prod\:composer-install: prod-composer-install
prod\:clear-cache: prod-clear-cache
prod\:restart-services: prod-restart-services
prod\:workers\:restart: prod-workers-restart
prod\:deploy\:code: prod-deploy-code
prod\:deploy\:php-deps: prod-deploy-php-deps
prod\:deploy\:runtime: prod-deploy-runtime
prod\:deploy\:image: prod-deploy-image
prod\:test-php: ENV=prod
prod\:test-php: test-php
prod\:db\:create-test: ENV=prod
prod\:db\:create-test: db\:create-test
prod\:doctor: prod-doctor

##@ Общие

.PHONY: help up down build restart ps logs logs\:core
.PHONY: shell artisan composer image
.PHONY: db-migrate db-fresh db-seed db\:create-test
.PHONY: test-preflight test-php
.PHONY: composer-install clear-cache
.PHONY: prod-pre-clean prod-image prod-composer-install prod-config-cache prod-clear-cache
.PHONY: prod-restart-services prod-workers-restart
.PHONY: prod-deploy-code prod-deploy-php-deps prod-deploy-runtime prod-deploy-image
.PHONY: prod-doctor

help:
	@echo "Docker Agelto (ENV=$(ENV))"
	@echo ""
	@echo "Окружение: ENV=dev (по умолчанию) | ENV=prod"
	@echo ""
	@echo "##@ Общие"
	@echo "  make up | down | build | restart | ps"
	@echo "  make logs [SERVICE=app] | shell"
	@echo "  make artisan ARGS=\"migrate\" | composer"
	@echo "  make image — собрать образ agelto/app:latest"
	@echo "  make db-migrate | db-fresh | db-seed | db:create-test"
	@echo "  make test-preflight | test-php"
	@echo ""
	@echo "##@ Dev (ENV=dev)"
	@echo "  make composer-install | clear-cache"
	@echo "  make logs:core"
	@echo ""
	@echo "##@ Production (ENV=prod или цели prod:*)"
	@echo "  make up ENV=prod | migrate ENV=prod"
	@echo "  make prod:deploy:code     — обычное обновление кода"
	@echo "  make prod:deploy:runtime  — полный деплой (deps + миграции)"
	@echo "  make prod:deploy:image    — пересборка image + runtime"
	@echo "  make prod:deploy:php-deps — только Composer"
	@echo "  make prod:doctor          — health/status-check"

up:
	$(if $(filter prod,$(ENV)),$(call require-prod-config),$(call require-dev-config))
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

build:
	$(COMPOSE) up -d --build

restart:
	$(COMPOSE) restart

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f $(or $(SERVICE),app)

logs\:core:
	$(COMPOSE_DEV) logs -f app horizon scheduler

shell:
	$(COMPOSE) exec app bash

artisan:
	$(COMPOSE) exec $(if $(filter prod,$(ENV)),-T,) app php artisan $(ARGS)

composer:
	$(COMPOSE) exec app composer $(ARGS)

image:
	docker build -t agelto/app:latest -f $(ROOT_DIR)/docker/php/Dockerfile $(ROOT_DIR)

db-migrate:
	$(COMPOSE) exec $(if $(filter prod,$(ENV)),-T,) app php artisan migrate $(MIGRATE_FORCE)

db-fresh:
	$(COMPOSE_DEV) exec app php artisan migrate:fresh

db-seed:
	$(COMPOSE) exec $(if $(filter prod,$(ENV)),-T,) app php artisan db:seed $(if $(filter prod,$(ENV)),--force,)

db\:create-test:
	bash $(ROOT_DIR)/docker/postgres/create-agelto-test-db.sh $(if $(filter prod,$(ENV)),prod,dev)

test-preflight:
	$(COMPOSE) ps
	$(COMPOSE) exec $(if $(filter prod,$(ENV)),-T,) app php -r "new PDO('pgsql:host=postgres;port=5432;dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'DB_OK'.PHP_EOL;"

test-php:
	@$(MK_SELF) test-preflight ENV=$(ENV)
	@$(MK_SELF) db:create-test ENV=$(ENV)
	$(COMPOSE) exec $(if $(filter prod,$(ENV)),-T,) -e DB_DATABASE=agelto_test app php artisan test --compact

##@ Dev

composer-install:
	$(COMPOSE_DEV) exec app composer install

clear-cache:
	$(COMPOSE_DEV) exec app php artisan optimize:clear

##@ Production

prod-pre-clean: ENV=prod
prod-pre-clean:
	$(call require-prod-config)
	@echo "prod: очистка dev-артефактов перед prod-сборкой"
	@$(COMPOSE_PROD) down --volumes --remove-orphans 2>/dev/null || true
	@echo "prod: артефакты очищены"

prod-image:
	$(call require-prod-config)
	@docker build -t agelto/app:latest -f $(ROOT_DIR)/docker/php/Dockerfile $(ROOT_DIR)

prod-composer-install: ENV=prod
prod-composer-install:
	$(call require-prod-config)
	@echo "prod: composer install"
	$(PROD_EXEC) composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
	$(PROD_EXEC) php artisan package:discover --ansi

prod-config-cache: ENV=prod
prod-config-cache:
	$(call require-prod-config)
	@echo "prod: config:cache"
	$(PROD_EXEC) php artisan config:cache

prod-clear-cache: ENV=prod
prod-clear-cache:
	$(call require-prod-config)
	$(PROD_EXEC) php artisan optimize:clear

prod-restart-services: ENV=prod
prod-restart-services:
	$(call require-prod-config)
	$(COMPOSE_PROD) restart app horizon scheduler
	@echo "prod: ждём готовности app (до 120s)..."
	@timeout 120 sh -c 'until docker exec agelto_app curl -fsS http://127.0.0.1:8080/up >/dev/null 2>&1; do sleep 3; done' \
		|| (echo "prod: app не поднялся за 120s — проверьте docker logs agelto_app" && exit 1)
	@echo "prod: app готов"

prod-workers-restart: ENV=prod
prod-workers-restart:
	$(call require-prod-config)
	$(COMPOSE_PROD) restart horizon scheduler

prod-deploy-code: ENV=prod
prod-deploy-code:
	$(call require-prod-config)
	@echo "prod: deploy code"
	$(PROD_EXEC) php artisan optimize:clear
	@$(MK_PROD) prod-config-cache
	@$(MK_PROD) prod-restart-services

prod-deploy-php-deps: prod-composer-install

prod-deploy-runtime: ENV=prod
prod-deploy-runtime: prod-composer-install db-migrate prod-config-cache prod-restart-services
	@echo "prod: deploy runtime готов"

prod-deploy-image: ENV=prod
prod-deploy-image:
	$(call require-prod-config)
	@echo "prod: rebuild image"
	@$(MK_SELF) prod-pre-clean
	@$(MK_SELF) prod-image
	$(COMPOSE_PROD) up -d --force-recreate app horizon scheduler
	@$(MK_PROD) prod-deploy-runtime

prod-doctor: ENV=prod
prod-doctor:
	$(call require-prod-config)
	@echo "== prod compose ps =="
	@$(COMPOSE_PROD) ps
	@echo ""
	@echo "== app health (/up) =="
	$(PROD_EXEC) sh -lc 'curl -fsS http://127.0.0.1:8080/up >/dev/null && echo "OK: /up" || echo "FAIL: /up"'
	@echo ""
	@echo "== vendor dir =="
	$(PROD_EXEC) sh -lc '[ -d vendor ] && echo "OK: vendor" || echo "MISSING: vendor"'
	@echo ""
	@echo "== services =="
	@for svc in agelto_app agelto_horizon agelto_scheduler; do \
		status=$$(docker inspect --format='{{.State.Status}}' $$svc 2>/dev/null || echo "not found"); \
		printf "%-28s %s\n" "$$svc:" "$$status"; \
	done

# Обратная совместимость со старыми именами
migrate: db-migrate
fresh: db-fresh
seed: db-seed
test: test-php
