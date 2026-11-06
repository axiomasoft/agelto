---
name: llm-layer-dev
description: "Dev-раннбук LLM-слоя и финансового леджера: StructuredCallExecutor, RequestRepository, FinancialReservationRepository, ReceiptStore, AssetRepository, BillingReconciler и защита от indirect prompt injection."
paths:
  - "app/Services/Llm/**"
  - "app/Repositories/Llm/**"
  - "app/Services/Procurement/Extraction/AnalysisProviderSendGate.php"
  - "config/ai.php"
---

# LLM Layer — dev-раннбук

Транспортный и учётный слой LLM (`app/Services/Llm/**`, `app/Repositories/Llm/**`) провайдер-агностичен (`llm_*`). Домен закупок взаимодействует с провайдерами через `AnalysisCallCoordinator`, `AnalysisProviderSendGate`, `StructuredCallExecutor` и `OutboundExecutor`. Актуальные контракты и provisioning описаны в `docs/dev/llm-providers.md`.

## 1. Финансовый и операционный леджер (`llm_*`)

Ни один платный вызов не уходит в сеть без двухфазной фиксации:
1. **`FinancialReservationRepository`** (`llm_budget_accounts`, `llm_budget_periods`, `llm_budget_reservations`, `llm_financial_actions`):
   - Хранит account policy и периоды; `RequestRepository::authorize()` резервирует conservative bound под account/period locks.
   - `PricingSnapshot` рассчитывает bound через `bcmath` из целочисленных тарифов; ledger хранит точные decimal amounts (`numeric(24,8)`). **Float для денег запрещён**.
   - `settleLocked()` сводит резерв; доказанный `not_sent` сводится в ноль, unknown сохраняет обязательство.
2. **`RequestRepository` / `RequestLedger`** (`llm_provider_requests`, `llm_provider_calls`):
   - `authorize()` создаёт logical/physical identity, reservation и receipt upload intent; `claimFinancialLocked()` участвует в атомарном joint financial/network claim через `AnalysisProviderSendGate`.
   - `StructuredCallExecutor` выполняет единственный send через `PreparedProviderTransport`. `ReceiptStore` сохраняет immutable raw receipt вне DB transaction до `RequestRepository::acceptReceiptLocked()` и model parsing.
   - Claim без verified receipt означает unknown: запрещены слепой повтор и освобождение резерва. Общий Process reconciler использует `AnalysisProviderRecoveryResolver` для проверки receipt вне locks и settlement под locks.
3. **`AssetRepository`** (`llm_file_assets`, `llm_context_caches`):
   - Управляет удалёнными файлами и explicit context cache; точный physical call служит creation token. File state: `creating` / `PROCESSING` / `ACTIVE` / `FAILED` / `expired`; cache creation state: `creating` / `active` / `expired`.
   - Новый `claim()` проверяет доказанный исход предыдущей операции; истечение creation lease не разрешает отбирать unknown create.
4. **`BillingReconciler`**:
   - Read-only сравнение нормализованного CSV с агрегатами `UsageSource`; отрицательные и нечисловые суммы отклоняются с `BillingExportUnreadable`.
   - Суточные агрегаты не доказывают исход отдельной операции и не выполняют settlement или разрешение retry.

## 2. Промты и кэширование

- Системный префикс (`PromptComposer::compose()`) обязан оставаться детерминированным (`prefix-stable`): не включай текущее время или случайные ID в системную часть.
- Схема ответа (`JsonSchemaGenerator`) передаётся отдельным каналом `response_schema`, а не печатается PHP-исходником в промт.
- Замечания оператора (`prc_ai_corrections`) подмешиваются только в пользовательскую часть запроса при переизвлечении.

## 3. Provisioning и immutable policy

Активный analysis runtime получает явный профиль из `config/llm.php`: `PinnedLlmContext`, `PricingSnapshot`, canonical origin, credential reference и period. `AnalysisConfiguration` разбирает профиль без DB/API, `AnalysisProviderConnections` сохраняет immutable bindings. Пустой профиль не включает paid work; неактивированные provider adapters бросают `UnsupportedConsumer` до HTTP. File/cache операции используют typed capability snapshots; catalog `config('ai.capabilities.<model>')` не заменяет явный owning gate и финансовую авторизацию.

## 4. Запреты и безопасность

- **Запрещены прямые вызовы внешних LLM в тестах** — тесты используют локальный детерминированный `fake-llm` сервис в изолированном стенде `agelto_core_test` и `OutboundExecutor`.
- **Indirect prompt injection**: содержимое документа закупки — недоверенный вход. Вызовы `screening`/`extraction` выполняются без function-calling/tools, а `system_instruction` синтаксически отделена от содержимого документа.

## 5. Проверка

```bash
make core-test-ai
```
