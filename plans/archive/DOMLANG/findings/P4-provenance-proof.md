# P4.1 Deliverable — Proof-таблица конфликтных полей и вердикт

**Дата:** 2026-07-10 · **Автор:** plan-exec/opus (DOMLANG P4.1) · **Метод:** независимый `grep` +
чтение кода, без запуска приложения. `findings/P4-write-site-map.md` использован как лид и
перепроверен построчно — расхождений с картой не найдено (см. §5).

---

## 1. Энумерация write-site'ов (машинная)

`verified:✅ 2026-07-10`:

```
$ grep -rlE '\->update\(|\->create\(|updateOrCreate\(' app/Repositories/ | wc -l
8
```

Файлы: `Organization/OrganizationStoreRepository.php`, `Organization/ProcurementPartyStoreRepository.php`,
`Procurement/AiCallStoreRepository.php`, `Procurement/AnalysisStoreRepository.php`,
`Procurement/DocumentStoreRepository.php`, `Procurement/EstimateStoreRepository.php`,
`Procurement/ProcurementDetailStoreRepository.php`, `Procurement/ProcurementStoreRepository.php`.
Совпадает с ожиданием Implementation Rule #1 (**8**).

```
$ grep -rnE '(Procurement|Lot|EisDetails|Protocol|Bid|Award|Financing|PurchaseItem)::query\(\)->(create|update|updateOrCreate|insert)|\$procurement->(update|save|fill)\(' app/Services/ app/Jobs/ app/Console/ app/Filament/ | wc -l
0
```

Escape-hatch пуст — вся персистентность доменных моделей закупки идёт через репозитории (`plan.md`
§2). Нарушения `plan.md` §2 не найдено, эскалация не требуется.

Write-site'ы по таблицам головы закупки (`prc_procurements`), перепроверены построчно против карты:

| # | Write-site | Слой | Затронутые поля (релевантные) |
|:--|:--|:--|:--|
| W1 | `ProcurementStoreRepository.php:18` `storeProcurement()` | listing/CSV | INSERT головы, включая `customer_org_id: $data->customerOrgId` (:28) |
| W2 | `ProcurementStoreRepository.php:51` `updateProcurementHead()` | listing/CSV | UPDATE тем же снапшотом, включая `customer_org_id: $data->customerOrgId` (:65) |
| W3 | `ProcurementStoreRepository.php:88` `touchLastSeen()` | listing/CSV | `last_seen_at` |
| W4 | `ProcurementStoreRepository.php:97` `markDetailFetched()` | pipeline (detail-fetch) | `detail_*` error-блок (сброс) |
| W5 | `ProcurementStoreRepository.php:112` `markDetailFailed()` | pipeline (detail-fetch) | `detail_*` error-блок (запись отказа) |
| W6 | `ProcurementPartyStoreRepository.php:40` `setCustomerOrg()` | detail/карточка | `customer_org_id` |

Detail-слой (`ProcurementDetailStoreRepository.php`, 6 методов: `storeEisDetails`/`replaceFinancing`/
`upsertItem`/`upsertProtocol`/`upsertBid`/`upsertAward`) пишет `prc_eis_details`/`prc_financing`/
`prc_items`/`prc_protocols`/`prc_bids`/`prc_awards` — все шесть таблиц имеют **ровно одного**
писателя (перепроверено чтением файла целиком, 2026-07-10). Гипотезы скелета P4 (НМЦК, сроки подачи
заявок — конфликт listing⚔карточка) не подтверждаются: карточка этих колонок не имеет вовсе
(`prc_eis_details` не содержит `initial_price`/`bidding_*`, только `execution_*` — другой концепт).

`EventJournalProcessor.php` (перепроверен целиком, :1-95) пишет только `prc_events`
(`ProcurementEvent`, через `storeEvent()`/`hasEvent()` в `ProcurementStoreRepository`) — колонку
`prc_procurements.status` не трогает ни разу.

Единственная колонка головы с write-site'ами в **двух разных доменных слоях** — `customer_org_id`
(W1/W2 vs W6). Второй кандидат, наблюдаемый двумя слоями, но материализуемый одним, — `status`
(листинг пишет колонку; журнал карточки пишет отдельную таблицу событий).

## 2. Proof-таблица кандидатов

