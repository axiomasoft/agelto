# ТЗ v2 · 01 — Схема базы данных

> PostgreSQL 17 (готовность к 18: UUIDv7). Обоснования — `research/tz-v2/ocds-schema.md`, `research/tz-v2/temporal-postgres.md`, `research/tz-v2/schema-validation-l2.md`; разведка — `research/eis-csv-recon.md`, `research/eis-card-recon.md`.

## 0. Принципы схемы

1. **OCDS 1.1 — словарь и референс-модель, не буквальная схема.** Декомпозиция (parties, tender/lots/items, documents, awards, amendments-как-релизы) и паттерн `releases → compiledRelease`: текущее состояние закупки — материализованная проекция, история — append-only.
2. **Три слоя истории**: `prc_procurements` (текущее состояние) + `prc_revisions` (полные снапшоты редакций, jsonb) + `prc_events` (типизированный журнал). Без temporal-расширений (maintenance-mode, несовместимы с managed PG).
3. **Идентификаторы**: везде `bigint GENERATED ALWAYS AS IDENTITY` как PK; на внешних сущностях (закупки, документы, сметы, организации) — дополнительно `uuid` (UUIDv7, генерация в приложении на PG17; unique) для API/ссылок/идемпотентности. Тип PK менять постфактум на больших таблицах = full rewrite → фиксируем bigint сразу.
4. **Партиционирование с первого дня** там, где рост линеен от событий: `prc_events`, `prc_revisions`, `llm_calls`, `prc_fetches`, `prc_operation_logs` — RANGE по времени, помесячно. «Шапки» (`prc_procurements`, `prc_documents`) не партиционируем.
   - Правило PG: UNIQUE/PK на партиционированной таблице обязан включать partition key → PK `(id, <time>)`; глобальная уникальность — на непартиционированных шапках.
   - Обслуживание: **pg_partman** (premake на 3 мес вперёд, поддержка UUIDv7-ключей); своя scheduled-команда — fallback, если расширение недоступно.
5. **jsonb**: `default_toast_compression = lz4`. Никаких generic GIN «на всякий случай» — фильтруемое выносим в типизированные колонки; GIN (`jsonb_path_ops`) — только под конкретный containment-запрос.
6. **Спец-поля площадок**: гибрид — ядро в колонках; редкое — `attributes jsonb`; богатая площадка — extension-таблица 1:1 (`prc_eis_details`).
7. **Статусы**: канонический закрытый enum ядра + сырой статус источника (`source_stage`). Маппинг сырой→канон — данные (таблица `prc_status_mappings`), не хардкод.
8. **Money**: `numeric(18,2)` + `currency char(3)`. Даты источника — `date`/`timestamptz` в колонках, наши метки — `*_at timestamptz`.
9. **Три разных «version»** держим раздельно, не сводим в одно поле: `source_edition` (редакция извещения на площадке), `document_version` (версия файла), внутренние ревизии `prc_revisions.revision_number`.
10. **Классификаторы (ОКПД2/КТРУ/ОКВЭД2/КБК) — справочниками** (`ref_*`) с историей версий; в позициях/закупках хранить **и сырой код как в источнике, и FK** на справочник (регуляторный аудит требует зафиксировать код на момент публикации, даже если справочник потом изменится).
11. **Организации — единый реестр** (`org_organizations`) + pivot ролей (`org_procurement_parties`), не плоские FK-колонки на каждую роль (OCDS parties-подход): у одной организации много ролей в разных закупках.

### 0.1 Таксономия префиксов (модуль = префикс)

Канон (D11, аудит F ось 1; домен `llm_` — D22 плана `2026.07.28-EST-ARCH`): `prc_` — единый мегадомен закупок (площадки и их справочники, ядро, история, детальная карточка, документы, AI-подомен, сметы, краулинг, операционная инфраструктура). Классификаторы и организации — общий shared-слой (`ref_`, `org_`), не принадлежат конкретному домену. `llm_` — ЧЕТВЁРТЫЙ доменный префикс: LLM-транспорт (провайдер/маршрут/промт/вызов), выделен из `prc_` планом `2026.07.28-EST-ARCH` — он не несёт ни одного закупочного понятия. Девятипрефиксная схема ниже (`plt_`/`doc_`/`ai_`/`est_`/`crw_`/`sys_`) — исходный черновой замысел ТЗ, отклонена в пользу (b) при реализации; не воспроизводить в новом коде.

