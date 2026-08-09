---
id: D28
date: 2026-08-09
status: accepted
item: P5.2
items: [P5.1, P5.2, P5.3, P5.4, P5.5, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Optional crawler/parser default хранится через `spatie/laravel-settings ^3.9` в dedicated database
repository `prc_settings` как typed nullable `CrawlerProxySettings::defaultProxyId`. Значение — только
ID существующего `prc_proxies`; raw URL/username/password в settings запрещены. Read-side carrier
refreshes settings перед resolve, а production cache допускается только с cross-process freshness test.
RAG:✅ 2026-08-09 official package releases, composer manifest and README; repo alternatives —
`research/07-P5-execution-design.md`.

## Почему

Env carrier не редактируется из Filament и дублирует секреты, bespoke singleton повторяет typed
migrations/cache/refresh. Spatie 3.9.0 официально поддерживает Illuminate 13/PHP 8.2+, typed DI,
database repository, migrations, cache и refresh; Agelto уже хранит identity, scopes, credentials и
health в `prc_proxies`.

## Consequences

План принимает одну production dependency и generic key/value schema внутри domain-prefixed table.
Referential integrity default ID проверяется Action/Resolver, а не FK payload. P5.1 обязан сверить
terminal P0.5/P0.6; несовместимый upstream verdict требует superseding D до `composer require`.
