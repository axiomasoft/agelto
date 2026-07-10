---
paths:
  - "**/tests/**"
  - "**/database/factories/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.12.0
Template: rules/tests.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->

При работе с тестами (глубина — скиллы `php:laravel-testing`, `php:test-isolation-guard`):

- Прогон только `docker compose exec app php artisan test` (хост-специфика проекта), не bare `php`.
- Тест-фреймворк проекта — `pest`: прочитай 1–2 существующих теста и зеркаль их стиль (PHPUnit — классы `extends TestCase`; Pest — `it()/test()`-функции).
- Изоляция: `RefreshDatabase` + тестовая БД `agelto_test` + существующие фабрики.
- На изменённое поведение — тест; существующие проверки не ослаблять.
- **Честность тестов:** любая обходка/форс-конфиг ради зелёного (форс `config(...)`, штамповка
  `timestamp` перед пагинацией, sibling-класс в обход `final`) — пометь `[POSSIBLE-DEFECT]` в отчёте:
  за обходкой почти всегда реальный баг (на chatom >60% попаданий).
- `ShouldQueue`-job: если тест зовёт `handle()` напрямую — отметь «queue-семантика (release/backoff/
  конкурентные воркеры) не покрыта», чтобы ревьюер проверил её отдельно.
