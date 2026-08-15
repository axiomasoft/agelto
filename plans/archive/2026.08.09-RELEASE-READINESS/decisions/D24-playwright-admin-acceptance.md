---
id: D24
date: 2026-08-09
status: accepted
item: P3.4
items: [P3.4, P3.5, P11.4, P11.5, P12.7]
supersedes: []
superseded_by: null
---
## Решение

Admin/Livewire acceptance использует exact `@playwright/test` 1.62.1, один Chromium project,
setup-project login со `storageState` только в ignored output и outer isolated clean-install runner.
Ручной Playwright MCP не считается acceptance evidence; отдельный long-running browser service в P3
не добавляется. RAG:✅ 2026-08-09 npm registry + official Playwright configuration/auth docs;
repo integration analysis — `research/05-P3-execution-design.md`.

## Почему

Playwright Test даёт детерминированные assertions, traces/screenshots и CI reuse, которых нет у
ручного MCP smoke. Dusk потребовал бы второй отсутствующий browser stack, а внешний service image
дублировал бы lifecycle, уже принадлежащий clean-install runner.

## Consequences

План получает Node dev dependency и Chromium install cost; P11 обязан cache/install их по lock, не
переизобретая suite. Auth state считается credential material, никогда не коммитится и очищается
вместе с изолированным run.
