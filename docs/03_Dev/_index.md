---
audience: dev
type: explanation
status: living
---

# 03 · Dev

Технический раздел для разработчиков и AI-агентов.

## Порядок чтения для новичка

1. [Требования и локальный запуск](getting-started.md) — docker/make/yarn/.env, ключ Gemini.
2. [Архитектура](architecture.md) — домены Procurement/Reference/Organization/Llm, раскладка `app/**`.
3. Пайплайн своей задачи — [document-pipeline.md](document-pipeline.md),
   [llm-providers.md](llm-providers.md) или [crawling-pipeline.md](crawling-pipeline.md).

## Основа и интерфейсы

- [Архитектура](architecture.md)
- [База данных](database.md)
- [Админ-панель](admin-panel.md)
- [Карта жизненного цикла](entity-lifecycle-map.md)
- [Машины состояний](state-machines.md)
- [OCDS mapping](ocds-mapping.md)
- [Контракт события ProcurementSucceeded v1](contracts/procurement-succeeded-v1.md)

## Пайплайны

- [Краулинг](crawling-pipeline.md)
- [Документы](document-pipeline.md)
- [LLM-провайдеры](llm-providers.md)
- [Контракт извлечения смет](ai/estimate-extraction.md)
- [Открытые технические вопросы](open-follow-ups.md)

## AI и контекст агента

- [Промты](ai/prompts.md)
- [Промт-инженерия](ai/prompt-engineering.md)
- [Экономика AI](ai/economics.md)
- [Feature card: screening](ai/feature-card-screening.md)
- [Feature card: estimate extraction](ai/feature-card-estimate-extraction.md)
- [Обучающий контур](ai/training-loop.md)
- [Локальное LLM-обучение](ai/local-llm-training.md)

## Среда и ежедневная работа

- [AI-контекст](ai-context/index.md)
- [Сценарии AI-агента](ai-context/scenarios.md)
- [Агенты и workflow](ai-context/subagents-and-workflows.md)
- [Docker: обзор](docker/index.md)
- [Docker: development](docker/development.md)
- [Docker: production](docker/production.md)
- [Docker troubleshooting](docker/troubleshooting.md)

## Практика разработки

- [Команды](commands.md)
- [Рецепты](recipes.md)
- [Troubleshooting](troubleshooting.md)
- [Статический анализ](static-analysis.md)
- [Деплой](process/deploy.md)
- [Тестирование](process/testing.md)
- [Runbooks](runbooks/_index.md)

## Архитектурные решения 0001–0005

- [0001: prc megadomain](adr/0001-prc-megadomain.md)
- [0002: Prism transport facade](adr/0002-prism-transport-facade.md)
- [0003: row range + fallback](adr/0003-row-range-plus-windowed-fallback.md)
- [0004: JSONB for non-estimate results](adr/0004-jsonb-for-non-estimate-results.md)
- [0005: protected prompts](adr/0005-protected-prompts.md)

## Архитектурные решения 0006–0010

- [0006: bounded contexts](adr/0006-bounded-contexts-as-folders.md)
- [0007: OCDS exchange layer](adr/0007-ocds-as-exchange-layer.md)
- [0008: thin provenance slice](adr/0008-thin-provenance-slice.md)
- [0009: job owns document transition](adr/0009-job-owns-document-transition.md)
- [0010: input representation](adr/0010-input-representation.md)
