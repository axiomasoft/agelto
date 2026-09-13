---
audience: dev
type: howto
status: living
---

# Сценарии работы агентов

Какие скилы подключать в каком порядке под конкретную задачу. Полный реестр — `.ai/guidelines/
navigation.md` (Reference Table) и `skiller status`; здесь — типовые маршруты, не полный список.

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
3. `db-test-preflight` — перед первым прогоном в новом окружении (Postgres-контейнер жив, БД
   `agelto_test`, миграции применены).

## Кросс-слойное изменение в пайплайне документов/смет

1. skill `procurement-pipeline-dev` — доменный чек-лист (dependency map, test-gate, PR checklist)
   для парсинга/jobs/очередей.
2. `.ai/guidelines/architecture.md` — layer boundaries, где живёт логика.
3. `database`/`eloquent-accessors` — при миграциях/аксессорах моделей `prc_*`.
4. `filament` — если меняется Filament-ресурс (и `docs-pr-checklist` напоминает про SOP-апдейт).

## Комплексная/неоднозначная задача

1. `complex-task-orchestrator` — декомпозиция и context routing.
2. Многошаговая работа с исполняемым мастер-планом — `plan-protocol`
   (`/task:plan-design`/`/task:plan-exec`/`/task:plan-close`/`/task:plan-audit`).
3. `context-economy` — дисциплина токенов (Plan→Clear→Execute, `/clear` между item'ами).

## Перед коммитом

`git-commit-rules` — формат сообщения, порядок безопасных действий; для agelto — русский язык
описания, conventional-commits-подобный тип (`feat`/`fix`/`docs`/`chore`/`test`), см. историю
`git log --oneline`.

## Смежное

- [`index.md`](index.md) — устройство самого `.ai/`-контура (источники → генерация).
- `.ai/guidelines/docs-pr-checklist.md` — сквозной PR-чеклист «docs обновлены?».
