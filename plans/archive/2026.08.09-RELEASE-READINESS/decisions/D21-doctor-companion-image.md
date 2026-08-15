---
id: D21
date: 2026-08-09
status: accepted
item: P2.6
items: [P2.6, P3.3, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

First-party `laravel/doctor` остаётся `require-dev` и запускается локально либо из same-source
one-off diagnostic image/profile. Production runtime image не содержит dev dependencies; production
Doctor использует deterministic agent/JSON output, `--fail-on=warn` и никогда не `--fix`.
RAG:✅ 2026-08-09 official `laravel/doctor` Packagist/GitHub v0.1.0 docs; image boundary — D17.

## Почему

Перенос Doctor в production requirements упростил бы один command, но расширил runtime каждого PHP
service diagnostic/fix кодом вопреки official `--dev` installation. Companion image сохраняет onsite
production checks и immutable-runtime boundary ценой одного дополнительного pinned artifact.

## Consequences

Build/deploy должен публиковать app и Doctor image из одного source SHA и проверять их labels; P12
pulls/runs/removes Doctor image как tool, не объявляет его long-running production service.
