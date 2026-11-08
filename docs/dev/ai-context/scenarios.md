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

## Локальная репетиция краулинга и пайплайна ЕИС (без платного AI)

1. `.ai/guidelines/cheatsheet.md` (раздел 5) и
   [Ранбук прогона архива и свежего потока ЕИС](../runbooks/archive-run.md) — пошаговая схема
   подготовки окружения и итеративной верификации.
2. **Подготовка без расхода бюджета LLM**: выполнить `BaseSeeder`, `EisCrawlerProxySeeder`,
   `EisAuditSeeder`, затем `php artisan platform:sync-catalog` и
   `php artisan process:sync-documents`. Команду `php artisan process:sync-analysis` локально
   **не выполнять** (блокирует `screening`/`extraction`/`publication` на уровне отсутствия
   `prc_process_handler_holds` и бюджетного периода `llm_budget_periods`).
3. **Маршрутизация прокси**: убедиться, что у активного профиля `prc_crawlers` привязан
   `route_profile_id` с маршрутом на российский VDS (`PLAYWRIGHT_MCP_PROXY_SERVER`), а при
   включённом VPN на хосте (`amn0`) IP адрес VDS выведен напрямую через физический шлюз LAN.
4. **Поэтапный прогон через `prc_control_scopes`**:
   - Итерация 1 (`listing`): поставить на паузу `acquisition:stage:detail` и
     `documents` (`php artisan process:control pause ...`), прогнать ограниченное окно
     дат (`php artisan process:run <profile_id> --kind=archive --from=... --to=...`), дождаться двухпроходного
     закрытия блоков (`prc_crawl_blocks.status = complete`) и сверить `COUNT(*)` в
     `prc_procurements` с выдачей `zakupki.gov.ru` через Playwright по тем же фильтрам.
   - Итерация 2 (`detail`): снять паузу с `acquisition:stage:detail` (оставив
     `documents` или `documents:stage:prepare`/`documents:stage:screening` на паузе) и
     проверить разбор 4 вкладок карточки + контрольный проход, запись в `prc_revisions`,
     `prc_procurement_parties`, `prc_events`, `prc_documents` и `prc_document_versions`.
   - Итерация 3 (`delta` и `status_refresh`): проверить отслеживание обновлений по
     `sortBy = UPDATE_DATE` и регламентный опрос `php artisan process:schedule-acquisition`
     (`next_status_due_at <= now()`).
5. Все замеры, сверки и найденные расхождения фиксировать в локальной папке `/Audits/`
   (в `.gitignore`).

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
