.PHONY: help up down build restart ps logs logs\:core logs\:reverb logs\:scheduler logs\:horizon logs\:queue logs\:vite shell artisan composer yarn db-migrate db-fresh db-seed test-php test-preflight composer-install frontend-dev frontend-dev-reset frontend-fix-wayfinder frontend-stop frontend-build docs-build docs-preview docs-up docs-down docs-deploy prod\:artisan prod\:db-migrate prod\:db-seed prod\:test-php prod\:clear-cache prod\:composer-install prod\:frontend-build prod\:docs-build prod\:docs-preview prod\:docs-up prod\:docs-down prod\:restart-services prod\:workers\:restart prod\:deploy\:code prod\:deploy\:php-deps prod\:deploy\:frontend prod\:deploy\:runtime prod\:deploy\:image prod\:quick-deploy prod\:full-build prod\:release prod\:hotfix prod\:doctor clear-cache reverb-install reverb-restart scheduler-restart horizon-restart schedule-list image db\:create-test prod\:db\:create-test migrate fresh seed test install build-frontend logs-reverb logs-scheduler logs-horizon queue-restart

# Dev compose подхватывает .env (приложение) и .env.docker (порты/uid/gid).
# При --env-file docker compose перестаёт автоматически читать .env.
DOCKER_COMPOSE_DEV := docker compose
ifeq ($(wildcard .env.docker),.env.docker)
DOCKER_COMPOSE_DEV := docker compose --env-file .env --env-file .env.docker
endif

DOCS_PORT := $(or $(shell awk -F= '/^DOCS_PORT=/{print $$2}' .env 2>/dev/null),4173)

help:
	@echo "COMMON (dev/prod):"
	@echo "  make image — собрать образ agelto/app:latest"
	@echo "  make db-migrate | make prod:db-migrate — выполнить миграции"
	@echo "  make db-seed | make prod:db-seed       — выполнить сиды"
	@echo "  make test-php | make prod:test-php     — запустить PHP-тесты"
	@echo "  make test-preflight                    — pre-flight Docker+DB перед тестами"
	@echo "  make clear-cache | make prod:clear-cache — очистить кэши Laravel"
	@echo "  make db:create-test | make prod:db:create-test — создать БД agelto_test"
	@echo "DEV (docker compose из корня репозитория):"
	@echo "  make up | make down | make build   — поднять / остановить всё (down) / пересобрать"
	@echo "  make db-fresh — пересоздать схему БД (только dev)"
	@echo "  make composer-install — установить/обновить Composer- и yarn-зависимости (dev)"
	@echo "  make frontend-dev      — поднять frontend dev-server (yarn dev в vite)"
	@echo "  make frontend-dev-reset — восстановить frontend dev после конфликтов/падений"
	@echo "  make frontend-fix-wayfinder — очистить wayfinder cache и пересобрать типы маршрутов"
	@echo "  make frontend-stop     — остановить только vite-сервис"
	@echo "  make frontend-build    — собрать production frontend assets (yarn build)"
	@echo "  make docs-build       — собрать VitePress-документацию (dev)"
	@echo "  make docs-up          — поднять docs-сервис (собирает и отдает preview в браузер)"
	@echo "  make docs-down        — остановить docs-сервис"
	@echo "  make docs-deploy      — docs-build + docs-down + docs-up"
	@echo "  make logs | make logs:core | make logs:vite | make shell"
	@echo "PROD (использует docker-compose.prod.yml):"
	@echo "  make prod:deploy:image    — deployment с пересборкой runtime-image"
	@echo "  make prod:deploy:code     — deployment изменений кода (clear-cache + restart)"
	@echo "  make prod:deploy:runtime  — deployment без image (code + deps + assets)"
	@echo "  make prod:deploy:php-deps — только PHP-зависимости (composer install)"
	@echo "  make prod:deploy:frontend — только frontend-ассеты"
	@echo "  make prod:docs-build | make prod:docs-up | make prod:docs-down"
	@echo "  make prod:restart-services — перезапустить app/horizon/scheduler/reverb"
	@echo "  make prod:workers:restart — перезапустить horizon/scheduler/reverb (alias: queue-restart)"
	@echo "  make prod:release (= prod:deploy:image)"
	@echo "  make prod:hotfix  (= prod:deploy:runtime)"
	@echo "  make prod:doctor — быстрый health/status-check prod-стека"
	@echo "Рекомендуемые сценарии:"
	@echo "  обычный деплой кода после git pull -> make prod:deploy:code"
	@echo "  первый запуск / обновление runtime-образа -> make prod:deploy:image"
	@echo "  полный runtime-деплой без image -> make prod:deploy:runtime"
	@echo "  только PHP deps -> make prod:deploy:php-deps"
	@echo "  только frontend -> make prod:deploy:frontend"
	@echo "  Все prod-команды используют docker-compose.prod.yml."

up:
	$(DOCKER_COMPOSE_DEV) up -d

