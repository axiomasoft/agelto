# P8 — карта писателей статусов и природы каждой оси (design recon)

`verified:✅ 2026-07-10` — все анкоры сняты грепом/чтением кода на ветке `feat/v2-scaffold`
(разведка plan-design P8). Исполнитель P8.1/P8.2 **обязан перепроверить каждый анкор чтением
процитированного файла** (Code Guidance анти-выдумка), а не доверять этой таблице вслепую:
рёбра диаграмм рисуются ТОЛЬКО по подтверждённым код-сайтам.

## 0. Ключевой вывод — четыре оси НЕ однородны

У пяти enum'ов **нет ни одного** метода `allowedTransitions()`/`canTransitionTo()`/`transitions()`
(`grep` по всем файлам `app/Enums/Procurement/*.php` = 0). Переходы нигде не декларируются на
enum'е — они выполняются присваиваниями в job'ах/сервисах. Из методов существуют только
`isTerminal()` (`ProcurementStatus`, `RunStatus`) и `isFailed()` (`ProcurementStatus`).

Отсюда — три РАЗНЫХ класса оси, которые нельзя рисовать одинаково:

| Ось | Класс | Как представлять |
|:--|:--|:--|
| `DocumentStatus` (8) | guarded pipeline-FSM, **уже нарисован** | ссылка на Д1 (`document-pipeline.md:16-33`), НЕ дублировать |
| `AnalysisStatus` (5) | guarded FSM, диаграммы нет | новый `stateDiagram-v2`, рёбра анкорены (§2) |
| `RunStatus` (5) | guarded FSM, диаграммы нет | новый `stateDiagram-v2`, рёбра анкорены (§3) |
| `ProcurementStatus` (7) | **НЕ guarded-FSM** — внешне маппится | классификация, БЕЗ выдуманных рёбер (§1) |
| `PipelineStage` (3), `AnalysisStep` (2) | не статус — ось-измерение | раздел «это не FSM» (§4) |

## 1. ProcurementStatus — внешне маппится, а не guarded (несущий факт D35)

Статус `prc_procurements.status` **не имеет ни одного внутреннего transition-гарда**. Он целиком
задаётся `StatusMappingService::resolve(registryId, sourceStage)`:
- `StatusMappingService.php:18-31` — `resolve()` возвращает `ProcurementStatus::from($mapping->canonical_status)`;
  незнакомая пара → `ProcurementStatus::Unknown` (`:26`, `firstOrCreate` в `unknown`, D17).
- Присваивается через `ProcurementStoreRepository::storeProcurement()` (`:24`, `'status' => $status`) и
  `::updateProcurementHead()` (`:60`) — параметром `$status`, вычисленным маппером, на КАЖДОЙ ревизии.
- Классификация кейсов реальна: `ProcurementStatus.php:36-50` — `isTerminal()` (`Completed`/`Cancelled`/
  `Unsuccessful`) и `isFailed()` (`Cancelled`/`Unsuccessful`).
- Порядок «Bidding → Evaluation → …» — это **процедура ЕИС** (проза, `RAG:✅ 2026-07-10` этапы ЕИС,
  `findings/P2-eis-stages.md`), НЕ enforced приложением. `DetailRefetchSelector.php:23` использует
  `{Evaluation, Awarded}` как критерий рескана — это чтение, не переход.

**Запрет P8.1:** нарисовать рёбра `Bidding --> Evaluation` и т.п. = выдумать контракт, которого в
коде нет (класс spec drift, ровно то, что убивал P6). Единственные анкорённые отношения:
`StatusMappingService → состояние` и терминальная/failed-группировка по `isTerminal()`/`isFailed()`.
`Awarded`/`Unsuccessful` — forward-provision (`ProcurementStatus.php` docblock, D16), пометить.

## 2. AnalysisStatus — guarded FSM (`pending → running → succeeded|failed|skipped`)

Методов на enum'е нет; терминальность — по конвенции (`succeeded`/`failed`/`skipped`). Писатели:
- → `Pending`: `AnalysisStoreRepository.php:35,61` (создание сессии анализа).
- → `Running`: `AnalysisStoreRepository.php:80`, `Screening/ScreeningProcessor.php:64`,
  `Extraction/ExtractionOrchestrator.php:84`.
