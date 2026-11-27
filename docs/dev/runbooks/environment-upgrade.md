---
audience: shared
type: runbook
status: living
---

# Обновление окружения: PHP 8.6, PostgreSQL 19, Silo

Страница описывает переход существующей установки на текущую целевую среду и то, что меняется
для разработчика и оператора. Новая установка ничего отсюда не требует: достаточно
[чистой установки](../getting-started.md) или [подготовки сервера](../../operators/production/server-setup.md).

## Целевые версии

| Компонент | Было | Стало | Где закреплено |
|:--|:--|:--|:--|
| PHP | 8.5 (`composer.json`: `^8.4`) | 8.6 (`serversideup/php:8.6-fpm-nginx` по digest) | `docker/php/Dockerfile`, `composer.json` (`^8.6`) |
| pcov (только dev) | PECL 1.0.12 | сборка с закреплённого коммита upstream | `ARG PCOV_COMMIT` в `docker/php/Dockerfile` |
| PostgreSQL | 17 | 19 (`postgres:19` по digest + `pg_partman`) | `docker/postgres/Dockerfile` |
| Redis | 7 | 8.12 (alpine, по digest) | Compose-файлы |
| Объектное хранилище | MinIO (`minio/minio`) | Silo (`pgsty/silo`, форк MinIO) | Compose-файлы, стадия `mc` в Dockerfile |
| Node.js | 24 (bookworm), 22 (alpine) | 26 (trixie, alpine) | Dockerfile, Compose, CI |
| Laravel / Filament / Livewire | 13.44 / 5.8 / 4.4 | 13.44 / 5.12 / 4.4 | `composer.lock` |
| Pest / Larastan | 5.0 / 3.13 | 5.3 / 3.15 (PHPStan `max`, строже флаги) | `composer.lock`, `phpstan.neon` |

Каждый образ закреплён по тегу и digest. Обновление образа — отдельный коммит, который меняет
тег и digest вместе.

## Переход на PostgreSQL 19 {#postgresql-19}

Сменилась раскладка volume. Образы PostgreSQL 18+ объявляют `VOLUME /var/lib/postgresql`, а
данные лежат в `/var/lib/postgresql/<major>/docker`. Поэтому volume `pgsql_data` теперь
монтируется в `/var/lib/postgresql`, а переопределение `PGDATA` удалено. Если в корне volume
лежит кластер старой версии (`PG_VERSION` в корне), entrypoint PostgreSQL 19 отказывается
стартовать. Это намеренное fail-fast поведение: старые данные не подхватываются молча.

Обновление выполняется через логический дамп. `pg_upgrade` между образами не используется.

```mermaid
flowchart LR
    A[Проверенная резервная копия<br/>на PostgreSQL 17] --> B[Остановка app, воркеров<br/>и планировщика]
    B --> C[Финальный pg_dump -Fc]
    C --> D[Новый пустой volume<br/>для PostgreSQL 19]
    D --> E[Старт postgres 19]
    E --> F[pg_restore]
    F --> G[migrate --force<br/>и app:readiness]
    G --> H[Старый volume хранится<br/>до подтверждения]
```

1. Сделайте и проверьте резервную копию по [процедуре бэкапа](../../operators/production/backups.md).
   Без проверенного восстановления не продолжайте.
2. Остановите всё, что пишет в базу: `app`, `horizon`, `scheduler`, `process-*`.
   Сервис `postgres` 17 оставьте запущенным.
3. Снимите финальный дамп в custom-формате:

   ```bash
   docker compose -f docker-compose.prod.yml exec -T postgres \
     sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > agelto-pg17.dump
   ```

4. Остановите `postgres`. Не удаляйте старый volume: переименуйте проект или создайте новый
   volume, чтобы откат оставался возможным. Самый простой путь — скопировать данные старого
   volume в архив и затем пересоздать `pgsql_data` пустым.