| Префикс | Домен | Таблицы |
|---|---|---|
| `ref_` | Классификаторы (shared) | okpd2, ktru, okved2, kbk |
| `org_` | Организации (shared) | organizations |
| `llm_` | LLM-транспорт (shared, D22) | providers, routes, prompts, calls |
| `prc_` | Закупки — единый мегадомен | platforms, registries, status_mappings, regions, procurement_parties, procurements, revisions, events, lots, items, financing, eis_details, protocols, bids, awards, clarifications, cancellations, documents, document_versions, prc_ai_analyses, prc_ai_dataset_examples, prc_ai_eval_runs, prc_ai_eval_results, estimates, estimate_sections, estimate_items, estimate_resources, estimate_totals, crawlers, crawler_runs, proxies, fetches, proxy_checks, ignore_rules, ignored_items, daily_stats, operation_logs, search (витрина, §10) |

Внутри `prc_`-мегадомена имя по умолчанию без повтора домена (`prc_lots`, а не `prc_procurement_lots`). Осознанное отклонение — сателлиты уже занятых корней: `prc_estimate_items/sections/resources/totals` (не `prc_items` — коллизия с позициями объекта закупки), `prc_crawler_runs` (не `prc_runs`). Фактические имена индексов/FK везде выровнены под настоящее имя таблицы (raw-DDL в 100018/100026/100033/100039/100041/100046 — правка аудита F).

## 1. Справочники и классификаторы

### prc_platforms
`id, code text unique (eis), name, base_url, is_active`.

### prc_registries — реестр внутри площадки (у ЕИС — два)
`id, platform_id FK, code text (fz44/fz223) unique(platform_id,code), name, settings jsonb (лимиты выдачи, шаблоны URL карточек по типам процедур ea20/ok20/zk20/ezt20)`.

### prc_status_mappings — маппинг сырых статусов (данные, не код)
`id, registry_id FK, source_stage text unique(registry_id,source_stage), canonical_status text (enum)`. Неизвестный сырой статус → `unknown` + событие + алерт; оператор дополняет в админке (закрывает конфигурируемость 223-ФЗ).

### prc_document_types — снесён (D16 плана `2026.07.28-EST-ARCH`)
SSOT типа документа — enum `App\Enums\Procurement\Ai\AiDocumentType` (код) + реестр анализаторов `DocumentAnalyzerRegistry` (`config('ai.analyzers')`); `has_extractor` заменён фактом «в реестре есть анализатор с извлечением». Целостность колонок `document_type` (`prc_ai_analyses`, `prc_ai_dataset_examples`, `llm_routes`, `llm_prompts`) — check-constraint по `AiDocumentType::values()`, не FK на таблицу.

### prc_regions — справочник регионов площадки
`id, platform_id FK, external_id text (код региона на площадке), name text, region_code text nullable (наш внутренний код)`. Перенос v1 `plt_platform_regions`: словарь «код региона площадки → имя/внутренний код» для фильтра краулера по региону.

### ref_okpd2 / ref_ktru / ref_okved2 / ref_kbk — классификаторы
Общий паттерн: `id, code text unique, parent_code text nullable index, level int, name text, valid_from date, valid_to date nullable, attributes jsonb`. Иерархия через `parent_code`; версионность через `valid_from/valid_to` (коды переиздаются — историческая закупка привязана к коду как в источнике). `ref_ktru` дополнительно `okpd2_code` (КТРУ ⊂ ОКПД2). Наполняются из официальных выгрузок (Росстат/Минфин) отдельным загрузчиком.

## 2. Организации и роли (shared)

### org_organizations — единый реестр (OCDS parties)
| Колонка | Тип | Примечание |
|---|---|---|
| id | bigint PK | |
| uuid | uuid unique | UUIDv7 |
| inn | text nullable index | |
| kpp, ogrn | text nullable | unique `(inn, kpp)` nulls not distinct |
| eruz_number | text nullable index | номер в ЕРУЗ (единый реестр участников закупок) |
| full_name, short_name | text | |
| address_raw | text nullable | адрес как в источнике |
| region_code | text nullable index | денормализация для срезов |
| phone, email | text nullable | |
| attributes | jsonb | |

Entity resolution по ИНН/ОГРН: одна master-запись на организацию поверх всех упоминаний.

### org_procurement_parties — участие организации в закупке с ролью (pivot)
| Колонка | Тип | Примечание |
|---|---|---|
| id | bigint PK | |
| procurement_id | FK prc_procurements index | |
| organization_id | FK org_organizations index | |
| role | text (enum) | `customer`, `placer` (размещающая), `authorized_body` (уполномоченный орган), `specialized_organization` (спец. организация, ст.40 44-ФЗ), `platform_operator` (оператор ЭТП), `winner`, `participant`, `guarantor` (банк-гарант) |
| first_seen_revision_id | bigint nullable | роль «как заявлено» в редакции (ingest-слой faithful to source) |
| attributes | jsonb | контактное лицо (ФИО/должность) и пр. |

Unique `(procurement_id, organization_id, role)`. Быстрый доступ к заказчику — денормализованный `prc_procurements.customer_org_id` (см. §3); полный набор ролей (включая участников/победителя/гаранта) — здесь.

