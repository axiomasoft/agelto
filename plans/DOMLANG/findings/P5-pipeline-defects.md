# P5 — Карта четырёх дефектов пайплайна (design-time recon)

**Дата:** 2026-07-10 · **Автор:** plan-design/opus (DOMLANG P5) · **Метод:** чтение кода и
`vendor/laravel/framework` + один RAG-прогон (Postgres 17 CREATE INDEX).

> **Это ЛИД, а не ВЕРДИКТ** (та же дисциплина, что `P4-write-site-map.md`). Документ фиксирует,
> что дизайн увидел в коде на 2026-07-10, чтобы исполнитель не платил за разведку заново. Каждый
> item обязан независимо перепроверить предпосылку своим грепом/тестом. Расхождение с этим файлом
> фиксируется в `Known Deviations` item'а, а не молча правится.

---

## 1. P5.1 — защита от двойного диспатча УЖЕ ЕСТЬ; настоящий дефект противоположен

### 1.1 Предпосылка скелета фазы опровергнута кодом

Скелет P5 (и `docs-private/risk-register.md` риск #1, и `docs-private/crawler-gotchas.md` §5)
утверждают: «прикладной защиты от повторной постановки того же документа в очередь нет».

`verified:✅ 2026-07-10` — на сегодня документ защищён **тремя независимыми гардами**:

| # | Гард | Где | Что закрывает |
|:--|:--|:--|:--|
| G1 | `ShouldBeUnique` + `uniqueId() = "{documentId}:prepare"` | `app/Jobs/Procurement/PrepareDocumentJob.php:24,35` | второй `dispatch()` не доходит до очереди: `Illuminate\Foundation\Bus\PendingDispatch:208` берёт `UniqueLock` ДО пуша (в т.ч. под `Queue::fake()`) |
| G2 | status-guard первым выражением `handle()` | `app/Jobs/Procurement/PipelineJob.php:36-38` + `PrepareDocumentJob::statusGuardPassed()` (`status === Downloaded`) | job, доехавший вторым, молча выходит |
| G3 | атомарный лиз (`UPDATE … WHERE locked_until IS NULL OR locked_until < now()`) | `app/Models/Procurement/Concerns/HasLease.php:24-42` | параллельная обработка одной записи двумя воркерами |

G1 уже **запинен тестом**: `tests/Feature/Document/PipelineDispatcherTest.php:26`
(«повторный dispatch … не дублирует job в очереди (ShouldBeUnique)»).

Вывод: риск #1 в формулировке «нет защиты от дубля» — **устаревший текст**, написанный до того, как
EST-AI завёл `PipelineJob`/`PrepareDocumentJob`. Он не воспроизводится.

### 1.2 Настоящий остаточный дефект: unique-lock без TTL → вечный лок

`verified:✅ 2026-07-10` — чтение `vendor/laravel/framework/src/Illuminate/Bus/UniqueLock.php:32-34`:

```php
$uniqueFor = method_exists($job, 'uniqueFor') ? $job->uniqueFor() : ($job->uniqueFor ?? 0);
return (bool) $cache->lock($this->getKey($job), $uniqueFor)->get();
```

`grep -rn 'uniqueFor' app/Jobs/` = **0 вхождений**. Значит `$uniqueFor = 0` у всех pipeline-job'ов.

Что означает `0` для двух драйверов:

- **Redis (прод, `CACHE_STORE=redis`)** — `RedisLock::acquire()` при `seconds === 0` делает `SETNX`
  **без `EXPIRE`**: лок вечный.
- **Array (тесты, `phpunit.xml:25 CACHE_STORE=array`)** — `ArrayLock::acquire()` (`:38`) пишет
  `'expiresAt' => $this->seconds === 0 ? null : …`, а на чтении делает
  `$this->store->locks[$name]['expiresAt'] ?? Carbon::now()->addSecond()` — `??` ловит **null** и
  подставляет «сейчас + 1 секунда». То есть в тестах «вечный» лок протухает через секунду.

Следствие: **дефект существует только в проде и структурно невидим текущему сюиту.**

Лок снимается штатно при завершении/финальном провале job'а (`CallQueuedHandler:217-218`,
`Queue.php:358-361` — release на rollback транзакции). Он НЕ снимается, если процесс-воркер убит
без PHP-shutdown: SIGKILL, OOM-killer, рестарт контейнера, `horizon:terminate` по таймауту.

Тогда:

- `prc_documents.status` остаётся `downloaded` (`recordFailure()` статус не меняет —
  `HasErrorBlock:20-33`);
