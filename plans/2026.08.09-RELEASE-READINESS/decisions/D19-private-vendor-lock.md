---
id: D19
date: 2026-08-09
status: accepted
item: P2.1
items: [P2.1, P2.3, P2.4, P12.2]
supersedes: []
superseded_by: null
---
## Решение

D6 nested clone дополняется tracked lock manifest: normalized repository identity, полный commit
SHA и allowlist Composer package subpaths. `main` допустим только как discovery branch; build/install
отказывает на floating ref, credential-bearing origin, dirty package source или SHA mismatch.
RAG:— (repo-grounded: D6, `composer.json`, local AzGuard inspection and
`research/04-P2-execution-design.md`).

## Почему

Composer path repository с default reference может брать HEAD локального Git checkout. Без отдельного
pin один и тот же Agelto commit не определяет private source, а clean production image/rollback нельзя
воспроизвести.

## Consequences

Обновление AzGuard требует отдельного reviewable изменения manifest + `composer.lock`; clone/sync
script нормализует equivalent SSH/HTTPS identity, но не хранит credentials и не чинит dirty checkout.
