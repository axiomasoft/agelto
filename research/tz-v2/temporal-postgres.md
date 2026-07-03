# Темпоральность и версионирование в Postgres для истории госзакупок

## 1. Паттерны версионирования: снапшоты+event log vs SCD Type 2 vs temporal tables

### Native SQL:2011 system versioning в Postgres — отсутствует

По состоянию на 2025–2026 (актуальные версии — PG17/18, готовится PG19) **Postgres не реализует SQL:2011 system-versioned tables (`PERIOD FOR SYSTEM_TIME`, `WITH SYSTEM VERSIONING`, `AS OF SYSTEM TIME`) в ядре**. Это подтверждено официальным wiki-разделом Temporal Extensions и документацией релизов.

Что есть в ядре к PG18/19:
- **Application-time (valid time)** — через range-типы (`daterange`, `tstzrange`), `WITHOUT OVERLAPS` для `PRIMARY KEY`/`UNIQUE` на range-колонках, `PERIOD` для `FOREIGN KEY` — это новое в PG18, но это **валид-тайм**, не транзакционное системное время.
- **System-time (transaction time)** — только через триггеры/расширения, автоматической генерации `ROW START/END` в ядре нет.

Варианты для системного времени:
| Вариант | Статус | Комментарий |
|---|---|---|
| `temporal_tables` (PGXN, C-расширение) | maintenance-mode, "новых разработок нет годами" | Только system-period (uni-temporal), архивирует старые строки в history-таблицу через триггеры. Не работает на managed cloud PG (нельзя ставить C-расширения на Aurora/Azure/GCP) |
| `periods` extension | Активность минимальна, но ближе всего к SQL:2011/2023 семантике | Поддерживает uni-/bi-/tri-temporal |
| Ручные триггеры + history-таблица | Полный контроль, стандартный паттерн | Рекомендуется для greenfield при самостоятельном управлении PG |