## 3. Ядро закупок и история

### prc_procurements — текущее состояние (compiledRelease-проекция)
| Колонка | Тип | Примечание |
|---|---|---|
| id | bigint PK | |
| uuid | uuid unique | UUIDv7 |
| registry_id | FK prc_registries | |
| registry_number | text | reg_number ЕИС; **unique `(registry_id, registry_number)`** — глобальный дедуп |
| etp_number | text nullable | номер на ЭТП (для 223-ФЗ часто ≠ номеру ЕИС) — держим отдельно |
| ikz | text nullable index | ИКЗ 44-ФЗ (разбор — в `prc_eis_details`) |
| title | text | наименование объекта закупки |
| status | text (enum) | канонический, §3.1 |
| source_stage | text | сырой статус (этап закупки) как есть |
| procedure_type | text nullable | нормализованный код способа закупки |
| procedure_type_raw | text nullable | как в источнике (223-ФЗ — свободный текст по положению) |
| customer_org_id | FK org_organizations nullable index | заказчик (денормализация; все роли — в `org_procurement_parties`) |
| initial_price | numeric(18,2) nullable | НМЦК |
| currency | char(3) nullable | |
| advance_percent | numeric(5,2) nullable | размер аванса, % |
| okved_code | text nullable index | ОКВЭД основной (перенос из v1 `plt_crawler_links.okved`) |
| okved_additional | text nullable index | ОКВЭД дополнительный (v1 `okved_additional` — легко упускаемая деталь) |
| region_code | text nullable index | |
| published_at | timestamptz index | дата размещения (источник) |
| source_updated_at | timestamptz index | дата обновления (источник) — драйвер fresh-скана |
| bidding_starts_at, bidding_ends_at | timestamptz nullable | сроки подачи заявок |
| summary_at | timestamptz nullable | дата подведения итогов |
| detail_url | text nullable | карточка |
| detail_fetched_at | timestamptz nullable | когда снят детальный слой (`02` §5) |
| detail_attempts, detail_last_error, detail_next_retry_at, detail_failed | int/text/timestamptz/bool | error-блок стадии детального слоя (v1-канон): ретрай снятия карточки по `detail_fetched_at IS NULL OR detail_next_retry_at <= now`, без реконструкции через логи |
| registry_guid | text nullable index | GUID извещения на площадке (223-ФЗ `noticeGuid` — обязателен для части вкладок карточки, см. `eis-card-recon.md` §10; для 44-ФЗ — `null`, там GUID не требуется) |
| current_revision_id | bigint nullable | id последнего снапшота |
| current_revision_detected_at | timestamptz nullable | **partition key снапшота** — обязателен рядом с id: без него lookup по `current_revision_id` не даёт partition pruning. Резолв: `(id=… AND detected_at=…)` или `(procurement_id, detected_at DESC LIMIT 1)` |
| revision_count | int default 1 | |
| ignored_at, ignore_reason | timestamptz/text nullable | скрыто фильтрами |
| attributes | jsonb | платформо-специфичное редкое |
| first_seen_at, last_seen_at | timestamptz | наши метки |
| created_at, updated_at | timestamptz | |

Индексы: `(registry_id, status, published_at)`, `(status, bidding_ends_at)`, `(customer_org_id)`.
`okpd2_code`/`okpd2_codes` (+ GIN-индекс) сняты (D16 плана `2026.08.06-CRAWL-HARDEN`) — колонка
листинга на боевых данных всегда пуста, принадлежность скоупу ОКПД2 гарантирует серверный фильтр
профиля (P2.2–P2.3), второй носитель того же факта не заводится.

#### 3.1 Канонический статус (закрытый codelist, по мотивам OCDS tenderStatus)
`announced` → `bidding` (подача заявок) → `evaluation` (работа комиссии) → `awarded` (определён поставщик) → `completed` | `cancelled` | `unsuccessful` (не состоялась) | `unknown` (не смаппился — сигнал в мониторинг). `prc_lots.status` — тот же codelist (OCDS: отдельного lotStatus нет).

### prc_revisions — снапшоты редакций (партиции RANGE by detected_at, месяц)
`id PK (id, detected_at); procurement_id index; revision_number int (монотонный per-закупка) unique(procurement_id,revision_number,detected_at); source_edition text nullable (редакция извещения ЕИС); change_reason text nullable (причина: продление/изменение НМЦК/техправка); snapshot jsonb (полное состояние + список документов с хэшами); snapshot_hash text (sha256 — дедуп «редакция не изменилась»); source_updated_at timestamptz nullable; detected_at timestamptz (partition key); crawler_run_id bigint nullable`.

Чтение «состояние на дату X» — последняя ревизия с `detected_at <= X` (без replay). Replay событий — только диагностика.

