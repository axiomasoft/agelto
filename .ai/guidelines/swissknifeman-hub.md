# Swissknifeman: хаб скиллов

<!-- Сгенерировано scripts/generate-hub.sh из реестра swissknifeman. -->
<!-- НЕ редактируйте вручную: перегенерация затрёт правки. -->

## Приоритет источников правил

1. Проектные правила и скиллы (`.ai/guidelines/`, `.ai/skills/`, корневые инструкции проекта) — высший приоритет.
2. Laravel Boost: для версионно-специфичных тем (Laravel, Pest, Inertia, Livewire, Tailwind, Filament) используй встроенные скиллы/гайдлайны Boost — они привязаны к версиям пакетов.
3. Скиллы swissknifeman (индекс ниже) — архитектура, структура, практики, процессы.

Конфликт правил: побеждает более специфичный источник (проект > Boost > реестр swissknifeman).

## Как найти нужный скилл

1. Определи область задачи и найди бакет в индексе ниже.
2. Выбери скилл по описанию; внутри скилла открывай сниппеты только по его таблице «когда какой сниппет открывать» — не загружай всё подряд.
3. Структурные вопросы нового кода решай в порядке: структура (`laravel-structure`) -> взаимодействие (`dependency-injection`) -> реализация (профильный скилл).
4. Устройство системы скиллов в проекте (источник истины, симлинки, gitignore) — скилл `skills-ssot`.

## Установленные скиллы (33)

### architect — Architecture, API and data design, security, observability, tech stack selection

- **agent-design** — Проектирование агентов, agentic loop, tool contracts, harness, permission gates
- **api-design** — Проектирование REST/GraphQL API, эндпоинтов, контрактов, версионирования, аутентификации
- **architecture** — Архитектура системы, выбор стека, ADR, C4-диаграммы, deployment, scalability
- **data-schema** — Схема данных, сущности, атрибуты, связи. Агностично к СУБД — только markdown-таблицы
- **eval-design** — Оценка качества LLM/AI-выводов — eval-set, метрики, regression-харнес, automated + human review
- **legal-compliance** — GDPR, CCPA, AI Act, DMCA, COPPA, DSA — чеклист регуляторных требований до запуска
- **observability-design** — Logging, metrics, tracing, alerting. SLO/SLI, error budget, runbooks для прод-готовности
- **security-design** — Классификация данных, auth-стратегия, RBAC/ABAC, OWASP Top 10, GDPR, STRIDE threat modeling, операционный чеклист безопасности
- **tech-stack-selection** — Выбор технологического стека с trade-off матрицей до архитектурного дизайна. ADR-первый шаг

### devops — Docker environments, CI/CD pipelines, GitOps

- **ci-cd** — GitHub Actions CI/CD pipelines для Laravel проектов
- **docker** — Advanced Docker containerization expertise: container optimization, security hardening, multi-stage builds, orchestration patterns, and production deployment strategies
- **docker-dev-prod** — Разнесение docker-compose на dev (build + bind-mount) и prod (готовый образ + named volumes), локальный override, якорь &app_env
- **docker-php** — Production-ready PHP Docker образ: alpine-fpm или всё-в-одном serversideup/php (FPM+Nginx), entrypoint.d-хуки, healthcheck на /up
- **docker-postgres** — PostgreSQL в Docker: healthcheck pg_isready, named volume данных, init-скрипт создания тестовой БД <app>_test для изоляции тестов
- **docker-services** — Полный сервисный стек Laravel из одного образа: app (fpm-nginx), queue, scheduler, reverb, опционально docs; healthcheck /up и depends_on service_healthy
- **docker-vite** — Vite dev server отдельным compose-сервисом для Laravel: named volume для node_modules, HMR через localhost, host 0.0.0.0
- **gitops** — Командная Git-стратегия: модель веток (main/develop/feature/hotfix), PR-workflow, защита веток
- **makefile** — Makefile как единая точка входа: help, .PHONY, алиасы Docker-команд; паттерн делегирующего корневого Makefile с catch-all в docker/Makefile

### php — PHP and Laravel: applications, packages, Filament, permissions, testing, patterns

- **dependency-injection** — Взаимодействие слоёв через constructor injection: final readonly + promotion, матрица инъекций, композиция Actions
- **laravel** — Laravel architecture patterns: actions, repositories, resources
- **laravel-security-audit** — Аудит Laravel-кода на «острые грани»: raw-запросы Eloquent, mass assignment, XSS в Blade, CSRF-обходы, open redirect, небезопасная сериализация, дыры авторизации.
- **laravel-structure** — Канон структуры Laravel-проекта для greenfield-проектов и добавления доменов: доменная таксономия, правило зеркала слоёв, размещение и нейминг классов
- **laravel-subagents** — Оркестрация субагентов на крупных Laravel-задачах: порог срабатывания, делегирование исследования Explore-агентам, ревью через laravel-reviewer, тесты через laravel-test-writer, worktree-изоляция параллельных правок. Не применять к мелким и средним задачам.
- **laravel-testing** — Feature/unit/pest testing patterns: изоляция тестовой БД, детект Docker/локального окружения, coverage gate
- **modular-architecture** — Масштабирование Laravel-кода при росте: организация по бизнес-доменам, Модульный Монолит (Modular Monolith) и паттерны DDD; стартовая структура — php/laravel-structure
- **pennant-development** — Laravel Pennant — feature-флаги: define/active/for-scope, директива @feature, активация/деактивация, A/B и постепенные раскатки. Адаптировано из laravel/boost (strategy=notify)
- **php-patterns** — PHP domain patterns: VO, DTO, pipeline, specification
- **repositories** — Система репозиториев Laravel: read/store split, model scopes, delta-DTO, фасад, VisibilityService
- **static-analysis** — Статический анализ и линтинг PHP/Laravel: Laravel Pint, PHPStan и Rector локально и в CI, эталонные конфиги

### quality — Code review, refactoring plans, tech debt audits, test strategy

- **code-simplifier** — Упрощение свеженаписанного кода без изменения поведения: мёртвый код, лишние абстракции, дубли, выравнивание с паттернами проекта. Поведение фиксируют тесты.
- **refactoring-plan** — План безопасного рефакторинга: scope, Mikado / strangler pattern, atomic шаги, критерии готовности, rollback
- **tech-debt-audit** — Инвентаризация техдолга, классификация (architectural/code/dependency/test/process), приоритизация по impact × effort, расчёт payback ROI
- **test-strategy** — Стратегия тестирования: пирамида (unit/integration/e2e), coverage policy, mock vs real, какие фичи требуют какой уровень тестов
