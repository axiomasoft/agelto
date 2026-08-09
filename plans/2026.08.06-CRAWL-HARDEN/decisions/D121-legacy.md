---
id: D121
date: 2026-08-08
status: accepted
item: ''
items: []
supersedes: []
superseded_by: null
---
## Решение

**Follow-up P4.4 получает один типизированный control-flow контракт: `ArchiveWindowScanner::scan()` возвращает `ArchiveWindowScanResult::{Drained,Checkpointed,Stopped}`; только `Drained` разрешает archive/delta/backlog продвинуть `cursor.day`.** `PlatformBudgetExhaustedException` ловится в сканере, превращается в `recordCheckpoint()` + `Checkpointed` без `Failed`; archive продолжает существующий `CrawlRunJob`, delta — следующий cron, backlog — следующий 15-минутный sweep без `backlog_rescanned_at`. D119 применяется и к acceptance P4.9: `Http::preventStrayRequests()` + зелёный Pest заменяют literal URL-grep. Отвергнуты catch в каждой обёртке (три носителя одной семантики), exception как нормальный carrier (конфликт с широким failure-catch) и `bool` (не различает checkpoint/stop).

## Почему

`scan(): void` делает дренаж и прерывание неразличимыми, а три обёртки двигают день после любого возврата; budget exception сейчас попадает в failure-path. Синтез и проверяемые сценарии — `research/14-p4.9-budget-checkpoint-design.md`; сырая фактура — `findings/P4.9-budget-checkpoint-control-flow-2026-08-08.md`. `RAG:— (repo-grounded: app/Services/Procurement/ArchiveWindowScanner.php; app/Services/Procurement/Modes/ArchiveRunProcessor.php; app/Services/Procurement/Modes/DeltaScanProcessor.php; app/Services/Procurement/Modes/BacklogRescanProcessor.php; tests/TestCase.php)`