### prc_events — журнал (партиции RANGE by occurred_at, месяц)
`id PK (id, occurred_at); procurement_id index; revision_id bigint nullable; event_type text (enum); entity_ref jsonb nullable (ссылка на породившую сущность: протокол/разъяснение/отмену — §4); field text nullable; old_value, new_value text nullable (нормализованные строки для BI); payload jsonb nullable; occurred_at timestamptz (partition key)`.

`event_type`: `discovered`, `revision_published`, `status_changed`, `price_changed`, `dates_changed`, `document_added`/`replaced`/`removed`, `lot_changed`, `protocol_published`, `clarification_published`, `cancelled`, `restored`, `award_made`, `ignored`. Наполняется **тремя источниками**: (1) diff редакций (цена/сроки/поля), (2) типизированные сущности §4 (`prc_protocols`/`prc_clarifications`/`prc_cancellations`/`prc_awards` — при их создании пишется событие), (3) журнал событий ЕИС (`event-journal.html`) как **дополняющий** источник дат переходов этапов. Приоритет — типизированные сущности, не парсинг текстовых строк журнала: `research/tz-v2/events-results-l2.md` §1 предупреждает, что структура вкладки не подтверждена публичной схемой и хрупка; журнал используем для дат/подтверждения, а бизнес-данные строим из документов-протоколов. `prc_events` — тонкий агрегатор-проекция (факт+ссылка). `revision_published` — на каждую ревизию; редакция без изменений ядра, но с новым `source_edition` — только `revision_published`. Индекс `(procurement_id, occurred_at)`, `(event_type, occurred_at)`.

### prc_lots
`id PK; procurement_id FK unique(procurement_id,lot_number); lot_number int; lot_guid text nullable unique(procurement_id,lot_guid) (223-ФЗ — свой GUID лота из `lot-list.html`, см. `eis-card-recon.md` §10; 44-ФЗ — `null`, весь объект закупки = единственный виртуальный лот с `lot_number=1`); subject text; status text(enum §3.1); is_centralized bool default false (223-ФЗ «Централизованная закупка»); initial_price numeric(18,2) nullable; currency char(3) nullable; okpd2_code text nullable; okved_code text nullable; attributes jsonb`.

### prc_items — позиции объекта закупки (OCDS tender.items)
| Колонка | Тип | Примечание |
|---|---|---|
| id | bigint PK | |
| procurement_id | FK index | |
| lot_id | FK prc_lots nullable | |
| position_number | int nullable | № позиции в объекте закупки |
| name | text | наименование товара/работы/услуги |
| characteristics | text nullable | характеристики/доп. требования (текст) |
| classifier_scheme | text | `okpd2` / `ktru` / будущие (OCDS classification: схема заменяема) |
| classifier_code | text index | сырой код |
| okpd2_ref_id, ktru_ref_id | FK ref_* nullable | резолв в справочник |
| quantity | numeric(15,3) nullable | |
| unit | text nullable | |
| unit_price, total_cost | numeric(18,2) nullable | |

### prc_financing — источники финансирования по годам
`id, procurement_id FK index, lot_id FK nullable, kind text (budget/extrabudget/national_project), kbk text nullable, kbk_ref_id FK ref_kbk nullable, kvr text nullable (код вида расходов), receipt_code text nullable, fiscal_year int nullable, is_beyond_horizon bool default false (бакет «последующие годы»), amount numeric(18,2)`. Годовая разбивка финобеспечения из карточки (см. `eis-card-recon.md` §3). Строку «Всего» **не** сохраняем отдельной записью — она производная (сумма по годам); во избежание задвоения при агрегации. «Последующие годы» — `fiscal_year IS NULL AND is_beyond_horizon`.

### prc_eis_details — extension 1:1 (богатая ЕИС-специфика)
`procurement_id PK/FK`; поля из карточки, не входящие в универсальное ядро:
- Разбор **ИКЗ** (36 разрядов): `ikz_year, ikz_customer_code, ikz_plan_number, ikz_plangraph_number, ikz_okpd_class` (разр. 30–31), `ikz_okpd_subclass` (32), `ikz_okpd_group` (33), `ikz_kvr` (34–36) — готовый ключ связи с планом-графиком и бюджетом (разбивка объекта — `research/tz-v2/card-structure-l2.md` §2.2).
- Связь с планом-графиком: `plan_graph_position` + URL.
- Обеспечение: `bid_security_required bool, bid_security_percent numeric, bid_security_amount numeric, performance_security_required bool, performance_security_percent numeric, performance_security_amount numeric`.
- Признаки: `is_smp_only bool` (только СМП/СОНКО), `anti_dumping_applied bool`, `treasury_support bool`, `advantages jsonb` (преимущества ст.28–29), `national_regime jsonb` (нацрежим ст.14 / ПП 1875), `participant_requirements jsonb`.
- ЭТП: `etp_name, etp_url`.
- Сроки исполнения: `execution_starts_at, execution_ends_at` (может быть текстом «с даты заключения»).
- `contact_person jsonb` (ФИО/должность/тел/email), `print_form_urls jsonb`.

