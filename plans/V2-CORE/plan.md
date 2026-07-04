# V2-CORE — Greenfield-каркас и схема БД (Фаза 0 + Фаза 1 ТЗ v2)

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | V2-CORE |
| Title | Каркас проекта + полная схема БД ядра закупок |
| Version | 0.1.0 |
| Status | 🟡 In progress |
| Document Type | Executable Master Plan |
| Authoring Model | sonnet |
| Last Updated | 2026-07-03 |
| Repository | agelto/agelto, ветка `feat/v2-scaffold` |
| Related Packages | — |
| Execution Mode | phase-first |
| Target Operator Models | sonnet (exec) · sonnet (design/audit, эта же сессия) |
| Approval Owner | Dmitry Vostrikov |

## 1. Context

Строим greenfield v2 платформы сбора/отслеживания/AI-разбора закупок ЕИС поверх ТЗ
`docs/specs/v2/00-overview.md` … `07-carryover-from-v1.md` (source of truth, не
переизобретать). v1-код перенесён в `Old/` (см. `07-carryover-from-v1.md`), новая
ветка `feat/v2-scaffold` от `main`. Живая разведка трёх открытых рисков (многолотовые
44-ФЗ, 223-ФЗ карточка, триггер `lastDateChangeCsv`) завершена и учтена в
`01-database.md`/`02-crawling.md` (см. `research/eis-card-recon.md`).

Этот план покрывает **Фазу 0** (каркас: Laravel 12/PHP 8.4/Postgres 17/Redis-Horizon/
MinIO/Docker) и **Фазу 1** (полная схема БД `01-database.md`, партиционирование,
модели, репозитории read/store, инжест-процессор, статус-маппинг) по `06-roadmap.md`.
Приёмка Фазы 1 — чек-лист сценариев `01-database.md` §11 покрыт тестами.

Стек фиксирован ТЗ: PHP 8.4+ (хост: `/usr/bin/php8.4`), Laravel 12, PostgreSQL 17,
Redis/Horizon, MinIO (S3-совместимое), package manager фронта — yarn (не pnpm/npm).
Тестовая БД — `*_test`, изоляция по канону `test-isolation-guard`.

## 2. Execution Rules

- Один item за раз; статус обновляется в `phases/Pn.md` до завершения работы.
- Статусы — 6 канонических строк (см. skill `plan-protocol` §5).
- Каждый готовый срез — через ревью (agelto-reviewer/agelto-db-impact/agelto-security-review
  под read-only, agelto-test-writer/agelto-test-verifier для тестов) прежде чем помечать 🟢.
- Тесты — только через Docker test-gate (`make test-preflight` → `docker compose exec app php artisan test --compact`),
  не через голый `php artisan test` на хосте.
- Механическая генерация миграций/моделей по уже утверждённой схеме — можно делегировать
  Agent(subagent_type=general-purpose или agelto-slice-builder, модель по умолчанию),
  синтез схемных решений и ревью — основная сессия.
- План разошёлся со схемой `01-database.md` → не импровизировать, сверяться с ТЗ и
  при необходимости явно предлагать правку ТЗ отдельным шагом (как в Step 1), не тихо.
- После закрытия item/фазы — перезаписать `plans/V2-CORE/handoff.md`.

## 3. Routing

Без отклонений от дефолтов plan-protocol §7, кроме: планирование и ревью — та же
сессия (opus/sonnet недоступен для параллельного планировщика), не отдельная роль.

## 4. Phase Index & Status Board

| Phase | Title | Items 🟢/всего | Status |
|:--|:--|:--|:--|
| P0 | Каркас | 1/3 | 🟡 In progress |
| P1 | Схема БД и ядро закупок | 0/11 | ⬜ Not started |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-03 | v2 строится в текущем репозитории (agelto/agelto), новая ветка `feat/v2-scaffold`, не отдельный репо | Явное решение владельца по вопросу Step 0 |
| D2 | 2026-07-03 | v1-код перенесён в `Old/` сразу (git mv), до старта Фазы 0 | Явное решение владельца по вопросу Step 0 |
| D3 | 2026-07-03 | Правки схемы по итогам разведки (source_guid/lot_guid/is_centralized/okved_code) внесены в `01-database.md` до старта миграций | Явное решение владельца по итогам Step 1 |
| D4 | 2026-07-03 | Партиционирование — `pg_partman`, помесячно (`prc_events`, `prc_revisions`, `ai_llm_calls`, `crw_fetches`, `sys_operation_logs`); своя scheduled-команда как fallback | Зафиксировано в `01-database.md` §0.4 |
| D5 | 2026-07-04 | `StatusMappingService` (P1.10) АВТОМАТИЧЕСКИ создаёт строку в `plt_status_mappings` с `canonical_status='unknown'` для нового `source_status`, не только читает | Явное решение владельца — оператор видит и правит неизвестный статус в админке, не роется в логах |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-03 | issue-planner/sonnet | План создан после Step 0 (Old/ перенос) и Step 1 (живая разведка + правки схемы) |
