---
paths:
  - "tests/**"
---

При работе с тестами (глубина — скиллы `php:laravel-testing`, `php:test-isolation-guard`):

- Прогон только через Docker test-gate: `docker compose exec app php artisan test --compact`
  (PHP 8.4, PostgreSQL 17) — не голым `php artisan test` на хосте.
- Pest (pestphp/pest) — `test('<интент по-русски>', fn)`, без `it()` (канон `.ai/guidelines/glossary.md`).
- Изоляция: `RefreshDatabase` + тестовая БД `agelto_test` (Postgres) + фабрики;
  гейт — `Tests\Support\TestEnvironmentGuard` (`str_ends_with($database, '_test')`), см. `plans/V2-CORE/phases/P0.md` P0.3.
- На изменённое поведение — тест; существующие проверки не ослаблять.