5. Соберите или подтяните образ PostgreSQL 19 из нового релиза и запустите только `postgres`.
   Убедитесь, что `SHOW server_version` возвращает 19.x.
6. Восстановите дамп:

   ```bash
   docker compose -f docker-compose.prod.yml exec -T postgres \
     sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner --exit-on-error' < agelto-pg17.dump
   ```

7. Выполните `php artisan migrate --force` в контексте app, затем
   `php artisan app:readiness --format=agent` и `make prod:doctor IMAGE_TAG=<tag>`.
8. Запустите воркеры и планировщик. Храните старый volume и дамп до подтверждения владельцем.

Откат: остановите PostgreSQL 19, верните старый volume и образ PostgreSQL 17 предыдущего релиза
и выполните [откат образа](../../operators/production/rollback-restore.md).

Что изменилось для разработки. Каталог PostgreSQL 18+ хранит ограничения NOT NULL строками
`contype = 'n'` в `pg_constraint`, а `pg_dump` печатает `CONSTRAINT <name> NOT NULL` для
неименованных по умолчанию ограничений. Inventory схемы стенда это учитывает
(`docker/tests/process-core/catalog.sql`, `SchemaInventory::withoutNotNullConstraintNames()`),
поэтому `make core-test-install` сравнивает схему одинаково на любой поддерживаемой версии.
Ожидаемая major-версия стенда задана одной константой `POSTGRES_MAJOR` в `rig.py`.

Для локальной dev-базы проще всего снять дамп, удалить dev-volume и выполнить bootstrap заново:
dev-данные не являются источником истины.

## Переход с MinIO на Silo {#minio-silo}

Образы `minio/minio` и `minio/mc` пропали с Docker Hub, а `quay.io/minio` не отдаёт их анонимно.
Стек переведён на Silo (`pgsty/silo`), поддерживаемый форк MinIO community edition. Имя
сервиса `minio`, переменные `MINIO_*`, секрет `minio_root_password`, порты и volume
`minio_data` не менялись. Клиент `mc` в PHP-образе берётся из того же образа Silo
(`/usr/bin/mcli`).

1. Сделайте резервную копию объектов (`mc mirror` текущего состояния в отдельное место) по
   [процедуре бэкапа](../../operators/production/backups.md).
2. Обновите релиз: сервис `minio` пересоздаётся на образе Silo с тем же volume `/data`.
3. Проверьте `mc admin info`, наличие bucket и чтение нескольких известных объектов, затем
   `app:readiness`.
4. Если Silo не принимает существующий каталог данных, остановите сервис, поднимите Silo на
   новом volume и перенесите объекты через `mc mirror` из резервной копии.

Trivy сообщает CVE-2026-39414 для образа Silo по пути Go-модуля. Это ложное срабатывание:
исправление есть в релизах Silo начиная с 2026-04-17 и завершено в 2026-06-18. Исключение
ограничено одним файлом `.github/trivy/silo.trivyignore` и имеет срок действия до 2027-05-31.

## PHP 8.6 и инструменты разработки

- PECL-релиз pcov 1.0.12 не собирается на PHP 8.6. Стадия `development` собирает pcov из
  закреплённого коммита upstream (`ARG PCOV_COMMIT`). Вернуться на PECL можно, когда выйдет
  релиз с исправлением.
- Консольные команды регистрируются лениво через `#[AsCommand(name: ...)]`: без этого атрибута
  Laravel создаёт все команды при каждом запуске `artisan`. Архитектурный тест
  `ConsoleCommandLazyLoadingTest` требует атрибут у каждой команды.
- Монотонное время берётся из `App\Support\Time\Monotonic`, а не из прямого `hrtime(true)`.

## Проверка после обновления

```bash
make core-test-up && make core-test-install
make core-test-shard SHARD=1/4   # и остальные шарды
make core-quality
```

Полный CI на ветке — финальное доказательство. Локальные шарды его не заменяют.
