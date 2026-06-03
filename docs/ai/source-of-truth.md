# Единый источник истины (AI контекст)

В проекте **единый источник истины** для правил и навыков агентов — каталог **`.ai/`** и файл **`boost.json`** в корне репозитория.

## Что правим руками

- `.ai/guidelines/*`
- `.ai/skills/*/SKILL.md`
- `boost.json`
- `skills-lock.json` (если подключаем внешние skills)

После любых правок выполняем:

```bash
php artisan boost:update
```

или алиас:

```bash
composer ai:sync
```

## Что не правим руками

- сгенерированные файлы `AGENTS.md`, `CLAUDE.md` (если используются)
- сгенерированное содержимое `.claude/` и части `.cursor/`, которые обновляются Boost

## MCP Laravel Boost

Для работы агентов включаем MCP сервер Boost через `.cursor/mcp.json`.

