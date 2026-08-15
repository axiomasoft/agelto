---
id: D27
date: 2026-08-09
status: accepted
item: P4.3
items: [P4.2, P4.3, P4.4, P4.5, P12.4, P12.7]
supersedes: []
superseded_by: null
---
## Решение

HTML и каждый asset выдаются только одним Laravel wildcard controller после enum-aware AzGuard
`DocumentationPermission::View` на panel `admin`; SuperAdmin wildcard — единственный initial role
path. Locator допускает только manifest entries с known-good path grammar, `realpath` containment и
closed MIME map. Все успешные ответы несут `Cache-Control: private, no-store`, `nosniff` и единый
browser-security policy; public/Nginx/signed-url/X-Accel alternate route запрещён.
RAG:✅ 2026-08-09 Laravel 13 responses/middleware/authorization, RFC 9111, OWASP Path Traversal and
HTTP Headers; repo alternatives — `research/06-P4-execution-design.md`.

## Почему

Panel access пропускает любого пользователя с ролью и слишком груб для будущей матрицы, а отдельная
asset-ветка повторяет дефект Vintera. `private` без `no-store` разрешает private cache сохранять bytes;
substring-filter `..` не закрывает encoded/backslash/symlink traversal.

## Consequences

Первый release платит PHP request на каждый docs asset и не получает long-cache hashed bundles.
Filament navigation остаётся только discovery UI: direct URL всегда повторно проходит тот же permission.
Поздняя оптимизация serving требует отдельного security proof и superseding D-record.
