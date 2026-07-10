---
audience: dev
type: howto
status: draft
---

# Деплой

## Канон-деплой с живыми очередями (D26)

`make prod:deploy [COLD=1]` (`docker/Makefile` — единственная точка правды, не копируется сюда):

```
pull (app/horizon/scheduler)
  → [COLD=1: horizon:pause — пауза перед рискованным деплоем]
  → migrate --force (ТОЛЬКО аддитивные миграции!)
  → horizon:terminate (graceful — текущие jobs доделываются, НЕ down)
  → up -d (пересоздаёт контейнеры на новом коде)
  → [COLD=1: horizon:continue]
  → horizon:status
```

`COLD=1` — только для рискованных деплоев (миграция с блокировкой, крупная схемная правка);
обычный деплой кода идёт БЕЗ паузы — `horizon:terminate` сам дожидается текущих jobs.
**Деструктивные миграции — отдельным деплоем** (expand/contract паттерн — сначала добавить
новое/сделать код совместимым с обеими схемами, затем отдельно удалить старое).

## Разновидности

| Команда | Когда |
|:--|:--|
| `make prod:deploy:code` | Только код, без миграций/composer — самый частый путь |
| `make prod:deploy:runtime` | + Composer + миграции |
| `make prod:deploy:image` | + пересборка Docker-образа (изменились системные зависимости) |
| `make prod:deploy:php-deps` | Только Composer |

## Инвариант очередей

`stop_grace_period` контейнера `horizon` ОБЯЗАН быть ≥ самого длинного supervisor-таймаута
(`config/horizon.php`) — Docker default 10s убил бы job на 300+с. Прод-канон:
`HORIZON_STOP_GRACE_PERIOD` (default 600s, `docker-compose.prod.yml`). Полный разбор инварианта
`job timeout < supervisor timeout < retry_after` и Redis durability (D34) —
[`../runbooks/horizon-backlog.md`](../runbooks/horizon-backlog.md).

## Проверка после деплоя

`make prod:doctor` — health `/up`, наличие `vendor` (volume не потерян), статус трёх контейнеров
одним отчётом.

## Смежное

- [`../docker/production.md`](../docker/production.md) — устройство prod-стека (prebuilt image,
  `vendor`/`public/build` через volumes, Swarm secrets).
- [`../commands.md`](../commands.md) — полная шпаргалка команд.
