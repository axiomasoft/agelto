.PHONY: up down build restart logs shell composer npm migrate fresh seed test

# Запуск контейнеров
up:
	docker-compose up -d

# Остановка контейнеров
down:
	docker-compose down

# Пересборка и запуск
build:
	docker-compose up -d --build

# Перезапуск контейнеров
restart:
	docker-compose restart

# Просмотр логов
logs:
	docker-compose logs -f app

# Просмотр логов Reverb
logs-reverb:
	docker-compose logs -f reverb

# Просмотр логов Scheduler
logs-scheduler:
	docker-compose logs -f scheduler

# Просмотр логов Queue
logs-queue:
	docker-compose logs -f queue

# Доступ к shell контейнера приложения
shell:
	docker-compose exec app bash

# Composer команды
composer:
	docker-compose exec app composer $(ARGS)

# Node.js/Pnpm команды
npm:
	docker-compose exec app pnpm $(ARGS)

# Artisan команды
artisan:
	docker-compose exec app php artisan $(ARGS)

# Миграции
migrate:
	docker-compose exec app php artisan migrate

# Сброс БД и миграции
fresh:
	docker-compose exec app php artisan migrate:fresh

# Заполнение БД
seed:
	docker-compose exec app php artisan db:seed

# Тесты
test:
	docker-compose exec app php artisan test

# Установка зависимостей
install:
	docker-compose exec app composer install
	docker-compose exec app pnpm install

# Сборка фронтенда
build-frontend:
	docker-compose exec app pnpm run build

# Очистка кэша
clear-cache:
	docker-compose exec app php artisan cache:clear
	docker-compose exec app php artisan config:clear
	docker-compose exec app php artisan route:clear
	docker-compose exec app php artisan view:clear

# Установка Reverb
reverb-install:
	docker-compose exec app php artisan reverb:install

# Перезапуск Reverb
reverb-restart:
	docker-compose restart reverb

# Перезапуск Scheduler
scheduler-restart:
	docker-compose restart scheduler

# Перезапуск Queue
queue-restart:
	docker-compose restart queue

# Просмотр запланированных задач
schedule-list:
	docker-compose exec app php artisan schedule:list

# Просмотр неудачных задач очереди
queue-failed:
	docker-compose exec app php artisan queue:failed

# Повтор всех неудачных задач
queue-retry-all:
	docker-compose exec app php artisan queue:retry all

# Очистка неудачных задач
queue-flush:
	docker-compose exec app php artisan queue:flush

# Создание таблицы для очередей
queue-table:
	docker-compose exec app php artisan queue:table

