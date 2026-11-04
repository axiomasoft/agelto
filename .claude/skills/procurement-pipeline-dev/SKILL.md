---
name: procurement-pipeline-dev
description: "Разработка acquisition, обработки документов и анализа закупок: границы Process, зависимости и изолированная проверка."
paths:
  - "app/Services/Procurement/**"
  - "app/Services/Platforms/**"
  - "app/Jobs/Procurement/**"
  - "app/Models/Procurement/**"
  - "app/Dto/Procurement/**"
  - "app/Enums/Procurement/**"
  - "app/Repositories/Procurement/**"
  - "database/migrations/**"
---

# Procurement Pipeline — dev-раннбук

Начните с `.ai/guidelines/cheatsheet.md`, `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md` и `docs/dev/process-core.md`.

Acquisition создаёт `listing`, `detail` и `refresh` work; обнаруженные ссылки передаются независимому документному процессу. `DocumentFeeder` ставит `download` и `prepare` intents/work. Process владеет admission, outbox, lease, checkpoint, recovery и финализацией; очередь доставляет `ExecuteWorkJob`. Статус документа — проекция, а не разрешение выполнять работу. `screening` и `extraction` используют зарегистрированные Process handlers (`ScreeningHandler`, `ExtractionHandler`), versioned recipes и `AnalysisProviderSendGate`. Неподдерживаемые форматы не считать успешно обработанными.

Площадочная реализация живёт в `app/Services/Platforms/<Code>/`, общий контракт не должен содержать площадочных веток. Фильтры типизированы и разрешаются реестром. Исходящие запросы используют общий network owner (`OutboundExecutor`, `ScheduledAttemptGate`) и `ProxyResolver`; `SourceTlsTrust` — единственный владелец TLS trust, `verify => false` запрещён.

При изменении стадии проследите её DTO/контракт и recipe, schema и миграции, repository (`SubjectLockResolver` канонический порядок блокировок!), handler/registry, controls и operator UI, а также workflow/dev docs. Контроллер остаётся HTTP-слоем; длительное исполнение и AI не запускаются синхронно из HTTP.

Проверяйте изменения в изолированном стенде `docs/dev/runbooks/process-test-rig.md`: используйте `make core-test-*` или `make core-test-file TEST_FILE=...`. Не направляйте reset или тесты на dev/shared database.
