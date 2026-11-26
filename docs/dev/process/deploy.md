---
audience: dev
type: howto
status: living
---

# Деплой

## Immutable release contract

Текущий operator path без CI — [ручной deploy](../../operators/production/manual-deployment.md).
Там host build явно разрешён владельцем, но runtime всё равно использует закрытую immutable
пару app/doctor без source bind mounts. Описанные ниже pull helpers — только registry path.

Для registry-деплоя задайте `COMPOSE_PROJECT_NAME`, путь к существующему `ENV_FILE`
и неизменяемый `IMAGE_TAG`. При использовании registry укажите в production env
`IMAGE_REGISTRY` с квалифицированным именем хоста или адресом `хост:порт`.
`release-deploy.sh` подтягивает образы и пересоздаёт `app`, `horizon` и `scheduler`
через `up -d --no-build --wait`. Схема БД и оркестрация очереди имеют отдельные
операторские процедуры.

The release tag is produced beforehand as a same-SHA `agelto/app` + `agelto/doctor` pair. The build
carrier verifies private vendor closure, both final filesystem contracts, and an isolated local
registry push→clean-pull cycle; production receives only that immutable tag.

Documentation is part of that immutable application artifact. Before promoting a release that
changes `docs/` or the private documentation boundary, run `make documentation:acceptance` on the
committed candidate. The carrier creates isolated one-time E2E credentials and records the exact
candidate SHA/application image digest; a source-only deployment cannot publish it.

## Разновидности

| Команда | Когда |
|:--|:--|
| `make prod:deploy:code` | Pull + recreate готового tag для code invalidation |
| `make prod:deploy:php-deps` | Тот же immutable carrier для PHP dependency invalidation; Composer не запускается на host |
| `make prod:deploy:frontend` | Тот же immutable carrier для frontend artifact invalidation |
| `make prod:deploy:runtime` | Тот же immutable carrier для runtime invalidation |
| `make prod:image:promote` | Pull готового immutable tag без recreate и без build |
| `make prod:deploy:image` | Promote готового tag, затем runtime recreate без build |

## Инвариант очередей

`stop_grace_period` контейнера `horizon` ОБЯЗАН быть ≥ самого длинного supervisor-таймаута
(`config/horizon.php`) — Docker default 10s убил бы job на 300+с. Прод-канон:
`HORIZON_STOP_GRACE_PERIOD` (default 4200s, `docker-compose.prod.yml`). Полный разбор инварианта
`job timeout < supervisor timeout < retry_after` и Redis durability (D34) —
[`../runbooks/horizon-backlog.md`](../runbooks/horizon-backlog.md).

## Проверка после деплоя

После завершения Compose `--wait` проверьте liveness `/up` и статус трёх контейнеров через выбранный Compose project. Наличие `vendor`
или frontend artifacts на host не является release condition: они входят в immutable image.

После owner-gated миграции выполните идемпотентный production base seed: `make prod:seed-base`.
Начального администратора создавайте отдельной командой с читаемым password-file; reset не входит в
bootstrap или deploy и никогда не принимает пароль через CLI option.

После base data и initial admin выполните `make prod:doctor IMAGE_TAG=<immutable-tag>`. Это one-off
companion image, а не production service; он возвращает nonzero и при warning, не применяет fixes и
удаляется Compose после проверки.

До Doctor запустите active readiness carrier (`php artisan app:readiness --format=agent` в app
container): он actively verifies DB, cache, storage, release state, Filament assets, Horizon and
scheduler heartbeat. A green `/up` alone never authorizes a deployment.

## Смежное

- [`../docker/production.md`](../docker/production.md) — устройство prod-стека (prebuilt immutable
  image, Swarm secrets).
- [`../commands.md`](../commands.md) — полная шпаргалка команд.
- [текущий inventory команд](../../operators/production/commands.md#current-command-inventory)
  — source-derived environment/mutation/authority/evidence contract; external rows исполняются
  только в operator rehearsal.
