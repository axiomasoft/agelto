---
audience: dev
type: howto
status: living
---

# Docker: development

## Bind-mount, не именованный volume для кода

`docker-compose.yml` использует named target `development`: `./:/var/www/html` монтирует код с
хоста, поэтому code-only правка видна без rebuild. Bootstrap/cache, framework runtime и Pest Tia
— явные project-scoped named volumes. `bash docker/tests/dev-code-sync.sh` доказывает health,
sentinel, неизменный image ID и non-TTY agent command в изолированном Compose project.

## Права на файлы (`www-data` внутри контейнера)

`serversideup/php:8.4-fpm-nginx` НЕ ремаппит `www-data` под хостовой uid (в отличие от некоторых
других образов — проверено: в `entrypoint.d` нет PUID-логики, только
`PHP_FPM_CHILD_PROCESS_USER`/`_GROUP`, которые задают FPM-пул, не системного пользователя). После
`composer install`/`require` с ХОСТА владелец файлов в `storage`/`vendor` может смениться —
разовая починка: `docker compose exec -u root app chown -R www-data:www-data storage vendor`.

## Локальный override и project name

`cp docker-compose.override.example.yml docker-compose.override.yml` создаёт Git-ignored local
override. `COMPOSE_PROJECT_NAME` по умолчанию равен `agelto_dev`; fixed `container_name` нет.

## Фронтенд

Vite запускается отдельным сервисом `vite` на Node 24. Он использует `pnpm@9.15.0` и
`pnpm-lock.yaml` с `--frozen-lockfile`, а `node_modules`/pnpm store — project-scoped named volumes.
Сервис слушает `${VITE_PORT:-5173}`; HMR доступен браузеру только через `localhost` и `APP_URL`.

## Смежное

- [`index.md`](index.md) — весь стек сервисов.
- `../getting-started.md` — полный первый запуск с нуля.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои прав/env-файлов.
