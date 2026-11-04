---
audience: dev
type: explanation
status: living
---

# Document & Estimate Pipeline: internals

Acquisition публикует ссылки на документы; `DocumentFeeder` создаёт download и revalidation work. Общий Process владеет admission, outbox, lease, checkpoint, повторной доставкой, recovery и финализацией. `ExecuteWorkJob` доставляет bounded work зарегистрированному handler; `Document.status` и принятый `Analysis` — проекции, а не authority исполнения.

```mermaid
flowchart LR
    References[Acquisition references] --> Feeder[DocumentFeeder]
    Feeder --> Download[RuntimeDownloadHandler]
    Download --> Blob[Immutable revision and blob]
    Blob --> Prepare[PrepareHandler]
    Prepare --> Representation[Prepared representation and coverage]
    Representation --> AnalysisFeeder
    AnalysisFeeder --> Screening[ScreeningHandler]
    Screening -->|extract| Extraction[ExtractionHandler]
    Extraction --> Publication[PublicationHandler]
    Publication --> Result[Accepted analysis and estimate]
```

`DocumentRuntime` регистрирует download и prepare, `AnalysisRuntime` — screening, extraction и publication. Их codecs и finalizers подключены к `StageRegistry` через `AppServiceProvider`. Регистрация кода сама по себе не устанавливает database authorities: для документов используется `process:sync-documents`; provisioning analysis, recipes и provider account описан в [process-core](process-core.md#p313-canonical-ai-cutover) и [LLM providers](llm-providers.md). Отсутствующий account profile не заменяется угаданной моделью или ценой. Process controls и eligibility ограничивают запуск.

## Download и prepare

Download через общий network executor резервирует capacity до HTTP и сохраняет immutable bytes до публикации. `DownloadFinalizer` под guard принимает revision/blob и назначает дальнейшую revalidation. Повтор тех же bytes не создаёт лишнюю revision; A→B→A сохраняет три наблюдения и два blob. После загрузки дальнейшая обработка не зависит от состояния acquisition или source.

`PrepareHandler` сейчас подключает typed PDF path: native text и OCR сохраняются по исходным страницам, с page anchors и coverage manifest. Checkpoint позволяет продолжать pending batches. Failed, unrecognized, not-attempted и truncated required ranges запрещают complete representation и дают `blocked(document_quality)`; порог 10% влияет только на operational quality label. OCR exit code сам по себе не подтверждает полноту или точность. XLS/XLSX, office, images, GSFX и архивные стратегии сохраняют тестовый format/safety corpus, но не подключены к typed prepare: runtime возвращает `blocked(policy_review)`. Подробности storage и tool supervision — [process-core](process-core.md#p33-page-batched-prepare-runtime-cutover-p35).

## Анализ и публикация

`AnalysisFeeder` выбирает immutable prepared backlog. `ScreeningHandler` проверяет structured provider result и передаёт его на backend policy; для извлечения закрепляется source page/anchor manifest. `ExtractionHandler` обрабатывает bounded subrange, сохраняет checkpoint и validated chunks; split/reask подчиняются закреплённой recipe. `PublicationHandler` подготавливает результат для публикации. Реальные стадии подключены к Process; отсутствие пригодной recipe, данных или разрешения может оставить работу blocked или deferred.

`WorkFinalizer` и stage finalizers принимают результат под актуальными lease, generation, input, recipe и subject guards. Accepted chunk, coverage, progress, transition и outbox фиксируются атомарно. Отмена, смена head или recipe запрещают stale business write. Durable provider receipt и финансовый ledger позволяют восстановить результат без повторного send; неизвестный платный исход сохраняется как unknown, а не считается успешным или безопасным для повтора. Подробнее: [extraction contracts](ai/estimate-extraction.md), [process recovery](runbooks/process-recovery.md).

## Очереди и диагностика

Источники действующих limits, timeouts и очередей — `config/process.php`, `config/horizon.php` и `StageQueueMap`. Screening и extraction разделяют общий parent analysis lane; publication имеет отдельный lane. Состояние работы, причины blocked/deferred и controls следует смотреть через Process UI и `process:status`; восстановление и ручные действия требуют соблюдения [process controls](runbooks/process-controls.md) и [process recovery](runbooks/process-recovery.md).

Детерминированные проверки и fake provider подтверждают заданные контракты и recovery, но не измеряют semantic accuracy, live availability или production capacity. Неполный source не становится полным после валидации извлечённых строк. Требования к независимой оценке и пределы выводов приведены в [quality limits](runbooks/process-quality-limits.md).

Операторский путь — [документ и смета](../workflow/document-and-estimate-pipeline.md); команды изолированной проверки — [process test rig](runbooks/process-test-rig.md).