Заполняется адаптером ЕИС; ядро о таблице не знает.

## 4. Детальная карточка: результаты, протоколы, процессуальные сущности

Наполняются на стадии детального анализа карточки (`02` §5) из вкладок `supplier-results`/`documents`/`event-journal`. Для 44-ФЗ типы закрыты (enum), для 223-ФЗ — open-ended + `payload jsonb` (структура по положению заказчика).

### prc_protocols — протоколы процедуры
`id, uuid, procurement_id FK index, type text (рассмотрение_первых/вторых_частей, проведение_аукциона, оценка, подведение_итогов, несостоявшаяся, обоснование_ед_поставщика, прочее_223), stage text nullable, source_number text nullable (№ протокола), signed_at, published_at, document_version_id FK prc_document_versions nullable (файл-источник), payload jsonb (комиссия, обоснования), created_at`.

### prc_bids — заявки/предложения участников (построчно из протокола/результатов)
`id, procurement_id FK index, lot_id FK nullable, protocol_id FK prc_protocols nullable, organization_id FK org_organizations nullable, bid_number text nullable (ID заявки на площадке), participant_ordinal int nullable (порядковый по рассмотрению), price numeric(18,2) nullable, status text (admitted/rejected/winner), reject_reason text nullable, final_rank int nullable`.

### prc_awards — итог по лоту (OCDS awards; агрегат из протокола/bids)
`id, uuid, procurement_id FK index, lot_id FK nullable, winner_org_id FK org_organizations nullable, final_price numeric(18,2) nullable, currency char(3) nullable, status text (awarded/unsuccessful/cancelled), contract_registry_number text nullable (связь с реестром контрактов), awarded_at, payload jsonb`. Денормализованная витрина результата + якорь под будущий слой контрактов.

### prc_clarifications — разъяснения документации
`id, procurement_id FK index, requested_at date nullable (анонимно), answered_at date nullable, question text nullable, answer text nullable, document_version_id FK nullable`. Не двигает `revision_number` (в отличие от изменения извещения).

### prc_cancellations — решения об отмене
`id, procurement_id FK index, decision_at, reason text nullable, document_version_id FK nullable, reverted_at timestamptz nullable (отмена отмены — возобновление процедуры)`.

## 5. Документы

### prc_documents — логический документ закупки
`id, uuid, procurement_id FK index, external_id text nullable (uid filestore ЕИС) unique(procurement_id,external_id), title, kind text nullable (извещение/протокол/…), source_url, status text(enum: discovered→downloaded→prepared→screened→extracted / ignored / failed), first_seen_revision_id bigint nullable, removed_at timestamptz nullable`, + error-блок (`attempts, last_error, last_error_at, next_retry_at, failed_permanently`).

### prc_document_versions — контент-адресованные версии файла
`id, document_id FK index, content_hash text (sha256) unique(document_id,content_hash) + index (глобальный дедуп), size_bytes bigint, filename, extension, mime, storage_path text (object storage, immutable), downloaded_at, parent_version_id FK nullable (дочерние из архивов ZIP/RAR)`. Замена файла = новая версия + событие `document_replaced`; повторное скачивание того же контента — no-op по хэшу.

### prc_document_representations — подготовленные представления версии
`id, document_version_id FK index cascade, kind text (pdf/pdf_ocr/text/text_grid/structured_xml — значение RepresentationKind, ТЕКСТОМ: новый вид не требует миграции), storage_path text, disk text nullable (null = диск документов), size_bytes bigint nullable, page_count int nullable, token_estimate int nullable, mime text, meta jsonb (инструмент, версия инструмента, длительность генерации, исходный kind), generated_at, unique(document_version_id, kind)`. Оригинал представлением НЕ дублируется — это `prc_document_versions.storage_path`. Признаки годности измеримые; пороги потребителя живут в capability-матрице модели, не в схеме.

## 6. AI-анализ

> Сметы — в §6b; LLM-конфигурация — в §6c (детали `04`).

### prc_ai_analyses — сессия анализа версии документа
`id, uuid, document_version_id FK index, step text (screening/extraction) unique(document_version_id,step), status text (pending→running→succeeded/failed/skipped), document_type text nullable (code из AiDocumentType, check-constraint, D16), result jsonb nullable, provider text, model text, locked_by, locked_until (lease), + error-блок, started_at, finished_at`.
Кэш и прогресс (перенос из v1 `plt_document_analysis`): `cache_id text nullable, cache_expires_at timestamptz nullable` (быстрая проверка валидности Gemini-кэша без похода в API), `rules_hash text nullable` (инвалидация кэша при смене версии промптов — если не совпал с текущим, кэш пересоздать), `progress jsonb nullable` (состояние многошаговой экстракции: сколько строк/страниц пройдено и что осталось — резюм прерванного разбора **без полного повтора**, см. `03` §3.5).

