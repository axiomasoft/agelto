---
audience: shared
type: explanation
status: living
---

# Обзор архитектуры в схемах

Страница даёт общую картину на одном экране: из чего состоит система, как работа проходит
через Process Core, как классифицируются отказы источников и как устроен CI. Подробности и
точные контракты — на страницах, на которые ведут ссылки в каждом разделе. Версии окружения
перечислены в [обновлении окружения](runbooks/environment-upgrade.md).

## Компоненты и потоки данных

```mermaid
flowchart LR
    subgraph Внешний мир
        EIS[Площадки закупок<br/>ЕИС и др.]
        LLM[Провайдер ИИ<br/>Gemini]
        OP[Оператор<br/>браузер]
    end
    subgraph Хост
        NGINX[NGINX хоста<br/>TLS]
        subgraph Docker Compose
            APP[app<br/>Laravel + Filament]
            HZ[horizon<br/>воркеры ExecuteWorkJob]
            SCH[scheduler]
            DMN[process-*<br/>relay, reconcile, feed, project]
            PG[(PostgreSQL 19<br/>prc_* ledger)]
            RQ[(redis<br/>очереди)]
            RC[(redis-cache)]
            RS[(redis-session)]
            S3[(Silo<br/>S3-хранилище)]
            TOOLS[tool_supervisor<br/>qpdf, Ghostscript, OCR]
        end
    end
    OP --> NGINX --> APP
    APP --> PG
    APP --> RS
    APP --> RC
    SCH --> PG
    DMN --> PG
    DMN -->|outbox → wake| RQ
    RQ --> HZ
    HZ --> PG
    HZ -->|прокси, DNS pinning| EIS
    HZ -->|send gate, бюджет| LLM
    HZ --> S3
    HZ --> TOOLS
```

Источник истины для исполнения — PostgreSQL: work, попытки, переходы и outbox живут в таблицах
`prc_*`. Redis только доставляет сообщения; при его потере reconcile и relay восстанавливают
доставку из PostgreSQL. Сетевые вызовы и объектный I/O не выполняются внутри открытых транзакций
БД. Подробнее: [архитектура](architecture.md), [Process core](process-core.md),
[восстановление](runbooks/process-recovery.md).

## Стадии конвейера

```mermaid
flowchart LR
    L[listing] --> D[detail]
    D --> DL[download]
    DL --> P[prepare]
    P --> SC[screening]
    SC --> EX[extraction]
    EX --> PUB[publication]
    D -.периодически.-> SR[status_refresh]
```

Основной источник листинга — выгрузка CSV площадки, HTML-обход — запасной путь. Каждая стадия
выполняется одним `ExecuteWorkJob` по work из ledger; стадии ИИ (`screening`, `extraction`)
проходят через финансовый gate отправки и бюджет. Подробнее:
[краулинг](crawling-pipeline.md), [документы](document-pipeline.md),
[поставщики LLM](llm-providers.md).

## Жизненный цикл work

```mermaid
stateDiagram-v2
    [*] --> ready
    ready --> admitted: WorkFeeder, допуск по ёмкости
    deferred --> admitted: срок наступил
    admitted --> running: claim в WorkRunner
    admitted --> blocked: пауза или hold обработчика
    running --> succeeded
    running --> ready: частичный результат
    running --> deferred: повтор позже
    running --> blocked: неизвестный результат провайдера,<br/>лимит ошибок обработчика
    running --> failed_item: третья ошибка item
    blocked --> ready: снятие паузы, сверка провайдера
    ready --> cancelled
    admitted --> cancelled
    running --> superseded: изменился вход
    succeeded --> [*]
    failed_item --> [*]
    cancelled --> [*]
    superseded --> [*]
```

Терминальные состояния (`succeeded`, `failed_item`, `cancelled`, `superseded`) никто не
переписывает. Каждый переход — одна транзакция с записью перехода и outbox. Полная таблица
переходов и владельцев: [Process core → State machine](process-core.md#state-machine).

## Классификация отказов источника

Классификация детерминирована: учитываются только тип исключения, HTTP-статус и проверка
формата ответа (`SourceFailureKind`). Вид отказа описывает факт, а реакция на него задаётся
политикой.

```mermaid
flowchart TD
    R[Ответ или исключение] --> X{Исключение<br/>нашего кода?}
    X -->|да| CE[code_error<br/>блок пропускается, ошибка в журнал]
    X -->|нет| N{Ответ получен?}
    N -->|нет прокси или 407| PU[proxy_unavailable]
    N -->|соединение, 408, 5xx| SU[source_unavailable<br/>повтор с ротацией прокси]
    N -->|да| S{HTTP-статус}
    S -->|404| NF[not_found<br/>пропуск без повтора,<br/>не влияет на breaker]
    S -->|401, 403, 429, 451| SB[source_blocked]
    S -->|другой не 2xx| US[unexpected_status]
    S -->|2xx| F{Формат}
    F -->|пустое тело| EB[empty_body<br/>повтор и критичный инцидент]
    F -->|нет колонок или селекторов| LD[layout_drift<br/>отдельный breaker,<br/>ждёт решения оператора]
    F -->|ни одна запись не разобрана| IC[invalid_content]
    F -->|часть строк не разобрана| RE[row_errors<br/>курсор сохраняется]
```

Здоровье маршрутов прокси и целей считается отдельно (route-global, route-target, target),
429 не вызывает ротацию, а 407 удерживает версию учётных данных. Подробнее:
[Process core](process-core.md), [сетевой слой краулинга](crawling-pipeline.md).

## CI

Репозиторий рассчитан на закрытый режим, поэтому CI экономит минуты: фильтр путей решает, какие
задачи нужны, устаревшие прогоны той же ветки отменяются, зависимости кэшируются, а все actions
закреплены по SHA.

```mermaid
flowchart LR
    CH[changes<br/>фильтр путей] --> PF[preflight<br/>Pint, PHPStan, контракты]
    CH --> BS[build-security<br/>образы, Trivy]
    PF --> Q[quality]
    PF --> T[tests<br/>4 шарда]
    PF --> FL[faults]
    PF --> E2E[e2e]
    PF --> RQ[release-quality]
    PF --> LD[load<br/>W10, W20]
    Q --> G{ci-gate}
    T --> G
    FL --> G
    E2E --> G
    RQ --> G
    LD --> G
    BS --> G
```

`ci-gate` — единственная обязательная проверка: она запускается всегда и падает, если упала
любая нужная задача. Пропущенная по фильтру задача считается успешной. Workflow запускается на
pull request, push в `main`, еженедельное расписание, публикацию релиза и вручную
(`workflow_dispatch`). На pull request идут `quality` и `tests`; `faults` и `e2e` добавляются на
остальных событиях, а `load` (ширина 10 и 20) и `release-quality` — только по расписанию, на
релизе и вручную. Тесты используют изолированный стенд
([process-test-rig](runbooks/process-test-rig.md)).