Источники: [wiki.postgresql.org/wiki/Temporal_Extensions](https://wiki.postgresql.org/wiki/Temporal_Extensions), [postgresql.org/docs/current/release-18.html](https://www.postgresql.org/docs/current/release-18.html), [lord.technology — bitemporal keys PG18](https://lord.technology/2025/01/28/understanding-temporal-primary-keys.html)

**Вывод: расширения temporal_tables/periods для greenfield-проекта с десятками млн строк — рискованный выбор** (низкая поддержка, несовместимость с managed PG, если в будущем захотите перейти в облако). Лучше строить паттерн вручную на декларативном партиционировании + событийном журнале — это даёт тот же результат, но контролируется полностью проектом.

### SCD Type 2 vs Event Log — не взаимоисключающие, а разные слои

| Критерий | SCD Type 2 (снапшоты редакций) | Event log (журнал событий) |
|---|---|---|
| Что хранит | Полное состояние сущности на каждую версию (valid_from/valid_to/is_current) | Дискретные факты изменений (что именно поменялось) |
| Восстановление состояния "на дату X" | Тривиально: `WHERE valid_from <= X AND valid_to > X` | Требует replay событий от последнего снапшота/начала — дороже |
| Compliance/audit-trail ("кто/когда/что именно поменял") | Слабо — видно только итоговое состояние версии, не причину/дельту | Основное назначение — событие само по себе есть причина |
| Рост таблицы | Пропорционален числу версий сущности (может "разбухать" при частых изменениях) | Append-only, растёт линейно, хорошо партиционируется по времени |
| Точечные BI-запросы (time-travel join) | SQL простой, без window-функций | Нужны проекции/материализация под BI |

Источники: [reddit.com/r/dataengineering — SCD2 vs event log](https://www.reddit.com/r/dataengineering/comments/1sgonzy/how_is_scd_type_2_functionally_different_to_an/), [stackoverflow — audit table vs SCD2](https://stackoverflow.com/questions/24335266/audit-table-vs-type-2-slowly-changing-dimension), [6it.dev — historical data patterns](https://6it.dev/blog/historical-data-in-databases-audit-tables-event-sourcing-1343)

**Практический вывод для закупок**: типичный отраслевой паттерн — **гибрид**: event log как единственный источник истины (append-only, для аудита/compliance/восстановления состояния на любую дату) + SCD2-подобная "снапшот-таблица редакций" как **производная/кэширующая проекция** (для быстрого чтения "покажи редакцию извещения №X от даты Y" без replay). Именно так и устроена связка `procurement_revisions` + `procurement_events`, которую вы предлагаете в вопросе — это соответствует практике.

## 2. Декларативное партиционирование PG17 для десятков-сотен млн строк

### Range vs Hash

- **Range** — стандартный выбор для таблиц закупок/событий с естественной временной осью (дата публикации извещения, `occurred_at` события). Даёт partition pruning по времени, что критично для запросов "покажи закупки за квартал/год" и для retention (удаление/архивация старых партиций через `DROP`/`DETACH`, что на порядки быстрее mass DELETE).
- **Hash** — используется для равномерного распределения нагрузки, когда нет естественного диапазонного ключа для pruning (например, партиционирование по `platform_id`/`tenant_id` для параллелизации). Для событийного журнала закупок в вашем кейсе (единая площадка ЕИС, преимущественно временные запросы) **range по дате — основной выбор**; hash может иметь смысл как **вторичное измерение**, если решите делать составное партиционирование (сначала LIST/RANGE по platform, затем RANGE по времени) под будущие площадки.

Источник: [postgresql.org/docs/current/ddl-partitioning.html](https://www.postgresql.org/docs/current/ddl-partitioning.html), [smarttechdevs.in — PG partitioning для SaaS](https://smarttechdevs.in/blog/postgresql-table-partitioning-laravel-saas-architecture)

### Ловушка с UNIQUE/PK на партиционированных таблицах

Жёсткое правило ядра Postgres: **любой UNIQUE/PRIMARY KEY constraint на родительской партиционированной таблице обязан включать все колонки partition key** — потому что глобальных индексов через партиции у Postgres нет (уникальность проверяется только per-partition).

Типичная ошибка:
```
ERROR: unique constraint on partitioned table must include all partitioning columns
DETAIL: UNIQUE constraint on table ... lacks column scan_time which is part of the partition key.
```

Практические паттерны решения:
1. **Composite PK/UNIQUE** — `PRIMARY KEY (id, published_at)` вместо `PRIMARY KEY (id)`. Следствие: все FK, ссылающиеся на эту таблицу, тоже должны включать составной ключ — это меняет модель данных выше по цепочке (например, `procurement_events.procurement_id` придётся дополнить `procurement_id + occurred_at` если события ссылаются на партиционированную таблицу events, либо не партиционировать саму `procurements`, только `procurement_events`).
2. **Глобальная уникальность через отдельную непартиционированную таблицу** — если нужен, например, глобально уникальный `reg_number` (номер извещения ЕИС) независимо от даты, заводится вспомогательная непартиционированная таблица/индекс с `UNIQUE(reg_number)`, поддерживаемая триггером/приложением.
3. Уникальность только на уровне отдельных партиций (`local unique`) — если глобальная гарантия не нужна.

Источник: [stackoverflow.com — must include all partitioning columns](https://stackoverflow.com/questions/76071450/postgresql-unique-constraint-on-partitioned-table-must-include-all-partitioning)

**Для вашей схемы это значит**: партиционировать стоит в первую очередь `procurement_events` (append-only журнал, естественный кандидат на range by `occurred_at`), возможно `procurement_revisions` (по `revised_at`/`published_at`). Саму `procurements` (справочник актуальных закупок, "шапка") разумнее **не партиционировать** или партиционировать только если она реально вырастет до сотен млн строк — иначе вы платите цену составного PK (изменение FK везде) без выигрыша, так как "шапка" растёт намного медленнее событий.

### pg_partman

`pg_partman` (актуальная линия 5.x, версия 5.2.4 упоминается в релизах 2025) строится **поверх** декларативного партиционирования (не альтернатива ей, а автоматизация жизненного цикла):
- Автосоздание будущих партиций (`premake`), retention policy (`retention = 'N months'`, работает как скользящее окно "от текущего момента", не как фиксированная календарная дата),
- `retention_keep_table = false` — быстрый `DROP` партиции по истечении окна; `true` — `DETACH` для архивации в отдельное хранилище,
- Требует планировщика (`pg_cron` или внешний cron) для вызова `run_maintenance_proc()`,
- В 5.2.x добавлена поддержка UUIDv7 и time-encoded ключей партиционирования.

Источник: [postgresql.org/about/news/pg_partman-524-released](https://www.postgresql.org/about/news/pg_partman-524-released-2995/)

**Рекомендация**: `pg_partman` оправдан, если у вас много партиционированных таблиц и нужна единая автоматизированная политика retention/premake. Для 1-2 таблиц (события, ревизии) можно обойтись обычной Laravel-миграцией с raw SQL DDL + собственной scheduled command (`php artisan schedule`) на создание партиций наперёд — меньше внешних зависимостей, но `pg_partman` избавляет от написания и поддержки этой логики руками. Для регулятивного домена (госзакупки, вероятно бессрочное хранение по 44-ФЗ/223-ФЗ) retention/drop, скорее всего, не нужен вообще — важнее сама механика partition pruning и premake.

## 3. JSONB-снапшоты: TOAST-компрессия, GIN-индексы

### LZ4 vs pglz для TOAST

Для больших jsonb-снапшотов (полные редакции извещения/сметы) — **LZ4 предпочтительнее pglz** как default:
- LZ4 хуже сжимает на ~5-10% (bench: ratio ~2.07x vs ~2.23x у pglz), но **значительно быстрее** на чтении и записи.
- При параллельной нагрузке (много одновременных запросов, чтение TOAST) pglz становится CPU-bottleneck — LZ4 держит throughput ближе к несжатым данным вплоть до 32 параллельных запросов на 8 ядрах.
- На INSERT: LZ4 занимает ~20% времени от pglz на то же количество данных; при 16 параллельных writer'ах LZ4 быстрее на 60-70%.
- Доступно с PG14+: `ALTER DATABASE ... SET default_toast_compression = lz4;` или per-column `ALTER TABLE t ALTER COLUMN payload SET (toast_compression = 'lz4');`

Источник: [postgresql.fastware.com — LZ4 TOAST](https://www.postgresql.fastware.com/blog/what-is-the-new-lz4-toast-compression-in-postgresql-14), [tigerdata.com/learn/postgresql-compression](https://www.tigerdata.com/learn/postgresql-compression)

**Рекомендация**: для таблицы `procurement_revisions.snapshot jsonb` (полные редакции извещений/смет, преимущественно read-after-write с редкими повторными чтениями) выставить `default_toast_compression = lz4` на уровне БД.

### Когда jsonb становится проблемой и как индексировать

Ключевой практический вывод из бенчмарков (2.3М строк):
| Подход | Латентность на equality-запрос по скалярному атрибуту |
|---|---|
| Seq scan без индекса | ~480 мс |
| GIN на весь jsonb (jsonb_ops) | ~220 мс (bitmap + recheck) |
| BTREE expression index на `attrs->>'key'` | ~8 мс |
| Композитный BTREE на 2 скаляра | ~3 мс |

- `jsonb_ops` (default GIN) поддерживает `@>`, `?`, `?|`, `?&`, но большой индекс и медленнее.
- `jsonb_path_ops` — только `@>` (containment), но индекс меньше и запросы ~2x быстрее.
- **GIN плохо подходит для equality/scalar-запросов** ("найди все закупки с `status = 'published'` внутри jsonb") — здесь expression BTREE-индекс на извлечённом поле быстрее на 1-2 порядка.

Источники: [postgresql.org/docs/current/gin.html](https://www.postgresql.org/docs/current/gin.html), [dev.to — GIN vs BTREE jsonb](https://dev.to/baodev-studio/postgres-jsonb-indexes-gin-vs-btree-on-the-same-column-4hb0), [pulse.support/kb/postgresql-jsonb-performance](https://pulse.support/kb/postgresql-jsonb-performance)

**Рекомендация**: не индексировать полный `snapshot jsonb` généric GIN "на всякий случай". Практика:
- Часто фильтруемые/сортируемые поля (статус, сумма НМЦК, дата публикации, регион, ОКПД2, заказчик) — **вынести в реальные колонки** таблицы `procurements`/индекс на них (expression BTREE или обычные колонки при записи).
- `jsonb` в `procurement_revisions.snapshot` — использовать преимущественно для полного восстановления состояния "как было" (не для фильтрации), поэтому GIN там часто вообще не нужен; при необходимости — точечный `jsonb_path_ops` под конкретный containment-запрос (например, поиск по позициям сметы внутри снапшота).

## 4. spatie/laravel-activitylog vs своя доменная event-таблица

Вывод однозначный для вашего домена: **своя доменная event-таблица(ы)**, не Spatie activitylog.

Причины из практики:
- Spatie хранит всё в единой полиморфной `activity_log` с generic JSON `properties` — нет типизированных колонок под домен закупок (нельзя эффективно фильтровать/индексировать `old_price`/`new_status` без разбора JSON).
- Retention/партиционирование/архивация в Spatie — "одна метла на всю таблицу"; для закупок (события по разным доменным сущностям: статус, цена, документы) нужны раздельные политики и раздельные индексы.
- Инсерт-производительность у Spatie приемлема ("миллионы записей без явного замедления"), но нет встроенной async/queue записи — при высокочастотных доменных событиях (парсинг сотен тыс. извещений) лучше контролировать запись самостоятельно (batch insert, возможно через outbox).
- Для BI/аналитики поверх событий (что вам явно нужно — цены, статусы) типизированная доменная таблица с явными колонками `event_type`, `entity_id`, `field_name`, `old_value`, `new_value` кардинально проще и быстрее в запросах, чем разбор `properties` JSON у Spatie.

Источники: [finds.dev/repo/spatie/laravel-activitylog](https://finds.dev/repo/spatie/laravel-activitylog), [github.com/spatie/laravel-activitylog/discussions/842](https://github.com/spatie/laravel-activitylog/discussions/842), [reddit.com/r/laravel — activity logs at scale](https://www.reddit.com/r/laravel/comments/7ranek/how_are_you_handling_activity_logs_at_scale/)

**Рекомендация**: Spatie можно оставить точечно для человеко-читаемого admin-аудита (кто из операторов что поменял в Filament), но **не** как источник истины для доменной истории закупок — под неё проектировать `procurement_events` с явной доменной схемой.

## 5. Идентификаторы: bigint identity vs UUIDv7

Ключевые факты:
- **PG17 не имеет нативной генерации UUIDv7** — нужна либо генерация на уровне приложения (PHP/Laravel), либо расширение `pg_uuidv7` (поддерживает PG13-18).
- **PG18 добавляет нативную `uuidv7()` в ядро** (RFC 9562-совместимая, time-ordered, опциональный shift-параметр). PG18 вышел в 2025; если проект стартует на PG17 сейчас, но допускает апгрейд на PG18 в обозримой перспективе — это должно повлиять на выбор.
- Бенчмарки primary key: **bigint identity — минимальный размер индекса (0% bloat), максимальный insert throughput**. UUIDv4 (random) — до 3-3.5x медленнее вставка, ~38% bloat индекса. **UUIDv7 (time-ordered) почти догоняет bigint** (~8% bloat, ~12-15% медленнее вставка) за счёт сохранения временной локальности вставок в B-tree.
- Ширина ключа: bigint 8 байт vs UUID 16 байт — при десятках-сотнях млн строк и множестве FK/вторичных индексов разница выливается в ощутимые доп. гигабайты индексов и снижение cache-hit rate.

Источники: [the-practical-developer.online — UUID vs bigint](https://the-practical-developer.online/posts/postgres-uuid-vs-bigint-keys/), [queryplane.com/blog/uuid-vs-integer-ids-postgres](https://queryplane.com/blog/uuid-vs-integer-ids-postgres/), [credativ.de — UUIDv4 vs UUIDv7 в PG18](https://www.credativ.de/en/blog/postgresql-en/a-deeper-look-at-old-uuidv4-vs-new-uuidv7-in-postgresql-18/), [postgresql.org/docs/current/functions-uuid.html](https://www.postgresql.org/docs/current/functions-uuid.html)

**Рекомендация**: **dual-key паттерн** — внутренний `bigint GENERATED ALWAYS AS IDENTITY` как PK для FK/джойнов/производительности + отдельная колонка `uuid` (генерируемая UUIDv7, на PG17 — либо через `pg_uuidv7`, либо через приложение/Laravel, на PG18 — нативно) с `UNIQUE`-индексом как внешний идентификатор (для API, публичных ссылок, идемпотентности при интеграции с внешними площадками, будущего sharding). Это стандартная рекомендация из бенчмарков ("bigint для storage/joins, UUID для внешнего/публичного слоя").

## Рекомендации для ТЗ

1. **Схема — трёхслойная, без temporal_tables-расширений:**
   - `procurements` (bigint PK identity + uuidv7-колонка для внешних ссылок) — актуальное состояние/справочник, **не партиционировать** пока не вырастет до сотен млн (маловероятно для "шапок" закупок).
   - `procurement_revisions` — полные снапшоты редакций (jsonb, `default_toast_compression = lz4`), составной ключ `(id, published_at)` при партиционировании по `published_at` (RANGE, месяц/квартал). Индексация — точечная (expression BTREE на часто фильтруемые поля), без generic GIN на весь jsonb.
   - `procurement_events` — доменный event log (append-only, свои типизированные колонки `event_type`, `entity_id`, `field_name`, `old_value`, `new_value`, `occurred_at`, `payload jsonb` для деталей), RANGE-партиционирование по `occurred_at`, composite PK `(id, occurred_at)`.
   - Отказаться от spatie/laravel-activitylog как источника доменной истории; при желании оставить его отдельно только для UI-аудита операторских действий в Filament.

2. **Партиционирование**: RANGE по времени (месяц или квартал — зависит от объёма; для "десятки-сотни млн строк в год" месяц разумнее квартала для управляемости размера партиции). Заложить составные PK сразу в дизайн (`(id, occurred_at)`/`(id, published_at)`), учитывая, что все FK на эти таблицы должны включать partition key. Механику premake-партиций реализовать через `pg_partman` (если таких таблиц будет несколько) либо через собственную scheduled-команду Laravel (если только 1-2 таблицы) — оба варианта рабочие, выбор по operational preference команды.

3. **Retention**: госзакупки, вероятно, хранятся бессрочно (регуляторный контекст) — retention/DROP партиций закладывать не как обязательный механизм, а как опциональную возможность архивации в холодное хранилище (`DETACH` + перенос) на будущее.

4. **Восстановление состояния "на дату"**: приоритет — читать из `procurement_revisions` (снапшот уже содержит нужную редакцию), replay событий из `procurement_events` использовать только как fallback/для точечного построения снапшота между редакциями, не как основной путь чтения (дороже).

5. **jsonb-индексация**: вынести часто фильтруемые атрибуты (статус, НМЦК, регион, ОКПД2, заказчик, площадка) в обычные типизированные колонки `procurements`/`procurement_revisions` вместо расчёта на GIN по jsonb для этих полей. GIN (`jsonb_path_ops`) — только под конкретные containment-запросы, если такие появятся (например, полнотекстовый/структурный поиск внутри позиций сметы).

6. **Идентификаторы**: bigint identity как PK везде для производительности; отдельная UUIDv7-колонка (unique index) для внешних/публичных ссылок и будущей интеграции с другими площадками/шардингом. На PG17 генерировать UUIDv7 на уровне приложения (Laravel) или через `pg_uuidv7`; при апгрейде на PG18 — переключиться на нативный `uuidv7()`.

**Не удалось однозначно подтвердить**: точные бенчмарки конкретно для домена госзакупок РФ (специфика ЕИС) — вся статистика выше взята из общих Postgres-бенчмарков, не отраслевых кейсов гозакупок; экстраполяция на ваш конкретный объём/паттерн запросов требует собственного нагрузочного тестирования на репрезентативных данных.