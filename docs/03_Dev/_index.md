---
audience: dev
type: explanation
status: living
---

# Разработка Agelto

Раздел устроен как инженерная книга, а не каталог исходников. В верхнем меню выбран маршрут
«Разработка», в левой колонке — его постоянное оглавление. Начните с первого раздела и затем
переходите к своей задаче; длинные справочники и ADR не нужны для первого чтения.

## Первый день в проекте

1. [Требования и локальный запуск](getting-started.md) — Docker, Make, `.env` и первый здоровый запуск.
2. [Архитектура](architecture.md) — домены Procurement/Reference/Organization/Llm и границы слоёв.
3. Выберите рабочий поток: [краулинг](crawling-pipeline.md), [документы](document-pipeline.md) или
   [нейросети и маршруты моделей](llm-providers.md).
4. Перед изменением запускайте нужные проверки из [тестирования](process/testing.md) и
   [шпаргалки команд](commands.md).

## Карта инженерных тем

| Вопрос | Каноническая глава |
|---|---|
| Как устроена система и данные | [Архитектура](architecture.md), [база данных](database.md), [жизненный цикл сущностей](entity-lifecycle-map.md) |
| Как работает сбор и обработка | [Краулинг](crawling-pipeline.md), [документы](document-pipeline.md) |
| Как работают нейросети | [LLM-провайдеры](llm-providers.md), [извлечение смет](ai/estimate-extraction.md), [промты](ai/prompts.md) |
| Как запустить и проверить код | [Локальный запуск](getting-started.md), [команды](commands.md), [тестирование](process/testing.md) |
| Как готовится production-артефакт | [Docker production](docker/production.md), [модель релизов](process/deploy.md), [руководство оператора](../04_Operators/production/deployment.md) |

## Основа и интерфейсы

- [Архитектура](architecture.md)
- [База данных](database.md)
- [Админ-панель](admin-panel.md)
- [Карта жизненного цикла](entity-lifecycle-map.md)
- [Машины состояний](state-machines.md)
- [OCDS mapping](ocds-mapping.md)
- [Контракт события ProcurementSucceeded v1](contracts/procurement-succeeded-v1.md)
- [Контракт release readiness v1](contracts/release-readiness-v1.md)
- [Контракт admission read budget](contracts/read-budget-admission-v1.md)

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

## Справочник и редкие задачи

- [Команды](commands.md)
- [Рецепты](recipes.md)
- [Troubleshooting](troubleshooting.md)
- [Статический анализ](static-analysis.md)
- [Модель деплоя](process/deploy.md) — инженерное объяснение immutable release; пошаговый production-сценарий находится в [книге оператора](../04_Operators/production/deployment.md).
- [Тестирование](process/testing.md)
- [Runbooks](/03_Dev/runbooks/)

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
