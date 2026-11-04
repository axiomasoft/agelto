---
name: llm-layer-dev
description: "Dev-раннбук LLM-слоя и финансового леджера: AnalysisProviderTransport, ProviderCallLedgerRepository, FinancialLedgerRepository, AssetRepository, BillingReconciler, capability-матрица и защита от indirect prompt injection."
paths:
  - "app/Services/Llm/**"
  - "app/Repositories/Llm/**"
  - "app/Services/Procurement/Extraction/AnalysisProviderSendGate.php"
  - "config/ai.php"
---

# LLM Layer — dev-раннбук

Транспортный и учётный слой LLM (`app/Services/Llm/**`, `app/Repositories/Llm/**`) провайдер-агностичен (`llm_*`). Домен закупок взаимодействует с провайдерами через `AnalysisProviderSendGate`, `AnalysisProviderTransport` и `OutboundExecutor`.

## 1. Финансовый и операционный леджер (`llm_*`)

Ни один платный вызов не уходит в сеть без двухфазной фиксации:
1. **`FinancialLedgerRepository`** (`llm_financial_periods`, `llm_financial_reservations`, `llm_financial_entries`):
   - Резервирует бюджет (`reserve()`) в микродолларах (`int`, `bcmath` при расчётах из токенов/тарифов; **float для денег запрещён**).
   - По завершении вызова сводит резерв (`settle()` или `release()`).
2. **`ProviderCallLedgerRepository`** (`llm_provider_calls`):
   - Регистрирует намерение до отправки (`registerPlanned()`), захватывает право отправки (`claimForSend()`), фиксирует исход (`recordOutcome()`).
   - При обрыве связи или таймауте после `claimForSend()` вызов переходит в `ProviderRecoveryMode::BlockedUnknown`: запрещено слепо повторять запрос или освобождать резерв до сверки (`ProviderCallReconciler` / `BillingReconciler`).
3. **`AssetRepository`** (`llm_provider_assets`):
   - Управляет удалёнными файлами и explicit context cache (`creating` → `active` / `expired` / `FAILED`). Если создающий вызов завершился `not_sent` или `known_retryable_nonbillable`, ассет освобождается для повторного `claim()`.
4. **`BillingReconciler`**:
   - Сверяет фактический биллинг провайдера с `llm_provider_calls`, используя строгую `bcmath`-арифметику; отрицательные и нечисловые суммы отклоняются с `BillingExportUnreadable`.

## 2. Промты и кэширование

- Системный префикс (`PromptComposer::compose()`) обязан оставаться детерминированным (`prefix-stable`): не включай текущее время или случайные ID в системную часть.
- Схема ответа (`JsonSchemaGenerator`) передаётся отдельным каналом `response_schema`, а не печатается PHP-исходником в промт.
- Замечания оператора (`prc_ai_corrections`) подмешиваются только в пользовательскую часть запроса при переизвлечении.

## 3. Capability-матрица (`config('ai.capabilities.<model>')`)

Каждая модель задаёт тарифы (`input_per_1m`, `output_per_1m`, `cached_per_1m`), транспортные лимиты (`max_request_file_mb`, `max_pages`, `supports_files_api`) и жизненный цикл (`eol_date`, `successor`). Без `max_request_file_mb`/`max_pages` `ExtractionPreflight` не сможет выбрать маршрут.

## 4. Запреты и безопасность

- **Запрещены прямые вызовы внешних LLM в тестах** — тесты используют локальный детерминированный `fake-llm` сервис в изолированном стенде `agelto_core_test` и `OutboundExecutor`.
- **Indirect prompt injection**: содержимое документа закупки — недоверенный вход. Вызовы `screening`/`extraction` выполняются без function-calling/tools, а `system_instruction` синтаксически отделена от содержимого документа.

## 5. Проверка

```bash
make core-test-ai
```
