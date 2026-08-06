# Отчёт H — структура документации (research июль 2026 + канонический каркас)

## Выжимка research (Diátaxis, internal docs, операторские мануалы, LLM-доки)

1. **Diátaxis для внутренних продуктов**: модель намерений (learn/do/look up/understand), НЕ обязательные 4 папки. Канон: верхний уровень — по АУДИТОРИЯМ, внутри — типы Diátaxis без фанатизма. Общие reference/explanation (глоссарий, архитектура) — shared, линкуются, не дублируются. Правило «один тип на страницу». Ошибки: плоские гигантские бакеты; «один документ на все аудитории»; README-of-doom; отсутствие landing-роутеров (списки ≤7 ссылок с группировкой).
2. **Internal engineering docs**: канонический набор — architecture (C4-стиль), ADR (MADR: Context/Decision/Status/Consequences, один файл = одно решение, история не переписывается), runbooks (Symptoms→Triage→Remediation→Rollback→Escalation, один сбой = один файл), process docs, onboarding («первая неделя», ссылками). Для пайплайнов — документ на пайплайн: инварианты, producers/consumers, очереди, failure modes с линком на runbook. Docs-as-code: markdown в git, doc-обновление в Definition of Done, линк-чекер. **Mermaid inline по умолчанию** (диффится, GitHub+Obsidian рендерят); отдельные файлы только для больших карт (>2 использований).
3. **Операторские мануалы**: писать ПО ЗАДАЧАМ (task-based SOP), навигировать ПО РОЛЯМ (landing-страница роли → её SOP + матрица прав). Формат SOP: Назначение → Предусловия/права → Шаги → Исключения («что делать, если завис») → Ожидаемый результат → Ссылки + last-reviewed. **Текст-first, скриншоты минимально** (редизайн инвалидирует картинки; текст ищется поиском; ссылаться на стабильные UI-лейблы). Актуальность процессом: пункт в PR-чеклист «SOP обновлён при изменении Filament-ресурса?».
4. **LLM-доки**: промты — versioned-артефакты в файлах с why-аннотациями к правкам («ужесточили — модель пропускала позиции»); git = source of truth (registry типа Langfuse — опциональная эскалация); composite versioning: поведение = промт + модель + схема (логировать связку — уже канон prompt_version_set/rules_hash); eval-результаты как тест-план (набор, метрики-пороги, вердикт на версию); **feature card на AI-шаг** (не на модель): что делает, модель+промты+схема, why-решения, failure modes, как оценивается.

Источники: diataxis.fr (complex hierarchies), mintlify/fern (IA), koliber/codelit/docsio (eng docs, ADR), writethedocs/GitLab handbook/Google tech writing (SOP, текст-first), langfuse + LLMOps-гайды 2025-26 (prompt versioning, feature cards).

## КАНОНИЧЕСКИЙ КАРКАС docs/ (SSOT для P10; решение D19)

Верхний уровень — нумерованные категории Brain-канона (vault ждёт `docs/01_Business/_index` и т.д. — см. plans/rustling-jingling-quiche.md, раздел Context п.4; имена файлов 02_Workflow/03_Dev — те, на которые УЖЕ ссылается CLAUDE.md reference table). Судьба docs/specs/v2 — D23 plan.md: живёт до конца EST-AI, роспуск отложен. Внутрь вложен audience-first research-скелет. `docs/` зеркалится в Obsidian-vault, `docs-private/` — нет.

