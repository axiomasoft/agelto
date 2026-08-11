---
id: D74
date: 2026-08-11
status: accepted
item: P5.2
items: [P5.2]
supersedes: []
superseded_by: null
---
## Решение

По прямой директиве владельца schema migration P5.2 получает имя
`2026_08_09_099999_create_prc_settings_table.php`: Laravel запускает её перед package settings
migration `2026_08_09_100000_create_crawler_proxy_settings.php`. Семантика D28 не меняется:
таблица остаётся `prc_settings`, payload — только nullable Proxy ID. RAG:— (repo-grounded:
`docker/tests/p5.2-settings-contract.sh`, clean `agelto_test` reproduction 2026-08-11).

## Почему

Spatie registers settings migrations in the same Laravel migrator; the prior file order ran the
settings migration before its table and deterministically failed. Changing only the schema filename
is local, additive and reversible before any production migration is deployed.

## Alternatives

1. Create the table inside the settings migration. Rejected: duplicates schema ownership.
2. Keep timestamp `100086`. Rejected: clean test database cannot migrate.
3. Give the schema migration an earlier timestamp. Selected.

## Consequences

P5.2 Files and the acceptance carrier use `099999`; the previous escalation is historical journal
provenance. P5.2 resumes as attempt 2 and must still pass the unchanged storage/freshness contract.