- `PipelineDispatcher::dispatchBacklog()` (everyMinute, `routes/console.php:40-42`) исправно
  отбирает документ и вызывает `PrepareDocumentJob::dispatch()` — и **каждый раз молча
  проваливает `UniqueLock::acquire()`**;
- `pipeline:retry-failed` (everyTenMinutes) диспатчит тот же job — с тем же исходом
  (`app/Console/Commands/Procurement/PipelineRetryFailed.php`);
- `pipeline:stuck-check` увидит документ в `downloaded` дольше `document_stuck_minutes` (60) и
  просигналит — но **починить некому**: документ застревает навсегда.

Это ровно тот же класс, что v1 C1/C5 («найдено, но никогда не обработано»), только причина не
«нет моста», а «мост держит вечный лок». Дефект найден дизайном, не exec'ом.

### 1.3 Судьба D17 («мост — единственный owner перехода»)

`docs/specs/v2/02-crawling.md:159` до сих пор пишет: диспетчер «ставит их в очередь стадии
подготовки документов **и помечает статус**». Кода, помечающего статус, нет и не будет:

- в `DocumentStatus` нет значения «поставлено в очередь» (`app/Enums/Procurement/DocumentStatus.php`);
- владелец перехода `downloaded → prepared` — сам `PrepareDocumentJob:78`
  (`DocumentStoreRepository::update(status: Prepared)`);
- весь recovery-контур завязан на то, что застрявший документ **остаётся** `downloaded`:
  `Document::scopePendingRetry()`, `PipelineStuckCheck::stuckDocuments()` (`:107-113`),
  `PipelineRetryFailed` (комментарий `:24-26` прямо фиксирует «новый промежуточный статус НЕ
  вводить»).

Ввести `DocumentStatus::Queued` = сломать все три и не приобрести ничего: инвариант «один job на
документ» уже держат G1–G3. Решение — `plan.md` D25.

---

## 2. P5.2 — `prc_bids`: прикладной ключ без индекса под ним

`verified:✅ 2026-07-10` — `app/Repositories/Procurement/ProcurementDetailStoreRepository.php:104-128`:

```php
$key = ['procurement_id' => …, 'protocol_id' => …, 'organization_id' => …];
if ($organizationId === null) { $key['participant_ordinal'] = $participantOrdinal; }
Bid::query()->updateOrCreate($key, $attributes);
```

То есть **ключ идентичности ветвится**:

- участник резолвлен (есть ИНН/ОГРН) → `(procurement_id, protocol_id, organization_id)`;
- участник анонимен (`organization_id IS NULL`) → `(procurement_id, protocol_id, participant_ordinal)`
  (иначе `updateOrCreate` схлопнул бы всех безымянных в одну строку — `crawler-gotchas.md` §1).

Миграция `database/migrations/2026_07_04_100021_create_prc_bids_table.php` не содержит **ни одного**
unique-индекса. Ни одна колонка ключа не `NOT NULL`, кроме `procurement_id`:
`protocol_id` nullable, `organization_id` nullable, `participant_ordinal` nullable
(`SupplierResultsParser.php:187` — `is_numeric($ordinalText) ? (int) $ordinalText : null`).

Прецедент решения в этом же репозитории: `org_organizations` уже использует
`NULLS NOT DISTINCT` (`…100009_create_org_organizations_table.php:32-35`), СУБД — Postgres 17
(`docker-compose.yml:125` `agelto_postgres:17-partman`).

**RAG:✅ 2026-07-10 «PostgreSQL 17 CREATE UNIQUE INDEX + NULLS NOT DISTINCT + partial WHERE»**
(источник — postgresql.org/docs, раздел CREATE INDEX / Partial Indexes): комбинация легальна,
порядок клауз — `(columns) [INCLUDE …] NULLS NOT DISTINCT [WITH …] WHERE predicate`.

Два частичных индекса зеркалят обе ветки прикладного ключа один-в-один:

```sql
CREATE UNIQUE INDEX prc_bids_identified_participant_unique
    ON prc_bids (procurement_id, protocol_id, organization_id)
    NULLS NOT DISTINCT WHERE organization_id IS NOT NULL;

CREATE UNIQUE INDEX prc_bids_anonymous_participant_unique
    ON prc_bids (procurement_id, protocol_id, participant_ordinal)
    NULLS NOT DISTINCT WHERE organization_id IS NULL;
```

`NULLS NOT DISTINCT` обязателен из-за nullable `protocol_id`: без него `(1, NULL, 7)` и
`(1, NULL, 7)` считались бы разными строками, и индекс не защищал бы ровно тот случай, ради
которого заводится (заявка без протокола).

