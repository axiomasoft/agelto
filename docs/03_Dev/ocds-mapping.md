---
audience: dev
type: reference
status: draft
---

# OCDS mapping — `prc_*` ↔ Open Contracting Data Standard

**Статус: экспортный слой, не персистенс.** Эта таблица — справочник соответствий «внутреннее
поле ↔ OCDS-путь», а не спека сериализатора. Внутренняя схема `prc_*` остаётся единственным
источником правды (ADR-0007). Файл активируется как боевой экспортный контракт только по триггеру
ADR-0007 «Триггер пересмотра»: первый внешний потребитель запросил OCDS-выгрузку **или**
площадка/регулятор обязали публиковать данные в OCDS-профиле — до этого момента mapping остаётся
справочным, кода экспортёра нет.

## Идентификация (ocid/release/record)

| Внутреннее (таблица.поле) | OCDS путь | Комментарий |
|:--|:--|:--|
| `prc_procurements.registry_id` + `prc_procurements.registry_number` | `ocid` (профильный аналог) | ЕИС не отдаёт единый `ocid`; составной ключ (реестр + реестровый номер) — практический эквивалент, не буквальный OCDS `ocid` |
| `prc_procurements` (вся строка) | `record.compiledRelease` | последнее известное состояние закупки — изоморф ADR-0007 |
| `prc_revisions` (одна строка) | элемент `record.versionedRelease` | история изменений извещения на уровне снапшота/поля — ADR-0007 |
| `prc_events` (одна строка) | элемент `record.releases[]` (`release`) | immutable, append-only журнал — предусловие изоморфизма подтверждено ADR-0007 |
| `prc_events.occurred_at` | `release.date` | |
| `prc_events.event_type` (`ProcurementEventType`) | `release.tag` | внутренний codelist события, не 1:1 с OCDS release-тегами (`tender`/`tenderUpdate`/…) |
| `prc_revisions.revision_number` | нет прямого аналога | OCDS релизы не нумеруются последовательно; внутренний инкремент не публикуется как есть |

## tender

| Внутреннее (таблица.поле) | OCDS путь | Комментарий |
|:--|:--|:--|
| `prc_procurements.registry_number` | `tender.id` (профильный, не ванильный OCDS) | 44-ФЗ реестровый номер извещения, D5 |
| `prc_procurements.etp_number` | нет аналога в core OCDS | номер по 223-ФЗ на площадке; forward-provision без продюсера, D13 |
| `prc_procurements.title` | `tender.title` | |
| `prc_procurements.status` | `tender.status` (закрытый codelist, профильный маппинг) | канонический статус после `StatusMappingService::resolve()`, не сырой этап источника |
| `prc_procurements.source_stage` | нет прямого OCDS-поля | сырой этап ЕИС до нормализации (D5); ближе по духу к `release.tag`, но не эквивалентен |
| `prc_procurements.procedure_type` / `procedure_type_raw` | `tender.procurementMethod` / `tender.procurementMethodDetails` | |
| `prc_procurements.initial_price` + `prc_procurements.currency` | `tender.value.amount` + `tender.value.currency` | НМЦК |
| `prc_procurements.bidding_starts_at` / `bidding_ends_at` | `tender.tenderPeriod.startDate` / `tender.tenderPeriod.endDate` | |
| `prc_procurements.okpd2_code` / `okpd2_codes` / `okved_code` | `tender.items[].classification` | профильный классификатор (ОКПД2/ОКВЭД2), не CPV |
| `prc_lots` (строка) | `tender.lots[]` | core-расширение OCDS 1.1+ (`research/tz-v2/ocds-schema.md` §1) |
| `prc_lots.lot_number` | `tender.lots[].id` | |
| `prc_lots.subject` | `tender.lots[].title` | |
| `prc_lots.status` | `tender.lots[].status` | по OCDS 1.1 использует тот же закрытый codelist, что `tender.status` — отдельного `lotStatus` в стандарте нет |
| `prc_lots.initial_price` + `prc_lots.currency` | `tender.lots[].value` | |

## award

| Внутреннее (таблица.поле) | OCDS путь | Комментарий |
|:--|:--|:--|
| `prc_awards.uuid` | `awards[].id` | |
| `prc_awards.status` | `awards[].status` | |
| `prc_awards.final_price` + `prc_awards.currency` | `awards[].value` | |
| `prc_awards.winner_org_id` | `awards[].suppliers[]` (ссылка на party) | резолвится через `prc_procurement_parties` (роль `winner`, D27) |
| `prc_awards.contract_registry_number` | частично `contracts[].id` | секция `contract` не покрыта целиком (см. «Некрытые секции») |
| `prc_awards.awarded_at` | `awards[].date` | |
| `prc_awards.lot_id` | `awards[].items[].relatedLot` (косвенно) | сегодня у закупки ровно один `Award` (D27) |

## parties

| Внутреннее (таблица.поле) | OCDS путь | Комментарий |
|:--|:--|:--|
| `prc_procurement_parties` (строка) | `parties[]` | party-centric модель OCDS 1.1+ |
| `prc_procurement_parties.organization_id` (→ `org_organizations`) | `parties[].id` / `parties[].identifier` | |
| `prc_procurement_parties.role` (`App\Enums\Organization\ProcurementPartyRole`) | `parties[].roles[]` | ролевой маппинг профильный (`customer`→`buyer`, `winner`→`supplier`, `participant`→`tenderer`; `placer`/`authorized_body`/`specialized_organization`/`platform_operator`/`guarantor` — без прямого OCDS-аналога), не буквальное совпадение имён |
| `prc_procurements.customer_org_id` | `buyer` (ссылка на party с ролью `customer`) | денормализация головы закупки; авторитетный источник — detail-слой, не листинг (D24) |

## documents

| Внутреннее (таблица.поле) | OCDS путь | Комментарий |
|:--|:--|:--|
| `prc_documents` (строка) | `tender.documents[]` / `awards[].documents[]` / `contracts[].documents[]` (по `kind`) | привязка к секции не жёсткая — определяется свободным текстом `kind` |
| `prc_documents.title` | `documents[].title` | |
| `prc_documents.kind` | `documents[].documentType` | свободный текст источника (извещение/протокол/контракт/…), не закрытый OCDS-codelist |
| `prc_documents.source_url` | `documents[].url` | |
| `prc_documents.external_id` | `documents[].id` | |

## Некрытые секции

Перечислены явно — пропуск без пометки читался бы как «покрыто», это не так:

- **`planning`** — нет источника данных в `prc_*` (план-график закупки не парсится).
- **`contract`** — нет источника данных в `prc_*`, за исключением частичного
  `prc_awards.contract_registry_number` (см. «award»); полноценной секции контракта/исполнения нет.
- **`implementation`** — нет источника данных в `prc_*` (фактическое исполнение контракта, вехи,
  расторжение — не собираются).

## Смежное

- [`adr/0007-ocds-as-exchange-layer.md`](adr/0007-ocds-as-exchange-layer.md) — принцип «OCDS —
  exchange layer», триггер пересмотра, рассмотренные альтернативы.
- [`adr/0008-thin-provenance-slice.md`](adr/0008-thin-provenance-slice.md) — coarse-lineage колонки
  (`crawler_run_id`, `artifact_ref` и т.д.), не входящие в эту mapping-таблицу.
- `database.md` — карта `prc_*`-таблиц AI-пайплайна и ключевые инварианты.
- `research/tz-v2/ocds-schema.md` — справочный (не канон) обзор OCDS 1.1.x, использованный при
  составлении секций `tender`/`award`/`parties`/`documents`.
