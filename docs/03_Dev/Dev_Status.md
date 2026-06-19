---
type: dev-status
tags: [agelto, status, progress]
created: 2026-05-31
updated: 2026-06-14
---

# Статус разработки — Agelto

> [!info] Связанные документы
> ← [[BRD (Agelto)]] | ← [[Architecture_Agelto]] | → [[ADR-001_Catalog_From_TcenZor]]

> [!note] Снято со scope Agelto
> Построение собственного эталонного каталога/MDM (нормативка КТРУ/КСР/ГОСТ/ОКПД2)
> **снимается** с Agelto — приходит из [[TcenZor]] по API
> ([[ADR-001_Catalog_From_TcenZor|ADR-001]]). Фокус Agelto — парсинг смет +
> матчинг к эталону + golden dataset вердиктов.

---

## Реализовано

| Компонент | Статус |
|:---|:---|
| Кроулер zakupki.gov.ru (EisCrawlingService) | ✅ Рабочий |
| Дробление по датам и ценам | ✅ Рабочий |
| Загрузка вложений с ротацией прокси | ✅ Рабочий |
| DocumentAnalysis: загрузка PDF в Gemini Files API | ✅ Рабочий |
| DocumentAnalysis: context cache (6-часовой TTL) | ✅ Рабочий |
| DocumentAnalysis: иерархия шагов (parent_id) | ✅ Рабочий |
| 4-стадийный pipeline: Bus::chain + idempotency guards | ✅ Рабочий |
| State machine AnalysisStatus (11 статусов) + лог переходов | ✅ Рабочий |
| Telegram-алерты о сбоях пайплайна | ✅ Рабочий |
| Чанковый EstimateAnalysis (80 строк/чанк) | ✅ Рабочий |
| PromptRules: управление инструкциями через Filament | ✅ Рабочий |
| SmetaParserService: шапка Excel-сметы через Gemini | ✅ Рабочий |
| SmetaParserService: чанковый парсинг строк сметы | ✅ Рабочий |
| Схема данных Order/Lot/PurchaseObject | ✅ Миграции |
| Схема данных OrderCustomer (ИНН/КПП/ОГРН) | ✅ Миграции |
| Sys: прокси-пул | ✅ Рабочий |
| Sys: логирование | ✅ Рабочий |
| Docker: dev + prod compose (Swarm secrets) | ✅ Готово |
| Filament: базовый admin | ✅ Скелет |
| Fortify auth + 2FA (TOTP) | ✅ Рабочий |
| Pest v4 + architecture tests + larastan | ✅ Рабочий |
| Редизайн схемы БД (Вариант A): единый error-блок, лизинг, `plt_crawler_runs`; удалены `plt_parsing_*` | ✅ Выполнено (`AUDIT.md`) |

---

## В разработке

| Компонент | Статус |
|:---|:---|
| Синхронизация эталонного каталога из [[TcenZor]] (реплика строительного среза) | 🔧 Не начата (новое, ADR-001) |
| Семантический матчинг (pgvector): смета → эталон → коллекция | 🔧 Не начат |
| Клиентские коллекции (прайс-лист клиента-поставщика) | 🔧 Не начат |
| Lead Scorer (ранжирование) | 🔧 Не начат |
| CRM-интеграция (Bitrix24/amoCRM) | 🔧 Не начата |
| Frontend Radar / Smeta Viewer | 🔧 Не начат |
| RBAC (роли Admin/Client/Manager/Analyst) | 🔧 Не начат (Fortify ✅) |
| Биллинг / учёт квот (F-MON-1/3 — Must Have!) | ❌ Не начат |
| Мультитенантность (схема не выбрана, T4) | ❌ Не начата |
| Observability: метрики, SLO (есть только Telegram-алерты) | ❌ Не начата |
| Backup / DR (RPO/RTO не определены, T7) | ❌ Не начат |
| Лингвистический комплаенс (лат↔кир) | ❌ Планируется v2 |
| LayoutLMv3 / OCR для сканов | ❌ Планируется v2 |
| Мониторинг результата закупки (победитель, протокол итогов, сценарии возврата) | ❌ Предложено ([[ADR-002_Winner_Tracking_And_Monitoring]]) |

> [!warning] Разрыв требования ↔ код
> Матчинг (F-MATCH-*) — Must Have в [[BRD (Agelto)]], не начат — критический путь. Биллинг на пилоте — вручную (см. критерий MVP, [[BRD (Agelto)]] §1).