Пограничный случай: `organization_id IS NULL AND participant_ordinal IS NULL` — второй индекс
схлопывает такие строки в одну. Это **совпадает** с поведением `updateOrCreate` (его ключ тоже
вырождается). Индекс не ужесточает контракт, а материализует существующий.

---

## 3. P5.3 — роль `winner` не снимается при пересмотре итогов

`verified:✅ 2026-07-10` — `app/Services/Procurement/Detail/SupplierResultsProcessor.php:110-130`:

```php
$award = $this->detailStoreRepository->upsertAward(procurementId: …, lotId: null, attributes: ['winner_org_id' => $winner->id, …]);
$this->partyStoreRepository->upsertParty(procurementId: …, organizationId: $winner->id, role: Winner, attributes: null);
```

`upsertAward` идёт по `(procurement_id, lot_id)` — при смене победителя `prc_awards.winner_org_id`
корректно перезаписывается. `upsertParty` идёт по `(procurement_id, organization_id, role)` —
**старая** строка `(…, старый_победитель, winner)` остаётся навсегда, новая просто добавляется.
Итог: у закупки две роли `winner`, читатель денормализации получает ложь.

Факты, ограничивающие решение:

- `lotId` **захардкожен `null`** (`:116`) → у закупки сегодня ровно один `Award`. `prc_lots` вообще
  не имеет write-site'ов в `app/` (`P4-write-site-map.md` §3).
- `prc_procurement_parties` имеет `unique(procurement_id, organization_id, role)`
  (`…100010_create_prc_procurement_parties_table.php:30`) — роли независимы, снятие `winner` не
  задевает `participant`/`customer` той же организации.
- **Имя таблицы в скелете P5 и в `docs-private/open-follow-ups.md` §6 — `org_procurement_parties`.
  Такой таблицы не существует**; реальная — `prc_procurement_parties`
  (`app/Models/Procurement/ProcurementParty.php:24`). Тот же класс дефекта, что D22.

---

## 4. P5.4 — клоббер `customer_org_id` (подтверждение D24)

Полная карта — `P4-write-site-map.md` §2. Здесь только то, что нужно для правки.

`verified:✅ 2026-07-10` — исчерпывающий греп `customerOrgId|customer_org_id` по `app/ database/ tests/`:

| Файл:строка | Роль |
|:--|:--|
| `app/Dto/Procurement/ProcurementSnapshotData.php:31` | поле DTO листинга, дефолт `null` |
| `app/Dto/Procurement/ProcurementSnapshotData.php:67` | ключ в `toSnapshotArray()` (диффуемый снапшот ревизии) |
| `app/Services/Procurement/Eis/EisCsvRowMapper.php:76` | `customerOrgId: null` — **захардкожено**, CSV ЕИС идентификатора заказчика не содержит |
| `app/Repositories/Procurement/ProcurementStoreRepository.php:28` | INSERT головы (`storeProcurement`) |
| `app/Repositories/Procurement/ProcurementStoreRepository.php:65` | **UPDATE головы (`updateProcurementHead`) — источник клоббера** |
| `app/Repositories/Organization/ProcurementPartyStoreRepository.php:36-40` | `setCustomerOrg()` — единственный продюсер истины (detail-слой) |
| `app/Models/Procurement/Procurement.php:39` | `$fillable` |
| `database/factories/Procurement/ProcurementFactory.php:31` | атрибут фабрики |
| `app/Filament/…/ProcurementInfolist.php:40` | единственный потребитель |
| `tests/Feature/Organization/OrganizationPartiesProcessorTest.php:37,44` | тест `setCustomerOrg()` |

`tests/Support/Procurement/SnapshotFactory.php` **не передаёт** `customerOrgId` вовсе — ни один
тест не строит DTO с непустым заказчиком.

Значит write-path листинга (DTO-поле → INSERT → UPDATE → снапшот ревизии) **structurally dead**:
у него нет и не может быть продюсера, потому что колонки нет в источнике. Это ровно та «мёртвая
материализация без продюсера», которую D13/D15 запрещают держать в каноне.
Ремонт «фильтром непустых» оставил бы мёртвое поле живым и обратимым одной строкой; удаление поля
из DTO делает клоббер структурно невозможным.

Сценарий регресса (что обязан пинить тест):
листинг создаёт закупку (`customer_org_id = null`) → detail проставляет `42` → приходит ЛЮБАЯ новая
ревизия листинга (изменился `snapshot_hash` → `ProcurementIngestProcessor::applyRevision()` →
`updateProcurementHead()`) → **сегодня `customer_org_id` возвращается в `null`**.