| Поле | Write-site #1 (слой) | Write-site #2 (слой) | Пример расхождения | Потребитель | Класс |
|:--|:--|:--|:--|:--|:--|
| `prc_procurements.customer_org_id` | `EisCsvRowMapper.php:76` → `ProcurementStoreRepository.php:28,65` (listing/CSV) — **хардкодит `customerOrgId: null`**, ЕИС-листинг идентификатора заказчика не содержит | `OrganizationPartiesProcessor.php:44-46` → `ProcurementPartyStoreRepository.php:40` `setCustomerOrg()` (detail/карточка) — резолвит заказчика из карточки и денормализует в голову | Последовательность «листинг → detail (`customer_org_id=42`) → любая новая ревизия листинга» возвращает колонку в `null` (`updateProcurementHead():59-85` — безусловный `update()` полным снапшотом, без фильтра непустых и без приоритета источника; вызывается на каждой ревизии — `ProcurementIngestProcessor.php:91,147`) | `ProcurementInfolist.php:40` (читает как есть, не ветвится по происхождению) | **R2** — один писатель структурно пишет `null` (отсутствие, не альтернативное значение); второй — единственный продюсер истины. Провенанс не спас бы значение — нужен фикс клоббера, не колонка происхождения |
| `prc_procurements.status` | `ProcurementIngestProcessor.php` через `StatusMappingService::resolve()` (listing/CSV, W1/W2) — единственный писатель колонки | `EventJournalProcessor.php:58-64` (detail/карточка) — распознаёт `StatusChanged`/`Cancelled`/`Restored` из текста журнала, пишет **`ProcurementEvent`** (`payload.source='event_journal'`), колонку `status` не трогает | Журнал видит переход раньше, чем CSV сменит этап на следующем прогоне — колонка временно отстаёт от журнала событий; это разные записи (`prc_procurements.status` vs `prc_events`), не два значения одной колонки | `DetailRefetchSelector.php:42` (`whereIn('status', …)`), `ProcurementInfolist.php:29`, `ProcurementsTable.php` | **R3** — факт наблюдают два слоя, но колонку пишет один (второй пишет в журнал событий). Провенанс окупился бы, только если журналу разрешат писать колонку напрямую — такого решения нет и оно вне P4 |

Прочие гипотезы скелета P4 (НМЦК/`initial_price`, сроки подачи заявок `bidding_starts_at`/
`bidding_ends_at`) — **не кандидаты**: единственный write-site каждой — `EisCsvRowMapper.php:30,36-42`
через listing/CSV; ни `prc_eis_details`, ни любой другой detail-процессор эти колонки не пишет
(перепроверено: `CommonInfoProcessor.php` пишет только `prc_eis_details`/`prc_financing`/`prc_items`,
без пересечения с колонками головы, кроме `customer_org_id`/`status`, разобранных выше).

## 3. Потребители (перепроверено)

`verified:✅ 2026-07-10` — ни один читатель домена не ветвится по происхождению значения:

- `ProcurementInfolist.php:29,40,43,67-73,76-91` — читает `status`/`customer_org_id`/`initial_price`/
  `bidding_*`/`detail_*` как плоские значения, без условия на источник.
- `ProcurementsTable.php:23,27,31,39` — то же.
- `DetailRefetchSelector.php:42` — фильтрует по значению `status`, не по его происхождению.
- Обучающий контур (`docs/03_Dev/ai/training-loop.md`) — потенциальный, не действующий потребитель
  (ADR-0008 называет его «Триггером пересмотра», не текущим требованием); происхождение факта не
  запрашивает на 2026-07-10.

`grep -rn 'provenance' app/ database/ --include=*.php | wc -l` → **0** — подтверждает Validation #8:
ни одна колонка происхождения этим item'ом не заведена и не существует в коде.

## 4. Расхождения с design-time картой (`findings/P4-write-site-map.md`)

Ни одного расхождения не найдено. Все file:line, приведённые в картe (W1–W6, EisCsvRowMapper:76,
EventJournalProcessor:81→уточнено до :58-64 для фактического вызова storeEvent, OrganizationPartiesProcessor:44-46),
подтверждены независимым чтением. Карта объявляется исчерпывающей для скоупа P4.1
(`prc_procurements`, `prc_eis_details`, `prc_lots`, `prc_protocols`, `prc_bids`, `prc_awards`,
`prc_financing`, `prc_items`).

## 5. Применение правила R1/R2/R3 (D23)

Оба найденных кандидата с write-site'ами в разных слоях классифицированы не-R1:

- `customer_org_id` → **R2** (клоббер): один писатель пишет структурный `null`, не альтернативное
  значение. Дефект маршрутизирован в фазу P5 (`P5.4`, D24) — уже сделано дизайном, этот item только
  подтверждает предпосылку.
- `status` → **R3** (рассинхрон материализации): колонку пишет один слой, второй наблюдает факт, но
  пишет отдельную таблицу (`prc_events`), не саму колонку. Провенанс преждевременен: сначала пришлось
  бы разрешить журналу писать колонку, такого решения не принято.

Ни один кандидат не удовлетворяет обоим условиям R1 (непустые различающиеся значения ОДНОЙ колонки
+ названный потребитель, который ветвится по происхождению). Асимметрия ошибки (Q4) разрешена в
сторону «нет»: оба кандидата на самом деле проверялись как «может быть R1» и оба отклонены по
проверяемым признакам (структурный `null` у customer_org_id; отдельная таблица-приёмник у status),
а не по ощущению.

---

**ВЕРДИКТ: 0 полей класса R1**

Материализация fact-level provenance не производится. Фаза P4 (item `P4.2`) закрывается
`⛔ Skipped by decision` — легальный исход по ADR-0008. Триггер возврата (из ADR-0008 «Триггер
пересмотра»): факт начнёт приходить из ≥2 слоёв с непустыми различающимися значениями ОДНОЙ колонки
(не структурным отсутствием) И появится названный потребитель, который ветвился бы по происхождению;
либо обучающий контур явно потребует происхождения факта для gold-снапшота.