down:
	$(DOCKER_COMPOSE_DEV) down

build:
	$(DOCKER_COMPOSE_DEV) up -d --build

restart:
	$(DOCKER_COMPOSE_DEV) restart

ps:
	$(DOCKER_COMPOSE_DEV) ps

logs:
	$(DOCKER_COMPOSE_DEV) logs -f app

logs\:core:
	$(DOCKER_COMPOSE_DEV) logs -f app horizon scheduler reverb

logs\:reverb:
	$(DOCKER_COMPOSE_DEV) logs -f reverb

logs\:scheduler:
	$(DOCKER_COMPOSE_DEV) logs -f scheduler

logs\:horizon:
	$(DOCKER_COMPOSE_DEV) logs -f horizon

logs\:queue:
	$(DOCKER_COMPOSE_DEV) logs -f horizon

logs\:vite:
	$(DOCKER_COMPOSE_DEV) logs -f vite

shell:
	$(DOCKER_COMPOSE_DEV) exec app bash

artisan:
	$(DOCKER_COMPOSE_DEV) exec app php artisan $(ARGS)

prod\:artisan:
	docker compose -f docker-compose.prod.yml exec -T app php artisan $(ARGS)

composer:
	$(DOCKER_COMPOSE_DEV) exec app composer $(ARGS)

yarn:
	$(DOCKER_COMPOSE_DEV) exec vite yarn $(ARGS)

db-migrate:
	$(DOCKER_COMPOSE_DEV) exec app php artisan migrate

image:
	docker build -t agelto/app:latest -f docker/php/Dockerfile .

db-fresh:
	$(DOCKER_COMPOSE_DEV) exec app php artisan migrate:fresh

db-seed:
	$(DOCKER_COMPOSE_DEV) exec app php artisan db:seed

test-php:
	@$(MAKE) test-preflight
	$(DOCKER_COMPOSE_DEV) exec app php artisan test

test-preflight:
	$(DOCKER_COMPOSE_DEV) ps
	$(DOCKER_COMPOSE_DEV) exec app php -r "new PDO('pgsql:host=pgsql;port=5432;dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'DB_OK'.PHP_EOL;"

prod\:db-migrate:
	@$(MAKE) prod\:artisan ARGS="migrate --force"

prod\:db-seed:
	@$(MAKE) prod\:artisan ARGS="db:seed --force"

prod\:test-php:
	@$(MAKE) prod\:artisan ARGS="test"

composer-install:
	$(DOCKER_COMPOSE_DEV) exec app composer install
	$(DOCKER_COMPOSE_DEV) exec vite yarn install

frontend-fix-wayfinder:
	$(DOCKER_COMPOSE_DEV) up -d app
	$(DOCKER_COMPOSE_DEV) exec -u root app sh -lc '\
		rm -f /var/www/html/storage/wayfinder-cache/*.cache && \
		php artisan wayfinder:generate --no-interaction && \
		chown -R $${PUID:-1000}:$${PGID:-1000} /var/www/html/resources/js/wayfinder'

frontend-dev:
	$(DOCKER_COMPOSE_DEV) up -d app vite
	@$(MAKE) frontend-fix-wayfinder
	$(DOCKER_COMPOSE_DEV) restart vite
	$(DOCKER_COMPOSE_DEV) ps vite

frontend-dev-reset:
	$(DOCKER_COMPOSE_DEV) stop vite || true
	$(DOCKER_COMPOSE_DEV) up -d --force-recreate app vite
	@$(MAKE) frontend-fix-wayfinder
	$(DOCKER_COMPOSE_DEV) restart vite
	$(DOCKER_COMPOSE_DEV) ps vite

frontend-stop:
	$(DOCKER_COMPOSE_DEV) stop vite

frontend-build:
	$(DOCKER_COMPOSE_DEV) up -d app
	$(DOCKER_COMPOSE_DEV) exec app sh -lc 'yarn install --frozen-lockfile && yarn build'

docs-build:
	$(DOCKER_COMPOSE_DEV) exec vite yarn vitepress build docs

docs-preview:
	$(DOCKER_COMPOSE_DEV) exec vite yarn vitepress preview docs --host 0.0.0.0 --port=$(DOCS_PORT)

docs-up:
	$(DOCKER_COMPOSE_DEV) up -d docs
	@echo "Docs available at http://localhost:$(DOCS_PORT)"

docs-down:
	$(DOCKER_COMPOSE_DEV) stop docs

docs-deploy:
	@$(MAKE) docs-build
	@$(MAKE) docs-down
	@$(MAKE) docs-up

# prod:composer-install
# Установить/обновить Composer-зависимости в prod-контейнере приложения.
prod\:composer-install:
	docker compose -f docker-compose.prod.yml exec -T -u root app composer install --no-interaction --prefer-dist --optimize-autoloader
	docker compose -f docker-compose.prod.yml exec -T -u root app chown -R www-data:www-data /var/www/html/vendor

