---
paths:
  - "**/database/migrations/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.12.0
Template: rules/migrations.md
Edit-policy: generated-core — НЕ редактируй; расширяй через extension-point
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->

При работе с миграциями (глубина — скилл `php:database`). Risk-слой: **transactions**
(схема/обратимость) — `down()` обязан быть корректным и обратимым.

- Не менять уже применённые миграции — правка структуры идёт НОВОЙ миграцией.
- Имена таблиц через `#[Table]`-атрибут модели; `down()` корректен и обратим.
- Индексы под новые выборки; внешние ключи с явной `onDelete`.
- Новые поля отрази в `$fillable`/`casts` соответствующей модели.
