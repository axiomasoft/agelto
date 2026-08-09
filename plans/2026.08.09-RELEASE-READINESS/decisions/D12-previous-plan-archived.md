---
id: D12
date: 2026-08-09
status: accepted
item: ''
items: []
supersedes: [D3]
superseded_by: null
---
## Решение

Supersedes D3 в части отложенного действия: 2026.08.06-CRAWL-HARDEN уже перенесён в
plans/archive, его root-материалы мигрированы в project docs, а новый plan ACTIVE. Paused By
по-прежнему не требуется. RAG:— (repo-grounded: findings/bootstrap-recon-2026-08-09.md).

## Почему

Внешний процесс изменил Git history во время bootstrap-проверки; live-каталог прежнего плана
исчез, archive-каталог существует.

## Consequences

Отдельный plan-close archive больше не является Pending Work нового плана. Исторические
32 read-budget errors относятся к pre-archive preflight и не являются live blocker.
