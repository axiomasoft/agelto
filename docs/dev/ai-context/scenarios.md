---
audience: dev
type: howto
status: living
---

# Сценарии работы агентов

Какие skills подключать под конкретную задачу. Начальная маршрутизация —
`.ai/guidelines/navigation.md`; здесь — типовые сценарии. Сначала читают 1–2 релевантных файла
и `.ai/guidelines/cheatsheet.md`, затем расширяют контекст при неясном термине или конфликте правил.

## Ревью изменений

1. `code-review` (или `/code-review` для diff последнего изменения) — доменные оверрайды поверх
   пака в `.claude/rules/agelto-reviewer-overrides.md`.
2. При security-поверхности (auth, ввод от пользователя, LLM-вызовы) — `security-check`/
   `laravel-security-audit`.
3. Кросс-слойное изменение (backend+frontend+тесты) — сначала `cross-layer-change-checklist`.

## Написание/запуск тестов

1. `testing-rules` (agelto-специфичная изоляция БД, `test()` без `it()`, гейт
   `TestEnvironmentGuard`) — читается ПЕРВЫМ, до общего `laravel-testing`.
2. `repositories`/`laravel-data` — если тест затрагивает репозиторий/DTO-контракт.
3. [Изолированный rig](../runbooks/process-test-rig.md) — `make core-test-up`, `make core-quality`,
   затем нужный `make core-test-*` или `make core-test-file TEST_FILE=...`. БД `agelto_test`
   должна находиться в Compose project `agelto_core_test`; одного имени БД недостаточно.

## Кросс-слойное изменение в пайплайне документов/смет

1. skill `procurement-pipeline-dev` — доменный чек-лист (dependency map, test-gate, PR checklist)
   для парсинга/jobs/очередей.
2. `.ai/guidelines/architecture.md` — layer boundaries, где живёт логика.
3. `database`/`eloquent-accessors` — при миграциях/аксессорах моделей `prc_*`.
4. `filament` — если меняется Filament-ресурс (и `docs-pr-checklist` напоминает про SOP-апдейт).

## Комплексная/неоднозначная задача

1. Native Planner — активный plan state и execution-input с текущим scope/authority.
   Исполнитель меняет согласованные файлы и возвращает stage-report; координатор принимает
   evidence, ведёт lifecycle и коммиты.
2. `context-economy` — точечное чтение и короткая передача состояния между срезами работы.
3. Task Layout v2 и `plans/archive/**` — исторический контекст; архивные lifecycle-команды
   не используются как интерфейс выполнения.

## Правка AI-контекста

1. `ai-context-workflow` и `.ai/guidelines/navigation.md` — source-first порядок:
   `.ai/guidelines/**`, затем затронутый текст `CLAUDE.md`/`AGENTS.md` и локальных skills.
2. Локальные skills редактируют после проверки заголовка и Git-статуса. Managed/generated
   файлы и явно обозначенные mAInd copies меняют у владельца либо через разрешённый extension-point.
3. `ecosystem-setup` — для plugins/connections; generated `.agents/plugins/**` и manifest
   ссылок вручную не меняют. Общий маршрут описан в [`index.md`](index.md).

## Перед коммитом

`git-commit-rules` — формат сообщения, порядок безопасных действий; для agelto — русский язык
описания, conventional-commits-подобный тип (`feat`/`fix`/`docs`/`chore`/`test`), см. историю
`git log --oneline`.

## Смежное

- [`index.md`](index.md) — устройство самого `.ai/`-контура (источники → генерация).
- `.ai/guidelines/docs-pr-checklist.md` — сквозной PR-чеклист «docs обновлены?».
