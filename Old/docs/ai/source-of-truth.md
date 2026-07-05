# Единый источник истины (AI контекст)

В проекте источник правил/навыков агентов — каталог **`.ai/`** (модульные гайдлайны и скиллы)
и **agent-файлы в корне** (`CLAUDE.md`, `AGENTS.md`, `GEMINI.md`).

> **Laravel Boost — только MCP-сервер.** Раньше Boost (`boost:update`) собирал `CLAUDE.md`/`AGENTS.md`/
> `GEMINI.md` из трёх источников (встроенные гайдлайны Boost + пакетные + кастомные `.ai/`). Это
> убрано: в `boost.json` стоит `"guidelines": false`, `"skills": []`, `"mcp": true`. Boost больше НЕ
> композитор контекста и НЕ подмешивает свои фреймворк-гайдлайны (laravel/livewire/pest/filament/…).

## Что правим руками

- **`CLAUDE.md` / `AGENTS.md` / `GEMINI.md`** (в корне) — теперь это **ведомые вручную** файлы
  контекста (трекаются в git). Их читают агенты напрямую.
- `.ai/guidelines/*.md` — модульные кастомные гайдлайны проекта (источник/референс; на них ссылаются
  сами agent-файлы по путям).
- `.ai/skills/*/SKILL.md` — скиллы (vendor swissknifeman + локальные).

> Композитора (`boost:update`/`composer ai:sync`) больше нет — синхронизировать `.ai/` → agent-файлы
> нужно вручную при значимых правках. Скиллы для Claude Code предпочтительно доставлять нативно в
> `.claude/skills/` (swissknifeman vendor `skiller sync`), тогда Claude грузит их прогрессивно, а не
> текстом внутри `CLAUDE.md`.

## MCP Laravel Boost

Boost остаётся как **MCP-сервер** (database-query/tinker/logs/docs) — подключается через `.mcp.json` /
`.cursor/mcp.json`. Его guidelines и skills отключены (`boost.json`).
