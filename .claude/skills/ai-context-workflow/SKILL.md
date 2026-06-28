---
name: ai-context-workflow
description: "Редактирование AI-контекста проекта: agent-файлы CLAUDE.md/AGENTS.md/GEMINI.md (ведём вручную) + модульные .ai/guidelines, .ai/skills. Laravel Boost — только MCP, НЕ композитор и НЕ источник guidelines/skills. Объяснение цикла для Cursor и Claude Code."
---

# AI Context Workflow (Agelto)

## Когда активировать

- Нужно добавить/переименовать/изменить skill или guideline проекта.
- Пользователь правит контекст агентов или спрашивает про дубли `.cursor/skills/` vs `.ai/skills/`.
- Задача — описать другому разработчику, как безопасно обновлять правила для ИИ.

Смена архитектурных договорённостей в коде должна сопровождаться правками в **`docs/dev/*`** (и
workflow-доках) и в AI-контексте, иначе агенты разъедутся с репозиторием. См. **`.ai/guidelines/architecture.md`**.

## Laravel Boost — только MCP

Boost подключён **как MCP-сервер** (`boost.json: mcp:true`, `guidelines:false`, `skills:[]`) и больше
**НЕ** собирает agent-файлы и **НЕ** подмешивает свои фреймворк-гайдлайны. Композитора (`boost:update`/
`composer ai:sync`) нет.

## Единый источник правды

| Что правим руками | Что НЕ правим |
|---|---|
| `CLAUDE.md` / `AGENTS.md` / `GEMINI.md` (корень, трекаются — читают агенты) | — |
| `.ai/guidelines/*.md` (модульные кастомные гайдлайны, источник/референс) | встроенные гайдлайны Boost (отключены) |
| `.ai/skills/*/SKILL.md` (vendor swissknifeman + локальные) | — |

> Автокомпозиции больше нет: при значимых правках `.ai/guidelines/*.md` синхронизируй вручную в
> agent-файлы. Скиллы для Claude Code предпочтительно доставлять нативно в `.claude/skills/`
> (swissknifeman vendor `skiller sync`) — Claude грузит их прогрессивно, а не текстом в `CLAUDE.md`.

## Порядок

1. Правка в agent-файле и/или `.ai/`.
2. Коммит: agent-файлы + `.ai/*`. `boost.json`/`skills-lock.json` — локальные (в `.gitignore`).

## Дублирование

- Кастомные скиллы проекта живут в **`.ai/skills/`** (или `.claude/skills/` для нативной загрузки Claude).
- `.cursor/skills/` может содержать копии из экосистемы агента; доменные скиллы Agelto правим в источнике, не в копиях.

## Ссылка для людей

Полный разбор: `docs/ai/source-of-truth.md`, портал VitePress `/ai`.
