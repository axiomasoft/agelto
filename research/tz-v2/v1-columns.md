Достаточно контекста. Формирую финальную инвентаризацию.

# Инвентаризация БД v1 (agelto) — полная схема таблиц

Источники: `database/migrations/*.php` (24 файла) + `Modules/Sys/database/migrations/*.php` (2 файла). Модулей `Platform`/`Eis` с собственными миграциями в v1 **нет** — вся доменная схема лежит в корневых миграциях, модели — в `app/Models/*`; `Modules/Eis` — только сервис краулинга без своих таблиц.

## Служебные Laravel-таблицы (стандартные, переносить как есть через `php artisan install` — не инвентаризирую детально)
`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `temporary_uploads` (сессионный upload, `session_id`+timestamps).

---

## geo_countries
`id, name, add_name?, slug(50)?` — без индексов кроме PK. Модель `App\Models\Geo\Country`.

## geo_locations
`id, country_id→geo_countries, parent_id→geo_locations(self), name(idx), add_name?, genitive_name?, parent_name?, state?, slug(unique)?, type(16,idx, комментарий "область/город/поселок"), curator_id?, region_number?, phone_code?, description(json)?, timezone(100)?, geo_point(geometry point)?, geo_borders(geometry multilinestring)?, kladr_id(idx)?, fias_code(38,idx)?, fias_path(idx)?, fias_object_level?, population(default 0), distance_to_big?, capital(bool,default false), is_show(bool,default false)`.
— Справочник ФИАС/КЛАДР с геометрией. В v2 нужен только если есть привязка закупок/заказчиков к регионам; сейчас **не используется в доменных FK** (customers/orders хранят адрес текстом, не через geo_locations) — проверить целесообразность переноса целиком.

## media (spatie/laravel-medialibrary, стандартная схема)
`id, model_type+model_id(morphs), uuid(unique)?, collection_name, name, file_name, mime_type?, disk, conversions_disk?, size, manipulations(json), custom_properties(json), generated_conversions(json), responsive_images(json), order_column(idx)?, created_at?, updated_at?`.

---

## plt_platforms
`id, name, code(32,idx), url, timestamps`. Модель `App\Models\Platform`.

## plt_platform_proxies (pivot)
`id, platform_id→plt_platforms(cascade), proxy_id→sys_proxies(cascade)`. Без timestamps.

## plt_platform_regions
`id, platform_id→plt_platforms(cascade), name(100), external_id(100)?`.

## plt_crawlers
`id, platform_id→plt_platforms(cascade), url(500)?, options(json)?, filter(json)?, state(json)?, timestamps`.
— `options/filter/state` — гибкая конфигурация краулера без миграций под каждый новый параметр площадки. Хороший паттерн для v2.

## plt_crawler_runs
`id, crawler_id→plt_crawlers(cascade), status(20,default running)`
курсор обхода: `cursor_url(1000)?, current_page?, price_from(20,2)?, price_to(20,2)?, price_stack(json)?, day_from(date)?, day_to(date)?, cursor_offset?`
`stats(json)?, started_at?, finished_at?, last_heartbeat_at(idx)?`
error-блок: `attempts(default 0), last_error?, last_error_at?, next_retry_at?, failed_permanently(bool,default false)`
`timestamps`.
Индексы: `(crawler_id, status)`; **partial unique index** `plt_crawler_runs_one_active_per_crawler ON (crawler_id) WHERE status='running'` — инвариант "не более одного активного прогона на краулер", сделан через сырой `DB::statement` (Postgres partial index).
— Это самая ценная таблица для переноса паттернов: **иерархический курсор возобновления** (price_from/to + price_stack как стек диапазонов бинарного дробления при переполнении лимита выдачи + day_from/to + cursor_offset/cursor_url) и **партиционный unique-инвариант в БД, а не в коде**.

## ord_customers
`id, full_name(text), short_name?, inn(12,unique,idx), kpp(9)?, ogrn(15)?, address(text)?, phone?, email?, timestamps`.

## ord_orders
`id, reg_number(idx) — номер извещения, edition_number(int,default 1) — редакция извещения, name(text), status?`
`customer_id→ord_customers, platform_id→plt_platforms`
`initial_price(20,2)?, currency(3,default RUB)`
даты площадки: `order_published_at?, order_updated_at? (контроль повторного парсинга), order_submission_start_at?, order_submission_end_at?, order_scoring_at?`
`timestamps`.
— `edition_number` и `order_updated_at` — механизм отслеживания редакций извещения (площадка публикует правки, нужно понимать, что перепарсивать).

## ord_lots
`id, order_id→ord_orders(cascade), lot_number(default 1), subject(text)?, okved(14,idx)?, okpd2(20,idx)?, is_centralized(bool,default false), initial_price(20,2)?, dirty_data(json)?, timestamps`.
— `dirty_data` — сборная свалка для "сырых"/неразобранных полей с площадки, чтобы не терять данные при расширении парсера. Полезный паттерн.

## plt_crawler_analytics
`id, date, crawler_id→plt_crawlers(cascade)`
посуточная аналитика по стадиям пайплайна:
`s1_found, s1_errors` (Collector),
`s2_docs_indexed, s2_errors` (Document Indexer),
`s3_ai_processed, s3_smeta_found, s3_errors` (AI Screening),
`s4_parsed_items, s4_errors` (Full Extraction) — все int default 0.
Unique `(date, crawler_id)` под именем `crawler_date_unique`. `timestamps`.
— Готовая witness-таблица метрик по 4 стадиям пайплайна из `docs/workflow/parsing-process.md` — прямой кандидат на перенос концепции в v2 (дашборд/observability).

## plt_crawler_links
`id, platform_id→plt_platforms(cascade), crawler_id→plt_crawlers(nullable, nullOnDelete), crawler_run_id→plt_crawler_runs(nullable, nullOnDelete)`
`detail_url(500)`
`reg_number(60), subject(400)?, okved(14,idx)?, okved_additional(14,idx)?`
`published_at?, platform_updated_at?, submission_end_at?`
`status(20,default pending,idx), completed_at?`
error-блок: `attempts(default 0), last_error?, last_error_at?, next_retry_at?, failed_permanently(bool,default false)`
`timestamps`.
Индексы: unique `(platform_id, reg_number)`, `(status, next_retry_at)` — "быстрая выборка готовых к ретраю".
— `okved_additional` (доп. код ОКВЭД, не только основной) — легко упускаемая деталь домена. `submission_end_at` — дедлайн подачи заявки, критичен для приоритизации очереди парсинга (просроченные не парсить).

## plt_crawler_ignore_links
`id, platform_id→plt_platforms(cascade), external_id(70,idx), reason_category(40,idx)?, reason(255)?, timestamps`.
Unique `(platform_id, external_id)`.
— Denylist с категоризацией причины игнора (`reason_category`) — полезно для анализа "почему теряем закупки" (например, не тот регион/тип закупки). Не путать с обычным failed: это осознанный skip, а не ошибка.

## plt_crawler_link_attachments
`id, link_id→plt_crawler_links(cascade), external_id(60,idx), name(500), extension(12)?, url(1000), content_hash(64,idx)?`
статус скачивания + error-блок: `status(20,default pending), attempts(default 0), last_error?, last_error_at?, next_retry_at?, failed_permanently(bool,default false), downloaded_at?`
`timestamps`.
Индексы: `(status, next_retry_at)`, unique `(link_id, external_id)`.
— `content_hash` — дедупликация одинаковых вложений по контенту (защита от повторного скачивания/анализа идентичных файлов между извещениями).

## plt_document_analysis
`id, model_type+model_id(morph, nullable, idx plt_doc_analysis_model_idx), parent_id→self(cascade)?`
`name(500)?, extension(12)?`
`file_fingerprint? — отпечаток файла (имя+размер+дата изменения)`
`source(500,idx)?`
Gemini-кэш: `gemini_cache_id?, gemini_rules_hash?, gemini_cache_expires_at?`
`options(json)?, status(40,default preparing)` — см. `AnalysisStatus` enum ниже, `completed_at?`
error-блок: `attempts(default 0), last_error?, last_error_at?, next_retry_at?, failed_permanently(bool,default false)`
**лизинг**: `locked_by?, locked_until?` — атомарный захват записи воркером на время обработки (комментарий в коде: "Phase 2").
`timestamps`.
Индексы: unique `(model_type, model_id, source)` под именем `plt_doc_analysis_owner_source_uniq` (идемпотентность firstOrCreate по владельцу+источнику), `(status, next_retry_at)`, `(status, updated_at)` (под discover retry / stuck-check).
Статусы `AnalysisStatus`: `preparing→prepared→primary_analyzing→primary_analyzed|primary_ignored|primary_analysis_failed→estimate_analyzing→estimate_analyzed|estimate_analysis_failed→completed`, плюс `error`.
— Самая насыщенная "неочевидными" полезными полями таблица: **file_fingerprint** (защита от повторного анализа неизменного файла), **gemini_cache_id/rules_hash/expires_at** (переиспользование серверного кэша Gemini между запросами — экономия токенов), **locked_by/locked_until** (lease-паттерн вместо status-flag, безопасен при параллельных воркерах), составной unique для идемпотентности сессии анализа.

## plt_document_analysis_responses
`id, document_analysis_id→plt_document_analysis(cascade), parent_id→self(cascade)? — для учёта стадий анализа`
`prompt(text) — что подавали на вход`
`step? — тип шага (primary_analysis, detailed_parsing, ...)`
`cache_file_id(idx)? — ID кэша Gemini, использованного в момент запроса`
`rules_hash? — хеш инструкций, актуальных на момент ответа`
`success(bool,default true), message?, data(json)?`
`state(json)? — состояние процесса (напр. "первичный анализ нашёл N смет → для каждой нужен отдельный запрос")`
токены (добавлены отдельной миграцией `2026_06_19`): `prompt_tokens?, completion_tokens?, total_tokens?` (все unsignedInteger) — учёт стоимости Gemini по usageMetadata.
`timestamps`.
— `rules_hash`/`cache_file_id` дают полную трассируемость: каким промптом и на каком кэше получен конкретный ответ ИИ — важно при отладке регрессий после смены промпта. Токен-метрики — основа для cost-аналитики по Gemini.

## plt_prompt_rules
`id, key(idx) — уникальный ключ правила ('header_metadata'), step(idx) — шаг применения, content(text), version(default 1) — версия для отката, priority(default 100) — чем выше, тем ниже в итоговом промпте, is_active(bool,default true), timestamps`.
Unique `(key, version)`.
— Промпты как версионируемые записи в БД, а не хардкод в коде — позволяет откатывать/A-B-тестировать промпты без деплоя. Хороший паттерн для переноса, если v2 тоже использует LLM-анализ документов.

## plt_document_analysis_logs
`id, document_analysis_id→plt_document_analysis(cascade), status(40,idx)?, message(300)?, content(json)?, trace(text)?, timestamps`.
— Пошаговый журнал по конкретной сессии анализа (отдельно от общего `sys_logs`) — полезен для UI "история анализа документа".

## ord_purchase_objects
`id, order_id→ord_orders(cascade), okpd2(20,idx)?, name(text), quantity(15,3,default 0), unit(100)?, price(15,2)?, total_cost(15,2)?, timestamps`.
— Результат полного разбора позиций закупки (стадия 4), не привязан к lot напрямую — привязан к order.

## ord_lot_infos
`id, lot_id→ord_lots(cascade), okved_text?, okpd2_text?, unit?, quantity?, unit_price?, total_price?, additional_info?` — без timestamps.
— Все "числовые" поля хранятся как `string` (не decimal!) — вероятно потому что источник — сырой текст из документа сметы до нормализации. Стоит решить в v2 осознанно (типизировать при парсинге или явно хранить как raw text с отдельным нормализованным полем), а не копировать вслепую.

---

## Modules/Sys

## sys_proxity_services
`id, name(50), code(16,unique), timestamps`. (обратите внимание — таблица называется `sys_proxity_services`, опечатка "proxity" вместо "proxy", если переносить в v2 — стоит исправить имя).

## sys_proxies
`id, service_id→sys_proxity_services(cascade), username(50), password(50), ip_address(32), port(6), targets(json)?, is_active(bool,default true), fails_count(default 0), last_error(text)?, timestamps`.
— `targets(json)` — к каким сервисам/площадкам привязан прокси; `fails_count` — счётчик отказов для авто-исключения нестабильных прокси.

## sys_logs
`id, level(20), stage(20), source, entity_type?+entity_id? (полиморфная привязка к Link/CrawlerRun/DocumentAnalysis), message(text), context(json)?, trace(text)?, ip_address(45)?, timestamps`.
Индексы: `(stage, level)`, `(entity_type, entity_id)`.
— Общий структурированный журнал по всем стадиям, с привязкой к любой сущности через морф. Комментарий в коде явно перечисляет три типа сущностей.

---

# Список неочевидно-полезных колонок (легко упустить при переносе в v2)

| Колонка | Таблица | Зачем нужна |
|---|---|---|
| `edition_number` | `ord_orders` | Номер редакции извещения — площадка публикует правки к тому же `reg_number`, без этого поля non-idempotent overwrite или потеря истории правок |
| `dirty_data` (json) | `ord_lots` | Свалка сырых/неразобранных полей — не терять данные при неполном парсере, расширяемость без миграций |
| `okved_additional` | `plt_crawler_links` | Доп. код ОКВЭД помимо основного `okved` — легко забыть, что закупка может иметь несколько кодов |
| `content_hash` | `plt_crawler_link_attachments` | Дедуп одинаковых файлов-вложений по контенту между разными извещениями — экономия скачивания и AI-анализа |
| `file_fingerprint` | `plt_document_analysis` | Отпечаток файла (имя+размер+mtime) — защита от повторного дорогого AI-анализа неизменного файла |
| `gemini_cache_id` / `gemini_rules_hash` / `gemini_cache_expires_at` | `plt_document_analysis` | Переиспользование серверного контекстного кэша Gemini между запросами — прямая экономия токенов/денег |
| `cache_file_id` / `rules_hash` | `plt_document_analysis_responses` | Трассируемость: каким кэшем/версией промпта получен конкретный ответ — критично при отладке регрессий после смены промпта |
| `prompt_tokens` / `completion_tokens` / `total_tokens` | `plt_document_analysis_responses` | Cost-аналитика по LLM per-request |
| `price_stack` (json) + `price_from`/`price_to` + `day_from`/`day_to` + `cursor_offset` | `plt_crawler_runs` | Иерархический курсор возобновления обхода — бинарное дробление диапазона цен/дат при превышении лимита выдачи площадки; без этого нет надёжного resume после сбоя на площадках с пагинацией-ограничением |
| `next_retry_at` + `failed_permanently` + `attempts` + `last_error_at` | почти все "рабочие" таблицы (`plt_crawler_runs`, `plt_crawler_links`, `plt_crawler_link_attachments`, `plt_document_analysis`) | Единый "error-блок"-паттерн для retry/backoff/терминальных ошибок — переиспользуемый шаблон колонок, а не ad-hoc `is_failed` |
| `last_heartbeat_at` | `plt_crawler_runs` | Обнаружение зависших ("stuck") прогонов воркера |
| `locked_by` / `locked_until` | `plt_document_analysis` | Lease-паттерн атомарного захвата записи воркером — безопаснее чем просто `status`-флаг при параллельных воркерах (описан в коде как "Phase 2") |
| partial unique index `... WHERE status='running'` | `plt_crawler_runs` | Инвариант "один активный прогон на краулер" на уровне БД (Postgres partial index), а не в коде — устойчив к гонкам |
| `reason_category` | `plt_crawler_ignore_links` | Категоризация причины осознанного игнора закупки (не error, а skip) — база для анализа "почему теряем закупки" |
| `s1_..s4_*` метрики | `plt_crawler_analytics` | Готовая посуточная witness-таблица по всем 4 стадиям пайплайна, unique `(date, crawler_id)` |
| `submission_end_at` | `plt_crawler_links` | Дедлайн подачи заявки — нужен для приоритизации очереди (не тратить AI-бюджет на просроченные закупки) |
| `version` / `priority` / `is_active` | `plt_prompt_rules` | Промпты как версионируемые/откатываемые записи в БД, не хардкод |
| `state` (json) | `plt_crawlers`, `plt_document_analysis_responses` | Гибкое хранение промежуточного состояния многошагового процесса без отдельных колонок под каждый шаг |
| `options`/`filter` (json) | `plt_crawlers` | Конфигурация краулера под конкретную площадку без миграций на каждый новый параметр |
| `fails_count` | `sys_proxies` | Счётчик отказов для авто-исключения нестабильных прокси из ротации |

---

# Что НЕ стоит переносить как есть

- **`geo_locations`/`geo_countries`** — тяжёлый справочник ФИАС/КЛАДР с geometry-полями (`geo_point`, `geo_borders`), но в доменных таблицах v1 (`ord_customers`, `ord_orders`) реально **не связан FK** — адрес хранится текстом. Похоже на подготовку "на будущее", которая не была доведена до использования. В v2 переносить только если гео-справочник реально нужен домену, и делать через нормальную FK-связь с customer/order, а не отдельно.
- **`sys_proxity_services`** — опечатка в имени таблицы ("proxity" вместо "proxy"), при переносе исправить.
- **`ord_lot_infos`** — числовые поля (`quantity`, `unit_price`, `total_price`) типизированы как `string`, а не `decimal` — вероятно техдолг ("сырой текст из документа"), в v2 стоит решить осознанно: либо raw+normalized пара колонок, либо нормализация на этапе парсинга.
- **`temporary_uploads`** — тривиальная сессионная таблица под upload-виджет, специфична для конкретного UI-компонента, переносить только вместе с ним.
- Дублирование error-блока (`attempts/last_error/last_error_at/next_retry_at/failed_permanently`) вручную в 4 разных таблицах — в v2 стоит вынести в переиспользуемый trait/concern на уровне Eloquent + миграций, а не копипастить блок колонок (сам набор колонок ценен, буквальное копирование кода — нет).

Пути миграций для сверки: `/home/vostrikov/projects/agelto/agelto/database/migrations/` (24 файла) и `/home/vostrikov/projects/agelto/agelto/Modules/Sys/database/migrations/` (2 файла). Модели с явными `$table` — `/home/vostrikov/projects/agelto/agelto/app/Models/**` и `/home/vostrikov/projects/agelto/agelto/Modules/Sys/app/Models/**`.