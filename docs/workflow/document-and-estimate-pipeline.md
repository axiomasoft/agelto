---
audience: shared
type: explanation
status: living
---

# Путь документа и сметы

> Runtime cutover P3.5: source discovery publishes canonical references/intents.
> `DocumentFeeder` → common admission/outbox → `ExecuteWorkJob` → `DownloadHandler` → immutable
> version/blob → `PrepareHandler`. Download and prepare have independent document controls;
> acquisition pause/health does not gate downloaded bytes. Revalidation is a document producer,
> not an acquisition callback. `PipelineDispatcher`, `PipelineRecoveryReconciler`, the ordinary
> document jobs and `crawler.downstream_enabled` are removed.
>
> Install authorities/storage with `process:sync-documents`. Real AI is explicitly pending
> `handler_not_activated` until its recipe/financial ledger cutover. The current typed preparation
> runtime handles PDF page batches; other formats are explicitly blocked pending typed adaptation.
> Historical algorithm descriptions below describe the retained format/AI safety corpus, not
> alternate execution owners. See [process-core](../dev/process-core.md#p35--independent-document-runtime).


Как скачанный документ (см. `crawling-and-discovery.md`) превращается — если в нём есть смета —
в структурированные строки, которые можно анализировать.

Файл считается скачанным только после успешной записи immutable object в хранилище; затем одной
короткой транзакцией фиксируются версия, статус и событие. Повтор того же хэша также проверяет
объект и долечивает неполный database-state, не создавая вторую версию или событие.
То же правило действует для каждого подготовленного представления: пока object write не
подтверждён, representation не появляется в БД и документ не может стать `prepared`.

## Три шага после скачивания

### 1. Подготовка

Документ приводится к виду, пригодному для анализа. Архив разворачивается на отдельные файлы;
таблица Excel конвертируется в формат, который умеет читать модель. Если файл огромный (тысячи
строк в Excel, документ весом в сотни мегабайт) или похож на «zip-бомбу» — он сразу помечается
как необрабатываемый, не тратя время на дальнейшие шаги (см. §Терминальные исходы).

Система принимает конкретные семейства форматов, а не абстрактные «офисные документы»:

- `xls`/`xlsx` превращаются в PDF и, когда это возможно, в текстовую сетку таблицы;
- `doc`/`docx`/`rtf`/`odt` превращаются в PDF;
- PDF с бедным или отсутствующим текстовым слоем проходит OCR и получает OCR-PDF вместе с текстом;
- `png`/`jpg`/`jpeg`/`tif`/`tiff`/`bmp`/`webp` идут в vision-ветку без преобразования;
- `gsfx` читается как структурированный XML и текст;
- `zip`/`7z`/`rar` безопасно разворачиваются в дочерние документы, а каждый вложенный файл проходит тот же путь.

Новый формат не становится «поддержанным» только из-за расширения: разработчик добавляет его в
закрытый реестр с проверкой MIME и сигнатуры, ровно одним обработчиком и тестовыми примерами
успеха/несовпадения. Неизвестный формат и файл с неподходящими байтами останавливаются до анализа.

Каждый переход `prepared → screening` и `screened → extraction` восстанавливается из базы,
а не по наблюдению очереди. Если worker погиб после DB commit и до отправки job, периодический
reconciler берёт ограниченный атомарный claim на активной версии и незавершённом run, после чего
повторно ставит только следующий допустимый шаг. Временная ошибка AI остаётся `running` с due
backoff и проходит через тот же путь; permanent failure становится явным terminal outcome.
Решения `retain`, `review` и `ignore` не являются кандидатами на extraction.

### Бюджеты worker-операций

Матрица `config('ai.pipeline.operation_budgets')` версии `pipeline-operation-budget/v1`
является контрактом длительных операций. `prepare` работает на отдельном
`redis_prepare/doc_prepare` lane: source setup 120 s + text-layer probe 180 s + OCR 1800 s +
Tesseract version 180 s + PDF page-count 180 s + 120 s reserve выполняются в job 2580 s,
supervisor 2640 s и Redis `retry_after` 2700 s. Перед каждым внешним OCR-инструментом job проверяет, что
весь обязательный хвост ещё помещается до deadline; нехватка времени остаётся retriable
ошибкой, а не `unprocessable` документа. Для screening полный последовательный хвост
составляет 60 s PDF slice + 150 s upload/poll + 90 s generation + 30 s setup + 30 s
persistence/release reserve = 360 s; затем идут supervisor 390 s и `retry_after` 420 s.
Пограничный второй маршрут сохраняется как continuation и выполняется отдельным job, поэтому
один worker никогда не скрывает два полных provider-вызова внутри одного timeout. Extraction
checkpoint'ится на 120 s: ещё один максимум 150-секундный вызов и 30-секундный reserve ровно
помещаются в 300 s job, затем идут supervisor 360 s и `retry_after` 420 s. Lease берётся из той же строки матрицы: 2700 s для
prepare и 420 s для screening/extraction. Поэтому duplicate delivery не получает активный
document/analysis, но после SIGKILL короткая стадия не остаётся запертой дольше своего
visibility window.

Horizon получает SIGTERM, Docker даёт ему не менее 4200 s (`HORIZON_STOP_GRACE_PERIOD`),
поэтому worker завершает или сохраняет checkpoint до SIGKILL. После нештатного SIGKILL
P3.4 reconciler поднимает только due continuation и atomic lease/recovery claim не допускают
двух активных владельцев.

После этого система выбирает первое доступное представление из политики конкретного расширения;
если политика не дала результата, используется оригинал только когда это разрешено настройкой.
Так модель получает не «самый красивый» файл по предположению, а представление, реально
получившееся у подготовителя. Точные правила выбора, лимиты и fallback описаны в
[`dev/ai/estimate-extraction.md`](../dev/ai/estimate-extraction.md#346-выбор-представления-на-вход).

Ручной перезапуск анализа атомарно создаёт новый прогон и возвращает документ к нужной
стадии. Перезапуск устаревшего анализа, старой версии или документа в активной обработке
отклоняется. Потерянная задача ручного извлечения восстанавливается по новому анализу с
`parent_analysis_id`; screening из предыдущего прогона не обязан принадлежать новому run.
Повторный screening инвалидирует старое извлечение. Если новый вердикт требует extraction,
создаётся новый анализ со связью на прежний результат. Успешная публикация заменяет все
superseded-сметы этой версии, включая результаты до неудачных промежуточных перезапусков.

Имена файлов в архивах не могут содержать управляющие символы или маски `*?[]`, начинаться
с `-`/`@`, обходить каталоги или задавать абсолютный путь. Такой архив отклоняется до извлечения.

## Д13 — форматы, представления и модель

```mermaid
flowchart LR
    Source["Исходный документ"] --> Table["xls / xlsx"]
    Source --> Office["doc / docx / rtf / odt"]
    Source --> Pdf["PDF или скан"]
    Source --> Image["Изображение"]
    Source --> Gsfx["gsfx"]
    Source --> Archive["zip / 7z / rar"]
    Table --> PdfView["PDF или text_grid"]
    Office --> PdfView
    Pdf --> OcrView["OCR-PDF и текст при необходимости"]
    Image --> Vision["Оригинал для vision"]
    Gsfx --> Structured["Структурированный XML и текст"]
    Archive --> Source
    PdfView --> Policy["Политика представления"]
    OcrView --> Policy
    Vision --> Policy
    Structured --> Policy
    Policy --> Model["Маршрут LLM"]
```

### 2. Первичный анализ (скрининг)

Дешёвая и быстрая модель смотрит на первые несколько страниц документа и определяет: это смета,
что-то другое, или непонятно.

- **Явно смета** — документ идёт дальше, на полное извлечение.
- **Явно не смета** (например, само извещение о закупке, протокол, техзадание без сметы) —
  документ помечается своим типом и на этом останавливается — извлекать нечего.
- **Непонятно** — документ отсеивается как нецелевой.
- **Пограничный случай** (модель не уверена — не «явно да», но и не «явно нет») — вместо тихого
  отбрасывания система либо перепроверяет более сильной моделью, либо помечает документ
  «нужно ревью» — оператор увидит его отдельно и решит сам (`operators/how-to/review-estimate.md`).

Скрининг сохраняет краткое содержание, точные теги и число видимых смет (`none`, `single`,
`multiple` или `unknown`). Это не число страниц или файлов внутри архива: архив сначала становится
набором отдельных документов. Модель описывает факты, а система сама записывает решение: извлечь,
оставить как известный не-сметный тип, отправить на ревью или отсечь. Поэтому текст модели не может
самостоятельно обойти правило отсечения или запустить извлечение.

### 3. Извлечение сметы

Если документ прошёл скрининг как смета — начинается собственно извлечение:

1. **Паспорт** — шапка сметы: номер, наименование, единица измерения итога и т.п.
2. **Строки** — построчное содержимое: разделы, позиции, количества, цены, суммы. Для обычных
   документов это делается по всему документу целиком; для очень больших документов (сотни
   страниц, несколько смет вперемешку в одном файле) система сначала находит границы каждой
   сметы внутри документа окнами, а затем разбирает каждую смету отдельно — постепенно, не
   упираясь в ограничения одного запроса к AI-провайдеру.
3. **Итоги** — сверка сумм по разделам и по смете целиком.

После извлечения бэкенд (не модель) детерминированно проверяет полноту (все ли строки собраны)
и арифметику (сходятся ли суммы). Если что-то не сходится — смета не отбрасывается, но помечается
«подозрительная» и видна оператору для ревью, а не выдаётся как безусловно верная.

**Отдельно от сметы целиком, помечается и КАЖДАЯ ПОЗИЦИЯ** (например, строка, которую AI не смог
разобрать из-за плохого скана исходника): AI прямо говорит «эту строку и вот эти её поля я не смог
прочитать», вместо того чтобы молча вернуть пустоту, неотличимую от «поле в документе и правда
пустое». Бэкенд добавляет к этому свои находки (найденную арифметическую нестыковку раздела,
пропуск в нумерации) — обе причины видны оператору напротив конкретной позиции в витрине смет,
а не только общим статусом сметы.

## Терминальные исходы

Каждый документ рано или поздно приходит к одному из финальных состояний:

- **Разобран** — смета успешно извлечена (возможно, с пометкой «подозрительная», если суммы не
  сошлись).
- **Отсеян** — документ не смета (или скрининг не смог определить тип).
- **Развёрнут** (`expanded`) — архив успешно превратился в дочерние документы; сам архив дальше не
  анализируется, а каждый дочерний файл проходит обычную подготовку.
- **Не смогли обработать** (`unprocessable`) — документ принципиально не поддаётся разбору:
  нечитаемый формат, защищён паролем, слишком большой или превышает лимиты AI-провайдера даже
  для разбора окнами. Это НЕ технический сбой (который автоматически повторяется) — это
  констатация факта, видимая оператору (`operators/reference/statuses.md`,
  `operators/how-to/restart-failed-run.md`).
- **Ошибка (failed)** — временный технический сбой (например, недоступен провайдер) — такие
  документы автоматически подхватываются повторной попыткой. Вложение, не скачанное из-за
  временной ошибки, остаётся `discovered` до наступления backoff и затем попадает в отдельную
  ограниченную download-job; для этого не нужна новая редакция закупки.

## Д1-lite — путь документа (без job'ов и error-блока)

```mermaid
stateDiagram-v2
    [*] --> Скачан
    Скачан --> Подготовлен: приведение к разбираемому виду
    Скачан --> НеОбработан: формат нечитаем/защищён паролем
    Подготовлен --> Проверен: первичный анализ (скрининг)
    Подготовлен --> Отсеян: не смета/нецелевой тип
    Проверен --> Разобран: извлечена смета
    Проверен --> НеОбработан: документ слишком велик/лимит провайдера
    Разобран --> [*]
    Отсеян --> [*]
    НеОбработан --> [*]
```

Полная версия с job'ами, очередями и error-блоком (для разработчика) — `docs/dev/document-pipeline.md` (Д1).

Как готовятся промты, которыми пользуется модель на каждом шаге — `prompts-and-quality.md`; полный
канон контрактов ответов и алгоритма извлечения —
[`dev/ai/estimate-extraction.md`](../dev/ai/estimate-extraction.md).

## Immutable storage staging (P3.1)

The acquisition catalog remains the single logical document catalog. New storage primitives record
source revisions separately from content-addressed blobs: A→B→A means three observations and two
blobs. A current-head CAS prevents a stale observation from replacing a newer source head.
Representations pin their preparation recipe, range, tool version, original coverage and disk;
missing source ranges remain explicitly incomplete.

Upload/spool byte ceilings are durable reservations, not queue-slot counters. An uncertain put or
unconfirmed cleanup retains its reservation. Immutable bytes and their references do not depend on
crawler availability. GC and reference acquisition share one deletion gate; existing historical
references prevent deletion, and a deletion-marked object cannot receive a new reference.

P3.5 activates runtime download/prepare and removes the additive NULL legacy tuple. Every surviving
writer now records canonical blob/revision or recipe/range metadata, an explicit disk and bytea
hashes; see [process-core.md](../dev/process-core.md). No new runtime toggle was introduced.

### Новый download owner (P3.2, активация в P3.5)

Canonical references скачиваются через `Document/DownloadHandler` и общий network executor.
Source hook возвращает только bounded binary fetch plan; refresh signed URL ограничен одним
вызовом. Capacity резервируется до HTTP, bytes проверяются и сохраняются immutable до
publication. Общий `WorkFinalizer` атомарно принимает revision/blob, завершает acquisition intent
и назначает следующий revalidation due. Same bytes не создают лишнюю revision; возврат A после B
создаёт новую revision с прежним blob. Revalidation всегда выполняет полный content recheck.
Ошибка сети откладывает download/revalidation без item failure и без изменения существующих
bytes. После получения bytes source availability больше не участвует в финализации.

До P3.5 новый owner проверяется отдельно от legacy jobs/dispatcher; runtime consumers ещё не
переключены. Prepare/OCR и независимый feeder имеют собственные пункты P3.3/P3.5.
Подробные guards и граница приёмки: [process-core](../dev/process-core.md).

### Page-batched prepare (P3.3, подключение в P3.5)

Новый PDF prepare работает по immutable version/blob. Каждый batch сохраняет текст с исходными
`[page:N]` anchors и page manifest до guarded checkpoint; restart продолжает pending ranges.
Native text берётся из PDF, OCR sidecar не заменяет полный текст. Повторные ошибки делятся до
singleton с конечным budget. Truncation, skipped/failed/unrecognized required pages сохраняют
incomplete coverage и дают `blocked(document_quality)`; порог 10% меняет только quality label.
Текущий safe default не разрешает degraded input. Original bytes остаются неизменными.

Конвертеры выполняются под независимым bounded supervisor с TERM/KILL/reap и cooperative lease
tick. Tmp quota освобождается после подтверждённой остановки и удаления workspace; смерть PHP
сама по себе этого не доказывает. Оставшиеся локальные workspaces допускают повторный cleanup
по сохранённым stop/owner receipts. Подробности и граница runtime activation —
[process-core](../dev/process-core.md#p33-page-batched-prepare-runtime-cutover-p35).
