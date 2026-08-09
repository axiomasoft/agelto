---
audience: dev
type: howto
status: living
---

# Docker: development

## Bind-mount, не именованный volume для кода

`docker-compose.yml`: `./:/var/www/html` — весь код смонтирован с хоста (правки видны в контейнере
мгновенно, без ребилда). `vendor` — тоже bind-mount в dev (в отличие от prod, где это именованный
volume) — `composer install`/`require` можно запускать И с хоста, И из контейнера
(`make composer-install`/`make composer ARGS="..."`).

## Права на файлы (`www-data` внутри контейнера)

`serversideup/php:8.4-fpm-nginx` НЕ ремаппит `www-data` под хостовой uid (в отличие от некоторых
других образов — проверено: в `entrypoint.d` нет PUID-логики, только
`PHP_FPM_CHILD_PROCESS_USER`/`_GROUP`, которые задают FPM-пул, не системного пользователя). После
`composer install`/`require` с ХОСТА владелец файлов в `storage`/`vendor` может смениться —
разовая починка: `docker compose exec -u root app chown -R www-data:www-data storage vendor`.

## `.env.docker` (опционально)

`cp .env.docker.example .env.docker` — dev-специфичные переменные для `docker compose`, не
обязателен (если файла нет, `docker/Makefile` его просто не подключает вторым `--env-file`).

## Фронтенд

`yarn install && yarn dev` — Vite HMR запускается ВНЕ контейнера (Node на хосте), не через
`make`-цель; `APP_URL`/порты см. `.env`. Установка зависимостей и сборка — обычный yarn, `make`
целей под фронтенд нет (canonical CLAUDE.md §Docker/Runtime говорит только про runtime-образ —
composer/frontend-сборка вне `docker/php/Dockerfile`).

## Смежное

- [`index.md`](index.md) — весь стек сервисов.
- `../getting-started.md` — полный первый запуск с нуля.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои прав/env-файлов.