# prod:frontend-build
# Собрать production-ассеты frontend внутри prod-контейнера app.
prod\:frontend-build:
	docker compose -f docker-compose.prod.yml exec -T -u root app sh -lc 'mkdir -p /var/www/html/public/build /var/www/html/public/filament && chown -R www-data:www-data /var/www/html/public/build /var/www/html/public/filament'
	docker compose -f docker-compose.prod.yml exec -T -u www-data app sh -lc 'set -e; yarn install --frozen-lockfile; yarn build'

prod\:docs-build:
	docker compose -f docker-compose.prod.yml exec -T app yarn vitepress build docs

prod\:docs-preview:
	docker compose -f docker-compose.prod.yml exec -T app yarn vitepress preview docs --host 0.0.0.0 --port=$(DOCS_PORT)

prod\:docs-up:
	docker compose -f docker-compose.prod.yml up -d docs
	@echo "Docs available at http://localhost:$(DOCS_PORT)"

prod\:docs-down:
	docker compose -f docker-compose.prod.yml stop docs

# prod:restart-services
# Перезапустить ключевые сервисы prod после установки зависимостей/обновления кода.
prod\:restart-services:
	docker compose -f docker-compose.prod.yml restart app horizon scheduler reverb

# prod:workers:restart
# Перезапустить только фоновые процессы (без app).
prod\:workers\:restart:
	docker compose -f docker-compose.prod.yml restart horizon scheduler reverb

# prod:deploy:php-deps
# Установить/обновить только PHP-зависимости.
prod\:deploy\:php-deps: prod\:composer-install

# prod:deploy:frontend
# Собрать только фронтенд-ассеты.
prod\:deploy\:frontend: prod\:frontend-build

# prod:deploy:code
# Деплой только кода: очистка кэша и рестарт процессов.
prod\:deploy\:code:
	@$(MAKE) prod\:clear-cache
	@$(MAKE) prod\:restart-services

# prod:deploy:runtime
# Полный деплой без пересборки image: code + deps + assets.
prod\:deploy\:runtime:
	@$(MAKE) prod\:deploy\:php-deps
	@$(MAKE) prod\:deploy\:frontend
	@$(MAKE) prod\:deploy\:code

# prod:deploy:image
# Полная пересборка: image + recreate сервисов + runtime-деплой.
prod\:deploy\:image:
	@$(MAKE) image
	@docker compose -f docker-compose.prod.yml up -d --force-recreate app horizon scheduler reverb
	@$(MAKE) prod\:deploy\:runtime

prod\:release: prod\:deploy\:image

prod\:hotfix: prod\:deploy\:runtime

prod\:quick-deploy: prod\:deploy\:runtime

prod\:full-build: prod\:deploy\:image

clear-cache:
	$(DOCKER_COMPOSE_DEV) exec app php artisan optimize:clear

prod\:clear-cache:
	@$(MAKE) prod\:artisan ARGS="optimize:clear"

prod\:doctor:
	@echo "== prod compose ps =="
	@docker compose -f docker-compose.prod.yml ps
	@echo ""
	@echo "== app health (/up) =="
	@docker compose -f docker-compose.prod.yml exec -T app sh -lc 'curl -fsS http://127.0.0.1:8080/up >/dev/null && echo "OK: /up" || echo "FAIL: /up"'
	@echo ""
	@echo "== build/vendor dirs =="
	@docker compose -f docker-compose.prod.yml exec -T app sh -lc '[ -d /var/www/html/public/build ] && echo "OK: public/build" || echo "MISSING: public/build"'
	@docker compose -f docker-compose.prod.yml exec -T app sh -lc '[ -d /var/www/html/public/filament ] && echo "OK: public/filament" || echo "MISSING: public/filament"'
	@docker compose -f docker-compose.prod.yml exec -T app sh -lc '[ -d /var/www/html/vendor ] && echo "OK: vendor" || echo "MISSING: vendor"'

reverb-install:
	$(DOCKER_COMPOSE_DEV) exec app php artisan reverb:install

reverb-restart:
	$(DOCKER_COMPOSE_DEV) restart reverb

scheduler-restart:
	$(DOCKER_COMPOSE_DEV) restart scheduler

horizon-restart:
	$(DOCKER_COMPOSE_DEV) restart horizon

schedule-list:
	$(DOCKER_COMPOSE_DEV) exec app php artisan schedule:list

db\:create-test:
	bash docker/postgres/create-agelto-test-db.sh dev

prod\:db\:create-test:
	bash docker/postgres/create-agelto-test-db.sh prod

# Обратная совместимость со старыми именами целей
migrate: db-migrate
fresh: db-fresh
seed: db-seed
test: test-php
install: composer-install
build-frontend: frontend-build
logs-reverb: logs\:reverb
logs-scheduler: logs\:scheduler
logs-horizon: logs\:horizon
queue-restart: horizon-restart