- → `Succeeded`: `Screening/ScreeningProcessor.php:222`, `Extraction/ExtractionOrchestrator.php:700`.
- → `Skipped`: `Screening/ScreeningProcessor.php:255`, `Extraction/ExtractionOrchestrator.php:720`.
- → `Failed`: анкор искать грепом `grep -rn 'AnalysisStatus::Failed' app/` (обработчики `recordFailure`
  screening/extraction) — **исполнитель обязан найти и процитировать конкретный file:line, не рисовать
  ребро по памяти**.
- Ось `AnalysisStep` (`Screening`/`Extraction`) ортогональна статусу: одна сессия анализа несёт `step` и
  прогоняет свой pending→running→terminal. Показать как измерение, не как переход статуса.

## 3. RunStatus — guarded FSM, единственный писатель `CrawlerRunManager`

Все переходы прогона краулера — в одном сервисе (`app/Services/Procurement/CrawlerRunManager.php`):
- → `Running` (старт): `:48`; (resume из `Paused`): `:40` (`+ last_heartbeat_at`).
- → `Completed`: `:104` (`+ finished_at`).
- → `Failed`: `:112`.
- → `Paused`: `:131`.
- `isTerminal()` = `Completed`/`Failed`/`Cancelled` (`RunStatus.php:18-24`). `Cancelled` в enum'е есть,
  но writer'а `RunStatus::Cancelled` грепом искать (`grep -rn 'RunStatus::Cancelled' app/`) —
  подтвердить/пометить как forward-provision, если продюсера нет. `Paused` — единственный
  нетерминальный «пауза»-стейт с обратным ребром в `Running` (`:40`).

## 4. PipelineStage / AnalysisStep — оси-измерения, не статусы

- `PipelineStage` (`Prepare`/`Screening`/`Extraction`, `PipelineStage.php:18-20`) — маршрут диспетчера,
  не статус документа. Фабрики: `forDocumentRetry()` → `Prepare` (`:26`), `fromAnalysisStep()` (`:31`).
  Связан с Д2 (`document-pipeline.md:52-63`, flowchart диспетчеризации). `ProcessStage` — НЕ существует
  (`grep`=0), не упоминать.
- `AnalysisStep` — какой шаг анализа, см. §2.

## 5. Lifecycle-сущности (для P8.2) — кто создаёт впервые / кто мутирует

| Сущность | Таблица | Создатель (первый write) | Мутатор / append |
|:--|:--|:--|:--|
| `Procurement` | `prc_procurements` | `ProcurementStoreRepository::storeProcurement()` (`:16`, первый обход) | `updateProcurementHead()` (`:50`, каждая ревизия листинга), `markDetailFetched()` (`:95`), `markDetailFailed()` (`:110`), `touchLastSeen()` (`:86`) |
| `ProcurementRevision` | `prc_revisions` | `storeRevision()` (`ProcurementStoreRepository.php:123`) | **immutable append** — дедуп по `snapshot_hash`, `revision_number++` |
| `ProcurementEvent` | `prc_events` | `storeEvent()` (`:161`), read-guard `hasEvent()` (`:147`) | **immutable append-only** (журнал, ADR-0007 ≈ OCDS releases) |
| `Document` | `prc_documents` | `DocumentStoreRepository::create()` (`:35`, из `DocumentIndexer:71` → `Discovered`) | `update()` (`:43`, статус по пайплайну — Д1) |
| `DocumentVersion` | `prc_document_versions` | `createVersion()` (`:81`) | **immutable content-addressed** — дедуп по `content_hash`, `unique(document_id, content_hash)` |

### Повтор vs новая ревизия vs новая версия документа (сердце P8.2)

`verified:✅` — `DocumentDownloader.php:35-80` (docblock-инвариант `:18-24`):
- **новая версия документа** = изменились БАЙТЫ файла: `hash('sha256', $content)`; совпал
  `content_hash` → **no-op** (возврат существующей версии); новый хэш → `createVersion()` +
  событие `DocumentAdded` (первая) / `DocumentReplaced` (последующая).
- **новая ревизия закупки** = изменились МЕТАДАННЫЕ карточки: дедуп по `snapshot_hash` в
  `storeRevision()`; голова обновляется `updateProcurementHead(current_revision_id, revision_count)`.
- **повтор** (ни то ни другое) = совпал хэш → no-op; `touchLastSeen()` фиксирует «видели снова»
  без новой ревизии/версии.

Две оси дедупликации независимы: `snapshot_hash` (карточка) ⟂ `content_hash` (файл). Путаница
между ними — реальный риск для следующего исполнителя (валидная находка аудита, Phase Context P8).
