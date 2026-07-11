# P4 — Карта write-site'ов полей закупки (design-time recon)

**Дата:** 2026-07-10 · **Автор:** plan-design/opus (DOMLANG P4) · **Метод:** `grep`/чтение кода,
без запуска приложения.

> **Это ЛИД, а не ВЕРДИКТ.** Документ фиксирует, что дизайн увидел в коде на 2026-07-10, чтобы
> исполнитель `P4.1` не платил заново за разведку. Он **не заменяет** proof-таблицу `P4.1`:
> исполнитель обязан независимо перепроверить каждую строку грепом и объявить карту исчерпывающей
> или дополнить её. Отсутствие поля в этой карте — не доказательство того, что поля нет
> (та же ошибка, что D15: гипотеза в каноническом документе читается как спека).

---

## 1. Что вообще пишет `prc_procurements` (голова закупки)

`verified:✅ 2026-07-10` — `grep -rn 'customer_org_id\|->update(\|::query()->create(' app/Repositories/`

| # | Write-site | Слой | Колонки |
|:--|:--|:--|:--|
| W1 | `app/Repositories/Procurement/ProcurementStoreRepository.php:18` `storeProcurement()` | listing/CSV | INSERT всей головы: `registry_number`, `etp_number`, `ikz`, `title`, `status`, `source_stage`, `procedure_type(_raw)`, **`customer_org_id`**, `initial_price`, `currency`, `advance_percent`, `okpd2_*`, `okved_*`, `region_code`, `published_at`, `source_updated_at`, `bidding_starts_at`, `bidding_ends_at`, `summary_at`, `detail_url`, `registry_guid`, `attributes`, `revision_count`, `first_seen_at`, `last_seen_at` |
| W2 | `app/Repositories/Procurement/ProcurementStoreRepository.php:59` `updateProcurementHead()` | listing/CSV | UPDATE тем же набором (минус `published_at`/`first_seen_at`), **включая `customer_org_id`**; плюс `current_revision_*` |
| W3 | `…ProcurementStoreRepository.php:90` `touchLastSeen()` | listing/CSV | `last_seen_at` |
| W4 | `…ProcurementStoreRepository.php:97` `markDetailFetched()` | pipeline (detail-fetch) | `detail_fetched_at`, `detail_attempts`, `detail_last_error`, `detail_next_retry_at`, `detail_failed` |
| W5 | `…ProcurementStoreRepository.php:112` `markDetailFailed()` | pipeline (detail-fetch) | те же `detail_*` |
| W6 | `app/Repositories/Organization/ProcurementPartyStoreRepository.php:40` `setCustomerOrg()` | **detail/карточка** | **`customer_org_id`** |

Единственная колонка головы, у которой write-site'ы лежат в **двух разных доменных слоях**, —
`customer_org_id` (W1/W2 vs W6). `detail_*` (W4/W5) — служебный error-блок пайплайна, не доменный
факт: его никто, кроме самого пайплайна, не пишет.

## 2. Ключевой факт: `customer_org_id` — не расхождение, а затирание

`verified:✅ 2026-07-10`:

- `app/Services/Procurement/Eis/EisCsvRowMapper.php:76` — `customerOrgId: null` **захардкожено**.
  CSV-листинг ЕИС не содержит идентификатора организации-заказчика в принципе.
- `app/Services/Procurement/Detail/OrganizationPartiesProcessor.php:45` → `setCustomerOrg()` —
  detail-слой резолвит заказчика из карточки и денормализует его в голову.
- `ProcurementStoreRepository::updateProcurementHead()` — безусловный `$procurement->update([...])`
  полным набором полей снапшота, **без фильтра непустых** и без приоритета источника
  (`app/Repositories/Procurement/ProcurementStoreRepository.php:59-86`).
- `ProcurementIngestProcessor::applyRevision()` вызывает `updateProcurementHead()` на **каждой**
  новой ревизии листинга (`snapshot_hash` изменился — `…/ProcurementIngestProcessor.php:129,147`).

Следствие: последовательность «листинг → detail (`customer_org_id = 42`) → любая новая ревизия
листинга» возвращает `customer_org_id` в `null`. Заказчик теряется молча.

**Это не provenance-кейс.** Два слоя пишут одну колонку, но у факта **один продюсер истины**
(detail); листинг подставляет структурный `null`, а не альтернативное значение. Знание «откуда
пришло значение» не спасло бы значение — его надо не затирать. Провенанс здесь лечил бы симптом
чужой болезни (см. `plan.md` D24 — дефект маршрутизирован в фазу P5).

