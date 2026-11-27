---
audience: dev
type: howto
status: living
---

# Docker: troubleshooting

## `infra:preflight` stops before Docker starts

Preflight намеренно закрыт по умолчанию. Выполните `make vendor:check`, создайте секреты через
`make secrets:init`, затем используйте несекретный env-файл Compose, например `.env.example`.
JSON для агентов содержит только ID проверок, статусы и рекомендации; значения секретов никогда
не выводятся.

## `docker compose` вручную требует ДВА `--env-file`

`docker/Makefile` передаёт `.env` явно. Для ручного вызова также укажите project name и carrier:
`docker compose --project-name agelto_dev --env-file .env -f docker-compose.yml …`.
Предпочитать `make dev:artisan ARGS="…"` или `make agent:artisan ARGS="…"`.

## Bind-mount permission denied для `www-data`

`serversideup/php` не ремаппит `www-data` под хостовой uid — после операций с хоста (composer
require, git-операции, редактирование файлов от имени хостового пользователя) владелец
`storage`/`vendor` может стать «неправильным» изнутри контейнера. Починка:
`docker compose exec -u root app chown -R www-data:www-data storage vendor`. Подробнее —
`development.md` §Права на файлы.

## `pint --dirty` не видит untracked-файлы

`--dirty` смотрит на `git diff`, новый (ещё не `git add`) файл в это множество не попадает —
передавать явный список путей: `docker compose exec app ./vendor/bin/pint path/to/NewFile.php`.
Авто-фикс (запись изменений) в контейнере может упереться в ту же bind-mount permission-проблему,
что и выше — если `pint --dirty` молча не изменил файл, проверить владельца файла на хосте.

## `composer require`/`composer update --lock` внутри контейнера

Композитор-операции, меняющие `composer.lock`, надёжнее запускать С ХОСТА (не
`make composer ARGS="require ..."`) — избегает bind-mount permission-гонки на только что созданных
файлах. `make composer-install` (без изменения lock-файла) — безопасен из контейнера.

## Контейнер `app`/`horizon` не проходит healthcheck

`make dev:ps` (dev) — статус контейнеров одним отчётом; `curl
http://localhost:8080/up` — тот же эндпоинт, что слушает Docker healthcheck. Если `horizon` не
проходит `horizon:status | grep running` — см. [`../runbooks/horizon-backlog.md`](../runbooks/horizon-backlog.md).

## `app:readiness` is red while `/up` is green

Это ожидаемо, когда недоступны внешняя зависимость, сгенерированный ассет Filament, Horizon или
heartbeat планировщика: `/up` проверяет только живость. Выполните `make readiness:check`, чтобы
получить стабильные обезличенные коды проверок; если падает код `assets`, сначала выполните
`make assets:check`, а `make assets:repair` — только для изолированного канонического пути
восстановления.

## `vendor:publish`/`guard:filament:generate` не подхватывают правки

Генераторы, пишущие в `bootstrap/cache`/конфиг-кэш — надёжнее запускать С ХОСТА при подозрении на
bind-mount-гонку, аналогично composer-операциям выше.

## Production release target rejects its inputs

`prod:deploy:*` и `prod:image:promote` отказывают, если вызывающий не передал существующий
env-файл, явный `COMPOSE_PROJECT_NAME` и неизменяемый `IMAGE_TAG`, отличный от `latest`. Очистка
сборок и volumes на этих production-путях намеренно недоступна; соберите и продвиньте артефакт
вне production-хоста, затем повторите выбранную цель.