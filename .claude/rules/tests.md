---
paths:
  - "**/tests/**"
  - "**/database/factories/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.12.3
Template: rules/tests.md
Edit-policy: generated-core — DO NOT edit; expand through extension-point
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->

When working with tests (depth - skills `php:laravel-testing`, `php:test-isolation-guard`):

- Run only `docker compose exec app php artisan test` (project host specifics), not bare `php`.
- Project test framework - `pest`: read 1-2 existing tests and mirror their style (PHPUnit — classes `extends TestCase`; Pest — `it()/test()`-functions).
- Insulation: `RefreshDatabase` + test database `agelto_test` + existing factories.
- For changed behavior - test; existing checks should not be weakened.
- **Test fairness:** any workaround/force config for green (force `config(...)`, stamping
  `timestamp` before pagination, sibling-class bypass `final`) — mark `[POSSIBLE-DEFECT]` in the report:
  behind the workaround is almost always a real bug (on chatom >60% hits).
- `ShouldQueue`-job: if the test calls `handle()` directly - check «queue-semantics (release/backoff/
  competitive workers) not covered», so that the reviewer checks it separately.
