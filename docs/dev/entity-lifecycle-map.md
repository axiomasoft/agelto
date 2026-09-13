---
audience: dev
type: reference
status: living
---

# Entity Lifecycle Map — кто создаёт, кто мутирует, что есть повтор

Канонический справочник жизненного цикла пяти сущностей домена `Procurement`: кто пишет запись
первым, кто мутирует «голову», кто только дописывает immutable-дочерние записи, и — главное — чёткая
граница между **повтором**, **новой ревизией закупки** и **новой версией документа**. Путаница
«ревизия ⇄ версия документа» — валидная находка внешнего аудита (Phase Context `plans/archive/DOMLANG/phases/P8.md`):
это две независимые оси дедупликации, которые читаются как одна.

Статус-переходы `DocumentStatus` здесь не пересказываются — см. Д1
(`document-pipeline.md`). Соответствие OCDS (`prc_events` ≈ `releases` и т.д.) здесь не
пересказывается — см. [`ocds-mapping.md`](ocds-mapping.md) и [ADR-0007](adr/0007-ocds-as-exchange-layer.md).

## 1. Таблица писателей

| Сущность | Таблица | Создатель (первый write) | Мутатор / append |
|:--|:--|:--|:--|
| `Procurement` | `prc_procurements` | `ProcurementStoreRepository::storeProcurement()` (`app/Repositories/Procurement/ProcurementStoreRepository.php:16`) — первый обход закупки листингом | `updateProcurementHead()` (`:50`, каждая обнаруженная ревизия), `markDetailFetched()` (`:95`), `markDetailFailed()` (`:110`), `touchLastSeen()` (`:86`) |
| `ProcurementRevision` | `prc_revisions` | `storeRevision()` (`ProcurementStoreRepository.php:123`) | **immutable append** — новых записей не мутируют; дедуп по `snapshot_hash`, `revision_number` инкрементируется на каждой новой ревизии |
| `ProcurementEvent` | `prc_events` | `storeEvent()` (`ProcurementStoreRepository.php:161`); read-guard от дублей — `hasEvent()` (`:147`) | **immutable append-only журнал** — `ProcurementEvent`-модель (`app/Models/Procurement/ProcurementEvent.php`) не декларирует update-путь; единственный писатель — `storeEvent()` (чистый `create()`, ADR-0007) |
| `Document` | `prc_documents` | `DocumentStoreRepository::create()` (`app/Repositories/Procurement/DocumentStoreRepository.php:35`), вызывается из `DocumentIndexer` со статусом `DocumentStatus::Discovered` (`app/Services/Procurement/DocumentIndexer.php:71`) | `update()` (`DocumentStoreRepository.php:43`) — статус документа далее ведёт guarded pipeline-FSM, см. Д1 (`document-pipeline.md`), здесь не пересказывается |
| `DocumentVersion` | `prc_document_versions` | `createVersion()` (`DocumentStoreRepository.php:81`), вызывается из `DocumentDownloader::download()` (`app/Services/Procurement/DocumentDownloader.php:59`) | **immutable, content-addressed** — дедуп по `content_hash`, `unique(document_id, content_hash)`; `updateVersion()` в репозитории существует, но `DocumentDownloader` его не вызывает — новый контент всегда идёт через `createVersion()`, не через мутацию существующей строки |

Список platform attachments сверяется с `prc_documents` только при `DocumentsSnapshot=complete`.
`incomplete` и `failed` не дают права помечать записи удалёнными. Archive child определяется
явной связью `prc_document_versions.parent_version_id`, поэтому не может быть удалён как
отсутствующее вложение карточки.

`prc_eis_details`, `prc_financing`, `prc_items` и `prc_procurement_parties` — одна current
projection вкладки common-info. `CommonInfoSnapshot=complete` обновляет их одной transaction;
`incomplete` и `failed` не меняют ни одну из этих поверхностей. Items сверяются по
`(procurement_id, position_number)`, стороны — по `(procurement_id, organization_id, role)` после
organization resolution; отсутствующий customer обнуляет denormalized `customer_org_id`.

`PipelineRecoveryReconciler` — единственный владелец AI retry и lost-continuation recovery:
он выбирает только active `DocumentVersion` + незавершённый `AnalysisRun`, атомарно claim-ит
`pending`/retryable `running` analysis с наступившим backoff и ставит следующий job. Очередь не
является источником истины. `Prepared` продолжает только screening при включённом флаге, а
`Screened` — extraction только при сохранённом `decision.extract` с
`extraction_dispatched=true`; retain/review/ignore и permanent failures не диспатчатся.

## 2. Повтор / новая ревизия закупки / новая версия документа

Две оси дедупликации **независимы** — `snapshot_hash` (карточка закупки) ⟂ `content_hash` (файл
документа). Совпадение одного хэша ничего не говорит о другом.

### 2.1 Новая версия документа — `DocumentDownloader::download()`

`verified:✅` — `app/Services/Procurement/DocumentDownloader.php:35-80` (docblock-инвариант `:18-24`).

- Критерий: изменились **байты файла**. `$hash = hash('sha256', $content)` (`:49`).
- Совпал `content_hash` (`findVersionByHash()`) → новая запись не создаётся; при revalidation
  обновляется время последнего подтверждения bytes, а текущий pipeline status сохраняется.
