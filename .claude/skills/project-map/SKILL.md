---
name: project-map
description: "Быстрая карта структуры проекта Agelto и зон ответственности по директориям."
---

# Project Map (Agelto)

Сначала сверяйся с `.ai/guidelines/navigation.md`, `.ai/guidelines/architecture.md` и `.ai/guidelines/cheatsheet.md`.

## Домены (`app/**`)

Плоская структура без `Modules/`, 5 доменов:
1. **`Procurement` (`prc_*`)** — `app/{Models,Services,Dto,Enums,Repositories,Jobs,Console/Commands}/Procurement/`:
   - `Process/` — оркестрация работ (`prc_process_work`, `prc_operation_intents`, `prc_execution_leases`, `prc_subject_heads`, `prc_process_outbox`, `FeederDaemon`, `RecoveryDaemon`).
   - `Network/` — исходящий транспорт (`OutboundExecutor`, `ScheduledAttemptGate`, `ProxyResolver`, `NetworkPermitRepository`, `HealthRepository`, `RouteRepository`).
   - `Acquisition/` & `Crawler/` — запуск обхода, курсоры, coverage, финализация `listing`/`detail`/`refresh`.
   - `Document/` & `Prepare/` & `Storage/` — скачивание, immutable CAS blobs (`prc_storage_blobs`), резервации, конвертеры (`GsfxStrategy`, `WordStrategy`, `SpreadsheetStrategy`, `ScanStrategy`), GC/reconcile.
   - `Screening/` & `Extraction/` & `Ai/` — скрининг документов, извлечение смет, финализация анализов.
2. **`Platforms/<Code>`** — `app/{Services,Dto,Enums}/Platforms/<Code>/`:
   - Изолированные адаптеры площадок (`Eis`, `Rts`, `SberAst`, `Roseltorg`, `Zakazrf`, `Fabrikant`, `Gpbl`, `EtpEts`, `TekTorg`). Никаких площадочных полей/веток в общих моделях и сервисах.
3. **`Reference` (`ref_*`)** — `app/{Models,Services}/Reference/`:
   - Классификаторы ОКПД2, КТРУ, ОКВЭД2, КБК (`ClassifierResolver`, `ClassifierImporter`).
4. **`Organization` (`org_*`)** — `app/{Models,Services,Repositories}/Organization/`:
   - Организации и внешние идентификаторы `(platformCode, type, value)`.
5. **`Llm` (`llm_*`)** — `app/{Models,Services,Repositories,Dto,Enums}/Llm/`:
   - Провайдер-агностичный транспорт, промты, `FinancialLedgerRepository`, `ProviderCallLedgerRepository`, `AssetRepository`, `BillingReconciler`.

## Прочие директории

- `app/Filament/` — операторская панель (runs, work, incidents, storage, LLM ledger, каталоги).
- `app/Http/Controllers/` — тонкий HTTP-слой (Inertia, health/readiness, private docs).
- `docs/workflow/` и `docs/dev/` — документация процессов, схемы БД и `process-core.md`.
- `tests/Feature/ProcessCore/` и `tests/Unit/ProcessCore/` — изолированные сьюты `core-*`.