```
docs/
  index.md                    # Стартовый роутер: кто ты (разработчик / оператор / владелец) → куда идти; ≤7 ссылок на блок
  00_Ideas/                   # Vault-канон: черновики владельца. План НЕ наполняет, только _index.md-заглушка
  01_Business/                # Аудитория: владелец/менеджмент
    _index.md                 # Роутер раздела
    overview.md               # Что за продукт, ценность, аудитории, границы (explanation)
    roadmap.md                # Выжимка 06-roadmap: фазы, что сделано/дальше (без рисков — они в docs-private)
  02_Workflow/                # Аудитория: shared; доменные процессы бизнес-языком
    _index.md
    glossary.md               # Канон имён и терминов (Platform, Crawler, Procurement, Document, Estimate…) — единственный глоссарий
    crawling-and-discovery.md # Стадии обхода источников (существующий канон-имя из CLAUDE.md)
    document-and-estimate-pipeline.md  # Путь документа/сметы бизнес-языком + упрощённая FSM-диаграмма (Д1-lite)
  03_Dev/                     # Аудитория: разработчики и AI-агенты
    _index.md                 # Роутер: «начни отсюда», карта раздела
    onboarding.md             # Первая неделя: docker/make/yarn, тесты (agelto_test), day-2 таск — ссылками, без дублей
    architecture.md           # Домены Procurement/Reference/Organization, раскладка app/**, слои (имя из CLAUDE.md)
    database.md               # Схема prc_/ref_/org_, ключевые связи, unique-инварианты, партиционирование (имя из CLAUDE.md)
    crawling-pipeline.md      # Internals краулинга (имя из CLAUDE.md; наполнен ранее/вне плана — только линк-ревизия)
    document-pipeline.md      # Internals: мост → подготовка → скрининг → извлечение; jobs/очереди/lease/retry; диаграммы Д1 (FSM), Д2 (стадии), Д4 (extraction-сессия); failure modes → линки на runbooks
    llm-providers.md          # LLM-слой: контракт LlmProvider, адаптеры, роутинг ai_routes, capability-кэширование; диаграмма Д6
    admin-panel.md            # Filament для разработчика: ресурсы, где что лежит (имя из CLAUDE.md)
    ai/
      prompts.md              # Промт-система: файлы→сид→is_protected, композиция (диаграмма Д3), composite versioning (prompt+model+schema), как писать промты
      feature-card-screening.md          # Карточка AI-шага: модель, промт-версии, схема, why-решения, ограничения, eval-результаты
      feature-card-estimate-extraction.md# То же для извлечения смет (+ ссылка на Д4, stitcher, арифметическая валидация)
      training-loop.md        # Обучающий контур (диаграмма Д5): review→correction→re-run→gold→export; публичная часть
    runbooks/
      _index.md               # Каталог сбоев
      stuck-analysis.md       # Анализ завис (lease/статусы) — Symptoms→Triage→Fix→Rollback
      gemini-errors.md        # Квоты/429/региональные блокировки/прокси
      horizon-backlog.md      # Растёт очередь: диагностика supervisor'ов, drain/replay
    adr/
      0001-prc-megadomain.md  # MADR-перенос ключевых D-решений EST-AI: таксономия prc_, Prism за фасадом, row-range+fallback, protected-промты, jsonb для не-смет (по файлу на решение)
    process/
      deploy.md               # Релиз: make prod:*, volumes, миграции — ссылками на Makefile
      testing.md              # Как гонять тесты, изоляция agelto_test — ссылками на skill/rules, без копий
  04_Operators/               # Аудитория: операторы/менеджеры админки
    _index.md                 # Роутер по ролям: оператор / менеджер → их SOP; мини-матрица прав
    reference/
      statuses.md             # Каждый статус/колонка админки не-инженерным языком (DocumentStatus, validation_status, review_status)
    how-to/                   # Task-based SOP: текст-first, стабильные UI-лейблы, last-reviewed дата
      check-ai-analysis.md    # Проверить результат AI-разбора документа
      review-estimate.md      # Ревью сметы: галочка «проверено» / коррекция «что не так» / перезапуск (петля P9.2)
      restart-failed-run.md   # Диагностика и перезапуск упавшей обработки
      manage-prompts.md       # Корректирующие промты: когда создавать, почему канон read-only
docs-private/                 # НЕ зеркалится: экономика (реальные цены/лимиты/расход), глубокая промт-инженерия, local-llm-training.md (P9.4), риски/gotchas
```

Диаграммы (нумерация для P10): Д1 stateDiagram FSM документа; Д2 flowchart стадий+очередей; Д3 sequenceDiagram композиции промта (с границей кэшируемого префикса); Д4 sequenceDiagram extraction-сессии (+fallback-окно, re-run); Д5 flowchart обучающего контура; Д6 flowchart LLM-слоя/адаптеров.

Сквозные правила: (1) frontmatter `audience:` (dev/operator/owner/shared) и `type:` (howto/reference/explanation/runbook/adr) в каждом файле; (2) mermaid inline, отдельные файлы только для больших карт; (3) last-reviewed в SOP; (4) в PR-чеклист (source: `.ai/guidelines/`, не CLAUDE.md напрямую): «docs обновлены?», для Filament-правок — «операторский SOP обновлён?»; (5) один канонический документ + ссылки, никаких копий текста между разделами; (6) docs/specs/ остаётся рабочими ТЗ (не справочник), 08-estimate-extraction.md — источник для транскрипции в 03_Dev, не дубль.