### llm_calls — каждый вызов LLM (партиции RANGE by called_at, месяц; домен `llm_`, D22)
`id PK (id, called_at); analysis_id bigint nullable index; purpose text (screening/extract_passport/extract_rows/extract_totals/eval/shadow — совпадает с llm_routes.purpose); provider, model; prompt_version_set jsonb; cache_id text nullable (Gemini cachedContent); request_ref, response_ref text (object storage); prompt_tokens, completion_tokens, cached_tokens, total_tokens int; cost_micros bigint nullable; success bool; error text nullable; duration_ms int; called_at timestamptz (partition key)`.

### 6b. Сметы (est_*)

### prc_estimates — распарсенная смета (нормализованная)
`id, uuid unique, analysis_id FK unique, document_version_id FK index, procurement_id FK index, title, estimate_number text nullable, total_amount numeric(18,2) nullable, currency char(3), base_year, price_level text nullable, passport jsonb, raw_result jsonb (сырой ответ LLM целиком — по решению «нормализованные + сырой jsonb»; для переобработки/датасета), raw_result_ref text nullable (object storage только для payload > ~1 МБ), rows_expected int, rows_extracted int, validation_status text`.

### prc_estimate_sections — дерево разделов
`id, estimate_id FK index, parent_id FK nullable, position int, title, section_number text nullable` (+ `path ltree` опц. позже).

### prc_estimate_items — позиции
`id, estimate_id FK index, section_id FK nullable, row_index int unique(estimate_id,row_index), code text nullable (шифр ФЕР/ТЕР), name, unit text nullable, quantity numeric(18,6) nullable, unit_price/total_price numeric(18,2) nullable, attributes jsonb (коэффициенты/индексы)`.

### prc_estimate_resources — ресурсы позиции
`id, item_id FK index, resource_type enum(labor/machine/material/overhead/other), code, name, unit, quantity, price`. `overhead` (НР/СП/накладные/прибыль) — отдельная категория (перенос из v1 `EstimateResourceType`), чтобы маркировать накладные прямо на ресурсе, не только в итогах.

### prc_estimate_totals — итоговые строки/начисления (иерархия)
`id, estimate_id FK index, parent_id FK nullable (self), position int, label text, kind text nullable (НР/СП/НДС/…), amount numeric(18,2)`. `parent_id` — вложенные итоги («Итого» → «в т.ч. НДС»), перенос рекурсии `EstimateFooterItem` из v1.

### 6c. LLM-конфигурация — домен `llm_` (детали — 04, D22)

- **llm_providers**: `id, code (gemini/local-vllm/…), driver, base_url nullable, options jsonb, is_active` (ключи — в env/secrets, в БД только имя секрета).
- **llm_routes**: матрица — `document_type text, purpose text, provider_id FK, model text, options jsonb, priority int, is_active, shadow bool`; unique `(document_type, purpose, priority)`. `purpose` — из набора `llm_calls.purpose`. `shadow=true` — теневой прогон (флаг, не значение purpose). `document_type` = `code` из `AiDocumentType` (check-constraint, D16, D17) **или зарезервированное `any`** (wildcard); **точное совпадение приоритетнее `any`** (сначала exact, потом any). Реализована таблицей+Filament, **не spatie-settings** — осознанное отклонение от буквы решения владельца («настройка в админке через spatie-settings»): spatie-settings хранит скалярные настройки, а матрица роутинга многострочная (тип×шаг → строка). Обе цели решения сохранены: *настраивается в админке без деплоя* (Filament-ресурс), а *скалярные* LLM-настройки (провайдер по умолчанию, троттлинг) остаются в spatie-settings (`05` §2.4).
- **llm_prompts**: `id, document_type text, key, step, content, version, priority, is_active, is_protected`, unique `(key, version)`; фиксация `prompt_version_set` в `llm_calls`.
- **prc_ai_dataset_examples**: `id, document_version_id FK, task (screening/extraction), gold jsonb | gold_ref, source (llm/manual/corrected), verified_by, verified_at, version int`.
- **prc_ai_eval_runs**: `id, dataset_version, provider, model, prompt_version_set jsonb, metrics jsonb (precision/recall/F1, row_coverage), ci_status (pass/fail), started_at, finished_at`.
- **prc_ai_eval_results**: `id, eval_run_id FK index, dataset_example_id FK, metric text, value numeric, details jsonb nullable`.

## 7. Краулинг (crw_*)

