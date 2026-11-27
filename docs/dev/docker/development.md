---
audience: dev
type: howto
status: living
---

# Docker: development

## Bind-mount, не именованный volume для кода

`docker-compose.yml` использует named target `development`: `./:/var/www/html` монтирует код с
хоста, поэтому code-only правка видна без rebuild. Bootstrap/cache, framework runtime и Pest TIA
— явные project-scoped named volumes. `make test:tia-reset` очищает только TIA graph внутри этого
volume; `make test:tia-refresh` воспроизводимо записывает его заново. `bash docker/tests/dev-code-sync.sh` доказывает health,
sentinel, неизменный image ID и non-TTY agent command в изолированном Compose project.

## Bootstrap чистого clone

`make bootstrap MODE=dev ENV_FILE=.env COMPOSE_PROJECT_NAME=agelto_dev` — единственный носитель
первого запуска. Он по порядку вызывает существующие цели vendors, секретов, preflight, образа,
Compose и здоровья, повторяет проверку живости без удаления volumes проекта и сохраняет только
последний несекретный этап, статус и SHA исходников в игнорируемом
`local/secrets/.bootstrap-progress`. При ошибке он печатает ту же команду для продолжения; он не
запускает миграции, сидеры и production-операции.

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
Для isolated smoke задавайте уникальные `COMPOSE_PROJECT_NAME` и `VITE_PORT`; cleanup удаляет
только resources этого проекта.

## Application readiness и admin E2E

`/up` остаётся liveness probe и намеренно не обращается к DB, cache, storage, Horizon или scheduler.
Для release gate используйте `make readiness:check`; тот же typed service читает Doctor. Scheduler
пишет heartbeat, а readiness проверяет его свежесть.

`bash docker/tests/clean-install-e2e.sh` материализует отдельный worktree отслеживаемого дерева
и использует уникальные проект Compose и порт. Задайте `E2E_ADMIN_EMAIL` и
`E2E_ADMIN_PASSWORD_FILE`; состояние браузера и трассы остаются в игнорируемом выводе прогона.

## Смежное

- [`index.md`](index.md) — весь стек сервисов.
- `../getting-started.md` — полный первый запуск с нуля.
- [`troubleshooting.md`](troubleshooting.md) — типичные сбои прав/env-файлов.
# Secrets

Development Compose монтирует сгенерированные файлы из `local/secrets/`; не добавляйте пароли в
`.env.docker`. Создайте их один раз через `make secrets:init` до `make dev:up`. Инициализатор
принимает только фиксированный набор имён секретов и до записи отклоняет symlink и нерегулярные
корни и цели; неудачный запуск не оставляет подготовленных секретов.