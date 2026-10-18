---
paths:
  - "**/database/migrations/**"
---
<!-- swissknifeman:generated
Generated-by: harness agent-packs
Pack: backend-laravel@0.12.3
Template: rules/migrations.md
Edit-policy: generated-core — DO NOT edit; expand through extension-point
Extension-points: .claude/rules/agelto-reviewer-overrides.md
Upgrade: harness agent-packs upgrade --pack backend-laravel --target .
-->

When working with migrations (depth - skill `php:database`). Risk-layer: **transactions**
(diagram/reversibility) — `down()` must be correct and reversible.

- Do not change already applied migrations - the structure is edited with a NEW migration.
- Table names via `#[Table]`-model attribute; `down()` is correct and reversible.
- Indexes for new selections; foreign keys with explicit `onDelete`.
- New fields reflected in `$fillable`/`casts` corresponding to the model.
