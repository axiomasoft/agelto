# Docker: production (локальное тестирование)

Этот раздел — для **локального тестирования prod-режима** на рабочей станции.  
Для развёртывания на сервере — см. [docs/server/](../../server/setup.md).

## Конфиги: dev vs prod

| Файл | Режим | Назначение |
|------|-------|-----------|
| `.env.docker` | dev | Порты для `docker-compose.yml` (не пересекаются с prod) |
| `.env.docker.production` | prod | Порты для `docker-compose.prod.yml` |

Dev-порты (из `.env.docker.example`): `APP=8081`, `PGSQL=5434`, `REDIS=6380`.  
Prod-порты (из `.env.docker.production.example`): `APP=8080`, `PGSQL=5432`, `REDIS=6379`.

Запуск в prod-режиме локально:

```bash
cp .env.docker.production.example .env.docker.production
make up ENV=prod
```

## Очистка артефактов при переключении dev → prod

При первом переключении могут остаться dev-артефакты (`bootstrap/cache`, `public/build`),
которые ломают prod-сборку. Для очистки:

```bash
make prod-pre-clean
```

Что делает: удаляет `bootstrap/cache/config.php`, `public/build`, `public/filament`,
останавливает контейнеры с volumes.

## Команды в prod-режиме

```bash
make ps ENV=prod
make logs ENV=prod
make prod:artisan ARGS="route:list"
make prod:doctor
```

## Horizon

В Agelto вместо `queue:work` используется Horizon (`horizon`-сервис). Перезапуск:

```bash
make prod:workers:restart
```

## Подробнее

- Модель развёртывания, volumes, Docker Secrets → [server/docker-prod.md](../../server/docker-prod.md)
- Сценарии деплоя на сервере → [server/deploy.md](../../server/deploy.md)
- Подготовка сервера → [server/setup.md](../../server/setup.md)
