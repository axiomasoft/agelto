# FINDINGS (слой 1) — схлопывание миграций: внешняя фактура

**Дата:** 2026-08-06 · **Запрос:** чем и как схлопывают историю миграций в Laravel 13 на
PostgreSQL, и что происходит с `pg_partman` при снимке схемы · **Питает:** D81 (способ
схлопывания), P8.1 (ревизия), P8.4 (пост-аудит).

Факты сняты ДО детализации фазы (прецедент D11/D49/D60): без них нельзя было честно отвергнуть
маршрут «`schema:dump --prune`» — отвергать его «по слуху» значило бы подгонку под уже
понравившийся вариант.

---

## 1. Штатный механизм Laravel — `schema:dump [--prune]`

`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, migrations.md
«Squashing Migrations»)`

- `php artisan schema:dump` пишет файл `database/schema/<connection>-schema.sql`; с `--prune`
  ДОПОЛНИТЕЛЬНО удаляет ВСЕ файлы `database/migrations/**` (дословно в коде команды:
  `(new Filesystem)->deleteDirectory(database_path('migrations'), preserve: false)` —
  `RAG:— (repo-grounded: vendor/laravel/framework/src/Illuminate/Database/Console/DumpCommand.php:60-70)`).
- Дамп подхватывается ТОЛЬКО когда ни одна миграция ещё не исполнена; после загрузки файла
  Laravel прогоняет миграции, появившиеся после снимка.
- Прямое предупреждение документации: схлопывание доступно для MariaDB/MySQL/PostgreSQL/SQLite и
  **использует консольный клиент БД**.
- Тестовое окружение с другим подключением требует ОТДЕЛЬНОГО снимка
  (`schema:dump --database=testing`), иначе тесты не смогут построить БД.

Механика на PostgreSQL (репо-факт, читается в вендоре):
`RAG:— (repo-grounded: vendor/laravel/framework/src/Illuminate/Database/Schema/PostgresSchemaState.php:19-63)`

- `dump()` = `pg_dump --no-owner --no-acl … --schema-only` (БЕЗ `--schema=`, то есть все схемы
  подключения) плюс `-t <migrations> --data-only` для таблицы миграций;
- `load()` = `psql --file=…` для `.sql` (или `pg_restore` для `.dump`).

## 2. `pg_dump --schema-only` и `pg_partman`: регистрация партиций НЕ переносится

`RAG:✅ 2026-08-06 (perplexity-web «pg_dump --schema-only pg_partman part_config extension
configuration table data … 2026», источник github.com/pgpartman/pg_partman)`

- `--schema-only` выгружает партиционированные родительские таблицы, их партиции и ограничения,
  а также `CREATE EXTENSION pg_partman`;
- **но НЕ выгружает данные ни одной таблицы, включая `partman.part_config`** — метаданные
  управления партициями суть ДАННЫЕ, а не схема;
- следствие: восстановленная из `--schema-only` БД содержит таблицы, но pg_partman о них «не
  знает» — ни premake, ни retention-DROP не работают, пока конфигурация не восстановлена;
- рекомендованные пути ровно два: (а) отдельный `--data-only --table=partman.part_config
  --column-inserts` дамп; (б) заново применить `partman.create_parent()` и настройки SQL-ом,
  «treating them like infra as code» — то есть именно то, что у нас уже сделано миграциями
  `…100047`/`…100050`/`…100051`.

## 3. `migrate:fresh` и схемы вне `public`

`RAG:✅ 2026-08-06 (laravel-boost search-docs, laravel/framework@13.x, migrations.md «Drop All
Tables and Migrate»)`: `migrate:fresh` дропает ВСЕ таблицы подключения независимо от префикса.

Дополняющий репо-факт, уже зафиксированный докблоком нашей миграции:
`RAG:— (repo-grounded: database/migrations/2026_07_04_100047_setup_pg_partman.php, докблок)` —
дроп идёт по текущей `search_path`-схеме (`public`), а расширение `pg_partman` и его
`partman.part_config` лежат в ОТДЕЛЬНОЙ схеме и переживают `migrate:fresh` как есть. Именно
поэтому обе partman-миграции написаны идемпотентно (проверка «уже зарегистрирована» / повторный
`UPDATE`), и именно поэтому осиротевшие строки `part_config` живут вечно, пока их не удалят
явно (`…100076`).

## 4. Вердикт для плана

Маршрут «`schema:dump --prune`» отвергнут решением владельца 2026-08-06 (D81) — и внешняя
фактура выше объясняет, ПОЧЕМУ он и технически дороже, чем выглядит:

| Цена маршрута дампа | Источник факта |
|:--|:--|
| В runtime-образ `app` пришлось бы поставить `postgresql-client` (сейчас его нет: установлен только `libpq-dev`, а `psql`/`pg_dump` есть лишь в контейнере `postgres`) — иначе `migrate:fresh` в тестах не загрузит снимок | `RAG:— (repo-grounded: docker/php/Dockerfile:9-27; docker/postgres/*.sh)` |
| Регистрация 5 партиционированных таблиц в pg_partman в снимок не попадает → поверх дампа всё равно нужна миграция-регистратор | §2 выше |
| В снимок вмерзают исторические партиции, созданные на ДАТУ снятия (у нас есть миграция предсоздания исторических партиций) | §2 + `RAG:— (repo-grounded: database/migrations/2026_08_05_100074_precreate_historical_partitions.php)` |
| Пропадают докблоки миграций, несущие фактуру калибровки и ссылки на D-записи закрытых планов; имена файлов миграций цитируются в планах, коде, доках и тестах | `research/09-p8-schema-design.md` §1 |

Фактура сохранена не «на всякий случай»: если владелец когда-нибудь захочет вернуться к
дампу, эти четыре строки — готовый чек-лист того, что придётся оплатить.