### prc_crawlers — профиль обхода
`id, registry_id FK, name, mode enum(fresh/archive), source_method enum(csv/html/api), fallback_method nullable, filter jsonb (даты/цены/ОКПД2/этапы), schedule text nullable (cron), options jsonb (recordsPerPage, лимиты дробления, колонки CSV), is_active`.

### prc_crawler_runs — прогон (heartbeat + курсор + one-active)
`id, crawler_id FK (частичный unique: один running на crawler), status enum(running/paused/completed/failed/cancelled), cursor jsonb (день/ценовой стек/блок from/признак «дошли до известного»), stats jsonb, started_at/finished_at/last_heartbeat_at, + error-блок`.

### prc_fetches — забор блока (партиции RANGE by fetched_at, месяц)
`id PK (id, fetched_at); crawler_run_id index; method text; request_params jsonb (без секретов); artifact_ref text nullable (сырой CSV/HTML в storage); http_status, duration_ms int; rows_total/rows_new/rows_updated int; dedup_signature text nullable (первый reg_number блока — детект «тихого cap»); proxy_id bigint nullable; fetched_at timestamptz (partition key)`.

### prc_proxies / prc_proxy_checks
Как v1 `sys_proxies` (service, creds, targets, is_active, fails_count) + журнал проверок. Креды — только здесь.

### prc_ignore_rules
`id, registry_id nullable, field text, operator text (contains/regex), value text, reason_category, is_active` → попадание = `prc_procurements.ignored_at` + событие `ignored`.

### prc_ignored_items — denylist конкретных закупок (перенос v1 `plt_crawler_ignore_links`)
`id, registry_id FK, registry_number text unique(registry_id,registry_number), reason_category text index, reason text` — осознанный skip конкретной закупки **до** создания `prc_procurements` (не ошибка, а решение «не берём»); отделено от `prc_ignore_rules` (правила) и от failed (ошибки). Полезно для анализа «почему теряем закупки».

### prc_daily_stats — посуточная аналитика по стадиям (перенос v1 `plt_crawler_analytics`)
`id, date, crawler_id FK, unique(date, crawler_id)`; счётчики по стадиям: `listing_found/listing_errors`, `detail_fetched/detail_errors`, `docs_indexed/docs_errors`, `screened/estimates_found/screen_errors`, `extracted_items/extract_errors`. Готовый посуточный срез для дашборда — дешевле, чем агрегировать партиционированные `prc_fetches`/`llm_calls` на каждый запрос; наполняется инкрементально процессорами стадий.

## 8. Операционная инфраструктура

### prc_operation_logs — операционный журнал (аналог v1 sys_logs; партиции RANGE by created_at, месяц)
Технический журнал стадий/ошибок (**не** доменная история — та в `prc_events`).
`id PK (id, created_at); level text (debug/info/warning/error); stage, source text nullable; entity_type text nullable, entity_id bigint nullable (полиморфная привязка); message text; context jsonb nullable; trace text nullable; created_at timestamptz (partition key)`. Индекс `(entity_type, entity_id)`. Операторский аудит действий в админке — отдельно (spatie/activitylog, `05` §5).

## 9. Хранилище (object storage, S3/MinIO)

- `raw/csv/…` — CSV-артефакты (`prc_fetches.artifact_ref`), retention по умолчанию — хранить;
- `raw/html/…` — HTML-снапшоты карточек: **gzip, retention по умолчанию выключен (opt-in)** — расходник;
- `documents/<hash-prefix>/<sha256>` — оригиналы версий, immutable, дедуп по хэшу;
- `prepared/…` — конвертированные (PDF);
- `llm/requests|responses/…` — сырые payload'ы вызовов.

БД хранит только ссылки (`*_ref`, `storage_path`), кроме `prc_estimates.raw_result` (inline jsonb). spatie/medialibrary не используем для пайплайна (контент-адресация своя).

## 10. Полнотекстовый поиск

Отдельная материализованная витрина `prc_search` (не поиск по транзакционным таблицам): `procurement_id, tsv tsvector` (по `title` + позиции + заказчик, конфигурация `russian`), GIN на `tsv`. Обновляется по событию новой ревизии (инкрементально). Так тяжёлые джойны не висят на каждом поисковом запросе.

## 11. Чек-лист сценариев (валидация схемы)

