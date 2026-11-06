---
name: ai-context-workflow
description: "Обновление AI-контекста Agelto: canonical .ai/guidelines, AGENTS/CLAUDE, локальные skills и управляемые Swissknifeman bundles."
---

# AI Context Workflow (Agelto)

Используй при изменении инструкций агентов, локальных skills и их маршрутизации.
Сначала прочитай `.ai/guidelines/navigation.md` и `.ai/guidelines/architecture.md`;
проверь Git-статус и `git check-ignore` целевого файла.

## Источник и владение

- Общие проектные правила живут в `.ai/guidelines/**`. Сначала меняй canonical правило,
  затем синхронизируй соответствующий текст в `CLAUDE.md` и компактном `AGENTS.md`.
- Доменные решения и процесс описываются в `docs/dev/**` / `docs/workflow/**`; AI-контекст
  ссылается на них и сохраняет только нужный для работы маршрут.
- Локальные skills без managed/generated-маркера редактируются в `.claude/skills/**`.
  Перед правкой прочитай заголовок файла: некоторые skills управляются mAInd и прямо
  называют owning template, даже если их путь присутствует в `skills-lock.json` как local.
- Managed skills и agent-pack templates обновляются у владельца через Swissknifeman/mAInd.
  `swissknifeman:managed`, `swissknifeman:generated` и явно указанный owning template
  определяют канал правки; разрешённые extension-points проверяй в самом файле.
- `.agents/plugins/**` и `.agents/.swissknifeman-codex-links.json` — generated bundles/links;
  вручную не редактируются. Настройка plugins/connections — через `ecosystem-setup`.
- Laravel Boost служит MCP-инструментом, не источником проектных guidelines.
  Не восстанавливай retired `GEMINI.md` и не используй отсутствующие legacy-конфиги
  `.swissknifeman/config.json` / `.swissknife.json` как текущий контракт проекта.

## Проверка и передача

1. Сверь имена классов, таблиц, команды и ссылки с текущими файлами репозитория.
2. Синхронизируй только затронутые правила и ссылки; не копируй весь каталог skills
   в другие provider-каталоги вручную.
3. Для проверки используй изолированный `core-*` rig по `testing-rules`.
4. Native Planner владеет активным plan state; `plans/archive/**` и Task Layout v2
   служат историей, их lifecycle-команды не запускаются.

Документация AI-контура: `docs/dev/ai-context/index.md`. Если она расходится с canonical
правилами, исправляй подтверждённый drift от `.ai/guidelines/**`, сохраняя владение generated files.
