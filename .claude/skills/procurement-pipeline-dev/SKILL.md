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

Начните с `docs/workflow/crawling-and-discovery.md`, `docs/workflow/document-and-estimate-pipeline.md` и актуального среза `docs/dev/process-core.md`. Исторические описания отдельных срезов не являются доказательством состояния следующих срезов.

Acquisition создаёт listing, detail и refresh work; обнаруженные ссылки передаются независимому документному процессу. `DocumentFeeder` создаёт работу скачивания и подготовки. Process владеет admission, outbox, lease, checkpoint, recovery и финализацией; очередь доставляет `ExecuteWorkJob`. Статус документа — проекция, а не разрешение выполнять работу. Screening, extraction и publication используют зарегистрированные Process handlers и versioned recipes; проверяйте точную активацию и ограничения в текущем коде и `docs/dev/process-core.md`. Неподдерживаемые форматы не считать успешно обработанными.

Площадочная реализация живёт в `app/Services/Platforms/<Code>/`, общий контракт не должен содержать площадочных веток. Фильтры типизированы и разрешаются реестром. Исходящие запросы используют общий network owner и `ProxyResolver`; `SourceTlsTrust` — единственный владелец TLS trust, `verify => false` запрещён.

При изменении стадии проследите её DTO/контракт и recipe, schema и миграции, repository, handler/registry, controls и operator UI, а также workflow/dev docs. Для extraction, LLM, prompts, тестов и миграций применяйте соответствующий проектный skill. Контроллер остаётся HTTP-слоем; длительное исполнение и AI не запускаются синхронно из HTTP.

Проверяйте изменение в изолированном стенде из `docs/dev/runbooks/process-test-rig.md`: используйте подходящий `make core-test-*` или `make core-test-file TEST_FILE=...` и выполняйте preflight, описанный в runbook. Не направляйте reset или тесты на dev/shared database. Single-file proof не заменяет acceptance; явно сообщайте выполненные проверки и оставшиеся ограничения.
