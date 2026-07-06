---
title: Архитектура
type: reference
tags: [agelto, dev]
updated: 2026-07-06
---

# Архитектура

## Стек

- **PHP 8.4+**, **Laravel 12**.
- **PostgreSQL 17** (образ `agelto_postgres:17-partman` — с расширением **pg_partman** для обслуживания партиционированных таблиц).
- **Redis** + **Laravel Horizon** — очереди (тяжёлые job'ы: browser-автоматизация, LLM-вызовы, разбор смет).
- Object storage (MinIO/S3-совместимое) — сырые артефакты пайплайна (CSV, скачанные документы, ответы LLM).
- Node-сервис рендеринга (Playwright) — запасной backend для площадок с клиентским рендерингом; для ЕИС не используется (весь сбор — HTTP-first, см. [[crawling-pipeline]]).

Часть компонентов техстека (Filament-админка, LLM-провайдеры через Prism/`laravel/ai`, spatie-пакеты) — целевая архитектура, описанная в [[llm-providers]]/[[admin-panel]], но ещё не установлена в `composer.json` на момент этого документа: код сейчас реализует ядро домена закупок (модели, миграции, сервисы) без админки и без LLM-интеграции. Сверяйся с фактическим `composer.json`, если нужна точная картина установленных пакетов.

## Структура кода

Код плоский — `app/**`, без модулей (`Modules/Platform`, `Modules/Eis` существовали в предыдущей версии продукта и остались только в `Old/`, в текущем дереве их нет). Каждый слой разложен по доменным папкам:

```
app/
  Models/
    Procurement/     — единственный домен закупок: ядро закупки плоско в корне (Procurement, Lot,
                        PurchaseItem, Financing, EisDetails, Protocol, Bid, Award, Clarification,
                        Cancellation, ProcurementParty) + OperationLog; остальное — по подпапкам
                        назначения (см. ниже)
      Document/       — Document, DocumentVersion
      Estimate/       — Estimate, Section, Item, Resource, Total
      Crawler/        — Crawler, Run, Proxy, ProxyCheck, Fetch, IgnoreRule, IgnoredItem, DailyStat
      Ai/             — Analysis, LlmCall, Provider, AiRoute, Prompt, DatasetExample, EvalRun,
                        EvalResult
      Platform/       — Platform, Registry, StatusMapping, DocumentType, Region
    Reference/        — Kbk, Ktru, Okpd2, Okved2 (общероссийские классификаторы, shared)
    Organization/      — Organization (единый реестр организаций, shared)
  Services/
    Procurement/       — сервисы домена закупок (Eis/, Modes/, Contracts/, Detail/, Exceptions/ —
                          подпапки по смыслу, не по бывшему типу сущности)
    Reference/
    Organization/
  Dto/
    Procurement/        (+ Details/)
  Enums/
    Procurement/
    Organization/
  Repositories/
    Procurement/
    Organization/
  Console/Commands/
    Procurement/
    Reference/
```

**Правило:** папка верхнего уровня = полное доменное имя, соответствующее короткому префиксу таблицы (см. «Табличные префиксы» ниже) — `Ai`/`Crawler`/`Document`/`Estimate`/`Platform` НЕ являются отдельными доменами, это устаревший паттерн (были отдельными папками до консолидации таблиц под единый `prc_`). Внутри одного домена `Procurement` подпапки по смыслу разрешены и нужны для читаемости: в `Services/Procurement/` — по этапу пайплайна (`Eis/`, `Modes/`, `Contracts/`), в `Models/Procurement/` — по назначению сущности (`Document/`, `Estimate/`, `Crawler/`, `Ai/`, `Platform/`); ядро закупки и однофайловые группы (`OperationLog`) остаются плоско в корне домена. Ключевое отличие от старой схемы: тогда `Ai`/`Crawler`/`Document`/`Estimate`/`Platform` были ПАПКАМИ ВЕРХНЕГО УРОВНЯ рядом с `Procurement` (как будто отдельные домены), сейчас — подпапки ВНУТРИ `Procurement`.

`Reference/` (`ref_*`) и `Organization/` (`org_organizations`) — самостоятельные shared-домены, не относятся содержательно к закупкам и переиспользуются любым будущим модулем (документооборот, CRM).

## Табличные префиксы (модуль = префикс)

| Префикс | Домен | Примечание |
|---|---|---|
| `ref_` | Классификаторы (shared) | okpd2, ktru, okved2, kbk — версионные общероссийские справочники |
| `org_` | Организации (shared) | organizations — единый реестр по ИНН/ОГРН |
| `prc_` | Закупки (единый домен) | ядро, история, детальная карточка, площадки, документы, сметы, ИИ-анализ, краулинг, система — весь предметный код сейчас живёт в одном bounded context'е «закупки» |

Единый префикс `prc_` — намеренное решение: изначально под-домены (площадки/документы/сметы/ИИ/краулинг/система) имели разные префиксы по ТИПУ данных (`plt_`/`doc_`/`est_`/`ai_`/`crw_`/`sys_`), но это коллизирует при появлении второго bounded context'а. Полная карта переименования, таблица имён и осознанный компромисс (что делать, когда появится второй модуль) — [[database]] §0.1.

Слово после `prc_` добавляется только там, где без него неоднозначно, к какой сущности внутри домена относится таблица (`prc_estimate_items` — уточняет «позиции сметы», не позиции закупки), а не когда оно просто повторяет факт «это про закупку» (`prc_lots`, а не `prc_procurement_lots`).

## Куда дальше

- Полная схема БД, таблицы, партиционирование — [[database]].
- Сбор данных (краулинг) — [[crawling-pipeline]].
- Документы и AI-анализ — [[document-pipeline]], [[llm-providers]].
- Админка и наблюдаемость — [[admin-panel]].
- Бизнес-термины и канон доменных сущностей — [[../02_Workflow/glossary]].
