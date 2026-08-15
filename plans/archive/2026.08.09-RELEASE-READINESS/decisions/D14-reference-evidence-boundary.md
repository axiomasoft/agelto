---
id: D14
date: 2026-08-09
status: accepted
item: P0.2
items: [P0.2]
supersedes: []
superseded_by: null
---
## Решение

Sova, Vintera/CMS и Dterema остаются read-only external working trees. P0.2 не вендорит их файлы в plan bundle, а записывает абсолютный path, HEAD, digest/count `git status --porcelain=v1` и SHA-256 каждого прочитанного файла. RAG:— (repo-grounded: `findings/P0-reference-input-map-2026-08-09.md`).

## Почему

Все три reference repositories dirty на authoring snapshot; HEAD не идентифицирует фактически прочитанные bytes, а bundle v1 запрещает inputs вне plan/repo root.

## Consequences

Сравнение воспроизводимо по snapshot manifest, но требует той же host topology; любой drift во время P0.2 делает соответствующий evidence row непригодным, а не «достаточно похожим».
