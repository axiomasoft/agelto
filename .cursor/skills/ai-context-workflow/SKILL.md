---
name: ai-context-workflow
description: "Редактирование AI-контекста проекта: agent-файлы CLAUDE.md/AGENTS.md/GEMINI.md (ведём вручную) + модульные .ai/guidelines + скиллы в .claude/skills (зеркало .cursor/skills). Laravel Boost — только MCP, НЕ композитор и НЕ источник guidelines/skills. Объяснение цикла для Cursor и Claude Code."
---

# AI Context Workflow (Agelto)

## Когда активировать

- Нужно добавить/переименовать/изменить skill или guideline проекта.
- Пользователь правит контекст агентов или спрашивает про дубли `.cursor/skills/` vs `.claude/skills/`.
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
| `.claude/skills/*/SKILL.md` (vendor swissknifeman + локальные доменные) | — |

> Автокомпозиции больше нет: при значимых правках `.ai/guidelines/*.md` синхронизируй вручную в
> agent-файлы. Скиллы доставляются нативно в **`.claude/skills/`** (Claude грузит их прогрессивно,
> а не текстом в `CLAUDE.md`) и зеркалятся в `.cursor/skills/` для Cursor.

## Скиллы: канал и раскладка

- **Источник набора** — `.swissknifeman/config.json` (`mode:vendor`, `skills_path:.claude/skills`,
  `buckets`/`exclude`). Применение: `skiller sync` → физ. копии библиотечных скиллов в `.claude/skills/`
  + `skills-lock.json` (managed). Зеркало для Cursor: `cp -r .claude/skills/. .cursor/skills/`.
- **Библиотечные** (managed) обновляются из реестра swissknifeman через `skiller sync` — руками не
  правим (перетрутся). Нужна правка под проект → подними в библиотеку (`field-feedback-loop`) либо
  держи как локальный скилл.
- **Локальные доменные** (НЕ managed) — `ai-context-workflow`, `project-map`, `node-yarn-preflight`,
  `pest-testing`, `testing-rules`: живут в `.claude/skills/`, `sync` их не трогает; правим в источнике.

> **Managed-скиллы вне git** (`gitignore_managed: true` в конфиге). `skiller sync` сам генерит
> `.claude/skills/.gitignore` (+ зеркало в `.cursor/skills/.gitignore`) со списком managed-скиллов —
> они НЕ коммитятся (воспроизводимы), в репозитории лежат только локальные. **Восстановление после
> клона:** `skiller sync` (нужен доступ к реестру swissknifeman). Список в `.gitignore`
> сопровождается автоматически — руками не ведём, корневой `.gitignore` не трогаем.

## Порядок

1. Правка в agent-файле, `.ai/guidelines/` и/или локальном скилле `.claude/skills/`.
2. Набор библиотечных скиллов меняется в `.swissknifeman/config.json` → `skiller sync` → зеркало в `.cursor/skills/`.
3. После клона/смены набора managed-скиллов: `skiller sync` (подтянет managed в рабочее дерево; в git их нет).
4. Коммит: agent-файлы + `.ai/guidelines/*` + **локальные** `.claude/skills/*` + `.cursor/skills/*` (managed gitignored) + `.swissknifeman/config.json` + `skills-lock.json` + `.claude/skills/.gitignore`. `boost.json` — локальный (в `.gitignore`).

## Дублирование

- Кастомные доменные скиллы проекта живут в **`.claude/skills/`**; для Cursor зеркалятся в `.cursor/skills/`.
- `.cursor/skills/` — копии из `.claude/skills/`; доменные скиллы Agelto правим в источнике (`.claude/skills/`), не в копиях.

## Ссылка для людей

Полный разбор: `docs/ai/source-of-truth.md`, портал VitePress `/ai`.
