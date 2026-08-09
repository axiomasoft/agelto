---
id: D35
date: 2026-08-09
status: accepted
item: P8.2
items: [P8.1, P8.2, P8.5, P9.3, P10.2, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Runtime extension площадки регистрируется typed `PlatformAdapterDefinition`, keyed точным
`Platform.code`. Definition несёт поддержанные source-method implementations и nested registry
filter classes; `PlatformAdapterRegistry` является единственным reader
`config('crawler.platforms')`. `SourceMethodFactory`, `CrawlerFilterFactory` и `CrawlerForm`
потребляют этот registry и fail-closed на отсутствующей platform/registry capability. DB не хранит
FQCN; EIS behavior остаётся в `Services/Procurement/Eis/**`. RAG:— (repo-grounded:
`findings/P8-platform-status-extension-recon-2026-08-09.md` и
`research/10-P8-execution-design.md`).

## Почему

Схема разрешает одинаковый `Registry.code` на разных площадках, а текущая filter map индексируется
только этим кодом; global `SourceMethod::Csv` одновременно ведёт к EIS-specific implementation.
Typed platform definition выравнивает runtime identity с DB, не превращая БД в class registry и не
изобретая универсальный adapter API до появления второй реализации.

## Consequences

Data-only Platform/Registry rows остаются допустимы, но crawlable capability требует definition.
Legacy `crawler.source_methods`/`crawler.filters` удаляются только после миграции всех consumers.
P8.1 обязан сверить terminal P0.5/P0.6/P7.6; contradiction оформляется новой superseding D35, а не
локальным fallback.