- Новый хэш → `createVersion()` (`:59-68`) + `prc_documents.status = Downloaded` (`:70`) + событие:
  - `ProcurementEventType::DocumentAdded` — если это первая версия документа (`$isFirstVersion`,
    `countVersions() === 0`, `:57`);
  - `ProcurementEventType::DocumentReplaced` — если версия не первая.
  - Оба случая пишутся одним вызовом `storeEvent()` (`:72-77`), тернарник по `$isFirstVersion`.

Повторный production-вызов обеспечивает `DocumentRevalidationSelector`: revision-trigger
диспатчится после индексации карточки, а low-rate страховка каждые шесть часов выбирает ограниченную
пачку давно не проверявшихся версий. `RevalidateDocumentJob` использует общий lease/error block и
тот же `DocumentDownloader`; removal, future backoff и permanent failure остаются гейтами.
Owner-authorized P4.1 дал `insufficient_evidence` из-за трёх последовательных timeout ЕИС, поэтому
D8 исполнен fail-closed: revalidation обязательна, а публичной гарантии immutable bytes при одном
`uid` по-прежнему нет.

### 2.2 Новая ревизия закупки — `storeRevision()` / `updateProcurementHead()`

- Критерий: изменились **метаданные карточки закупки** (снапшот листинга/detail-слоя).
- Дедуп — по `snapshot_hash` в `storeRevision()` (`ProcurementStoreRepository.php:123-145`): новый
  снапшот с отличным хэшем создаёт новую `ProcurementRevision` (append, не мутация).
- Голова закупки (`prc_procurements`) синхронизируется отдельным вызовом
  `updateProcurementHead()` (`:50-84`) — принимает уже созданную `$revision`, обновляет
  `current_revision_id`, `current_revision_detected_at`, `revision_count`.
- `ProcurementIngestProcessor` захватывает PostgreSQL advisory lock по `(registry_id,
  registry_number)` до чтения текущей головы. Номер новой ревизии и запись головы выводятся уже под
  этой блокировкой, поэтому два worker-а не создают одинаковый logical revision или last-writer-wins
  head.
- Первое наблюдение всегда добавляет `Discovered` **и** канонический `StatusChanged` с первым
  распознанным статусом. Для terminal статуса это не выдуманный переход: timeline показывает
  terminal-at-discovery и нулевую наблюдённую длительность, а не «идёт».

### 2.3 Повтор (ни то ни другое)

- Совпал хэш снапшота закупки → новая `ProcurementRevision` не создаётся.
- Единственное действие на «увидели снова» без изменений — `touchLastSeen()`
  (`ProcurementStoreRepository.php:86-89`): обновляет только `last_seen_at`, не трогает ревизии,
  статус или события.
- Аналогично для документа: совпал `content_hash` → `download()` возвращает существующую версию
  без записи (§2.1), новое событие не создаётся.

## 3. Immutable-сущности

Явно помечены как append-only/content-addressed, мутация существующей записи в обычном потоке
кода отсутствует:

- `prc_revisions` (`ProcurementRevision`) — append по `storeRevision()`, дедуп `snapshot_hash`.
- `prc_events` (`ProcurementEvent`) — append-only журнал, партиционирован по `occurred_at`
  (ADR-0007); единственный писатель — `storeEvent()`.
- `prc_document_versions` (`DocumentVersion`) — content-addressed, дедуп `content_hash`
  (`unique(document_id, content_hash)`); `DocumentDownloader` пишет только через `createVersion()`.

## 4. Резолв организации: коллизия при неименованном участнике

`SupplierResultsProcessor` резолвит участников закупки в `org_organizations` через
`OrganizationResolver`. Первый прогон резолвил участников **без ИНН/ОГРН по голому имени**: при
двух и более безымянных участниках `OrganizationResolver` схлопывал разных юрлиц в одну
`org_organizations`, поскольку recovery `OrganizationStoreRepository::create()` после
`UniqueConstraintViolationException` возвращал первую найденную запись.

Участник без надёжного идентификатора (ИНН/ОГРН) вообще не резолвится в организацию:
`organization=null`, `prc_bids.organization_id=null`. Его естественный ключ upsert использует
`participant_ordinal`; победитель всегда резолвится через `OrganizationResolver` и получает роль
`winner`. При изменении `SupplierResultsProcessor` или `OrganizationResolver` не резолвьте по
имени без ИНН/ОГРН — это воспроизводит коллизию.

## Смежное

- [`ocds-mapping.md`](ocds-mapping.md) — соответствие `prc_procurements`/`prc_revisions`/`prc_events`
  секциям OCDS (`compiledRelease`/`versionedRelease`/`releases`) — не пересказывается здесь.
- [`adr/0007-ocds-as-exchange-layer.md`](adr/0007-ocds-as-exchange-layer.md) — принцип изоморфизма
  `prc_events` ≈ OCDS `releases`.
- [`document-pipeline.md`](document-pipeline.md) — Д1, guarded pipeline-FSM `DocumentStatus`
  (`Discovered → … → Downloaded → …`), статус-переходы документа не дублируются здесь.
- [`state-machines.md`](state-machines.md) — все пять осей состояния домена одним документом (P8.1).
