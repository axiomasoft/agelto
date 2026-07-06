<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.11.2
Template: rules/reviewer-overrides.md
Edit-policy: user-owned — файл ваш, пак его не перезапишет
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->
# Оверрайды ревью — agelto

Сюда — проектные оси ревью; пак этот файл не перезаписывает.

## Тесты (дополняет `.claude/rules/tests.md`)

- Только `test('<интент по-русски>', fn)` — без `it()` (весь текущий сюит уже на `test()`, канон
  зафиксирован в `.ai/guidelines/glossary.md`).
- Изоляция от прод/dev БД и media — гейт `Tests\Support\TestEnvironmentGuard`
  (`tests/Support/TestEnvironmentGuard.php`, покрыт `tests/Feature/Support/TestEnvironmentGuardTest.php`);
  падение теста на «не та БД» — сначала проверь этот гейт, не патчи симптом.
