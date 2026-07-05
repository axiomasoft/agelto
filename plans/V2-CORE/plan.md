# V2-CORE — Greenfield-каркас и схема БД (Фаза 0 + Фаза 1 ТЗ v2)

## 0. Meta

| Поле | Значение |
|:--|:--|
| Plan ID | V2-CORE |
| Title | Каркас проекта + полная схема БД ядра закупок |
| Version | 0.1.0 |
| Status | 🟠 Done with deviations |
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
| P0 | Каркас | 3/3 | 🟠 Done with deviations |
| P1 | Схема БД и ядро закупок | 11/11 | 🟢 Done |

## 5. Decision Log

| D# | Дата | Решение | Почему |
|:--|:--|:--|:--|
| D1 | 2026-07-03 | v2 строится в текущем репозитории (agelto/agelto), новая ветка `feat/v2-scaffold`, не отдельный репо | Явное решение владельца по вопросу Step 0 |
| D2 | 2026-07-03 | v1-код перенесён в `Old/` сразу (git mv), до старта Фазы 0 | Явное решение владельца по вопросу Step 0 |
| D3 | 2026-07-03 | Правки схемы по итогам разведки (source_guid/lot_guid/is_centralized/okved_code) внесены в `01-database.md` до старта миграций | Явное решение владельца по итогам Step 1 |
| D4 | 2026-07-03 | Партиционирование — `pg_partman`, помесячно (`prc_events`, `prc_revisions`, `ai_llm_calls`, `crw_fetches`, `sys_operation_logs`); своя scheduled-команда как fallback | Зафиксировано в `01-database.md` §0.4 |
| D5 | 2026-07-04 | `StatusMappingService` (P1.10) АВТОМАТИЧЕСКИ создаёт строку в `plt_status_mappings` с `canonical_status='unknown'` для нового `source_status`, не только читает | Явное решение владельца — оператор видит и правит неизвестный статус в админке, не роется в логах |
| D6 | 2026-07-04 | Базовый образ Postgres (dev+prod `docker-compose*.yml`) переключён с `postgres:17-alpine` на кастомный `docker/postgres/Dockerfile` (`FROM postgres:17` + `apt-get install postgresql-17-partman`) | Явное решение владельца по эскалации P1.9 — `pg_partman` недоступен в alpine-варианте (нет PGDG-пакета для alpine); debian-вариант `postgres:17` уже несёт настроенный PGDG apt-репозиторий, апт-пакет ставится без доп. настройки |
| D7 | 2026-07-04 | Неизвестный `source_status` при ингесте пишет `prc_events` с `event_type='status_changed'`, `field='status'`, `new_value='unknown'` — отдельный `event_type='unknown_status'` НЕ добавляется | P1.10 Implementation Rules явно требовал зафиксировать выбор; переиспользование существующего типа проще и не требует правки закрытого codelist `event_type` из `01-database.md` §3, семантически «статус изменился на unknown» точно описывает событие |

## 6. Update Log

| Дата | Кто (role/model) | Что |
|:--|:--|:--|
| 2026-07-03 | issue-planner/sonnet | План создан после Step 0 (Old/ перенос) и Step 1 (живая разведка + правки схемы) |
| 2026-07-04 | plan-exec/sonnet | P1.9 эскалирован — `pg_partman` недоступен в `postgres:17-alpine`, нужно решение владельца об образе Postgres (см. `phases/P1.md` P1.9, `handoff.md`) |
| 2026-07-04 | plan-exec/sonnet | Владелец решил в чате (D6): образ Postgres → `postgres:17`+`postgresql-17-partman`. P1.9 реализован кодом (Dockerfile/compose/миграции/модель/команда/тесты); `docker compose build postgres` не прогнан до конца в песочнице агента (сеть без IPv6-маршрута до `apt.postgresql.org`) — нужна ручная валидация владельцем на своей машине |
| 2026-07-04 | plan-exec/sonnet | Владелец прогнал build+migrate на своей машине и прислал вывод. Найдены и исправлены 2 бага (extension без `SCHEMA partman`; `run_maintenance_proc()` с внутренним COMMIT ломался под `RefreshDatabase`; `migrate:fresh` не идемпотентен против `part_config` — добавлена проверка). Validation зелёная (migrate:fresh ×2, тесты, Pint). P1.9 закрыт 🟢 |
| 2026-07-04 | plan-exec/sonnet | P1.10 закрыт 🟢 (D7 добавлен в Decision Log — событие для неизвестного статуса переиспользует `status_changed`, отдельный `event_type` не заводится). `ProcurementReadRepository`/`ProcurementStoreRepository`, `ProcurementIngestProcessor`, `StatusMappingService` + DTO `ProcurementSnapshotData`/`StatusResolution`. Validation: migrate:fresh зелёный, tinker-smoke всех сценариев чек-листа §11 (discover/no-op/price-change/unknown-status), Pint чисто, `php artisan test` без регресса |
| 2026-07-04 | plan-exec/sonnet | P1.11 закрыт 🟢 — 24 Pest-теста в `tests/Feature/Procurement/` (agelto-test-writer, коммит `9c3466e`), покрывают все 17 строк §11 (план указывал устаревшее «14» — расхождение зафиксировано в Known Deviations item'а, не эскалировано) + §11.1. Полный сьют 29 passed, регресса нет; Pint чисто. **Фаза P1 закрыта целиком (11/11 🟢)** |