Примечание к конфликтам: план rustling-jingling-quiche (черновик) владеет восстановлением категорий и актуализацией .ai/guidelines — если он исполнится раньше, P10 наполняет уже созданные категории; если нет — P10.1 создаёт каркас сам по этой схеме (совместимо).

## Дополнение (2026-07-07, вторая правка владельца): единая дока + референс SOVA

Решение владельца (D20): документация ЕДИНАЯ для менеджеров и разработчиков в одном `docs/` (идея «две папки: обычная + приватная для разработчиков» отменена). Основная часть — менеджерская (процессы: краулинг → первичный анализ → извлечение, условия стадий, как готовятся/устанавливаются промты и требования к ним); 03_Dev — чисто технический раздел (старт проекта, Docker-команды, где получить доступ/ключ к LLM, тех.детали). `docs-private/` сокращается до действительно чувствительного (реальный расход/лимиты аккаунта, риски, глубокая промт-инженерия) — всё остальное из прежних docs-private-намерений живёт в общем `docs/`.

Референс — `~/sibintek/projects/sova/docs` (владелец считает образцом). Перенимаемые паттерны:
1. **index.md — таблица «Для кого что читать»** (роль → цепочка стартовых страниц) + таблица «Быстрые ссылки» (задача → страница). Компактнее и polезнее прозы.
2. **dev/getting-started.md** — требования и локальный запуск одним файлом (не размазывать по onboarding).
3. **dev/commands.md** — шпаргалка всех команд (make/artisan/docker) одним листом.
4. **dev/docker/** — подраздел: index (стек), development, production, environment-preparation, troubleshooting, backup.
5. **dev/troubleshooting.md** и **dev/recipes.md** («типовые задачи») — отдельные страницы.
6. **docs/ai/ — человеческая дока про AI-КОНТУР** (не про LLM-пайплайн!): source-layout (.ai/, генерация CLAUDE.md/AGENTS.md, что коммитить), scenarios (какие скилы в каком порядке под задачу: ревью/тесты/security/кросс-слой), environment (что проверить перед прогоном) + mermaid «поток данных: источники → генерация → потребители».
7. **docs/meta/editing.md** — как править саму документацию.
8. **Диаграммы, генерируемые из кода** (sova: `php artisan docs:workflow:generate`, `docs:db:generate`) — опциональный паттерн: FSM/схема БД из enum'ов/моделей, не руками.

Правки к каркасу (дельта к дереву выше):
```
docs/
  index.md                    # ПО SOVA: таблица «Для кого что читать» (менеджер/оператор/разработчик/DevOps) + «Быстрые ссылки»
  03_Dev/
    getting-started.md        # Требования + локальный запуск (docker compose, make, yarn, .env, где взять ключ Gemini/прокси — ИНСТРУКЦИЯ получения, не сами ключи)
    commands.md               # Шпаргалка: make-цели, artisan-команды пайплайна (pipeline:*, ai:*), docker
    docker/
      index.md                # Стек контейнеров (app/horizon/scheduler/postgres/redis), shm для Chromium
      development.md          # Dev-режим: override, volumes, HMR
      production.md           # Prod: prebuilt image, make prod:*, миграции
      troubleshooting.md      # Типичные сбои контейнеров
    troubleshooting.md        # Дев-проблемы вне Docker (тесты, ключи, очереди)
    recipes.md                # Типовые задачи: добавить тип документа, добавить провайдера, поменять промт
    ai-context/
      index.md                # AI-контур проекта: .ai/-источники, скилы, генерация CLAUDE.md/AGENTS.md, mermaid поток данных (по образцу sova docs/ai/)
      scenarios.md            # Сценарии работы агентов: ревью/тесты/security/кросс-слой — какие скилы в каком порядке
  meta/
    editing.md                # Как править документацию: где что живёт, frontmatter, mermaid, last-reviewed, Brain-sync
```
(onboarding.md из исходного дерева поглощается getting-started.md; 03_Dev/ai/ остаётся про LLM-ПАЙПЛАЙН — не путать с 03_Dev/ai-context/ про агентский контур.)

Менеджерский акцент (основная часть доки): 02_Workflow дополняется страницей `prompts-and-quality.md` — «как готовятся промты для смет и как контролируется качество» бизнес-языком: жизненный цикл промта (файл → сид → админка → корректирующий промт), требования к промтам, петля качества (проверка оператором → коррекция → перезапуск → голден), условия прохождения стадий (что отсеивает скрининг и почему).
