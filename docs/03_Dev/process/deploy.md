---
audience: dev
type: howto
status: living
---

# Деплой

## Immutable release contract

All release commands require an explicit `COMPOSE_PROJECT_NAME`, an existing `ENV_FILE`, and an
immutable `IMAGE_TAG` other than `latest`. The production host only pulls and recreates the three
application services with `--no-build`; it never mounts source/vendor/frontend artifacts, builds an
image, or removes volumes. Migration and queue lifecycle orchestration remain separate owner-gated
work, outside this P1 carrier.

The release tag is produced beforehand as a same-SHA `agelto/app` + `agelto/doctor` pair. The build
carrier verifies private vendor closure, both final filesystem contracts, and an isolated local
registry push→clean-pull cycle; production receives only that immutable tag.

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
`HORIZON_STOP_GRACE_PERIOD` (default 600s, `docker-compose.prod.yml`). Полный разбор инварианта
`job timeout < supervisor timeout < retry_after` и Redis durability (D34) —
[`../runbooks/horizon-backlog.md`](../runbooks/horizon-backlog.md).

## Проверка после деплоя

Проверить health `/up` и статус трёх контейнеров через выбранный Compose project. Наличие `vendor`
или frontend artifacts на host не является release condition: они входят в immutable image.

После owner-gated миграции выполните идемпотентный production base seed: `make prod:seed-base`.
Начального администратора создавайте отдельной командой с читаемым password-file; reset не входит в
bootstrap или deploy и никогда не принимает пароль через CLI option.

После base data и initial admin выполните `make prod:doctor IMAGE_TAG=<immutable-tag>`. Это one-off
companion image, а не production service; он возвращает nonzero и при warning, не применяет fixes и
удаляется Compose после проверки.

## Смежное

- [`../docker/production.md`](../docker/production.md) — устройство prod-стека (prebuilt immutable
  image, Swarm secrets).
- [`../commands.md`](../commands.md) — полная шпаргалка команд.
