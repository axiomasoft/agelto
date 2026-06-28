---
paths:
  - "tests/**"
---

При работе с тестами (глубина — скиллы `php:laravel-testing`, `php:test-isolation-guard`):

- Прогон `php artisan test` (php 8.5, MySQL).
- Pest (pestphp/pest) — зеркаль стиль существующих тестов проекта.
- Изоляция: `RefreshDatabase` + тестовая БД `agelto_testing` (MySQL) + фабрики;
  правила БД-безопасности — local-скилл `testing-rules`.
- На изменённое поведение — тест; существующие проверки не ослаблять.