## 3. Поля, у которых продюсер один (расхождения между слоями нет)

`verified:✅ 2026-07-10`:

| Поле | Единственный слой-продюсер | Проверено |
|:--|:--|:--|
| `initial_price` (НМЦК) | listing/CSV (`EisCsvRowMapper.php:30`, парсинг `:127`) | detail-слой (`prc_eis_details`) колонки цены не имеет вовсе |
| `bidding_starts_at`, `bidding_ends_at`, `published_at`, `source_updated_at` | listing/CSV (`EisCsvRowMapper.php:36-42`) | `prc_eis_details.execution_*` — сроки **исполнения контракта**, другой концепт |
| `status` (canonical) | listing/CSV через `StatusMappingService::resolve()` (`ProcurementIngestProcessor.php:33`) | см. §4 — особый случай |
| `source_stage` | listing/CSV | — |
| `title`, `okpd2_*`, `okved_*`, `procedure_type*`, `region_code` | listing/CSV | detail-слой их не трогает |
| `prc_eis_details.*` | detail (`CommonInfoProcessor.php:32`) | ровно один процессор |
| `prc_financing`, `prc_items` | detail (`CommonInfoProcessor.php:37,43`) | ровно один процессор |
| `prc_protocols`, `prc_bids`, `prc_awards` | detail (`SupplierResultsProcessor.php:65,97,114`) | ровно один процессор |
| `prc_lots.*` | **никто** — 0 write-site'ов в `app/`, таблица наполняется только `LotFactory` | мёртвая на текущем срезе |
| `prc_cancellations`, `prc_clarifications` | **никто** — gap задекларирован в `SupplierResultsProcessor.php:30-33` | мёртвые на текущем срезе |

Гипотезы скелета P4 («НМЦК: listing ⚔ карточка», «сроки подачи: listing ⚔ карточка») **не
подтверждаются кодом**: карточка этих полей не пишет.

## 4. Особый случай: `status` — наблюдается двумя слоями, материализуется одним

`verified:✅ 2026-07-10` — `app/Services/Procurement/Detail/EventJournalProcessor.php:81`:
журнал событий карточки распознаёт `переведена на этап «…»` / `отменена` / `восстановлена` и пишет
`ProcurementEvent` (`StatusChanged`/`Cancelled`/`Restored`) с `payload.source = 'event_journal'`.
Колонку `prc_procurements.status` он **не трогает** — её пишет только listing (W1/W2).

Значит колонка может отставать от журнала (журнал видит отмену раньше, чем CSV сменит этап). Это
**рассинхрон материализации**, а не конфликт значений одного поля: у колонки по-прежнему один
писатель. Провенанс на `status` окупился бы только если бы журналу разрешили писать колонку —
такого решения нет, и оно не предмет P4 (см. `plan.md` D23, правило R3).

## 5. Потребители (кто бы читал происхождение)

`verified:✅ 2026-07-10`:

- `app/Filament/Resources/Procurement/Procurements/Schemas/ProcurementInfolist.php` — `status` :29,
  `customer_org_id` :40, `initial_price` :43, `bidding_*` :67-73, `detail_*` :76-91.
- `app/Filament/Resources/Procurement/Procurements/Tables/ProcurementsTable.php` — `initial_price` :23,
  `published_at` :27, `bidding_ends_at` :31, `detail_failed` :39.
- `app/Services/Procurement/DetailRefetchSelector.php:42` — `whereIn('status', …)`.
- Обучающий контур (`docs/03_Dev/ai/training-loop.md`) — **потенциальный** потребитель, назван в
  ADR-0008 «Триггер пересмотра»; на 2026-07-10 происхождение факта не запрашивает.

Ни один из них сегодня не ветвится по «из какого слоя пришло значение». `grep -rn 'provenance'
app/ database/ docs/` (кроме `adr/0008`) = **0**.

## 6. Что из этого следует для `P4.1`

Карта даёт **один** кандидат с write-site'ами в двух слоях (`customer_org_id`) и **ни одного**
кандидата с расхождением значений. `P4.1` обязан:

1. Перепроверить §1–§4 независимым грепом (не доверять этому файлу).
2. Прогнать классификацию R1/R2/R3 (`plan.md` D23) по каждому найденному кандидату.
3. Убедиться, что карта исчерпывающая: если найден write-site, которого здесь нет, — он попадает в
   proof-таблицу, а расхождение с этой картой фиксируется в `Known Deviations`.
4. Выдать вердикт. Отрицательный вердикт (`0 полей класса R1`) — **легальный исход** (ADR-0008).