| Сценарий | Путь по схеме |
|---|---|
| Новая закупка найдена (листинг) | insert `prc_procurements` + `prc_revisions` (rev 1) + событие `discovered` + rows `prc_documents` |
| Детальный слой карточки снят | `prc_procurements.detail_fetched_at`; заполнение `prc_items`/`prc_financing`/`prc_eis_details`/`org_procurement_parties`; события из журнала ЕИС в `prc_events` |
| Изменилась цена/статус/даты | `snapshot_hash` отличается → новая ревизия + события `price_changed`/`status_changed` + update шапки |
| Редакция без изменений ядра | `snapshot_hash` совпал → только `last_seen_at` |
| Размещён протокол / определён победитель | `prc_protocols` + `prc_bids` + `prc_awards` (+ события `protocol_published`/`award_made`), победитель → роль `winner` в `org_procurement_parties` |
| Разъяснение / отмена | `prc_clarifications` / `prc_cancellations` + событие; отмена отмены → `reverted_at` |
| Документ заменён | тот же `prc_documents.external_id`, новый `prc_document_versions` + событие `document_replaced`; анализы старой версии остаются |
| Архив в документе | распаковка → новые `prc_documents` + `prc_document_versions` с `parent_version_id` |
| Повторный парсинг сметы | unique `(document_version_id, step)` не даёт дублей; новая смета по `analysis_id` unique |
| Состояние на дату X | последняя `prc_revisions` с `detected_at <= X`; таймлайн — `prc_events` |
| Новая площадка | insert `prc_platforms`+`prc_registries`+`prc_status_mappings`, адаптер в коде; ядро без миграций |
| Неизвестный статус 223-ФЗ | `unknown` + алерт → оператор дополняет `prc_status_mappings` |
| Организация в нескольких ролях | одна `org_organizations` (резолв по ИНН) + N строк `org_procurement_parties` с разными `role` |
| Обучение локальной LLM | `prc_ai_dataset_examples` (gold) + `prc_estimates.raw_result` (сырьё) + `prc_ai_eval_runs` (метрики) |
| Смена версии промптов | `prc_ai_analyses.rules_hash` не совпал с текущим → Gemini-кэш невалиден, пересоздать; `cache_expires_at` в прошлом → тоже пересоздать |
| Прерванная многошаговая экстракция | `prc_ai_analyses.progress` хранит пройденные строки/страницы → резюм с точки обрыва, не с нуля |
| Детальная карточка упала | `detail_next_retry_at`/`detail_failed` на `prc_procurements` → ретрай по `detail_fetched_at IS NULL` без реконструкции из логов |

### 11.1 Валидация универсальности «на бумаге» — коммерческая площадка

Гипотетическая площадка без ИКЗ/ОКПД2, иной классификатор, свои статусы:
- `prc_platforms` (`code='commercial-x'`) + `prc_registries` + `prc_status_mappings`; адаптер в коде. **Миграций ядра нет.**
- Ядро `prc_procurements` заполняется как есть; `ikz` — `null`; классификатор позиций → `prc_items.classifier_scheme` (OCDS: схема заменяема).
- Специфика → `prc_procurements.attributes` или своя `<platform>_details` extension-таблица (как `prc_eis_details`).
- Организации/роли — тот же `org_organizations` + `org_procurement_parties` (роли открыты enum'ом).
- Статусы вне канона → ближайший или `unknown`+алерт; устойчивая потребность → расширение enum (одно место).

Вывод: ЕИС-специфика (ИКЗ, `prc_eis_details`, ОКПД2 как основной код) живёт в nullable-колонках и extension-слое, ядро площадко-нейтрально.

## 12. Открытые вопросы / риски

- ~~Многолотовые процедуры (конкурсы) — структуру лотов/позиций снять на реальном примере~~ — **закрыто** живой разведкой: у 44-ФЗ явных блоков «Лот N» в карточке нет, каждый лот публикуется отдельным `reg_number` (см. `eis-card-recon.md` §9); `prc_lots.lot_number=1` виртуален для 44-ФЗ.
- ~~223-ФЗ карточка — набор полей и URL-схема отличаются от 44-ФЗ~~ — **закрыто**: отдельная ветка `notice223`, лоты — первичная сущность через `lot-list.html`/`lot-info.html` по `lotGuid`, требует `noticeGuid` (см. `eis-card-recon.md` §10; поля `prc_procurements.registry_guid`/`prc_lots.lot_guid` добавлены выше). Типы протоколов 223-ФЗ по-прежнему open-ended.
- Триггер `lastDateChangeCsv` для разъяснений документации — подтверждён живьём только для протокола/отмены; для разъяснения экстраполяция без прямого наблюдения (`eis-card-recon.md` §11) — перепроверить при первой возможности.
- Слой контрактов (реестр контрактов, исполнение) — за рамками MVP; `prc_awards.contract_registry_number` — якорь связи на будущее.
- Адресный слой (ГАР, не КЛАДР/ФИАС) — если понадобится гео-аналитика; пока `region_code` денормализован на организации/закупке.
- Партиция месяц vs квартал для `prc_events` — стартуем с месяца, пересмотр после замера потока.
- UUIDv7: PG17 — генерация в приложении; PG18 — нативный `uuidv7()`.
- Наполнение `ref_*` классификаторов (загрузчик из официальных выгрузок Росстат/Минфин) — отдельная задача инфраструктуры.
