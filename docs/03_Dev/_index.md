---
audience: dev
type: explanation
status: living
---

# 03 · Dev

Технический раздел для разработчиков и AI-агентов.

## Порядок чтения для новичка

1. [Требования и локальный запуск](getting-started.md) — docker/make/yarn/.env, ключ Gemini.
2. [Архитектура](architecture.md) — домены Procurement/Reference/Organization, раскладка `app/**`.
3. Пайплайн своей задачи — [document-pipeline.md](document-pipeline.md),
   [llm-providers.md](llm-providers.md) или [crawling-pipeline.md](crawling-pipeline.md).

## Разделы

- [architecture.md](architecture.md) · [database.md](database.md) — устройство системы.
- [crawling-pipeline.md](crawling-pipeline.md) · [document-pipeline.md](document-pipeline.md) ·
  [llm-providers.md](llm-providers.md) — internals пайплайнов.
- [admin-panel.md](admin-panel.md) — Filament для разработчика.
- [ai/](ai/) — промт-система, feature-карточки AI-шагов, обучающий контур.
- [ai-context/](ai-context/) — устройство `.ai/`-контура и генерация CLAUDE.md/AGENTS.md.
- [docker/](docker/) — стек контейнеров, dev/prod, troubleshooting.
- [runbooks/](runbooks/) — что делать при конкретном сбое.
- [adr/](adr/) — ключевые архитектурные решения (MADR).
- [process/](process/) — деплой, тестирование.
- [commands.md](commands.md) · [recipes.md](recipes.md) · [troubleshooting.md](troubleshooting.md).

Рабочие ТЗ v2 (спеки, живут до конца плана EST-AI) — `docs/specs/v2/`, не дублируются здесь.
